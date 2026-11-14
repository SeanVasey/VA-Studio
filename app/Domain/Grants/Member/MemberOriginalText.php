<?php

namespace App\Domain\Grants\Member;

use App\Domain\Contracts\ContractIssuanceException;
use App\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;

/** Exact retained fields and terms, escaped as text; no generated legal wording or assent. */
final class MemberOriginalText
{
    public function build(MemberOriginalRenderInput $input): array
    {
        $value = $input->value();
        $entries = [['VASEY.AUDIO', $value['provenance'] === 'synthetic_rehearsal'
            ? 'SYNTHETIC REHEARSAL MEMBER ORIGINAL CANDIDATE - NOT AN OPERATIVE LICENSE'
            : 'MEMBERSHIP LICENSE ORIGINAL CANDIDATE'],
            ['Record', 'Prepared from retained reservation sources. This document does not record activation or assent.'],
            ['Account reference', 'Account control is separate from verified legal identity.'],
            ['Source date', 'reservation_created_at is the retained reservation timestamp, not an effective or assent date.']];
        $this->walk($value, '', $entries, 0);
        $html = '<div style="font-family:dejavusans;font-size:10pt;white-space:pre-wrap;overflow-wrap:break-word">';
        $text = '';
        foreach ($entries as [$label, $item]) {
            $this->supported($label."\n".$item);
            // Keep the text digest over exact source spelling; HTML gets escaped line-break markup only.
            $text .= $label."\n".$item."\n";
            $html .= '<p><b>'.$this->escape($label).'</b><br>'.$this->escape($item).'</p>';
        }
        $html .= '</div>';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value['reservation_created_at'], new DateTimeZone('UTC'));

        return ['html' => $html, 'text' => $text, 'text_digest' => hash('sha256', $text),
            'input_hash' => $input->hash(), 'timestamp' => $date->getTimestamp()];
    }

    private function walk(array $value, string $path, array &$entries, int $depth): void
    {
        if ($depth > 24 || count($entries) > 20000) {
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

    private function supported(string $value): void
    {
        if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[^\p{Latin}\p{Greek}\p{Cyrillic}\p{Common}]/u', $value)
            || preg_match('/[\p{M}\p{Cf}\p{Co}\p{Cs}\p{Cn}]/u', $value)
            || preg_match('/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F-\x9F]/u', $value)) {
            throw new ContractIssuanceException('unsupported_input');
        }
    }

    private function escape(string $value): string
    {
        return str_replace(["\r\n", "\r", "\n", "\t"], ['<br>', '<br>', '<br>', '    '],
            htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'));
    }
}
