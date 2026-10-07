<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;

/** Entirely additive immutable commerce records; no historical checkout table or guard is changed. */
final class CheckoutSchema
{
    public const TABLES = [
        'authority' => 'production_checkout_exemption_authorities',
        'basis' => 'production_checkout_exemption_bases',
        'review' => 'production_checkout_reviews',
        'order' => 'production_checkout_orders',
        'line' => 'production_checkout_order_lines',
        'attempt' => 'production_checkout_attempts',
        'intent' => 'production_checkout_provider_intents',
        'session' => 'production_checkout_sessions',
        'observation' => 'production_checkout_observations',
        'payment' => 'production_checkout_payments',
    ];

    public static function definitions(): array
    {
        $base = ['id' => 'id', 'public_id' => 'char(36)', 'created_at' => 'char(20)', 'payload_ciphertext' => 'longtext',
            'payload_hash' => 'char(64)', 'canonicalization_version' => 'varchar(32)'];
        $parts = [
            'authority' => [['candidate_id' => 'bigint', 'owner_user_id' => 'bigint', 'request_key' => 'char(64)', 'request_hash' => 'char(64)'], [['owner_user_id', 'request_key']], ['owner_user_id' => 'users']],
            'basis' => [['authority_id' => 'bigint', 'candidate_id' => 'bigint', 'buyer_origin_id' => 'char(36)', 'selection_hash' => 'char(64)', 'request_key' => 'char(64)', 'created_by' => 'bigint'], [['created_by', 'request_key']], ['authority_id' => self::TABLES['authority'], 'created_by' => 'users']],
            'review' => [['basis_id' => 'bigint', 'candidate_id' => 'bigint', 'buyer_origin_id' => 'char(36)', 'request_key' => 'char(64)', 'request_hash' => 'char(64)'], [['buyer_origin_id', 'request_key']], ['basis_id' => self::TABLES['basis']]],
            'order' => [['review_id' => 'bigint', 'buyer_origin_id' => 'char(36)', 'request_key' => 'char(64)', 'request_hash' => 'char(64)', 'total_minor' => 'bigint', 'line_count' => 'integer'], [['review_id'], ['buyer_origin_id', 'request_key']], ['review_id' => self::TABLES['review']]],
            'line' => [['order_id' => 'bigint', 'position' => 'integer', 'track_id' => 'bigint', 'offer_revision_id' => 'bigint', 'license_version_id' => 'bigint', 'amount_minor' => 'bigint', 'line_hash' => 'char(64)'], [['order_id', 'position'], ['order_id', 'track_id']], ['order_id' => self::TABLES['order'], 'track_id' => 'tracks', 'offer_revision_id' => 'offer_revisions', 'license_version_id' => 'license_versions']],
            'attempt' => [['order_id' => 'bigint', 'expires_at' => 'char(20)'], [['order_id']], ['order_id' => self::TABLES['order']]],
            'intent' => [['order_id' => 'bigint', 'attempt_id' => 'bigint', 'account_id' => 'varchar(255)', 'funds_mode' => 'varchar(4)', 'idempotency_key' => 'varchar(128)', 'retry_before' => 'char(20)', 'provider_expires_at' => 'char(20)'], [['order_id'], ['attempt_id'], ['idempotency_key']], ['order_id' => self::TABLES['order'], 'attempt_id' => self::TABLES['attempt']]],
            'session' => [['intent_id' => 'bigint', 'account_id' => 'varchar(255)', 'funds_mode' => 'varchar(4)', 'provider_session_id' => 'varchar(128)'], [['intent_id'], ['account_id', 'funds_mode', 'provider_session_id']], ['intent_id' => self::TABLES['intent']]],
            'observation' => [['intent_id' => 'bigint', 'sequence' => 'integer', 'kind' => 'varchar(32)'], [['intent_id', 'sequence']], ['intent_id' => self::TABLES['intent']]],
            'payment' => [['intent_id' => 'bigint', 'session_id' => 'bigint', 'order_id' => 'bigint', 'account_id' => 'varchar(255)', 'funds_mode' => 'varchar(4)', 'provider_payment_id' => 'varchar(128)', 'amount_minor' => 'bigint', 'observed_at' => 'char(20)', 'outcome' => 'varchar(32)'], [['intent_id'], ['session_id'], ['order_id'], ['account_id', 'funds_mode', 'provider_payment_id']], ['intent_id' => self::TABLES['intent'], 'session_id' => self::TABLES['session'], 'order_id' => self::TABLES['order']]],
        ];
        $definitions = [];
        foreach ($parts as $kind => [$columns, $uniques, $foreign]) {
            $prefix = 'pco_'.array_search($kind, array_keys(self::TABLES), true);
            $indexes = [$prefix.'_public' => ['public_id']];
            foreach ($uniques as $i => $fields) {
                $indexes[$prefix.'_u'.$i] = $fields;
            }
            $definitions[self::TABLES[$kind]] = ['prefix' => $prefix, 'columns' => $base + $columns, 'unique' => $indexes,
                'foreign' => $foreign + (isset($columns['candidate_id']) ? ['candidate_id' => 'production_track_capability_candidates'] : [])];
        }

        return $definitions;
    }

