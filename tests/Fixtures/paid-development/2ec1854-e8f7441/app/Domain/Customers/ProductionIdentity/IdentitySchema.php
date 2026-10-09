<?php

namespace App\Domain\Customers\ProductionIdentity;

/** Additive v1 identity provenance/outbox. Every table and terminal event is retained. */
final class IdentitySchema
{
    public static function definitions(): array
    {
        $identity = ['public_id' => 'uuid', 'provenance' => 'scope', 'identity_policy_version' => 'version', 'identity_policy_hash' => 'hash'];
        $definitions = [
            'production_identity_addresses' => ['columns' => ['address_hash' => 'hash'], 'unique' => ['pia_address_unique' => ['address_hash']], 'foreign' => []],
            'production_identity_challenges' => [
                'columns' => $identity + ['address_id' => 'bigint', 'purpose' => 'purpose', 'request_hash' => 'hash', 'recipient_hmac' => 'hash',
                    'payload_ciphertext' => 'text', 'payload_hash' => 'hash', 'proof_hash' => 'hash', 'bound_user_id' => 'bigint',
                    'bound_account_id' => 'bigint', 'bound_origin_id' => 'bigint', 'bound_access_version' => 'integer',
                    'bound_credential_binding' => 'hash', 'availability' => 'state', 'created_at' => 'date', 'expires_at' => 'date', 'challenge_hash' => 'hash'],
                'unique' => ['pic_uuid_unique' => ['public_id'], 'pic_request_unique' => ['request_hash']],
                'foreign' => ['pic_address_fk' => ['address_id', 'production_identity_addresses']],
            ],
            'production_identity_origins' => [
                'columns' => $identity + ['account_id' => 'bigint', 'user_id' => 'bigint', 'address_id' => 'bigint',
                    'initial_challenge_id' => 'bigint', 'owner_digest' => 'hash', 'recipient_hmac' => 'hash', 'created_at' => 'date', 'origin_hash' => 'hash'],
                'unique' => ['pio_uuid_unique' => ['public_id'], 'pio_account_unique' => ['account_id'], 'pio_user_unique' => ['user_id'], 'pio_challenge_unique' => ['initial_challenge_id']],
                'foreign' => ['pio_account_fk' => ['account_id', 'customer_accounts'], 'pio_user_fk' => ['user_id', 'users'],
                    'pio_address_fk' => ['address_id', 'production_identity_addresses'], 'pio_challenge_fk' => ['initial_challenge_id', 'production_identity_challenges']],
            ],
            'production_identity_verifications' => [
                'columns' => $identity + ['origin_id' => 'bigint', 'account_id' => 'bigint', 'user_id' => 'bigint', 'challenge_id' => 'bigint',
                    'sequence' => 'integer', 'purpose' => 'purpose', 'recipient_hmac' => 'hash', 'proof_hash' => 'hash',
                    'credential_binding' => 'hash', 'completion_hash' => 'hash', 'challenge_hash' => 'hash',
                    'prior_observation_hash' => 'hash', 'created_at' => 'date', 'observation_hash' => 'hash'],
                'unique' => ['piv_uuid_unique' => ['public_id'], 'piv_challenge_unique' => ['challenge_id'], 'piv_sequence_unique' => ['origin_id', 'sequence']],
                'foreign' => ['piv_origin_fk' => ['origin_id', 'production_identity_origins'], 'piv_account_fk' => ['account_id', 'customer_accounts'],
                    'piv_user_fk' => ['user_id', 'users'], 'piv_challenge_fk' => ['challenge_id', 'production_identity_challenges']],
            ],
            'production_identity_notices' => [
                'columns' => $identity + ['challenge_id' => 'bigint', 'challenge_hash' => 'hash', 'template_version' => 'version',
                    'created_at' => 'date', 'notice_hash' => 'hash'],
                'unique' => ['pin_uuid_unique' => ['public_id'], 'pin_challenge_unique' => ['challenge_id']],
                'foreign' => ['pin_challenge_fk' => ['challenge_id', 'production_identity_challenges']],
            ],
            'production_identity_attempts' => [
                'columns' => ['public_id' => 'uuid', 'notice_id' => 'bigint', 'number' => 'integer', 'token_hash' => 'hash',
                    'notice_hash' => 'hash', 'started_at' => 'date', 'lease_expires_at' => 'date'],
                'unique' => ['pita_uuid_unique' => ['public_id'], 'pita_number_unique' => ['notice_id', 'number']],
                'foreign' => ['pita_notice_fk' => ['notice_id', 'production_identity_notices']],
            ],
            'production_identity_outcomes' => [
                'columns' => ['public_id' => 'uuid', 'attempt_id' => 'bigint', 'status' => 'state', 'reason' => 'version',
                    'receipt_hash' => 'hash', 'created_at' => 'date', 'next_attempt_at' => 'date'],
                'unique' => ['pito_uuid_unique' => ['public_id'], 'pito_attempt_unique' => ['attempt_id']],
                'foreign' => ['pito_attempt_fk' => ['attempt_id', 'production_identity_attempts']],
            ],
        ];

        return $definitions;
    }

