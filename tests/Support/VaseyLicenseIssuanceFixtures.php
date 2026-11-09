<?php

namespace Tests\Support;

use App\Domain\Contracts\Production\ContractLicenseConstants;
use App\Domain\Grants\VaseyLicenseContractSource;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * SYNTHETIC, NONBINDING inputs for the gated `vasey-license-pdf-v2` issuance: an invented template (the v2 renderer test's),
 * invented license constants and a generated stand-in signature on the test's private disk. No real template wording,
 * signature, seller value or license term is used.
 */
trait VaseyLicenseIssuanceFixtures
{
    protected const SYNTHETIC_TEMPLATE = "SYNTHETIC TEST TEMPLATE: {LICENSE_NAME}\n\nNONBINDING FIXTURE\n\n"
        .'This synthetic fixture is dated {CONTRACT_DATE}. It names {CUSTOMER_FULLNAME} of {CUSTOMER_ADDRESS} and {PRODUCER_ALIAS}, '
        ."{STATE_PROVINCE_COUNTRY}.\nA kept line break.\n\nSYNTHETIC TERMS\n\n"
        .'Track {PRODUCT_TITLE}; fee {PRODUCT_PRICE} ({PRODUCT_PRICE_WORD}); files {FILE_TYPE}; share {PUBLISHING_RIGHTS}. '
        .'Copies {DISTRIBUTE_COPIES}; stations {NUMBER_OF_RADIO_STATIONS}; videos {MONETIZED_MUSIC_VIDEOS} ({MONETIZED_MUSIC_VIDEOS_WORD}); '
        .'streams {AUDIO_STREAMS}, {NON_MONETIZED_VIDEO_STREAMS_ALLOWED} and {MONETIZED_VIDEO_STREAMS_ALLOWED}; downloads {FREE_DOWNLOADS}; '
        .'shows {PERFORMANCES_FOR_PROFIT} and {PERFORMANCES_NOT_FOR_PROFIT}.';

    /** Synthetic schema 5 terms with every usage placeholder bindable (no prohibited use). */
    protected static function v2LicenseTerms(): array
    {
        $terms = TieredLicenseFixtures::terms();
        $terms['usage']['radio_stations'] = ['mode' => 'limited', 'limit' => 2];
        $terms['usage']['for_profit_performances'] = ['mode' => 'limited', 'limit' => 21];

        return $terms;
    }

    protected static function v2LicenseContent(): array
    {
        return ['authored_source' => TieredLicenseFixtures::source()];
    }

    /** Binds a synthetic contract source; `$template` null binds a source that has no v2 text for any license. */
    protected function bindSyntheticContractSource(?string $template = self::SYNTHETIC_TEMPLATE): void
    {
        app()->instance(VaseyLicenseContractSource::class, new class($template) implements VaseyLicenseContractSource
        {
            public function __construct(private readonly ?string $template) {}

            public function contract(array $license): ?array
            {
                return $this->template === null ? null
                    : ['template' => $this->template, 'constants' => new ContractLicenseConstants('Synthetic Producer Alias', 'Synthetic State, Synthetic Country')];
            }
        });
    }

    /**
     * Writes the generated stand-in signature to the private local disk and pins it in configuration, as production
     * would (`VASEY_LICENSE_SIGNATURE_PATH` / `..._SHA256`). Returns the PNG's SHA-256.
     */
    protected function installSyntheticSignature(string $relative = 'contracts/signature/synthetic-signature.png', int $alpha = 0): string
    {
        $width = 600;
        $height = 180;
        $rows = '';
        for ($y = 0; $y < $height; $y++) {
            $rows .= "\0";
            for ($x = 0; $x < $width; $x++) {
                $phase = intdiv($x, 60) % 2 === 0 ? $x % 60 : 60 - $x % 60;
                $ink = $x >= 40 && $x < $width - 40 && abs($y - (60 + $phase)) <= 2;
                $rows .= $ink ? "\x14\x1e\x3c\xff" : "\xff\xff\xff".chr($alpha);
            }
        }
        $deflate = "\x78\x01";
        foreach (str_split($rows, 65535) as $index => $block) {
            $last = ($index + 1) * 65535 >= strlen($rows);
            $deflate .= chr($last ? 1 : 0).pack('v', strlen($block)).pack('v', strlen($block) ^ 0xFFFF).$block;
        }
        $deflate .= hash('adler32', $rows, true);
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0)).$chunk('IDAT', $deflate).$chunk('IEND', '');
        Storage::disk('local')->put($relative, $png);
        config(['contracts.vasey_license_v1.signature_path' => $relative, 'contracts.vasey_license_v1.signature_sha256' => hash('sha256', $png)]);

        return hash('sha256', $png);
    }

    /** Everything a v2 selection needs, with the gate as given. */
    protected function enableV2Issuance(bool $gate = true): void
    {
        $this->bindSyntheticContractSource();
        $this->installSyntheticSignature();
        config(['contracts.vasey_license_v2_issuance' => $gate]);
    }

    /** The text a reader gets from the PDF (poppler `pdftotext`). */
    protected function extractPdfText(string $pdf): string
    {
        $path = tempnam(sys_get_temp_dir(), 'va-v2-issuance-');
        try {
            file_put_contents($path, $pdf);
            $extract = new Process(['pdftotext', '-enc', 'UTF-8', $path, '-'], timeout: 30);
            $extract->mustRun();

            return $extract->getOutput();
        } finally {
            unlink($path);
        }
    }
}
