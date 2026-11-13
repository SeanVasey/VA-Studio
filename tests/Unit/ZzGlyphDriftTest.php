<?php

namespace Tests\Unit;

use App\Domain\Commerce\ContractIdentity\ContractGlyphCoverage;
use App\Domain\Commerce\ContractIdentity\ContractIdentityDeclarationV2;
use Tests\TestCase;

/** Scratch diagnostic (not for merge): which pinned codepoint this runtime's Unicode tables classify differently. */
final class ZzGlyphDriftTest extends TestCase
{
    public function test_drift(): void
    {
        $coverage = ContractGlyphCoverage::pinnedContractFaces();
        $now = array_values(array_filter($coverage->codepoints(), fn (int $cp): bool => ContractIdentityDeclarationV2::isPrintable('A'.mb_chr($cp, 'UTF-8'), $coverage)));
        $then = json_decode(file_get_contents(base_path('tests/Fixtures/scratch/acc74.json')), true);
        $describe = fn (int $cp): string => sprintf('U+%04X %s gc=%s script=%s', $cp, \IntlChar::charName($cp), \IntlChar::charType($cp), \IntlChar::getIntPropertyValue($cp, \IntlChar::PROPERTY_SCRIPT));
        $refused = '/[\p{C}\p{M}\p{Z}\p{Hebrew}\p{Arabic}\p{Syriac}\p{Thaana}\p{Nko}\p{Samaritan}\p{Mandaic}]/u';
        foreach (array_diff($then, $now) as $cp) {
            fwrite(STDERR, 'GLYPH-DRIFT removed '.$describe($cp).' refused='.preg_match($refused, mb_chr($cp, 'UTF-8')).' C='.preg_match('/\p{C}/u', mb_chr($cp, 'UTF-8')).' M='.preg_match('/\p{M}/u', mb_chr($cp, 'UTF-8')).' Z='.preg_match('/\p{Z}/u', mb_chr($cp, 'UTF-8'))."\n");
        }
        foreach (array_diff($now, $then) as $cp) {
            fwrite(STDERR, 'GLYPH-DRIFT added '.$describe($cp)."\n");
        }
        fwrite(STDERR, 'GLYPH-DRIFT counts now='.count($now).' then='.count($then).' PCRE='.PCRE_VERSION.' ICU='.INTL_ICU_VERSION."\n");
        $this->assertTrue(true);
    }
}
