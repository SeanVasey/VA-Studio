<?php

namespace Tests\Unit;

use App\Domain\Memberships\Production\MembershipCatalogDefinition;
use App\Domain\Memberships\Production\MembershipException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure byte-projection corpus. No Laravel application, database or native format claim. */
class MembershipCatalogDefinitionTest extends TestCase
{
    private const BODY = "CREATE TABLE `tracks` (\n  `id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `note` varchar(255) DEFAULT 'plain',\n  PRIMARY KEY (`id`)\n)";

    private const OPTIONS = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    #[DataProvider('counters')]
    public function test_only_the_native_table_counter_changes_the_equivalence_class(string $counter): void
    {
        $expected = self::BODY.self::OPTIONS;
        $shown = self::BODY.' ENGINE=InnoDB'.$counter.' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $actual = MembershipCatalogDefinition::mysql($shown, 'tracks');
        $this->assertSame($expected, $actual);
        $this->assertSame($actual, MembershipCatalogDefinition::mysql($actual, 'tracks'));
        $this->assertStringContainsString('NOT NULL AUTO_INCREMENT,', $actual);
    }

    public static function counters(): array
    {
        return [[''], [' AUTO_INCREMENT=1'], [' AUTO_INCREMENT=9'], [' AUTO_INCREMENT=10'],
            [' AUTO_INCREMENT=18446744073709551615']];
    }

    #[DataProvider('protectedText')]
    public function test_quoted_text_comments_and_nested_expressions_remain_byte_exact(string $body, string $options): void
    {
        $prefix = 'CREATE TABLE `tracks` ('.$body.')';
        $shown = $prefix.' ENGINE=InnoDB AUTO_INCREMENT=27'.$options;
        $this->assertSame($prefix.' ENGINE=InnoDB'.$options, MembershipCatalogDefinition::mysql($shown, 'tracks'));
    }

    public static function protectedText(): array
    {
        return [
            'single quote' => ["`note` text DEFAULT 'AUTO_INCREMENT=12 ) ENGINE=MyISAM'", " COMMENT='AUTO_INCREMENT=77'"],
            'double quote' => ['`note` text DEFAULT "AUTO_INCREMENT=12 ) ENGINE=MyISAM"', ' COMMENT="AUTO_INCREMENT=77"'],
            'doubled quote' => ["`note` text DEFAULT 'it''s ) AUTO_INCREMENT=12'", " COMMENT='it''s AUTO_INCREMENT=77'"],
            'backslash quote' => ["`note` text DEFAULT 'it\\'s AUTO_INCREMENT=12'", " COMMENT='it\\'s AUTO_INCREMENT=77'"],
            'literal backslash' => ["`note` text DEFAULT 'path\\'", " COMMENT='tail'"],
            'quoted identifier' => ['`AUTO_INCREMENT=12 ) `` ENGINE=MyISAM` text', ''],
            'nested expression' => ['`n` int DEFAULT ((1 + (2))), CHECK ((`n` > 0) AND (`n` < 8))', ''],
            'block comment' => ['`n` int /* ) AUTO_INCREMENT=12 ENGINE=MyISAM */', ' /* AUTO_INCREMENT=77 */'],
            'version comment' => ['`n` int /*!80016 NOT NULL */', ' /*!50100 AUTO_INCREMENT=77 */'],
            'optimizer comment' => ['`n` int /*+ AUTO_INCREMENT=12 ) */', ''],
            'hash comment' => ["`n` int # ) AUTO_INCREMENT=12\n", ''],
            'dash comment' => ["`n` int -- ) AUTO_INCREMENT=12\n", ''],
            'dash expression' => ['`n` int DEFAULT (8--2)', ''],
            'partition options' => ['`n` int', ' PARTITION BY HASH (`n`) (PARTITION p0 ENGINE=InnoDB)'],
        ];
    }

    #[DataProvider('significantChanges')]
    public function test_every_non_counter_byte_remains_significant(string $changed): void
    {
        $baseline = self::BODY.self::OPTIONS;
        $this->assertNotSame(MembershipCatalogDefinition::mysql($baseline, 'tracks'),
            MembershipCatalogDefinition::mysql($changed, 'tracks'));
    }

