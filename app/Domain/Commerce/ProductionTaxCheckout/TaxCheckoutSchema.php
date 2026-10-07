<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use App\Support\CanonicalJson;

/**
 * Tax255 append-only records. A distinct family: it never references, alters or guards a V1
 * `production_checkout_*` table, so the frozen V1 installer's foreign-reference refusal stays satisfied.
 * Statements are pure functions of the driver so both drivers' guard text is reviewable from either one.
 */
final class TaxCheckoutSchema
{
    public const MIGRATION = '2026_10_07_255000_production_tax_checkout';

    public const TABLES = [
        'order' => 'production_tax_checkout_orders',
        'request' => 'production_tax_checkout_requests',
        'binding' => 'production_tax_checkout_bindings',
        'reviewed' => 'production_tax_checkout_reviewed_sessions',
    ];

    public const IDEMPOTENCY_PREFIX = 'va-production-tax-checkout-v2-';

    private const PREFIXES = ['order' => 'ptx_o', 'request' => 'ptx_r', 'binding' => 'ptx_b', 'reviewed' => 'ptx_s'];

    public static function definitions(): array
    {
        $base = ['id' => 'id', 'public_id' => 'char(36)', 'created_at' => 'char(20)', 'payload_ciphertext' => 'longtext',
            'payload_hash' => 'char(64)', 'canonicalization_version' => 'varchar(32)'];
        $parts = [
            'order' => [['buyer_origin_id' => 'char(36)', 'candidate_id' => 'bigint', 'request_key' => 'char(64)', 'request_hash' => 'char(64)',
                'currency' => 'char(3)', 'subtotal_minor' => 'bigint', 'line_count' => 'integer'],
                [['buyer_origin_id', 'request_key']], []],
            'request' => [['order_id' => 'bigint', 'account_id' => 'varchar(255)', 'funds_mode' => 'varchar(4)', 'idempotency_key' => 'varchar(128)',
                'currency' => 'char(3)', 'subtotal_minor' => 'bigint', 'tax_behavior' => 'varchar(9)', 'maximum_rate_bps' => 'integer',
                'provider_expires_at' => 'char(20)'],
                [['order_id'], ['idempotency_key']], ['order_id' => self::TABLES['order']]],
            'binding' => [['request_id' => 'bigint', 'account_id' => 'varchar(255)', 'funds_mode' => 'varchar(4)', 'provider_session_id' => 'varchar(128)'],
                [['request_id'], ['account_id', 'funds_mode', 'provider_session_id']], ['request_id' => self::TABLES['request']]],
            'reviewed' => [['binding_id' => 'bigint', 'request_id' => 'bigint', 'order_id' => 'bigint', 'account_id' => 'varchar(255)',
                'funds_mode' => 'varchar(4)', 'provider_session_id' => 'varchar(128)', 'provider_payment_id' => 'varchar(128)',
                'currency' => 'char(3)', 'tax_behavior' => 'varchar(9)', 'amount_subtotal_minor' => 'bigint', 'amount_tax_minor' => 'bigint',
                'amount_total_minor' => 'bigint', 'observed_at' => 'char(20)'],
                [['binding_id'], ['request_id'], ['order_id'], ['account_id', 'funds_mode', 'provider_payment_id'], ['account_id', 'funds_mode', 'provider_session_id']],
                ['binding_id' => self::TABLES['binding'], 'request_id' => self::TABLES['request'], 'order_id' => self::TABLES['order']]],
        ];
        $definitions = [];
        foreach ($parts as $kind => [$columns, $uniques, $foreign]) {
            $prefix = self::PREFIXES[$kind];
            $indexes = [$prefix.'_public' => ['public_id']];
            foreach ($uniques as $i => $fields) {
                $indexes[$prefix.'_u'.$i] = $fields;
            }
            $definitions[self::TABLES[$kind]] = ['kind' => $kind, 'prefix' => $prefix, 'columns' => $base + $columns, 'unique' => $indexes, 'foreign' => $foreign];
        }

        return $definitions;
    }

