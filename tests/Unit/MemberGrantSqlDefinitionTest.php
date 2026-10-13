<?php

namespace Tests\Unit;

use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantSchema;
use App\Domain\Grants\Member\MemberGrantSqlDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Native printer samples are synthetic here; actual SHOW output is exercised by the native feature file. */
class MemberGrantSqlDefinitionTest extends TestCase
{
    private const PROFILE = <<<'SQL'
CREATE TABLE `production_member_profiles` (
  `id` varchar(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `profile_hash` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `original_terms_hash` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `implementation_hash` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `font_manifest_hash` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `provenance` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payload_ciphertext` longtext COLLATE utf8mb4_bin NOT NULL,
  `seal` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` varchar(19) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `production_member_profiles_u0` (`profile_hash`),
  CONSTRAINT `production_member_profiles_bounds` CHECK (((length(`id`) = 36) and (length(`seal`) = 64) and (length(`profile_hash`) = 64) and (length(`original_terms_hash`) = 64) and (length(`implementation_hash`) = 64) and (length(`font_manifest_hash`) = 64) and (`provenance` in (_ascii'synthetic_rehearsal',_ascii'verified_production'))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
SQL;

    public function test_native_printing_parentheses_keyword_case_and_exact_enum_introducers_are_allowed(): void
    {
        MemberGrantSqlDefinition::mysqlTable(self::PROFILE, $this->profile(), '');
        $this->addToAssertionCount(1);
    }

    public static function changedDefinitions(): array
    {
        return [
            'engine' => ['ENGINE=InnoDB', 'ENGINE=MyISAM'],
            'table collation' => ['DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin', 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'],
            'column collation' => ['`id` varchar(36) CHARACTER SET ascii COLLATE ascii_bin', '`id` varchar(36) CHARACTER SET ascii COLLATE ascii_general_ci'],
            'literal case' => ["_ascii'verified_production'", "_ascii'Verified_production'"],
            'literal whitespace' => ["_ascii'verified_production'", "_ascii'verified production'"],
            'boolean grouping' => ['(length(`seal`) = 64) and', '(length(`seal`) = 64) or'],
            'check disabled' => ["_ascii'verified_production'))))", "_ascii'verified_production')))) NOT ENFORCED"],
            'counter' => ['ENGINE=InnoDB', 'ENGINE=InnoDB AUTO_INCREMENT=2'],
            'extra key' => ['PRIMARY KEY (`id`),', 'PRIMARY KEY (`id`), KEY `other` (`seal`),'],
            'different unique' => ['UNIQUE KEY `production_member_profiles_u0` (`profile_hash`)', 'UNIQUE KEY `production_member_profiles_u0` (`seal`)'],
            'default' => ['`created_at` varchar(19)', "`created_at` varchar(19) DEFAULT '2026-10-13 00:00:00'"],
        ];
    }

    #[DataProvider('changedDefinitions')]
    public function test_semantic_or_unowned_printer_changes_are_refused(string $from, string $to): void
    {
        $changed = str_replace($from, $to, self::PROFILE, $count);
        $this->assertGreaterThan(0, $count);
        $this->expectException(MemberGrantException::class);
        MemberGrantSqlDefinition::mysqlTable($changed, $this->profile(), '');
    }

    public function test_trigger_definer_is_the_only_ignored_header_and_literal_bytes_remain_owned(): void
    {
        $expected = array_values($this->profile()['guards'])[1]['sql'];
        $shown = str_replace('CREATE TRIGGER', 'CREATE DEFINER=`synthetic`@`localhost` TRIGGER', $expected);
        MemberGrantSqlDefinition::mysqlTrigger($shown, $expected);
        $this->addToAssertionCount(1);
        $this->expectException(MemberGrantException::class);
        MemberGrantSqlDefinition::mysqlTrigger(str_replace('Retain production member originals', 'Retain production member  originals', $shown), $expected);
    }

    public function test_sqlite_comparison_preserves_literal_whitespace_and_identifier_case(): void
    {
        $this->assertTrue(MemberGrantSqlDefinition::same("SELECT  'a b'", "select 'a b'"));
        $this->assertFalse(MemberGrantSqlDefinition::same("SELECT 'a  b'", "SELECT 'a b'"));
        $this->assertFalse(MemberGrantSqlDefinition::same('SELECT `id`', 'SELECT `ID`'));
        $this->assertFalse(MemberGrantSqlDefinition::same("SELECT 'A'", "SELECT 'a'"));
    }

    private function profile(): array
    {
        return MemberGrantSchema::expectedPlan('mysql', 'synthetic', '')['tables']['production_member_profiles'];
    }
}
