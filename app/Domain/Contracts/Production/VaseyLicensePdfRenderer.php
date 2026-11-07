<?php

namespace App\Domain\Contracts\Production;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\RenderedContract;
use App\Support\CanonicalJson;
use Com\Tecnick\Color\Pdf;
use Com\Tecnick\Pdf\Encrypt\Encrypt;
use Com\Tecnick\Pdf\Page\Page;
use Com\Tecnick\Pdf\Tcpdf;
use Throwable;

/**
 * Renders a {@see VaseyLicenseDocument} under the `vasey-license-pdf-v1` profile: a charcoal header band carrying the
 * unmodified VASEY.AUDIO lockup, Bebas Neue headings, Reddit Sans body text, an acceptance block with Sean's signature
 * on the Producer/Licensor side, and JetBrains Mono identifiers.
 *
 * Deterministic: the same document, profile and signature pin give byte-identical output. The creation and
 * modification dates are the contract date (UTC midnight), the file and document IDs derive from the input, profile
 * and signature hashes, fonts are embedded whole and uncompressed as the profile pins, and nothing reads the clock.
 *
 * Built, not activated: no issuer, dispatch or policy selects this renderer (increment 5 does that, with review).
 */
final class VaseyLicensePdfRenderer
{
    private const PAGE = ['format' => 'A4', 'orientation' => 'P', 'margin' => ['PL' => 18, 'PR' => 18, 'CT' => 18, 'CB' => 22]];

    private const LEFT = 18.0;

    private const WIDTH = 174.0;

    private const TOP = 18.0;

    private const BOTTOM = 275.0;

    private const BAND_HEIGHT = 34.0;

    /** The lockup's own 420 × 100 aspect ratio, drawn at a fixed height. */
    private const LOGO_HEIGHT = 15.0;

    private const SIGNATURE_BOX = ['width' => 60.0, 'height' => 20.0];

    public function __construct(private readonly ?string $projectRoot = null) {}

    public function render(VaseyLicenseDocument $document, array $profile, VaseyLicenseSignature $signature): RenderedContract
    {
        if (($profile['version'] ?? null) !== VaseyLicenseProfile::VERSION) {
            throw new ContractIssuanceException('profile_changed');
        }
        VaseyLicenseProfile::verifyRuntime($profile, $this->projectRoot);
        $logo = VaseyLicenseProfile::assetBytes($profile, VaseyLicenseProfile::LOGO, $this->projectRoot);
        $signed = $signature->read();
        $profileHash = VaseyLicenseProfile::hash($profile, $this->projectRoot);
        // The recorded profile identity binds the signature pin, so a different signature is a different profile.
        $effectiveHash = CanonicalJson::hash(['profile_sha256' => $profileHash, 'signature_sha256' => $signature->sha256]);
        $identity = hash('sha256', $document->inputHash.':'.$effectiveHash);
        $fonts = VaseyLicenseProfile::assetPath('fonts/generated', $this->projectRoot);

        $timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
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
            $pdf->setCreator('VASEY.AUDIO license profile '.VaseyLicenseProfile::VERSION);
            $pdf->setAuthor('VASEY.AUDIO');
            $pdf->setTitle($document->title);
            $pdf->setSubject('License agreement');
            $pdf->setKeywords(VaseyLicenseProfile::VERSION.','.$effectiveHash);
            $pdf->setDocCreationDate($document->timestamp);
            $pdf->setDocModificationDate($document->timestamp);
            $pdf->setFileId(substr($identity, 0, 32));
            $pdf->setDocumentId('urn:sha256:'.$identity);

            $layout = new VaseyLicenseLayout($pdf, $profile, $fonts);
            $acceptance = $document->acceptance;
            $identifier = VaseyLicenseProfile::VERSION.'  urn:sha256:'.$identity;
            $layout->assertDrawable('heading', [...array_column(array_filter($document->blocks, static fn (array $block): bool => $block['type'] !== 'paragraph'), 'text'),
                ...VaseyLicenseLayout::LABELS]);
            $layout->assertDrawable('body', [...array_column(array_filter($document->blocks, static fn (array $block): bool => $block['type'] === 'paragraph'), 'text'),
                $acceptance['representative_line'] ?? '', 'Date: '.$acceptance['date']]);
            $layout->assertDrawable('body_bold', [$acceptance['licensor'], $acceptance['licensee']]);
            $layout->assertDrawable('identifier', [$identifier]);

            $page = $pdf->addPage(self::PAGE);
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
        }
    }

    /** Page geometry shared with {@see VaseyLicenseLayout}. */
    public static function geometry(): array
    {
        return ['page' => self::PAGE, 'left' => self::LEFT, 'width' => self::WIDTH, 'top' => self::TOP, 'bottom' => self::BOTTOM];
    }
}