    /** Ordered installation: four tables (with SQLite unique indexes), then three guards per table. */
    public static function statements(string $driver): array
    {
        $mysql = $driver === 'mysql';
        $statements = [];
        foreach (self::definitions() as $table => $definition) {
            $parts = [];
            foreach ($definition['columns'] as $name => $type) {
                $parts[] = $name.' '.self::columnSql($type, $mysql);
            }
            foreach ($definition['foreign'] as $field => $parent) {
                $parts[] = 'CONSTRAINT '.$definition['prefix'].'_f_'.$field.' FOREIGN KEY ('.$field.') REFERENCES '.$parent.' (id) ON DELETE RESTRICT ON UPDATE RESTRICT';
            }
            if ($mysql) {
                foreach ($definition['unique'] as $name => $fields) {
                    $parts[] = 'UNIQUE KEY '.$name.' ('.implode(', ', $fields).')';
                }
                foreach ($definition['foreign'] as $field => $parent) {
                    $parts[] = 'KEY '.$definition['prefix'].'_f_'.$field.' ('.$field.')';
                }
            }
            $statements[$table] = ['type' => 'table', 'table' => $table, 'statement' => 'CREATE TABLE '.$table.' ('.implode(', ', $parts).')'
                .($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '')];
            if (! $mysql) {
                foreach ($definition['unique'] as $name => $fields) {
                    $statements[$name] = ['type' => 'index', 'table' => $table, 'statement' => 'CREATE UNIQUE INDEX '.$name.' ON '.$table.' ('.implode(', ', $fields).')'];
                }
            }
        }
        foreach (self::definitions() as $table => $definition) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $operation) {
                $name = $definition['prefix'].'_'.strtolower($operation);
                $condition = $operation === 'INSERT' ? self::insertCondition($driver, $definition['kind']) : null;
                if ($mysql) {
                    $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or immutable production tax checkout evidence';";
                    $body = 'BEGIN '.($condition === null ? $signal : 'IF NOT COALESCE(('.$condition.'), 0) THEN '.$signal.' END IF;').' END';
                    $sql = 'CREATE TRIGGER '.$name.' BEFORE '.$operation.' ON '.$table.' FOR EACH ROW '.$body;
                } else {
                    $body = null;
                    $sql = 'CREATE TRIGGER '.$name.' BEFORE '.$operation.' ON '.$table.($condition === null ? '' : ' WHEN NOT COALESCE(('.$condition.'), 0)')
                        ." BEGIN SELECT RAISE(ABORT, 'Invalid or immutable production tax checkout evidence'); END";
                }
                $statements[$name] = ['type' => 'trigger', 'table' => $table, 'operation' => $operation, 'body' => $body, 'statement' => $sql];
            }
        }

        return $statements;
    }

    /** Every name the installer owns, including MySQL-only key/constraint identities. */
    public static function reservedNames(string $driver): array
    {
        $names = array_keys(self::statements($driver));
        foreach (self::definitions() as $definition) {
            $names = [...$names, ...array_keys($definition['unique'])];
            foreach ($definition['foreign'] as $field => $parent) {
                $names[] = $definition['prefix'].'_f_'.$field;
            }
        }

        return array_values(array_unique($names));
    }

    public static function insertCondition(string $driver, string $kind): string
    {
        $o = self::TABLES['order'];
        $r = self::TABLES['request'];
        $b = self::TABLES['binding'];
        $common = [self::uuidV4($driver, 'public_id'), self::timestamp($driver, 'created_at'), self::bytes($driver, 'payload_ciphertext', 2097152),
            self::hex($driver, 'payload_hash'), "NEW.canonicalization_version = '".CanonicalJson::VERSION."'"];
        $conditions = match ($kind) {
            'order' => [self::uuidAny($driver, 'buyer_origin_id'), self::integer($driver, 'candidate_id', 1, 21474836470),
                self::hex($driver, 'request_key'), self::hex($driver, 'request_hash'), "NEW.currency = 'USD'",
                self::integer($driver, 'subtotal_minor', 50, 99999999), self::integer($driver, 'line_count', 1, 10)],
            'request' => [self::integer($driver, 'order_id', 1, 21474836470), self::providerId($driver, 'account_id', 'acct_', 64),
                "NEW.funds_mode IN ('test', 'live')", 'NEW.idempotency_key = '.self::concat($driver, "'".self::IDEMPOTENCY_PREFIX."'", 'NEW.public_id'),
                "NEW.currency = 'USD'", self::integer($driver, 'subtotal_minor', 50, 99999999), "NEW.tax_behavior IN ('exclusive', 'inclusive')",
                self::integer($driver, 'maximum_rate_bps', 0, 10000), self::timestamp($driver, 'provider_expires_at'), 'NEW.provider_expires_at > NEW.created_at',
                'EXISTS (SELECT 1 FROM '.$o.' o WHERE o.id = NEW.order_id AND o.created_at <= NEW.created_at AND o.currency = NEW.currency AND o.subtotal_minor = NEW.subtotal_minor)'],
            'binding' => [self::integer($driver, 'request_id', 1, 21474836470), self::providerId($driver, 'account_id', 'acct_', 64),
                "NEW.funds_mode IN ('test', 'live')", self::sessionId($driver, 'provider_session_id'),
                'EXISTS (SELECT 1 FROM '.$r.' r WHERE r.id = NEW.request_id AND r.created_at <= NEW.created_at AND r.account_id = NEW.account_id AND r.funds_mode = NEW.funds_mode)'],
            'reviewed' => [self::integer($driver, 'binding_id', 1, 21474836470), self::integer($driver, 'request_id', 1, 21474836470),
                self::integer($driver, 'order_id', 1, 21474836470), self::providerId($driver, 'account_id', 'acct_', 64), "NEW.funds_mode IN ('test', 'live')",
                self::sessionId($driver, 'provider_session_id'), self::providerId($driver, 'provider_payment_id', 'pi_', 120), "NEW.currency = 'USD'",
                "NEW.tax_behavior IN ('exclusive', 'inclusive')", self::integer($driver, 'amount_subtotal_minor', 50, 99999999),
                self::integer($driver, 'amount_tax_minor', 0, 99999999), self::integer($driver, 'amount_total_minor', 50, 199999998),
                self::timestamp($driver, 'observed_at'), 'NEW.observed_at <= NEW.created_at',
                // Retained provider arithmetic only; the bound is the approved machine-policy ceiling, never a local calculation.
                'EXISTS (SELECT 1 FROM '.$b.' b JOIN '.$r.' r ON r.id = b.request_id WHERE b.id = NEW.binding_id AND r.id = NEW.request_id'
                .' AND r.order_id = NEW.order_id AND b.provider_session_id = NEW.provider_session_id AND b.account_id = NEW.account_id'
                .' AND b.funds_mode = NEW.funds_mode AND b.created_at <= NEW.observed_at AND r.currency = NEW.currency'
                .' AND r.subtotal_minor = NEW.amount_subtotal_minor AND r.tax_behavior = NEW.tax_behavior'
                ." AND ((r.tax_behavior = 'exclusive' AND NEW.amount_total_minor = NEW.amount_subtotal_minor + NEW.amount_tax_minor"
                .' AND NEW.amount_tax_minor * 10000 <= NEW.amount_subtotal_minor * r.maximum_rate_bps)'
                ." OR (r.tax_behavior = 'inclusive' AND NEW.amount_total_minor = NEW.amount_subtotal_minor AND NEW.amount_tax_minor <= NEW.amount_subtotal_minor"
                .' AND NEW.amount_tax_minor * 10000 <= (NEW.amount_subtotal_minor - NEW.amount_tax_minor) * r.maximum_rate_bps)))'],
        };

        return implode(' AND ', [...$common, ...$conditions]);
    }

    public static function columnSql(string $type, bool $mysql): string
    {
        if ($type === 'id') {
            return $mysql ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        }
        if (in_array($type, ['bigint', 'integer'], true)) {
            return $mysql ? ($type === 'integer' ? 'INT' : 'BIGINT').' UNSIGNED NOT NULL' : 'INTEGER NOT NULL';
        }
        if ($type === 'longtext') {
            return $mysql ? 'LONGTEXT CHARACTER SET ascii COLLATE ascii_bin NOT NULL' : 'TEXT NOT NULL';
        }

        return strtoupper($type).($mysql ? ' CHARACTER SET ascii COLLATE ascii_bin' : '').' NOT NULL';
    }

    /** Lowercase version-4 UUID, exactly what Str::uuid() mints and the runtime readers require. */
    private static function uuidV4(string $driver, string $column): string
    {
        $h = '[0-9a-f]';
        if ($driver === 'sqlite') {
            $pattern = str_repeat($h, 8).'-'.str_repeat($h, 4).'-4'.str_repeat($h, 3).'-[89ab]'.str_repeat($h, 3).'-'.str_repeat($h, 12);

            return "LENGTH(NEW.{$column}) = 36 AND NEW.{$column} GLOB '{$pattern}'";
        }

        return "CHAR_LENGTH(NEW.{$column}) = 36 AND NEW.{$column} REGEXP '^{$h}{8}-{$h}{4}-4{$h}{3}-[89ab]{$h}{3}-{$h}{12}\$'";
    }

    /** Identity origin identifiers: 8-4-4-4-12 hex of either case, as Str::isUuid() accepts. */
    private static function uuidAny(string $driver, string $column): string
    {
        $h = '[0-9A-Fa-f]';
        if ($driver === 'sqlite') {
            $pattern = implode('-', array_map(fn (int $n): string => str_repeat($h, $n), [8, 4, 4, 4, 12]));

            return "LENGTH(NEW.{$column}) = 36 AND NEW.{$column} GLOB '{$pattern}'";
        }

        return "CHAR_LENGTH(NEW.{$column}) = 36 AND NEW.{$column} REGEXP '^{$h}{8}-{$h}{4}-{$h}{4}-{$h}{4}-{$h}{12}\$'";
    }

    /**
     * Exactly `YYYY-MM-DDTHH:MM:SSZ` and a real UTC calendar instant (the runtime's only timestamp format).
     * SQLite: the GLOB pins the shape; STRFTIME returns NULL for unparsable text and normalises out-of-range
     * days, so equality refuses both; an hour of 24 is bounded explicitly. MySQL: the CHAR column keeps the
     * text, the REGEXP pins the shape and the STR_TO_DATE/DATE_FORMAT round trip refuses invalid instants.
     */
    private static function timestamp(string $driver, string $column): string
    {
        if ($driver === 'sqlite') {
            return "NEW.{$column} GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'"
                ." AND SUBSTR(NEW.{$column}, 12, 2) < '24' AND STRFTIME('%Y-%m-%dT%H:%M:%SZ', NEW.{$column}) = NEW.{$column}";
        }

        return "NEW.{$column} REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\$'"
            ." AND DATE_FORMAT(STR_TO_DATE(NEW.{$column}, '%Y-%m-%dT%H:%i:%sZ'), '%Y-%m-%dT%H:%i:%sZ') = NEW.{$column}";
    }

    private static function hex(string $driver, string $column): string
    {
        return $driver === 'sqlite' ? "LENGTH(NEW.{$column}) = 64 AND NEW.{$column} NOT GLOB '*[^a-f0-9]*'"
            : "CHAR_LENGTH(NEW.{$column}) = 64 AND NEW.{$column} REGEXP '^[a-f0-9]{64}\$'";
    }

    private static function bytes(string $driver, string $column, int $maximum): string
    {
        return ($driver === 'sqlite' ? 'LENGTH(CAST(NEW.'.$column.' AS BLOB))' : 'LENGTH(NEW.'.$column.')').' BETWEEN 1 AND '.$maximum;
    }

    /** SQLite keeps any storage class in an INTEGER column; integer money and counts must stay integers. */
    private static function integer(string $driver, string $column, int $minimum, int $maximum): string
    {
        return ($driver === 'sqlite' ? "TYPEOF(NEW.{$column}) = 'integer' AND " : '')."NEW.{$column} BETWEEN {$minimum} AND {$maximum}";
    }

    /** `<prefix>` followed by 1..maximum ASCII letters/digits, as the provider adapters admit. */
    private static function providerId(string $driver, string $column, string $prefix, int $maximum): string
    {
        $length = strlen($prefix);
        if ($driver === 'sqlite') {
            return "LENGTH(NEW.{$column}) BETWEEN ".($length + 1).' AND '.($length + $maximum)
                ." AND SUBSTR(NEW.{$column}, 1, {$length}) = '{$prefix}' AND SUBSTR(NEW.{$column}, ".($length + 1).") NOT GLOB '*[^A-Za-z0-9]*'";
        }

        return "NEW.{$column} REGEXP '^{$prefix}[A-Za-z0-9]{1,{$maximum}}\$'";
    }

    /** `cs_<funds_mode>_` followed by 1..120 ASCII letters/digits; the session mode must equal the row's funds mode. */
    private static function sessionId(string $driver, string $column): string
    {
        if ($driver === 'sqlite') {
            return "LENGTH(NEW.{$column}) BETWEEN 9 AND 128 AND SUBSTR(NEW.{$column}, 1, 3) = 'cs_' AND SUBSTR(NEW.{$column}, 4, 4) = NEW.funds_mode"
                ." AND SUBSTR(NEW.{$column}, 8, 1) = '_' AND SUBSTR(NEW.{$column}, 9) NOT GLOB '*[^A-Za-z0-9]*'";
        }

        return "NEW.{$column} REGEXP '^cs_(test|live)_[A-Za-z0-9]{1,120}\$' AND SUBSTR(NEW.{$column}, 4, 4) = NEW.funds_mode";
    }

    private static function concat(string $driver, string $left, string $right): string
    {
        return $driver === 'sqlite' ? '('.$left.' || '.$right.')' : 'CONCAT('.$left.', '.$right.')';
    }
}
