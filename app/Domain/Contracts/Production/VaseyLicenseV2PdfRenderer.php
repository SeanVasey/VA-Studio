<?php

namespace App\Domain\Contracts\Production;

use App\Domain\Commerce\ContractIdentity\ContractGlyphCoverage;
use App\Domain\Commerce\ContractIdentity\ContractPrintableSet;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\RenderedContract;
use App\Support\CanonicalJson;
use Com\Tecnick\Color\Pdf;
use Com\Tecnick\Pdf\Encrypt\Encrypt;
use Com\Tecnick\Pdf\Page\Page;
use Com\Tecnick\Pdf\Tcpdf;
use Throwable;

/**
 * Contract increment 5, part 2: the `vasey-license-pdf-v2` renderer, successor of {@see VaseyLicensePdfRenderer}, which
 * stays byte-identical because v1's manifest pins it to `VaseyLicenseProfile`. Only the profile binding is new. The
 * document ({@see VaseyLicenseDocument}), page layout ({@see VaseyLicenseLayout}, which takes the profile and font
 * directory as parameters) and signature ({@see VaseyLicenseSignature}, with the `contracts.vasey_license_v1` pin, as
 * decided) are v1's own classes, so the two profiles print the same template the same way and differ only in faces.
 *
 * Input rule: {@see coverage()}, the four v2 faces intersected and restricted to the profile's `allowed_scripts`; that is
 * the set checkout accepts ({@see ContractPrintableSet}). {@see document()} builds under it, and the layout re-checks every
 * character against the face that sets it before anything is drawn.
 *
 * Deterministic exactly as v1: the contract date (UTC midnight) is every document date, the file and document IDs derive
 * from the input, profile and signature hashes, fonts are embedded whole and uncompressed, and nothing reads the clock.
 *
 * Built, not activated: no issuer, dispatch or policy selects this renderer; wiring it needs separate review.
 */
final class VaseyLicenseV2PdfRenderer
{
    /** Band, logo and signature box as v1; page geometry is v1's own ({@see VaseyLicensePdfRenderer::geometry()}). */
    private const BAND_HEIGHT = 34.0;

    /** The lockup's own 420 × 100 aspect ratio, drawn at a fixed height. */
    private const LOGO_HEIGHT = 15.0;

    private const SIGNATURE_BOX = ['width' => 60.0, 'height' => 20.0];

    public function __construct(private readonly ?string $projectRoot = null) {}

    /**
     * The renderer's own input rule: the v2 faces, verified against the manifest pin and each metric file's SHA-256,
     * intersected and restricted to the profile's `allowed_scripts`.
     */
    public function coverage(): ContractGlyphCoverage
    {
        return ContractPrintableSet::faces($this->projectRoot);
    }

    /**
     * The `vasey-license-text-v1` document under this renderer's input rule. Refusals are those of
     * {@see VaseyLicenseDocument::build}; a value outside {@see coverage()} refuses `unsupported_characters`.
     *
     * @param  array<string, string>  $values  {@see ContractVariableBinder::bind} output for the line.
     */
    public function document(string $template, array $values, array $declaration): VaseyLicenseDocument
    {
        return VaseyLicenseDocument::build($template, $values, $declaration, $this->coverage());
    }

    public function render(VaseyLicenseDocument $document, array $profile, VaseyLicenseSignature $signature): RenderedContract
    {
        if (($profile['version'] ?? null) !== VaseyLicenseV2Profile::VERSION) {
            throw new ContractIssuanceException('profile_changed');
        }
        VaseyLicenseV2Profile::verifyRuntime($profile, $this->projectRoot);
        $logo = VaseyLicenseV2Profile::assetBytes($profile, VaseyLicenseV2Profile::LOGO, $this->projectRoot);
        $signed = $signature->read();
        $profileHash = VaseyLicenseV2Profile::hash($profile, $this->projectRoot);
        // The recorded profile identity binds the signature pin, so a different signature is a different profile.
        $effectiveHash = CanonicalJson::hash(['profile_sha256' => $profileHash, 'signature_sha256' => $signature->sha256]);
        $identity = hash('sha256', $document->inputHash.':'.$effectiveHash);
        $fonts = sys_get_temp_dir().'/vasey-license-v2-fonts-'.bin2hex(random_bytes(16));
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
            $pdf->setCreator('VASEY.AUDIO license profile '.VaseyLicenseV2Profile::VERSION);
            $pdf->setAuthor('VASEY.AUDIO');
            $pdf->setTitle($document->title);
            $pdf->setSubject('License agreement');
            $pdf->setKeywords(VaseyLicenseV2Profile::VERSION.','.$effectiveHash);
            $pdf->setDocCreationDate($document->timestamp);
            $pdf->setDocModificationDate($document->timestamp);
            $pdf->setFileId(substr($identity, 0, 32));
            $pdf->setDocumentId('urn:sha256:'.$identity);

            $layout = new VaseyLicenseLayout($pdf, $profile, $fonts);
            $acceptance = $document->acceptance;
            $identifier = VaseyLicenseV2Profile::VERSION.'  urn:sha256:'.$identity;
            $layout->assertDrawable('heading', [...array_column(array_filter($document->blocks, static fn (array $block): bool => $block['type'] !== 'paragraph'), 'text'),
                ...VaseyLicenseLayout::LABELS]);
            $layout->assertDrawable('body', [...array_column(array_filter($document->blocks, static fn (array $block): bool => $block['type'] === 'paragraph'), 'text'),
                $acceptance['representative_line'] ?? '', 'Date: '.$acceptance['date']]);
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
                $bytes = VaseyLicenseV2Profile::assetBytes($profile, $asset, $this->projectRoot);
                if (file_put_contents($directory.'/'.basename($asset), $bytes, LOCK_EX) !== strlen($bytes)) {
                    throw new ContractIssuanceException('render_failed');
                }
            }
        }
    }
}
