<?php

namespace App\Domain\Grants;

use App\Domain\Commerce\ContractIdentity\ContractIdentityDeclarationV2;
use App\Domain\Commerce\ProductionCheckout\ProductionCheckout;
use App\Domain\Contracts\ContractIo;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\Production\ContractBindingException;
use App\Domain\Contracts\Production\ContractLicenseConstants;
use App\Domain\Contracts\Production\ContractVariableBinder;
use App\Domain\Contracts\Production\VaseyLicenseDocument;
use App\Domain\Contracts\Production\VaseyLicenseSignature;
use App\Domain\Contracts\Production\VaseyLicenseV2PdfRenderer;
use App\Domain\Contracts\Production\VaseyLicenseV2Profile;
use App\Domain\Contracts\RenderedContract;
use App\Support\CanonicalJson;
use Throwable;

/**
 * Contract increment 5, wiring: selects the `vasey-license-pdf-v2` renderer for NEW paid (V1) and paid-tax (V2) originals
 * and renders the originals it selected. The pinned paid renderers, their child processes and their profiles are unchanged;
 * this dispatch sits in the unpinned issuers (`PaidGrants`/`PaidTaxGrants` at origin creation, `PaidGrantDocuments`/
 * `PaidTaxDocuments` at render, and the issuers' graph checks).
 *
 * Selection rule, decided once per line when its grant origin is created (paid-grant finalization, after the payment is
 * recorded), and frozen into the origin's `profile`:
 *
 * 1. the gate is on: `contracts.vasey_license_v2_issuance` is exactly `true` (a code literal, false as shipped) and the
 *    environment is `local` or `testing`;
 * 2. the line's frozen declaration is a `buyer-contract-identity-v2` one (name-only declarations keep the existing profile);
 * 3. it re-verifies against checkout's printable set, the v2 faces (`ContractIdentityHolds` already holds any other at
 *    finalization; declarations frozen before #241 under the wider `test-v2` set are held there, never routed here);
 * 4. a {@see VaseyLicenseContractSource} is bound and returns a template and constants for the line's license version; and
 * 5. the line binds and its document builds under the v2 input rule now, and the configured signature reads and matches
 *    its pin.
 *
 * Anything else, including any unexpected failure while selecting, keeps the family's existing profile (its prior behaviour
 * and bytes), so selection never refuses a paid line. Selection builds the document but does not render it: a refusal that
 * only rendering finds (drawability, page or byte limits, `invalid_pdf`/`render_failed`, or a signature rotation or pinned-file
 * change before the render) fails that line's attempts after payment, as a changed paid profile does today (an activation
 * blocker in docs/production-contract-profile.md). Once an origin exists its recorded profile decides every render, retry and replay, whatever the gate says
 * later; nothing here ever re-renders a completed original.
 *
 * Payload growth: the recorded profile carries only the SHA-256 of the template and of the renderer profile. Their bytes
 * are stored once each in {@see VaseyLicenseContractTexts} when a line is selected, and every render resolves them by hash
 * and verifies them, so a stored text that differs refuses `profile_changed`.
 */
final class VaseyLicenseIssuance
{
    public const DESCRIPTOR_SCHEMA = 'paid-vasey-license-v2-selection-v2';

    /**
     * The #245 descriptor, which carried the template and the profile inline (local and testing only, never activated).
     * Still recognized as a v2 selection, so it never routes to the paid renderers, but it no longer renders.
     */
    public const RETIRED_DESCRIPTOR_SCHEMA = 'paid-vasey-license-v2-selection-v1';

    /** Bounds the template frozen into each origin (origins are capped at 4 MiB for up to ten lines). */
    public const TEMPLATE_BYTES = 262144;

    /** The two frozen render-input families and their grant purposes. */
    private const FAMILIES = ['paid-grant-render-input-v1' => 'paid-license-grant', 'paid-tax-render-input-v2' => 'paid-tax-license-grant'];

    /** The gate: a code literal, admitted only as an exact `true` in local or testing. */
    public static function enabled(): bool
    {
        return app()->environment('local', 'testing') && config('contracts.vasey_license_v2_issuance') === true;
    }

    /** True for an origin profile this dispatch selected. */
    public static function selected(array $profile): bool
    {
        return in_array($profile['schema_version'] ?? null, [self::DESCRIPTOR_SCHEMA, self::RETIRED_DESCRIPTOR_SCHEMA], true);
    }

