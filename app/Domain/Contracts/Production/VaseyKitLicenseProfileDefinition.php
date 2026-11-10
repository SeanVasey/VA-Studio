<?php

namespace App\Domain\Contracts\Production;

use App\Domain\Contracts\ContractRenderProfileRegistry;

/**
 * The reviewed definition of `vasey-kit-license-pdf-v1` (BL-66): the sound-kit license, rendered in the same visual system
 * as the generated license contracts. It is a NEW profile beside `vasey-license-pdf-v1` and `-v2`, which are untouched and
 * stay byte-identical.
 *
 * It prints with the faces, geometry, colors, sizes and limits of `vasey-license-pdf-v2` and the unmodified lockup, written
 * out below so that no v2 class names this profile or is named by it; a test compares every shared key with the v2
 * profile's, so the two cannot drift apart unnoticed. The font and logo files are the v2 profile's own, in
 * {@see ASSET_FILES_DIRECTORY}, pinned again here by hash ({@see VaseyKitLicenseProfile}); only the manifest and its
 * provenance live in the kit directory.
 *
 * Kept apart from {@see VaseyKitLicenseProfile}, which holds the manifest's SHA-256 and so cannot be pinned by it.
 */
final class VaseyKitLicenseProfileDefinition
{
    public const VERSION = 'vasey-kit-license-pdf-v1';

    /** The kit manifest and its provenance. */
    public const ASSETS_DIRECTORY = 'resources/contracts/vasey-kit-license-v1';

    /** The directory holding the pinned font and logo files: the v2 profile's, shared by hash rather than copied. */
    public const ASSET_FILES_DIRECTORY = 'resources/contracts/vasey-license-v2';

    /** Face role to the tc-lib metric file the profile sets it from. */
    public const FACES = [
        'heading' => 'fonts/generated/bebasneue.json',
        'body' => 'fonts/generated/redditsans.json',
        'body_bold' => 'fonts/generated/redditsansb.json',
        'identifier' => 'fonts/generated/jetbrainsmono.json',
    ];

    public const LOGO = 'images/vasey-audio-logo.png';

    public const METADATA = ['schema_version' => 1, 'version' => self::VERSION, 'test_only' => false,
        'issuance' => 'not_selected', 'purpose' => 'sound-kit-license', 'renderer' => 'tecnickcom/tc-lib-pdf',
        'template' => 'vasey-kit-license-text-v1', 'layout' => 'vasey-license-text-v1', 'php_runtime' => '8.4', 'timezone' => 'UTC',
        'pdf_conformance' => 'plain-pdf',
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
