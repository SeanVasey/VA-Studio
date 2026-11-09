<?php

namespace App\Domain\Contracts\Production;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\RenderedContract;
use App\Support\CanonicalJson;
use Throwable;

/**
 * The application-free core of one `vasey-license-pdf-v2` original render: it checks a frozen selection descriptor and its
 * line, rebuilds the document from the frozen template and the line's bound values under the renderer's own input rule,
 * and renders it. It reads no configuration, container or environment, so the isolated child
 * (`scripts/render-vasey-license-v2.php`, run by {@see VaseyLicenseV2RendererProcess}) runs it without Laravel; the
 * grants' `VaseyLicenseIssuance` dispatch uses the same descriptor checks before it starts that child.
 *
 * Nothing here trusts the caller's claims: the descriptor must be internally consistent (its profile hash is its profile's,
 * its effective hash binds that hash to its signature pin), the renderer re-validates the recorded profile against the
 * manifest and every pinned file ({@see VaseyLicenseV2Profile::verifyRuntime()}), the signature bytes must hash to the
 * recorded pin, the rebuilt document must have the frozen input hash, and the PDF must carry the frozen effective hash and
 * text digest. Any difference refuses; nothing falls back.
 *
 * In the v2 manifest's implementation pin (a root of {@see VaseyLicenseV2Implementation}), with the child script and the
 * process that starts it.
 */
final class VaseyLicenseV2Render
{
    /** The recorded descriptor: hashes only; the template and renderer profile are stored once by hash and passed in. */
    public const DESCRIPTOR_SCHEMA = 'paid-vasey-license-v2-selection-v2';

    /** The two frozen render-input families and their grant purposes. */
    public const FAMILIES = ['paid-grant-render-input-v1' => 'paid-license-grant', 'paid-tax-render-input-v2' => 'paid-tax-license-grant'];

    /** True for an origin profile the v2 dispatch selected. */
    public static function selected(array $profile): bool
    {
        return ($profile['schema_version'] ?? null) === self::DESCRIPTOR_SCHEMA;
    }

    /** The recorded descriptor must be well formed and internally consistent; otherwise `profile_changed`. */
    public static function shape(array $profile): void
    {
        try {
            $valid = self::selected($profile) && $profile['version'] === VaseyLicenseV2ProfileDefinition::VERSION
                && is_array($profile['contract']) && ! array_key_exists('template', $profile['contract']) && ! array_key_exists('renderer_profile', $profile)
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

    /** The line must be one of the two paid render-input families, with its own purpose; otherwise `unsupported_input`. */
    public static function family(array $input): void
    {
        if ((self::FAMILIES[$input['schema_version'] ?? null] ?? null) !== ($input['purpose'] ?? false)) {
            throw new ContractIssuanceException('unsupported_input');
        }
    }

    /**
     * The renderer profile from its stored bytes: they must hash to the recorded hash and be the canonical JSON of what they
     * decode to; otherwise `profile_changed`.
     */
    public static function rendererProfile(array $profile, string $bytes): array
    {
        try {
            $decoded = hash_equals((string) $profile['renderer_profile_sha256'], hash('sha256', $bytes))
                ? json_decode($bytes, true, 32, JSON_THROW_ON_ERROR) : null;
            $valid = is_array($decoded) && CanonicalJson::encode($decoded) === $bytes;
        } catch (Throwable) {
            $valid = false;
        }
        if (! $valid) {
            throw new ContractIssuanceException('profile_changed');
        }

        return $decoded;
    }

    /**
     * Render the recorded selection for its line. The template and the renderer profile's canonical JSON are the stored texts
     * the descriptor addresses by hash; both are checked against those hashes here. `$values` is the line's
     * {@see ContractVariableBinder::bind()} output; it is not trusted either: the document built from it must have the input
     * hash frozen at selection, which binds the template, every value and the declaration.
     *
     * @param  array<string, string>  $values
     */
    public static function render(array $input, array $profile, array $values, VaseyLicenseSignature $signature, string $template,
        string $rendererProfile, ?string $projectRoot = null): RenderedContract
    {
        self::shape($profile);
        $renderer = self::rendererProfile($profile, $rendererProfile);
        self::family($input);
        if (($input['purpose'] ?? null) !== $profile['purpose'] || ($input['provenance'] ?? null) !== $profile['provenance']) {
            throw new ContractIssuanceException('profile_changed');
        }
        if (! hash_equals($profile['signature_sha256'], $signature->sha256)) {
            throw new ContractIssuanceException('signature_changed');
        }
        if (! hash_equals($profile['contract']['template_sha256'], hash('sha256', $template))) {
            throw new ContractIssuanceException('profile_changed');
        }
        $declaration = $input['buyer']['declarations'] ?? null;
        if (! is_array($declaration)) {
            throw new ContractIssuanceException('unsupported_input');
        }
        $pdfRenderer = new VaseyLicenseV2PdfRenderer($projectRoot);
        try {
            $document = $pdfRenderer->document($template, $values, $declaration);
        } catch (ContractBindingException) {
            throw new ContractIssuanceException('unsupported_input');
        }
        if (! hash_equals($profile['expected']['input_hash'], $document->inputHash)) {
            throw new ContractIssuanceException('profile_changed');
        }
        $rendered = $pdfRenderer->render($document, $renderer, $signature);
        if (! hash_equals($profile['effective_hash'], $rendered->profileHash) || ! hash_equals($profile['expected']['text_digest'], $rendered->textDigest)) {
            throw new ContractIssuanceException('profile_changed');
        }

        return $rendered;
    }
}
