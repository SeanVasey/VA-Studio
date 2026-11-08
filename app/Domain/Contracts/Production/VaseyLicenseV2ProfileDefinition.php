<?php

namespace App\Domain\Contracts\Production;

use App\Domain\Contracts\ContractRenderProfileRegistry;

/**
 * The reviewed definition of `vasey-license-pdf-v2`, the successor of `vasey-license-pdf-v1` decided on 2026-11-08
 * (decision register): the same three brand families and layout metadata, with faces that add Latin Extended, so
 * names such as "Łukasz" and "Ștefan" print. v1 stays byte-identical and pinned; nothing here changes it.
 *
 * Kept apart from {@see VaseyLicenseV2Profile}, which holds the manifest's SHA-256 and so cannot be pinned by that
 * manifest; this class is in the manifest's implementation pin, so a change to the metadata or faces refuses until the
 * manifest is rebuilt. The signature pin is the one Sean's signature already has.
 */
final class VaseyLicenseV2ProfileDefinition
{
    public const VERSION = 'vasey-license-pdf-v2';

    public const SUPERSEDES = 'vasey-license-pdf-v1';

    public const ASSETS_DIRECTORY = 'resources/contracts/vasey-license-v2';

    /** Face role to the tc-lib metric file the profile sets it from. */
    public const FACES = [
        'heading' => 'fonts/generated/bebasneue.json',
        'body' => 'fonts/generated/redditsans.json',
        'body_bold' => 'fonts/generated/redditsansb.json',
        'identifier' => 'fonts/generated/jetbrainsmono.json',
    ];

    public const LOGO = 'images/vasey-audio-logo.png';

    public const METADATA = ['schema_version' => 1, 'version' => self::VERSION, 'supersedes' => self::SUPERSEDES, 'test_only' => false,
        'issuance' => 'not_selected', 'purpose' => 'production-buyer-license', 'renderer' => 'tecnickcom/tc-lib-pdf',
        'template' => 'vasey-license-text-v1', 'php_runtime' => '8.4', 'timezone' => 'UTC', 'pdf_conformance' => 'plain-pdf',
        'page' => ['format' => 'A4', 'orientation' => 'P', 'margin_mm' => ['left' => 18, 'right' => 18, 'top' => 18, 'bottom' => 22]],
        'faces' => ['heading' => 'bebasneue', 'body' => 'redditsans', 'body_bold' => 'redditsansb', 'identifier' => 'jetbrainsmono'],
        'font_repertoire' => ['latin', 'latin-ext'],
        'sizes_pt' => ['title' => 24, 'heading' => 15, 'body' => 10, 'label' => 9, 'identifier' => 8],
        'colors' => ['band' => '#29363f', 'accent' => '#00b8d9', 'text' => '#1b2329', 'muted' => '#56656f'],
        'font_subset' => false, 'compression' => false, 'allowed_scripts' => ['Latin', 'Common'], 'combining_marks' => false,
        'remote_resources' => false, 'markup_resources' => false,
        'signature' => ['source' => 'private_storage', 'media_type' => 'image/png', 'pin' => 'config:contracts.vasey_license_v1.signature_sha256'],
        'limits' => ContractRenderProfileRegistry::V1_LIMITS];
}