    public static function significantChanges(): array
    {
        $baseline = self::BODY.self::OPTIONS;

        return [
            'column allocation attribute' => [str_replace(' NOT NULL AUTO_INCREMENT', ' NOT NULL', $baseline)],
            'column type' => [str_replace('bigint unsigned', 'int unsigned', $baseline)],
            'column nullable' => [str_replace(' NOT NULL', '', $baseline)],
            'literal counter text' => [str_replace("DEFAULT 'plain'", "DEFAULT 'AUTO_INCREMENT=12'", $baseline)],
            'literal engine text' => [str_replace("DEFAULT 'plain'", "DEFAULT 'ENGINE=MyISAM'", $baseline)],
            'default' => [str_replace("DEFAULT 'plain'", "DEFAULT 'changed'", $baseline)],
            'column comment' => [str_replace("DEFAULT 'plain'", "DEFAULT 'plain' COMMENT 'AUTO_INCREMENT=9'", $baseline)],
            'table comment' => [$baseline." COMMENT='AUTO_INCREMENT=9'"],
            'collation' => [str_replace('utf8mb4_unicode_ci', 'utf8mb4_bin', $baseline)],
            'index' => [str_replace('PRIMARY KEY (`id`)', 'PRIMARY KEY (`id`,`note`)', $baseline)],
            'check' => [str_replace('PRIMARY KEY (`id`)', 'PRIMARY KEY (`id`), CHECK ((`id` > 0) OR (`id` < 3))', $baseline)],
            'foreign action' => [str_replace('PRIMARY KEY (`id`)', 'PRIMARY KEY (`id`), FOREIGN KEY (`id`) REFERENCES `other` (`id`) ON DELETE CASCADE', $baseline)],
            'format' => [$baseline.' ROW_FORMAT=COMPACT'],
            'compression' => [$baseline." COMPRESSION='zlib'"],
            'tablespace' => [$baseline.' TABLESPACE `catalog_space`'],
            'literal whitespace' => [str_replace("DEFAULT 'plain'", "DEFAULT 'plain  '", $baseline)],
            'structural whitespace' => [str_replace('PRIMARY KEY', 'PRIMARY  KEY', $baseline)],
        ];
    }

    #[DataProvider('refusals')]
    public function test_malformed_ambiguous_or_foreign_definition_is_refused(string $shown, string $table = 'tracks'): void
    {
        try {
            MembershipCatalogDefinition::mysql($shown, $table);
            $this->fail('Unsupported catalog DDL must not produce a comparison value.');
        } catch (MembershipException $error) {
            $this->assertSame('catalog_shadow_or_engine', $error->reason);
        }
    }

    public static function refusals(): array
    {
        $baseline = self::BODY.self::OPTIONS;

        return [
            'temporary' => [str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $baseline)],
            'foreign name' => [$baseline, 'offers'],
            'invalid expected identifier' => [$baseline, 'tracks`'],
            'view' => [str_replace('CREATE TABLE', 'CREATE VIEW', $baseline)],
            'engine' => [str_replace('ENGINE=InnoDB', 'ENGINE=MyISAM', $baseline)],
            'engine in comment' => [str_replace('ENGINE=InnoDB', "ENGINE=MyISAM COMMENT='ENGINE=InnoDB'", $baseline)],
            'engine suffix' => [str_replace('ENGINE=InnoDB', 'ENGINE=InnoDBOther', $baseline)],
            'duplicate engine' => [$baseline.' ENGINE=InnoDB'],
            'duplicate counter' => [self::BODY.' ENGINE=InnoDB AUTO_INCREMENT=3 AUTO_INCREMENT=4'],
            'counter after options' => [$baseline.' AUTO_INCREMENT=4'],
            'signed counter' => [self::BODY.' ENGINE=InnoDB AUTO_INCREMENT=+3'],
            'negative counter' => [self::BODY.' ENGINE=InnoDB AUTO_INCREMENT=-3'],
            'decimal counter' => [self::BODY.' ENGINE=InnoDB AUTO_INCREMENT=3.5'],
            'exponent counter' => [self::BODY.' ENGINE=InnoDB AUTO_INCREMENT=3e5'],
            'counter tail' => [self::BODY.' ENGINE=InnoDB AUTO_INCREMENT=3suffix'],
            'missing counter' => [self::BODY.' ENGINE=InnoDB AUTO_INCREMENT='],
            'unclosed body quote' => ["CREATE TABLE `tracks` (`n` text DEFAULT 'x) ENGINE=InnoDB"],
            'unclosed suffix quote' => [$baseline." COMMENT='x"],
            'unclosed comment' => [$baseline.' /* never closed'],
            'unclosed body' => ['CREATE TABLE `tracks` (`n` int ENGINE=InnoDB'],
            'unbalanced suffix' => [$baseline.' PARTITION BY HASH (`id`'],
            'extra close' => [$baseline.')'],
            'another statement' => [$baseline.'; SELECT 1'],
            'nul' => [$baseline."\0"],
            'different complete quote-mode boundaries' => [<<<'SQL'
CREATE TABLE `tracks` (`n` text DEFAULT 'a\') ENGINE=InnoDB AUTO_INCREMENT=8 COMMENT=') ENGINE=InnoDB AUTO_INCREMENT=9 COMMENT='x\'y'
SQL],
            'quote boundary cannot hide nontransactional engine' => [<<<'SQL'
CREATE TABLE `tracks` (`n` text DEFAULT 'a\') ENGINE=InnoDB AUTO_INCREMENT=8 COMMENT=') ENGINE=MyISAM AUTO_INCREMENT=9 COMMENT='x\'y'
SQL],
            'escaped literal with competing outer close' => ["CREATE TABLE `tracks` (`note` text DEFAULT 'it\\'s ) AUTO_INCREMENT=12') ENGINE=InnoDB AUTO_INCREMENT=27 COMMENT='it\\'s AUTO_INCREMENT=77'"],
        ];
    }
}