    /**
     * The profile to freeze into a new origin, or null to keep the family's existing profile, with the branch taken:
     * `selected`, `gate_off`, `name_only_declaration`, `identity_held`, `no_contract_source`, `template_too_large`,
     * `signature_unavailable`, `not_renderable` or `contract_text_unavailable`.
     *
     * Selection never refuses: it runs inside the issuer's locked origin transaction after the payment is recorded, so any
     * unexpected failure (a throwing contract source, a runtime error) keeps the existing profile instead of refusing the
     * paid order. A selected line's template and renderer profile are then stored by hash in that same transaction
     * ({@see VaseyLicenseContractTexts}); a stored text that differs keeps the existing profile, but a database error is
     * not caught, as for the origin insert that follows it. The issuers record the returned branch code in their
     * `paid_origin.retained` audit context.
     *
     * @param  array  $input  The line's frozen render input (`PaidGrantRenderInput` or `PaidTaxRenderInput`).
     * @return array{0: array|null, 1: string}
     */
    public static function decide(array $input): array
    {
        try {
            [$profile, $branch, $texts] = self::select($input);
        } catch (Throwable) {
            return [null, 'not_renderable'];
        }
        // Outside the catch: a database error here must abort the origin transaction, never be swallowed.
        foreach ($texts as $kind => $bytes) {
            if (! VaseyLicenseContractTexts::retain($kind, $bytes)) {
                return [null, 'contract_text_unavailable'];
            }
        }

        return [$profile, $branch];
    }

    /** @return array{0: array|null, 1: string, 2: array<string, string>} the profile, the branch and the texts to store by hash */
    private static function select(array $input): array
    {
        if (! self::enabled()) {
            return [null, 'gate_off', []];
        }
        $declaration = $input['buyer']['declarations'] ?? null;
        if (! is_array($declaration) || ! ContractIdentityDeclarationV2::isV2($declaration)) {
            return [null, 'name_only_declaration', []];
        }
        if (ProductionCheckout::contractIdentityHeld($declaration)) {
            return [null, 'identity_held', []];
        }
        try {
            $contract = app()->bound(VaseyLicenseContractSource::class)
                ? app(VaseyLicenseContractSource::class)->contract($input['source']['license'] ?? []) : null;
        } catch (Throwable) {
            $contract = null;
        }
        if (! is_array($contract) || ! is_string($contract['template'] ?? null) || ! ($contract['constants'] ?? null) instanceof ContractLicenseConstants) {
            return [null, 'no_contract_source', []];
        }
        if (strlen($contract['template']) > self::TEMPLATE_BYTES) {
            return [null, 'template_too_large', []];
        }
        try {
            $signature = VaseyLicenseSignature::fromConfig();
            $signature->read();
        } catch (Throwable) {
            return [null, 'signature_unavailable', []];
        }
        $constants = $contract['constants'];
        $descriptor = ['schema_version' => self::DESCRIPTOR_SCHEMA, 'version' => VaseyLicenseV2Profile::VERSION,
            'purpose' => $input['purpose'] ?? null, 'provenance' => $input['provenance'] ?? null,
            'contract' => ['template_sha256' => hash('sha256', $contract['template']), 'producer_alias' => $constants->producerAlias, 'state_province_country' => $constants->stateProvinceCountry],
            'signature_sha256' => $signature->sha256];
        try {
            self::family($input);
            $profile = VaseyLicenseV2Profile::current();
            $profileSha256 = VaseyLicenseV2Profile::hash($profile);
            $document = self::document(new VaseyLicenseV2PdfRenderer, $input, $descriptor, $contract['template']);
        } catch (Throwable) {
            return [null, 'not_renderable', []];
        }

        return [$descriptor + ['renderer_profile_sha256' => $profileSha256,
            'effective_hash' => CanonicalJson::hash(['profile_sha256' => $profileSha256, 'signature_sha256' => $signature->sha256]),
            'expected' => ['input_hash' => $document->inputHash, 'text_digest' => hash('sha256', $document->text)]], 'selected',
            ['template' => $contract['template'], 'renderer_profile' => CanonicalJson::encode($profile)]];
    }

    /** The input hash and text digest frozen at selection; an issued original is checked against these, never re-rendered. */
    public static function expected(array $profile): array
    {
        self::shape($profile);

        return $profile['expected'];
    }

    /** The profile hash a stored original's artifact records: the effective (profile + signature) hash for v2. */
    public static function artifactProfileHash(array $profile): string
    {
        if (! self::selected($profile)) {
            return CanonicalJson::hash($profile);
        }
        self::shape($profile);

        return $profile['effective_hash'];
    }