    public static function tableSql(string $table, string $driver): string
    {
        $definition = self::definitions()[$table] ?? throw new IdentityException;
        $columns = [$driver === 'sqlite' ? '`id` integer PRIMARY KEY AUTOINCREMENT NOT NULL' : '`id` bigint unsigned NOT NULL AUTO_INCREMENT'];
        foreach ($definition['columns'] as $name => $type) {
            $columns[] = '`'.$name.'` '.self::columnSql($type, $driver).' NOT NULL';
        }
        if ($driver === 'mysql') {
            $columns[] = 'PRIMARY KEY (`id`)';
        }
        foreach ($definition['unique'] as $name => $fields) {
            $columns[] = ($driver === 'sqlite' ? 'CONSTRAINT `'.$name.'` UNIQUE' : 'UNIQUE KEY `'.$name.'`').' (`'.implode('`,`', $fields).'`)';
        }
        foreach ($definition['foreign'] as $name => [$column, $parent]) {
            if ($driver === 'mysql' && ! in_array([$column], $definition['unique'], true)) {
                $columns[] = 'KEY `'.$name.'` (`'.$column.'`)';
            }
            $columns[] = 'CONSTRAINT `'.$name.'` FOREIGN KEY (`'.$column.'`) REFERENCES `'.$parent.'` (`id`) ON DELETE RESTRICT ON UPDATE NO ACTION';
        }

        return 'CREATE TABLE `'.$table.'` ('.implode(', ', $columns).')'.($driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '');
    }

    public static function columnSql(string $type, string $driver): string
    {
        return match ($type) {
            'bigint' => $driver === 'sqlite' ? 'integer' : 'bigint unsigned',
            'integer' => $driver === 'sqlite' ? 'integer' : 'int unsigned',
            'date' => 'datetime', 'text' => $driver === 'sqlite' ? 'text' : 'longtext',
            default => 'varchar('.match ($type) {
                'uuid' => 36, 'hash', 'version' => 64, 'scope', 'state' => 32, 'purpose' => 8
            }.')'
                .($driver === 'mysql' ? ' CHARACTER SET ascii COLLATE ascii_bin' : ''),
        };
    }

