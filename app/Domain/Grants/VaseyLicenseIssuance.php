<?php

namespace App\Domain\Grants;

use App\Domain\Commerce\ContractIdentity\ContractGlyphCoverage;
use App\Domain\Commerce\ContractIdentity\ContractIdentityDeclarationV2;
use App\Domain\Commerce\ContractIdentity\ContractPrintableSet;
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
use App\Domain\Contracts\Production\VaseyLicenseV2Render;
use App\Domain\Contracts\Production\VaseyLicenseV2RendererProcess;
use App\Domain\Contracts\RenderedContract;
use App\Domain\Contracts\RenderFit\LicensePdfCheck;
use App\Domain\Contracts\RenderFit\RenderFitException;
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
 * 5. the line binds and its document builds under the v2 input rule now, its render request fits the isolated child's
 *    input bound, and the configured signature reads and matches its pin.
 *
 * Anything else, including any unexpected failure while selecting, keeps the family's existing profile (its prior behaviour
 * and bytes), so selection never refuses a paid line.
 *
 * File reads happen before the issuer's locked origin transaction ({@see prepare()}: the manifest, the four metric files and
 * the signature); inside it, {@see decide()} only re-checks the prepared values against what it can see without file I/O
 * (the gate, the configured signature pin, the prepared profile's own hash). A pinned file or signature that changes after
 * preparing is found by the render, which re-verifies everything.
 *
 * Selection builds the document but does not render it: a refusal only rendering finds (drawability, page or byte limits,
 * `invalid_pdf`/`render_failed`, or a signature rotation or pinned-file change before the render) fails the line's attempt
 * after payment. The issuers then record one staff hold for the order ({@see ContractRenderHolds}); the runbook forbids a
 * signature rotation or pinned v2 file change while any v2 origin is unrendered. Every render runs in the isolated child
 * ({@see VaseyLicenseV2RendererProcess}). Once an origin exists its recorded profile decides every render, retry and replay,
 * whatever the gate says later; nothing here ever re-renders a completed original.
 *
 * Payload growth: the recorded profile carries only the SHA-256 of the template and of the renderer profile. Their bytes
 * are stored once each in {@see VaseyLicenseContractTexts} when a line is selected (inside the origin transaction: a
 * database write, never a file read), and every render resolves them by hash, verifies them and passes them to the child,
 * which verifies them again. A stored text that is missing or does not decrypt (or a database error reading it) refuses
 * `contract_text_unavailable`, a refusal a retry may fix (held for staff only once the line's attempts are spent); a stored
 * row that decrypts but does not match its hash refuses `profile_changed`, which is permanent and held at once.
 */
final class VaseyLicenseIssuance
{
    public const DESCRIPTOR_SCHEMA = VaseyLicenseV2Render::DESCRIPTOR_SCHEMA;

    /**
     * The #245 descriptor, which carried the template and the profile inline (local and testing only, never activated).
     * Still recognized as a v2 selection, so it never routes to the paid renderers, and its frozen digests still verify a
     * completed original ({@see expected()}, {@see artifactProfileHash()}), but it no longer renders.
     */
    public const RETIRED_DESCRIPTOR_SCHEMA = 'paid-vasey-license-v2-selection-v1';

    /** Bounds the template frozen into each origin (origins are capped at 4 MiB for up to ten lines). */
    public const TEMPLATE_BYTES = 262144;

    /** The gate: a code literal, admitted only as an exact `true` in local or testing. */
    public static function enabled(): bool
    {
        return app()->environment('local', 'testing') && config('contracts.vasey_license_v2_issuance') === true;
    }

    /** True for an origin profile this dispatch selected. */
    public static function selected(array $profile): bool
    {
        return VaseyLicenseV2Render::selected($profile) || ($profile['schema_version'] ?? null) === self::RETIRED_DESCRIPTOR_SCHEMA;
    }

    /**
     * The file reads a selection needs, done before the issuer's locked origin transaction: null while the gate is off
     * (nothing is read). Never throws; a read that fails leaves its field null. Inside any open transaction it reads nothing
     * and returns an empty preparation, so a selection made from it keeps the existing profile.
     *
     * @param  string|null  $projectRoot  The project root whose profile and metric files are read (tests use a copy).
     */
    public static function prepare(?string $projectRoot = null): ?VaseyLicensePreparedSelection
    {
        if (! self::enabled()) {
            return null;
        }
        try {
            ContractIo::outsideTransactions();
        } catch (Throwable) {
            return new VaseyLicensePreparedSelection;
        }
        try {
            // Warm checkout's printable set (loaded once per process), so the in-frame identity check reads no file.
            ContractPrintableSet::checkout();
            $profile = VaseyLicenseV2Profile::current($projectRoot);
            $profileSha256 = VaseyLicenseV2Profile::hash($profile, $projectRoot);
            $coverage = (new VaseyLicenseV2PdfRenderer($projectRoot))->coverage();
        } catch (Throwable) {
            [$profile, $profileSha256, $coverage] = [null, null, null];
        }
        try {
            $signature = VaseyLicenseSignature::fromConfig();
            $signature->read();
            $signatureSha256 = $signature->sha256;
        } catch (Throwable) {
            $signatureSha256 = null;
        }

        return new VaseyLicensePreparedSelection($profile, $profileSha256, $coverage, $signatureSha256);
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
     * @param  VaseyLicensePreparedSelection|null  $prepared  From {@see prepare()} before the transaction. Without it the
     *                                                        selection prepares itself, which reads nothing inside a transaction.
     * @return array{0: array|null, 1: string}
     */
    public static function decide(array $input, ?VaseyLicensePreparedSelection $prepared = null): array
    {
        try {
            [$profile, $branch, $texts] = self::select($input, $prepared);
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
    private static function select(array $input, ?VaseyLicensePreparedSelection $prepared): array
    {
        if (! self::enabled()) {
            return [null, 'gate_off', []];
        }
        $declaration = $input['buyer']['declarations'] ?? null;
        if (! is_array($declaration) || ! ContractIdentityDeclarationV2::isV2($declaration)) {
            return [null, 'name_only_declaration', []];
        }
        $prepared ??= self::prepare();
        if (! $prepared instanceof VaseyLicensePreparedSelection || $prepared->coverage === null) {
            return [null, 'not_renderable', []];
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
        // The prepared signature must still be the configured pin (configuration is in memory; no file is read here).
        $pin = config('contracts.vasey_license_v1.signature_sha256');
        if ($prepared->signatureSha256 === null || ! is_string($pin) || ! hash_equals($prepared->signatureSha256, $pin)) {
            return [null, 'signature_unavailable', []];
        }
        $constants = $contract['constants'];
        $descriptor = ['schema_version' => self::DESCRIPTOR_SCHEMA, 'version' => VaseyLicenseV2Profile::VERSION,
            'purpose' => $input['purpose'] ?? null, 'provenance' => $input['provenance'] ?? null,
            'contract' => ['template_sha256' => hash('sha256', $contract['template']),
                'producer_alias' => $constants->producerAlias, 'state_province_country' => $constants->stateProvinceCountry],
            'signature_sha256' => $prepared->signatureSha256];
        try {
            VaseyLicenseV2Render::family($input);
            if ($prepared->profile === null || $prepared->profileSha256 === null
                || ! hash_equals($prepared->profileSha256, CanonicalJson::hash($prepared->profile))) {
                throw new ContractIssuanceException('profile_changed');
            }
            [$values, $document] = self::document($input, $descriptor, $prepared->coverage, $contract['template']);
            $texts = ['template' => $contract['template'], 'renderer_profile' => CanonicalJson::encode($prepared->profile)];
            $descriptor += ['renderer_profile_sha256' => $prepared->profileSha256,
                'effective_hash' => CanonicalJson::hash(['profile_sha256' => $prepared->profileSha256, 'signature_sha256' => $prepared->signatureSha256]),
                'expected' => ['input_hash' => $document->inputHash, 'text_digest' => hash('sha256', $document->text)]];
            // A line whose render request (with the resolved texts) would exceed the isolated child's input bound is never selected.
            if (strlen(VaseyLicenseV2RendererProcess::payload($input, $descriptor, $values, $texts['template'], $texts['renderer_profile']))
                > VaseyLicenseV2RendererProcess::MAX_INPUT) {
                throw new ContractIssuanceException('unsupported_input');
            }
        } catch (Throwable) {
            return [null, 'not_renderable', []];
        }

        return [$descriptor, 'selected', $texts];
    }

    /** The input hash and text digest frozen at selection; an issued original is checked against these, never re-rendered. */
    public static function expected(array $profile): array
    {
        self::shape($profile, true);

        return $profile['expected'];
    }

    /** The profile hash a stored original's artifact records: the effective (profile + signature) hash for v2. */
    public static function artifactProfileHash(array $profile): string
    {
        if (! self::selected($profile)) {
            return CanonicalJson::hash($profile);
        }
        self::shape($profile, true);

        return $profile['effective_hash'];
    }

    /**
     * Render one line under the profile recorded at its origin, in the isolated child. Refuses (a
     * {@see ContractIssuanceException}) when the pinned profile, the signature or the bound document differ from what was
     * frozen; nothing falls back. The checks here only refuse early; the child repeats every one of them itself.
     */
    public function render(array $input, array $profile): RenderedContract
    {
        ContractIo::outsideTransactions();
        // Defence in depth: a recorded v2 selection renders only where the gate could have admitted it.
        if (! app()->environment('local', 'testing')) {
            throw new ContractIssuanceException('profile_changed');
        }
        VaseyLicenseV2Render::shape($profile);
        // Resolved by hash and verified before the signature or anything else is read. A stored row that decrypts to bytes
        // other than its address is an integrity failure no retry can fix (`profile_changed`, held at once); a missing or
        // undecryptable row (perhaps a key this worker lacks) or a database error may resolve on a retry
        // (`contract_text_unavailable`, held only once attempts are spent).
        try {
            $template = VaseyLicenseContractTexts::resolve('template', $profile['contract']['template_sha256']);
            $rendererProfile = VaseyLicenseContractTexts::resolve('renderer_profile', $profile['renderer_profile_sha256']);
        } catch (ContractIssuanceException $error) {
            throw $error->reason === 'profile_changed' ? $error : new ContractIssuanceException('contract_text_unavailable');
        } catch (Throwable) {
            throw new ContractIssuanceException('contract_text_unavailable');
        }
        try {
            VaseyLicenseV2Render::family($input);
            if (($input['purpose'] ?? null) !== $profile['purpose'] || ($input['provenance'] ?? null) !== $profile['provenance']) {
                throw new ContractIssuanceException('profile_changed');
            }
            $signature = VaseyLicenseSignature::fromConfig();
            if (! hash_equals($profile['signature_sha256'], $signature->sha256)) {
                throw new ContractIssuanceException('signature_changed');
            }
            [$values, $document] = self::document($input, $profile, (new VaseyLicenseV2PdfRenderer)->coverage(), $template);
            if (! hash_equals($profile['expected']['input_hash'], $document->inputHash)) {
                throw new ContractIssuanceException('profile_changed');
            }
            $signed = $signature->read();
            $rendered = app(VaseyLicenseV2RendererProcess::class)->render($input, $profile, $values, $template, $rendererProfile, $signed['bytes']);
        } catch (ContractBindingException) {
            throw new ContractIssuanceException('unsupported_input');
        }
        if (! hash_equals($profile['effective_hash'], $rendered->profileHash) || ! hash_equals($profile['expected']['text_digest'], $rendered->textDigest)) {
            throw new ContractIssuanceException('profile_changed');
        }
        // BL-72: the finished original must draw every character of the document, inside the page and the right margin.
        // Read from the PDF itself, so it holds whichever way the pinned layout treats an over-long unbroken token.
        try {
            $v2 = VaseyLicenseV2Profile::current();
            (new LicensePdfCheck($v2, static fn (string $asset): string => VaseyLicenseV2Profile::assetBytes($v2, $asset)))
                ->assertContained($rendered->pdfBytes, $document, VaseyLicenseV2Profile::VERSION);
        } catch (RenderFitException) {
            throw new ContractIssuanceException('invalid_pdf');
        }

        return $rendered;
    }

    /**
     * The line's bound values and document under the given coverage (the v2 renderer's input rule). Reads no file: the
     * coverage is passed in, and the document is built directly rather than through the renderer, which would re-read it.
     *
     * @return array{0: array<string, string>, 1: VaseyLicenseDocument}
     */
    private static function document(array $input, array $descriptor, ContractGlyphCoverage $coverage, string $template): array
    {
        $contract = $descriptor['contract'];
        if (! hash_equals($contract['template_sha256'], hash('sha256', $template))) {
            throw new ContractIssuanceException('profile_changed');
        }
        $values = ContractVariableBinder::bind($input, $input['source']['license']['structured_terms'] ?? [],
            new ContractLicenseConstants($contract['producer_alias'], $contract['state_province_country']), $coverage);

        return [$values, VaseyLicenseDocument::build($template, $values, $input['buyer']['declarations'], $coverage)];
    }

    /**
     * The recorded descriptor's shape ({@see VaseyLicenseV2Render::shape()}). Only the frozen-digest readers admit the retired
     * #245 shape (`$retired`), and only with its inline template and profile matching their recorded hashes; render never does.
     */
    private static function shape(array $profile, bool $retired = false): void
    {
        if (! $retired || ($profile['schema_version'] ?? null) !== self::RETIRED_DESCRIPTOR_SCHEMA) {
            VaseyLicenseV2Render::shape($profile);

            return;
        }
        try {
            $valid = $profile['version'] === VaseyLicenseV2Profile::VERSION
                && is_string($profile['contract']['template']) && is_array($profile['renderer_profile'])
                && is_string($profile['contract']['producer_alias']) && is_string($profile['contract']['state_province_country'])
                && preg_match('/\A[a-f0-9]{64}\z/D', (string) $profile['contract']['template_sha256']) === 1
                && hash_equals((string) $profile['contract']['template_sha256'], hash('sha256', $profile['contract']['template']))
                && preg_match('/\A[a-f0-9]{64}\z/D', (string) $profile['signature_sha256']) === 1
                && preg_match('/\A[a-f0-9]{64}\z/D', (string) $profile['renderer_profile_sha256']) === 1
                && hash_equals((string) $profile['renderer_profile_sha256'], CanonicalJson::hash($profile['renderer_profile']))
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