    /**
     * Render one line under the profile recorded at its origin. Refuses (a {@see ContractIssuanceException}) when the
     * pinned profile, the signature or the bound document differ from what was frozen; nothing falls back.
     */
    public function render(array $input, array $profile): RenderedContract
    {
        ContractIo::outsideTransactions();
        // Defence in depth: a recorded v2 selection renders only where the gate could have admitted it.
        if (! app()->environment('local', 'testing')) {
            throw new ContractIssuanceException('profile_changed');
        }
        self::shape($profile);
        // Resolved by hash and verified before the signature or anything else is read.
        $template = VaseyLicenseContractTexts::resolve('template', $profile['contract']['template_sha256']);
        $rendererProfile = self::rendererProfile($profile['renderer_profile_sha256']);
        try {
            self::family($input);
            if (($input['purpose'] ?? null) !== $profile['purpose'] || ($input['provenance'] ?? null) !== $profile['provenance']) {
                throw new ContractIssuanceException('profile_changed');
            }
            $signature = VaseyLicenseSignature::fromConfig();
            if (! hash_equals($profile['signature_sha256'], $signature->sha256)) {
                throw new ContractIssuanceException('signature_changed');
            }
            $renderer = new VaseyLicenseV2PdfRenderer;
            $document = self::document($renderer, $input, $profile, $template);
            if (! hash_equals($profile['expected']['input_hash'], $document->inputHash)) {
                throw new ContractIssuanceException('profile_changed');
            }
            $rendered = $renderer->render($document, $rendererProfile, $signature);
        } catch (ContractBindingException) {
            throw new ContractIssuanceException('unsupported_input');
        }
        if (! hash_equals($profile['effective_hash'], $rendered->profileHash) || ! hash_equals($profile['expected']['text_digest'], $rendered->textDigest)) {
            throw new ContractIssuanceException('profile_changed');
        }

        return $rendered;
    }

    /** The renderer profile stored under the recorded hash: canonical JSON whose hash is that hash. */
    private static function rendererProfile(string $sha256): array
    {
        $bytes = VaseyLicenseContractTexts::resolve('renderer_profile', $sha256);
        try {
            $profile = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
            $canonical = is_array($profile) && CanonicalJson::encode($profile) === $bytes;
        } catch (Throwable) {
            $canonical = false;
        }
        if (! $canonical) {
            throw new ContractIssuanceException('profile_changed');
        }

        return $profile;
    }

    private static function document(VaseyLicenseV2PdfRenderer $renderer, array $input, array $descriptor, string $template): VaseyLicenseDocument
    {
        $contract = $descriptor['contract'];
        if (! hash_equals($contract['template_sha256'], hash('sha256', $template))) {
            throw new ContractIssuanceException('profile_changed');
        }
        $values = ContractVariableBinder::bind($input, $input['source']['license']['structured_terms'] ?? [],
            new ContractLicenseConstants($contract['producer_alias'], $contract['state_province_country']), $renderer->coverage());

        return $renderer->document($template, $values, $input['buyer']['declarations']);
    }

    private static function family(array $input): void
    {
        if ((self::FAMILIES[$input['schema_version'] ?? null] ?? null) !== ($input['purpose'] ?? false)) {
            throw new ContractIssuanceException('unsupported_input');
        }
    }

    private static function shape(array $profile): void
    {
        try {
            $valid = $profile['schema_version'] === self::DESCRIPTOR_SCHEMA && $profile['version'] === VaseyLicenseV2Profile::VERSION
                && ! array_key_exists('template', $profile['contract']) && ! array_key_exists('renderer_profile', $profile)
                && is_string($profile['contract']['producer_alias']) && is_string($profile['contract']['state_province_country'])
                && preg_match('/\A[a-f0-9]{64}\z/D', (string) $profile['contract']['template_sha256']) === 1
                && preg_match('/\A[a-f0-9]{64}\z/D', (string) $profile['signature_sha256']) === 1
                && preg_match('/\A[a-f0-9]{64}\z/D', (string) $profile['renderer_profile_sha256']) === 1
                && hash_equals($profile['effective_hash'], CanonicalJson::hash(['profile_sha256' => $profile['renderer_profile_sha256'],
                    'signature_sha256' => $profile['signature_sha256']]))
                && preg_match('/\A[a-f0-9]{64}\z/D', (string) $profile['expected']['input_hash']) === 1
                && preg_match('/\A[a-f0-9]{64}\z/D', (string) $profile['expected']['text_digest']) === 1;
        } catch (Throwable) {
            $valid = false;
        }
        if (! $valid) {
            throw new ContractIssuanceException('profile_changed');
        }
    }
}
