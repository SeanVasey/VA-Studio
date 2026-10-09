<?php

namespace App\Domain\Grants\Paid;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\RenderedContract;
use App\Support\CanonicalJson;
use Com\Tecnick\Color\Pdf;
use Com\Tecnick\Pdf\Encrypt\Encrypt;
use Com\Tecnick\Pdf\Page\Page;
use Com\Tecnick\Pdf\Tcpdf;
use Throwable;

/** Invoked in a bounded isolated PHP process by the production service binding. */
final class PaidGrantPdfRenderer implements ContractRenderer
{
    public function __construct(private readonly ?string $projectRoot = null) {}

    public function render(array $input, array $profile): RenderedContract
    {
        if (! in_array($profile['version'] ?? null, ['rehearsal-paid-grant-pdf-v1', 'verified-paid-grant-pdf-v1'], true) || ($profile['provenance'] ?? null) !== ($input['provenance'] ?? null)) {
            throw new ContractIssuanceException('profile_changed');
        }
        PaidGrantRenderProfile::verifyRuntime($profile, $this->projectRoot);
        $document = (new PaidGrantText)->build($input);
        $root = $this->projectRoot ?? dirname(__DIR__, 4);
        $fonts = $root.'/resources/contracts/test-v2/fonts/generated/';
        if (defined('K_PATH_FONTS') && constant('K_PATH_FONTS') !== $fonts) {
            throw new ContractIssuanceException('profile_changed');
        }
        if (! defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', $fonts);
        }
        $timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $pdf = new Tcpdf(unit: 'mm', isunicode: true, subsetfont: false, compress: false, mode: '', fileOptions: [
                'allowedHosts' => [], 'allowedPaths' => [rtrim($fonts, '/')], 'markupAllowedPaths' => [],
            ]);
            // Auto-created pages discard their predecessor's time. Freeze the page sanitizer too.
            $pdf->page = new class($document['timestamp'], $pdf->color, $pdf->encrypt) extends Page
            {
                public function __construct(private readonly int $timestamp, Pdf $color,
                    Encrypt $encrypt)
                {
                    parent::__construct('mm', $color, $encrypt, notransparency: false, compress: false, sigapp: false);
                }

                public function sanitizeTime(array &$data): void
                {
                    $data['time'] = $this->timestamp;
                }
            };
            $pdf->enableDefaultPageContent(false);
            $profileHash = CanonicalJson::hash(PaidGrantRenderProfile::validate($profile));
            $identity = hash('sha256', $input['origin_id'].':'.$document['input_hash'].':'.$profileHash);
            $pdf->setCreator('VASEY.AUDIO paid grant profile v1');
            $pdf->setAuthor('VASEY.AUDIO retained paid issuance');
            $pdf->setTitle(($input['provenance'] === 'synthetic_rehearsal' ? 'Rehearsal paid grant ' : 'Paid license original ').$input['origin_id']);
            $pdf->setSubject('Original paid-license assent record');
            $pdf->setKeywords($input['provenance'].','.$profileHash);
            $pdf->setDocCreationDate($document['timestamp']);
            $pdf->setDocModificationDate($document['timestamp']);
            $pdf->setFileId(substr($identity, 0, 32));
            $pdf->setDocumentId('urn:sha256:'.$identity);
            foreach (['B', ''] as $style) {
                $font = $pdf->font->insert($pdf->pon, 'dejavusans', $style, 10);
                foreach (array_unique(mb_str_split($document['text'])) as $char) {
                    if ($char === "\n" || $char === "\t") {
                        continue;
                    }
                    $codepoint = mb_ord($char);
                    if (! $pdf->font->isCharDefined($codepoint) || $pdf->font->getGidForOrd($codepoint) === 0) {
                        throw new ContractIssuanceException('unsupported_input');
                    }
                }
            }
            $pdf->addPage(['format' => 'A4', 'orientation' => 'P']);
            $pdf->page->addContent($font['out']);
            $pdf->addHTMLCell(html: $document['html'], posx: 15, posy: 15, width: 180);
            $bytes = $pdf->getOutPDFString();
            $pages = count($pdf->page->getPages());
            if ($pages < 1 || $pages > $profile['limits']['pages'] || strlen($bytes) > $profile['limits']['output_bytes']
                || ! str_starts_with($bytes, '%PDF-') || ! str_ends_with(rtrim($bytes), '%%EOF') || $pdf->getWarnings() !== []) {
                throw new ContractIssuanceException('invalid_pdf');
            }

            return new RenderedContract($bytes, hash('sha256', $bytes), strlen($bytes), $pages, $document['text_digest'], $profileHash);
        } catch (ContractIssuanceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ContractIssuanceException('render_failed');
        } finally {
            date_default_timezone_set($timezone);
        }
    }
}