    public static function statements(): array
    {
        $mysql = DB::getDriverName() === 'mysql';
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
            $sql = 'CREATE TABLE '.$table.' ('.implode(', ', $parts).')'.($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '');
            $statements[$table] = ['type' => 'table', 'table' => $table, 'statement' => $sql];
            if (! $mysql) {
                foreach ($definition['unique'] as $name => $fields) {
                    $statements[$name] = ['type' => 'index', 'table' => $table, 'statement' => 'CREATE UNIQUE INDEX '.$name.' ON '.$table.' ('.implode(', ', $fields).')'];
                }
            }
        }
        foreach (self::definitions() as $table => $definition) {
            foreach (['insert', 'update', 'delete'] as $operation) {
                $name = $definition['prefix'].'_'.$operation;
                $condition = $operation === 'insert' ? self::insertCondition($definition) : null;
                if ($mysql) {
                    $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or immutable production checkout evidence';";
                    $body = 'BEGIN '.($condition === null ? $signal : 'IF NOT COALESCE(('.$condition.'), 0) THEN '.$signal.' END IF;').' END';
                    $sql = 'CREATE TRIGGER '.$name.' BEFORE '.$operation.' ON '.$table.' FOR EACH ROW '.$body;
                } else {
                    $body = null;
                    $sql = 'CREATE TRIGGER '.$name.' BEFORE '.$operation.' ON '.$table.($condition === null ? '' : ' WHEN NOT COALESCE(('.$condition.'), 0)')." BEGIN SELECT RAISE(ABORT, 'Invalid or immutable production checkout evidence'); END";
                }
                $statements[$name] = ['type' => 'trigger', 'table' => $table, 'operation' => strtoupper($operation), 'body' => $body, 'statement' => $sql];
            }
        }

        return $statements;
    }

    private static function columnSql(string $type, bool $mysql): string
    {
        if ($type === 'id') {
            return $mysql ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        }
        if (in_array($type, ['bigint', 'integer'], true)) {
            return $mysql ? strtoupper($type === 'integer' ? 'INT' : $type).' UNSIGNED NOT NULL' : 'INTEGER NOT NULL';
        }
        if ($type === 'longtext') {
            return $mysql ? 'LONGTEXT CHARACTER SET ascii COLLATE ascii_bin NOT NULL' : 'TEXT NOT NULL';
        }

        return strtoupper($type).($mysql ? ' CHARACTER SET ascii COLLATE ascii_bin' : '').' NOT NULL';
    }

    private static function insertCondition(array $definition): string
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $conditions = ['LENGTH(NEW.public_id) = 36', 'LENGTH(NEW.created_at) = 20', 'LENGTH(NEW.payload_ciphertext) BETWEEN 1 AND 2097152',
            "NEW.canonicalization_version = '".CanonicalJson::VERSION."'"];
        foreach ($definition['columns'] as $name => $type) {
            if ($type === 'char(64)') {
                $conditions[] = $sqlite ? "LENGTH(NEW.{$name}) = 64 AND NEW.{$name} NOT GLOB '*[^a-f0-9]*'"
                    : "LENGTH(NEW.{$name}) = 64 AND NEW.{$name} = LOWER(NEW.{$name}) AND NEW.{$name} NOT REGEXP '[^a-f0-9]'";
            } elseif ($type === 'bigint' || $type === 'integer') {
                $conditions[] = ($sqlite ? "TYPEOF(NEW.{$name}) = 'integer' AND " : '')."NEW.{$name} BETWEEN 1 AND 21474836470";
            }
        }
        foreach (['position', 'line_count'] as $field) {
            if (isset($definition['columns'][$field])) {
                $conditions[] = 'NEW.'.$field.' BETWEEN 1 AND 10';
            }
        }
        if (isset($definition['columns']['funds_mode'])) {
            $conditions[] = "NEW.funds_mode IN ('test', 'live')";
        }

        return implode(' AND ', $conditions);
    }
}