    public static function guards(string $driver): array
    {
        $guards = [];
        foreach (self::definitions() as $table => $definition) {
            $invalid = ['EXISTS (SELECT 1 FROM `'.$table.'` WHERE id=NEW.id)'];
            foreach ($definition['unique'] as $columns) {
                $invalid[] = 'EXISTS (SELECT 1 FROM `'.$table.'` WHERE '.implode(' AND ', array_map(fn ($column) => '`'.$column.'`=NEW.`'.$column.'`', $columns)).')';
            }
            foreach ($definition['columns'] as $column => $type) {
                if ($type === 'hash') {
                    $invalid[] = $driver === 'sqlite' ? "length(NEW.$column)!=64 OR length(CAST(NEW.$column AS BLOB))!=64 OR NEW.$column GLOB '*[^0-9a-f]*'"
                        : "OCTET_LENGTH(NEW.$column)!=64 OR NOT REGEXP_LIKE(NEW.$column,'^[0-9a-f]{64}$','c')";
                } elseif ($type === 'uuid') {
                    $invalid[] = $driver === 'sqlite' ? "length(NEW.$column)!=36 OR length(CAST(NEW.$column AS BLOB))!=36 OR NEW.$column GLOB '*[^0-9a-f-]*' OR substr(NEW.$column,9,1)!='-' OR substr(NEW.$column,14,2)!='-4' OR substr(NEW.$column,19,1)!='-' OR substr(NEW.$column,20,1) NOT IN ('8','9','a','b') OR substr(NEW.$column,24,1)!='-' OR length(replace(NEW.$column,'-',''))!=32"
                        : "OCTET_LENGTH(NEW.$column)!=36 OR NOT REGEXP_LIKE(NEW.$column,'^[0-9a-f]{8}-[0-9a-f]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$','c')";
                } elseif ($type === 'date') {
                    $invalid[] = $driver === 'sqlite' ? "length(NEW.$column)!=19 OR NEW.$column IS NOT strftime('%Y-%m-%d %H:%M:%S',NEW.$column)"
                        : "NEW.$column<'1000-01-01 00:00:00' OR NEW.$column>'9999-12-31 23:59:59'";
                } elseif (in_array($type, ['bigint', 'integer'], true)) {
                    $invalid[] = $driver === 'sqlite' ? "typeof(NEW.$column)!='integer' OR NEW.$column<0" : "NEW.$column<0";
                }
            }
            $invalid[] = self::rules($table, $driver);
            foreach (['insert' => implode(' OR ', array_map(fn ($rule) => '('.$rule.')', $invalid)), 'update' => '1=1', 'delete' => '1=1'] as $operation => $condition) {
                $name = 'pi_'.str_replace('production_identity_', '', $table).'_'.$operation;
                $body = "BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Production identity evidence is retained'; END IF; END";
                $sql = $driver === 'sqlite' ? "CREATE TRIGGER `$name` BEFORE $operation ON `$table` WHEN $condition BEGIN SELECT RAISE(ABORT,'Production identity evidence is retained'); END"
                    : "CREATE TRIGGER `$name` BEFORE $operation ON `$table` FOR EACH ROW $body";
                $guards[$name] = ['table' => $table, 'operation' => strtoupper($operation), 'body' => $body, 'sql' => $sql];
            }
        }

        return $guards;
    }

