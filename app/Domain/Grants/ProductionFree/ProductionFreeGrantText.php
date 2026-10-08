<?php

namespace App\Domain\Grants\ProductionFree;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractRenderProfile;
use App\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Displays the complete frozen input, including the definition's approved terms text verbatim. It adds only
 * structural labels: no legal wording, price or catalog fact is generated here or looked up live.
 */
final class ProductionFreeGrantText
{
    public function build(array $input): array
    {
        try {
            return $this->document($input);
        } catch (ContractIssuanceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ContractIssuanceException('unsupported_input');
        }
    }

    private function document(array $input): array
    {
        $canonical = CanonicalJson::encode($input);
        if (strlen($canonical) > ContractRenderProfile::LIMITS['input_bytes']
            || ($input['schema_version'] ?? null) !== 'production-free-grant-render-input-v1'
            || ($input['purpose'] ?? null) !== 'production-free-license-grant-v1'
            || ! in_array($input['provenance'] ?? null, ['synthetic_rehearsal', 'verified_production'], true)
            || ! is_string($input['origin_id'] ?? null)
            || ! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $input['origin_id'])
            || ! is_string($input['effective_at'] ?? null)
            || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $input['effective_at'])) {
            throw new ContractIssuanceException('unsupported_input');
        }
        $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $input['effective_at'], new DateTimeZone('UTC'));
        if ($timestamp === false || $timestamp->format('Y-m-d\TH:i:s\Z') !== $input['effective_at']) {
            throw new ContractIssuanceException('unsupported_input');
        }
        foreach (['buyer', 'definition', 'terms', 'assent', 'assets'] as $key) {
            if (! is_array($input[$key] ?? null) || $input[$key] === []) {
                throw new ContractIssuanceException('unsupported_input');
            }
        }
        if (! is_string($input['terms']['text'] ?? null) || $input['terms']['text'] === ''
            || ! hash_equals((string) ($input['definition']['terms_hash'] ?? ''), hash('sha256', $input['terms']['text']))) {
            throw new ContractIssuanceException('unsupported_input');
        }
        $rehearsal = $input['provenance'] === 'synthetic_rehearsal';
        $entries = [['VASEY.AUDIO', $rehearsal ? 'SYNTHETIC REHEARSAL FREE GRANT - LOCAL/TESTING ONLY, NOT AN OPERATIVE LICENSE' : 'FREE LICENSE GRANT ORIGINAL'],
            ['Record', 'Original free-license assent record. No payment was collected.']];
        $this->walk($input, '', $entries, 0);
        $html = '<div style="font-family:dejavusans;font-size:10pt;white-space:pre-wrap;overflow-wrap:break-word">';
        $text = '';
        foreach ($entries as [$label, $value]) {
            $value = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $value);
            $this->supportedText($label."\n".$value);
            $text .= $label."\n".$value."\n";
            $html .= '<p><b>'.$this->escape($label).'</b><br>'.$this->escape($value).'</p>';
        }
        $html .= '</div>';

        return ['html' => $html, 'text' => $text, 'text_digest' => hash('sha256', $text),
            'input_hash' => hash('sha256', $canonical), 'timestamp' => $timestamp->getTimestamp()];
    }

    private function walk(array $value, string $path, array &$entries, int $depth): void
    {
        if ($depth > 32 || count($entries) > 20000) {
            throw new ContractIssuanceException('unsupported_input');
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $label = $path === '' ? (string) $key : $path.'.'.$key;
            if (is_array($item) && $item !== []) {
                $this->walk($item, $label, $entries, $depth + 1);
            } else {
                $entries[] = [$label, is_string($item) ? $item : CanonicalJson::encode($item)];
            }
        }
    }

    private function supportedText(string $value): void
    {
        if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[^\p{Latin}\p{Greek}\p{Cyrillic}\p{Common}]/u', $value)
            || preg_match('/[\p{M}\p{Cf}\p{Co}\p{Cs}\p{Cn}]/u', $value)
            || preg_match('/[\x00-\x08\x0B-\x1F\x7F-\x9F]/u', $value)) {
            throw new ContractIssuanceException('unsupported_input');
        }
    }

    private function escape(string $value): string
    {
        return str_replace("\n", '<br>', htmlspecialchars(str_replace("\t", '    ', $value), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'));
    }
}
