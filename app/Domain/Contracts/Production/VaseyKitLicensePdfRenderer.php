<?php

namespace App\Domain\Contracts\Production;

use App\Domain\Commerce\ContractIdentity\ContractGlyphCoverage;
use App\Domain\Commerce\ContractIdentity\ContractPrintableSet;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\RenderedContract;
use App\Support\CanonicalJson;
use Closure;
use Com\Tecnick\Color\Pdf;
use Com\Tecnick\Pdf\Encrypt\Encrypt;
use Com\Tecnick\Pdf\Page\Page;
use Com\Tecnick\Pdf\Tcpdf;
use InvalidArgumentException;
use JsonException;
use Throwable;
use WeakMap;

/**
 * The `vasey-kit-license-pdf-v1` renderer (BL-66): the sound-kit license in the same visual system as the generated license
 * contracts. It draws with the existing page layout ({@see VaseyLicenseLayout}: header band with the unmodified lockup,
 * title, headings, justified paragraphs, the PRODUCER / LICENSOR and LICENSEE acceptance block with Sean's signature, and the
 * document identifier footer) and signature ({@see VaseyLicenseSignature}, the private-path and SHA-256 pin of
 * `contracts.vasey_license_v1`), so the kit license looks like the others. Only the profile binding and the document are new;
 * `vasey-license-pdf-v1` and `-v2` and their renderers are untouched and stay byte-identical.
 *
 * Input rule: {@see coverage()}, the v2 faces restricted to Latin and Common, which is the set checkout accepts.
 * {@see document()} builds under it, and {@see render()} accepts only a document that built, as the v2 renderer does.
 *
 * Deterministic exactly as v2: the purchase date (UTC midnight) is every document date, the file and document IDs derive from
 * the input, profile and signature hashes, fonts are embedded whole and uncompressed, and nothing reads the clock.
 *
 * Not selected by any issuer, dispatch or policy: there is no kit checkout yet, and the profile's metadata says `not_selected`.
 */
final class VaseyKitLicensePdfRenderer
{
    /** Band, logo and signature box as v1; page geometry is v1's own ({@see VaseyLicensePdfRenderer::geometry()}). */
    private const BAND_HEIGHT = 34.0;

    /** The lockup's own 420 × 100 aspect ratio, drawn at a fixed height. */
    private const LOGO_HEIGHT = 15.0;

    private const SIGNATURE_BOX = ['width' => 60.0, 'height' => 20.0];

    /** @var WeakMap<SoundKitLicenseDocument, true>|null Documents built by {@see document()} in this process. */
    private static ?WeakMap $built = null;

    /**
     * @param  Closure|null  $afterRuntimeVerified  Test-only: runs right after the profile's runtime verification, so a test
     *                                              can change a pinned file between verification and use. Refused outside `testing`.
     */
    public function __construct(private readonly ?string $projectRoot = null, private readonly ?Closure $afterRuntimeVerified = null)
    {
        if ($afterRuntimeVerified !== null && (! function_exists('app') || ! app()->environment('testing'))) {
            throw new ContractIssuanceException('unsupported_input');
        }
    }

    /**
     * The renderer's own input rule: the v2 faces, verified against the manifest pin and each metric file's SHA-256,
     * intersected and restricted to the profile's `allowed_scripts`.
     */
    public function coverage(): ContractGlyphCoverage
    {
        try {
            return ContractPrintableSet::faces($this->projectRoot);
        } catch (InvalidArgumentException|JsonException) {
            // Metric files that differ from the manifest, or cannot be decoded, are a changed profile, not bad input.
            throw new ContractIssuanceException('profile_changed');
        }
    }

    /**
     * The `vasey-kit-license-text-v1` document under this renderer's input rule. Refusals are those of
     * {@see SoundKitLicenseDocument::build}; a value outside {@see coverage()} refuses `unsupported_characters`.
     *
     * @param  array<string, string>  $values  {@see SoundKitLicenseBinder::bind} output for the purchase.
     */
    public function document(string $template, array $values): SoundKitLicenseDocument
    {
        $document = SoundKitLicenseDocument::build($template, $values, $this->coverage());
        self::$built ??= new WeakMap;
        self::$built[$document] = true;

        return $document;
    }