    private static function rules(string $table, string $driver): string
    {
        $b = fn ($column) => $driver === 'mysql' ? 'BINARY NEW.'.$column : 'NEW.'.$column;
        $scope = $b('provenance')." NOT IN ('synthetic_rehearsal','verified_production') OR ".$b('identity_policy_version')."!='".IdentityPolicy::VERSION."'";
        $zero = IdentityEvidence::EMPTY_HASH;

        return match ($table) {
            'production_identity_addresses' => '1=0',
            'production_identity_challenges' => $scope.' OR '.$b('purpose')." NOT IN ('enroll','recover') OR ".$b('availability')." NOT IN ('pending','unavailable') OR NEW.expires_at<=NEW.created_at OR length(NEW.payload_ciphertext)>16384 OR ((".$b('purpose')."='enroll' OR ".$b('availability')."='unavailable') AND (NEW.bound_user_id!=0 OR NEW.bound_account_id!=0 OR NEW.bound_origin_id!=0 OR NEW.bound_access_version!=0 OR NEW.bound_credential_binding!='$zero')) OR (".$b('purpose')."='recover' AND ".$b('availability')."='pending' AND NOT EXISTS (SELECT 1 FROM production_identity_origins o JOIN customer_accounts a ON a.id=o.account_id JOIN users u ON u.id=o.user_id WHERE o.id=NEW.bound_origin_id AND a.id=NEW.bound_account_id AND u.id=NEW.bound_user_id AND a.access_version=NEW.bound_access_version AND a.active=1 AND u.is_admin=0 AND u.email_verified_at IS NOT NULL))",
            'production_identity_origins' => $scope.' OR NOT EXISTS (SELECT 1 FROM customer_accounts a JOIN users u ON u.id=a.user_id JOIN production_identity_challenges c ON c.id=NEW.initial_challenge_id WHERE a.id=NEW.account_id AND u.id=NEW.user_id AND a.active=1 AND a.access_version=1 AND u.is_admin=0 AND u.email_verified_at IS NOT NULL AND c.address_id=NEW.address_id AND c.purpose=\'enroll\' AND c.availability=\'pending\' AND c.provenance=NEW.provenance AND c.identity_policy_hash=NEW.identity_policy_hash AND c.recipient_hmac=NEW.recipient_hmac AND NEW.created_at>=c.created_at AND NEW.created_at<c.expires_at)',
            'production_identity_verifications' => $scope.' OR NEW.sequence<1 OR NEW.sequence>128 OR NEW.sequence!=(SELECT COUNT(*)+1 FROM production_identity_verifications WHERE origin_id=NEW.origin_id) OR NOT EXISTS (SELECT 1 FROM production_identity_origins o JOIN customer_accounts a ON a.id=o.account_id JOIN users u ON u.id=o.user_id JOIN production_identity_challenges c ON c.id=NEW.challenge_id WHERE o.id=NEW.origin_id AND a.id=NEW.account_id AND u.id=NEW.user_id AND a.active=1 AND u.is_admin=0 AND u.email_verified_at IS NOT NULL AND c.availability=\'pending\' AND c.purpose=NEW.purpose AND c.provenance=NEW.provenance AND o.provenance=NEW.provenance AND c.identity_policy_hash=NEW.identity_policy_hash AND o.identity_policy_hash=NEW.identity_policy_hash AND c.proof_hash=NEW.proof_hash AND c.challenge_hash=NEW.challenge_hash AND c.recipient_hmac=NEW.recipient_hmac AND NEW.created_at>=c.created_at AND NEW.created_at<c.expires_at AND ((NEW.sequence=1 AND c.purpose=\'enroll\' AND o.initial_challenge_id=c.id AND NEW.prior_observation_hash=\''.$zero.'\') OR (NEW.sequence>1 AND c.purpose=\'recover\' AND c.bound_origin_id=o.id AND c.bound_user_id=u.id AND c.bound_account_id=a.id AND c.bound_access_version=a.access_version AND EXISTS (SELECT 1 FROM production_identity_verifications p WHERE p.origin_id=NEW.origin_id AND p.sequence=NEW.sequence-1 AND p.observation_hash=NEW.prior_observation_hash))))',
            'production_identity_notices' => $scope.' OR NOT EXISTS (SELECT 1 FROM production_identity_challenges c WHERE c.id=NEW.challenge_id AND c.availability=\'pending\' AND c.challenge_hash=NEW.challenge_hash AND c.provenance=NEW.provenance AND c.identity_policy_hash=NEW.identity_policy_hash AND NEW.created_at=c.created_at AND ((c.purpose=\'enroll\' AND '.$b('template_version')."='identity-enroll-v1') OR (c.purpose='recover' AND ".$b('template_version')."='identity-recover-v1')))",
            'production_identity_attempts' => "NEW.number<1 OR NEW.number>3 OR NEW.lease_expires_at<=NEW.started_at OR NOT EXISTS (SELECT 1 FROM production_identity_notices n WHERE n.id=NEW.notice_id AND n.notice_hash=NEW.notice_hash) OR NEW.number!=(SELECT COUNT(*)+1 FROM production_identity_attempts WHERE notice_id=NEW.notice_id) OR (NEW.number>1 AND NOT EXISTS (SELECT 1 FROM production_identity_attempts p JOIN production_identity_outcomes r ON r.attempt_id=p.id WHERE p.notice_id=NEW.notice_id AND p.number=NEW.number-1 AND r.status='definitely_not_submitted' AND NEW.started_at>=r.next_attempt_at))",
            'production_identity_outcomes' => $b('status')." NOT IN ('accepted','definitely_not_submitted','unknown','blocked') OR NOT EXISTS (SELECT 1 FROM production_identity_attempts a WHERE a.id=NEW.attempt_id AND NEW.created_at>=a.started_at AND ((".$b('status')."='accepted' AND ".$b('reason')."='smtp_accepted' AND NEW.receipt_hash!='$zero' AND NEW.created_at<a.lease_expires_at AND NEW.next_attempt_at=NEW.created_at) OR (".$b('status')."='definitely_not_submitted' AND ".$b('reason')."='transport_not_submitted' AND NEW.receipt_hash='$zero' AND NEW.created_at<a.lease_expires_at AND NEW.next_attempt_at=".($driver === 'sqlite' ? "datetime(NEW.created_at,'+'||(30*a.number)||' seconds')" : 'DATE_ADD(NEW.created_at,INTERVAL (30*a.number) SECOND)').') OR ('.$b('status')."='unknown' AND NEW.receipt_hash='$zero' AND NEW.next_attempt_at=NEW.created_at AND ((".$b('reason')."='transport_uncertain' AND NEW.created_at<a.lease_expires_at) OR (".$b('reason')."='lease_expired' AND NEW.created_at>=a.lease_expires_at))) OR (".$b('status')."='blocked' AND ".$b('reason')." IN ('authority_withdrawn','configuration_withdrawn','retry_exhausted') AND NEW.receipt_hash='$zero' AND NEW.created_at<a.lease_expires_at AND NEW.next_attempt_at=NEW.created_at)))",
        };
    }
}