    /** Renders only a document {@see document()} built under this renderer's input rule; any other refuses `unsupported_input`. */
    public function render(SoundKitLicenseDocument $document, array $profile, VaseyLicenseSignature $signature): RenderedContract
    {
        if (! isset(self::$built[$document])) {
            throw new ContractIssuanceException('unsupported_input');
        }
        if (($profile['version'] ?? null) !== VaseyKitLicenseProfile::VERSION) {
            throw new ContractIssuanceException('profile_changed');
        }
        VaseyKitLicenseProfile::verifyRuntime($profile, $this->projectRoot);
        if ($this->afterRuntimeVerified !== null) {
            ($this->afterRuntimeVerified)();
        }
        $logo = VaseyKitLicenseProfile::assetBytes($profile, VaseyKitLicenseProfile::LOGO, $this->projectRoot);
        $signed = $signature->read();
        $profileHash = VaseyKitLicenseProfile::hash($profile, $this->projectRoot);
        // The recorded profile identity binds the signature pin, so a different signature is a different profile.
        $effectiveHash = CanonicalJson::hash(['profile_sha256' => $profileHash, 'signature_sha256' => $signature->sha256]);
        $identity = hash('sha256', $document->inputHash.':'.$effectiveHash);
        $fonts = sys_get_temp_dir().'/vasey-kit-license-fonts-'.bin2hex(random_bytes(16));
        $geometry = VaseyLicensePdfRenderer::geometry();

        $timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $this->verifiedFonts($profile, $fonts);
            $pdf = new Tcpdf(unit: 'mm', isunicode: true, subsetfont: $profile['font_subset'], compress: $profile['compression'], mode: '', fileOptions: [
                'allowedHosts' => [], 'allowedPaths' => [$fonts], 'markupAllowedPaths' => [],
            ]);
            // Auto-created pages discard their predecessor's time. Freeze the page sanitizer too.
            $pdf->page = new class($document->timestamp, $pdf->color, $pdf->encrypt, $profile['compression']) extends Page
            {
                public function __construct(private readonly int $timestamp, Pdf $color, Encrypt $encrypt, bool $compress)
                {
                    parent::__construct('mm', $color, $encrypt, notransparency: false, compress: $compress, sigapp: false);
                }

                public function sanitizeTime(array &$data): void
                {
                    $data['time'] = $this->timestamp;
                }
            };
            $pdf->enableDefaultPageContent(false);
            $pdf->setDefaultCellPadding(0, 0, 0, 0);
            $pdf->setDefaultCellMargin(0, 0, 0, 0);
            $pdf->setCreator('VASEY.AUDIO sound-kit license profile '.VaseyKitLicenseProfile::VERSION);
            $pdf->setAuthor('VASEY.AUDIO');
            $pdf->setTitle($document->title);
            $pdf->setSubject('Sound kit license agreement');
            $pdf->setKeywords(VaseyKitLicenseProfile::VERSION.','.$effectiveHash);
            $pdf->setDocCreationDate($document->timestamp);
            $pdf->setDocModificationDate($document->timestamp);
            $pdf->setFileId(substr($identity, 0, 32));
            $pdf->setDocumentId('urn:sha256:'.$identity);

            $layout = new VaseyLicenseLayout($pdf, $profile, $fonts);
            $acceptance = $document->acceptance;
            $identifier = VaseyKitLicenseProfile::VERSION.'  urn:sha256:'.$identity;
            $layout->assertDrawable('heading', [...array_column(array_filter($document->blocks, static fn (array $block): bool => $block['type'] !== 'paragraph'), 'text'),
                ...VaseyLicenseLayout::LABELS]);
            $layout->assertDrawable('body', [...array_column(array_filter($document->blocks, static fn (array $block): bool => $block['type'] === 'paragraph'), 'text'),
                'Date: '.$acceptance['date']]);
            $layout->assertDrawable('body_bold', [$acceptance['licensor'], $acceptance['licensee']]);
            $layout->assertDrawable('identifier', [$identifier]);

            $page = $pdf->addPage($geometry['page']);
            $layout->band($logo, self::BAND_HEIGHT, self::LOGO_HEIGHT, (float) $page['height']);
            $layout->setY(self::BAND_HEIGHT + 10);
            foreach ($document->blocks as $block) {
                $layout->block($block['type'], $block['text']);
            }
            $layout->acceptance($acceptance, $signed, self::SIGNATURE_BOX);
            $layout->identifier($identifier);

            $bytes = $pdf->getOutPDFString();
            $pages = count($pdf->page->getPages());
            if ($pages < 1 || $pages > $profile['limits']['pages'] || strlen($bytes) > $profile['limits']['output_bytes']
                || ! str_starts_with($bytes, '%PDF-') || ! str_ends_with(rtrim($bytes), '%%EOF') || $pdf->getWarnings() !== []) {
                throw new ContractIssuanceException('invalid_pdf');
            }

            return new RenderedContract($bytes, hash('sha256', $bytes), strlen($bytes), $pages, hash('sha256', $document->text), $effectiveHash);
        } catch (ContractIssuanceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ContractIssuanceException('render_failed');
        } finally {
            date_default_timezone_set($timezone);
            if (is_dir($fonts) && ! is_link($fonts)) {
                foreach (glob($fonts.'/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($fonts);
            }
        }
    }

    /**
     * tc-lib reads font files itself, so it is given a private copy written from the bytes that were hashed against
     * the manifest, never the asset directory, which could change between verification and use.
     */
    private function verifiedFonts(array $profile, string $directory): void
    {
        if (! mkdir($directory, 0700)) {
            throw new ContractIssuanceException('render_failed');
        }
        foreach (array_keys($profile['assets']['assets']) as $asset) {
            if (str_starts_with($asset, 'fonts/generated/')) {
                $bytes = VaseyKitLicenseProfile::assetBytes($profile, $asset, $this->projectRoot);
                if (file_put_contents($directory.'/'.basename($asset), $bytes, LOCK_EX) !== strlen($bytes)) {
                    throw new ContractIssuanceException('render_failed');
                }
            }
        }
    }
}
