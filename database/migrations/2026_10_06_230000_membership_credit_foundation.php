<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['membership_plans', 'membership_plan_versions', 'membership_credit_buckets', 'membership_credit_events'];

    private const INDEXES = ['membership_plan_version_number_unique', 'membership_credit_source_unique', 'membership_credit_event_sequence_unique', 'membership_credit_event_key_unique'];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'sqlite'], true)) {
            throw new LogicException('Membership evidence requires MySQL or SQLite.');
        }
        $this->preflight();
        // Interrupted MySQL DDL prefixes are refused on retry, never silently adopted or removed.
        Schema::create('membership_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });
        Schema::create('membership_plan_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('membership_plan_id')->constrained('membership_plans')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('title', 180);
            $table->json('policy');
            $table->char('manifest_hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['membership_plan_id', 'number'], 'membership_plan_version_number_unique');
        });
        Schema::create('membership_credit_buckets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_account_id')->constrained('customer_accounts')->restrictOnDelete();
            $table->foreignId('membership_plan_version_id')->constrained('membership_plan_versions')->restrictOnDelete();
            $table->char('source_event_hash', 64);
            $table->char('source_request_hash', 64);
            $table->unsignedInteger('allowance');
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique('source_event_hash', 'membership_credit_source_unique');
        });
        Schema::create('membership_credit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('membership_credit_bucket_id')->constrained('membership_credit_buckets')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('kind', 16);
            $table->unsignedInteger('amount');
            $table->foreignId('reservation_event_id')->nullable()->constrained('membership_credit_events')->restrictOnDelete();
            $table->foreignId('source_event_id')->nullable()->constrained('membership_credit_events')->restrictOnDelete();
            $table->char('resource_hash', 64)->nullable();
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->foreignId('previous_event_id')->nullable()->constrained('membership_credit_events')->restrictOnDelete();
            $table->char('previous_hash', 64)->nullable();
            $table->json('before_balance');
            $table->json('after_balance');
            $table->char('event_hash', 64);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('account_access_version');
            $table->timestamp('created_at');
            $table->unique(['membership_credit_bucket_id', 'sequence'], 'membership_credit_event_sequence_unique');
            $table->unique(['membership_credit_bucket_id', 'key_hash'], 'membership_credit_event_key_unique');
        });
        foreach ($this->conditions() as $table => $insert) {
            $this->guard($table, 'INSERT', $insert);
            $this->guard($table, 'UPDATE', '1=1');
            $this->guard($table, 'DELETE', '1=1');
        }
    }

    private function conditions(): array
    {
        $sql = DB::getDriverName() === 'mysql';
        $conditions = [];
        $collision = fn (string $table, string $extra = '') => "EXISTS (SELECT 1 FROM $table WHERE id=NEW.id".($extra === '' ? '' : " OR $extra").')';
        $staff = 'EXISTS (SELECT 1 FROM users WHERE id=NEW.created_by AND is_admin=1 AND email_verified_at IS NOT NULL)';
        $conditions['membership_plans'] = $collision('membership_plans')." OR NEW.created_at IS NULL OR NOT ($staff)";

        $policy = 'NEW.policy';
        $p = fn (string $field) => $this->json($policy, $field);
        $policyCount = $this->count($policy);
        $expiryType = $this->type($policy, 'validity_seconds');
        $integer = $sql ? 'INTEGER' : 'integer';
        $null = $sql ? 'NULL' : 'null';
        $boolean = $sql ? "='BOOLEAN'" : "IN ('true','false')";
        $titleLength = $sql ? 'CHAR_LENGTH(NEW.title)' : 'length(NEW.title)';
        $unit = $sql ? 'NOT REGEXP_LIKE('.$p('unit').", '^[a-z][a-z0-9_]{0,31}$', 'c')" : '(length('.$p('unit').') NOT BETWEEN 1 AND 32 OR '.$p('unit')." GLOB '*[^a-z0-9_]*' OR substr(".$p('unit').",1,1) GLOB '[^a-z]')";
        $conditions['membership_plan_versions'] = $collision('membership_plan_versions', 'membership_plan_id=NEW.membership_plan_id AND number=NEW.number')
            .' OR NEW.number != COALESCE((SELECT MAX(number) FROM membership_plan_versions WHERE membership_plan_id=NEW.membership_plan_id),0)+1 OR NEW.number > 10000'
            ." OR NEW.title IS NULL OR $titleLength NOT BETWEEN 1 AND 180 OR trim(NEW.title) != NEW.title OR NEW.created_at IS NULL OR NOT ($staff)"
            .' OR '.$this->invalidHash('NEW.manifest_hash')." OR $policyCount != 6 OR ".$p('schema_version').' != 1 OR '.$this->type($policy, 'schema_version')." != '$integer' OR $unit"
            .' OR '.$this->type($policy, 'allowance')." != '$integer' OR ".$p('allowance').' NOT BETWEEN 1 AND 1000000'
            ." OR ($expiryType NOT IN ('$null','$integer')) OR ($expiryType='$integer' AND ".$p('validity_seconds').' NOT BETWEEN 1 AND 31536000)'
            .' OR '.$p('rollover')." != 'none' OR NOT (".$this->type($policy, 'reversal_allowed').$boolean.')'
            .' OR NOT EXISTS (SELECT 1 FROM membership_plans WHERE id=NEW.membership_plan_id)';

        $allowance = $this->json('v.policy', 'allowance');
        $validity = $this->json('v.policy', 'validity_seconds');
        $expiry = $sql ? "TIMESTAMPADD(SECOND, $validity, NEW.created_at)" : "datetime(NEW.created_at, '+' || $validity || ' seconds')";
        $equalExpiry = $this->equal('NEW.expires_at', $expiry);
        $conditions['membership_credit_buckets'] = $collision('membership_credit_buckets', 'source_event_hash=NEW.source_event_hash')
            .' OR '.$this->invalidHash('NEW.source_event_hash').' OR '.$this->invalidHash('NEW.source_request_hash')
            ." OR NEW.allowance NOT BETWEEN 1 AND 1000000 OR NEW.created_at IS NULL OR NOT ($staff)"
            .' OR NOT EXISTS (SELECT 1 FROM customer_accounts AS a JOIN users AS u ON u.id=a.user_id WHERE a.id=NEW.customer_account_id AND a.active=1 AND a.access_version>=1 AND u.is_admin=0 AND u.email_verified_at IS NOT NULL)'
            ." OR NOT EXISTS (SELECT 1 FROM membership_plan_versions AS v WHERE v.id=NEW.membership_plan_version_id AND NEW.allowance=$allowance AND $equalExpiry)";

        $last = '(SELECT MAX(id) FROM membership_credit_events WHERE membership_credit_bucket_id=NEW.membership_credit_bucket_id)';
        $lastSequence = '(SELECT COALESCE(MAX(sequence),0) FROM membership_credit_events WHERE membership_credit_bucket_id=NEW.membership_credit_bucket_id)';
        $lastHash = '(SELECT event_hash FROM membership_credit_events WHERE id='.$last.')';
        $lastAt = '(SELECT created_at FROM membership_credit_events WHERE id='.$last.')';
        $bucketAllowance = '(SELECT allowance FROM membership_credit_buckets WHERE id=NEW.membership_credit_bucket_id)';
        $bucketExpiry = '(SELECT expires_at FROM membership_credit_buckets WHERE id=NEW.membership_credit_bucket_id)';
        $expired = "($bucketExpiry IS NOT NULL AND NEW.created_at >= $bucketExpiry)";
        $b = [];
        $a = [];
        $shape = [];
        foreach (['available', 'reserved', 'consumed', 'expired'] as $key) {
            $b[$key] = $this->json('NEW.before_balance', $key);
            $a[$key] = $this->json('NEW.after_balance', $key);
            $previous = '(SELECT '.$this->json('after_balance', $key).' FROM membership_credit_events WHERE id='.$last.')';
            foreach (['NEW.before_balance', 'NEW.after_balance'] as $column) {
                $shape[] = $this->type($column, $key)." != '$integer' OR ".$this->json($column, $key).' NOT BETWEEN 0 AND 1000000';
            }
            $shape[] = $b[$key]." != COALESCE($previous,0)";
        }
        $resourceEqual = $this->equal('r.resource_hash', 'NEW.resource_hash');
        $reserve = "EXISTS (SELECT 1 FROM membership_credit_events AS r WHERE r.id=NEW.reservation_event_id AND r.membership_credit_bucket_id=NEW.membership_credit_bucket_id AND r.kind='reserve' AND r.amount=NEW.amount AND $resourceEqual)";
        $terminal = "EXISTS (SELECT 1 FROM membership_credit_events WHERE reservation_event_id=NEW.reservation_event_id AND kind IN ('consume','release'))";
        $reverseAllowed = '(SELECT '.$this->json('v.policy', 'reversal_allowed').' FROM membership_credit_buckets AS b JOIN membership_plan_versions AS v ON v.id=b.membership_plan_version_id WHERE b.id=NEW.membership_credit_bucket_id)'.($sql ? "='true'" : '=1');
        $source = "EXISTS (SELECT 1 FROM membership_credit_events AS c WHERE c.id=NEW.source_event_id AND c.membership_credit_bucket_id=NEW.membership_credit_bucket_id AND c.kind='consume' AND c.reservation_event_id=NEW.reservation_event_id AND c.amount=NEW.amount AND ".$this->equal('c.resource_hash', 'NEW.resource_hash').')';
        $kinds = [
            "(NEW.kind='grant' AND NEW.sequence=1 AND NEW.amount=$bucketAllowance AND NEW.reservation_event_id IS NULL AND NEW.source_event_id IS NULL AND NEW.resource_hash IS NULL AND EXISTS (SELECT 1 FROM membership_credit_buckets AS b WHERE b.id=NEW.membership_credit_bucket_id AND NEW.created_at=b.created_at AND NEW.key_hash=b.source_event_hash AND NEW.request_hash=b.source_request_hash AND NEW.actor_id=b.created_by))",
            "(NEW.kind='reserve' AND NEW.sequence>1 AND NOT $expired AND ".$b['available'].">=NEW.amount AND NEW.reservation_event_id IS NULL AND NEW.source_event_id IS NULL AND NEW.resource_hash IS NOT NULL AND NOT EXISTS (SELECT 1 FROM membership_credit_events WHERE membership_credit_bucket_id=NEW.membership_credit_bucket_id AND kind='reserve' AND resource_hash=NEW.resource_hash))",
            "(NEW.kind IN ('consume','release') AND NEW.sequence>1 AND $reserve AND NOT $terminal AND NEW.source_event_id IS NULL AND (NEW.kind='release' OR NOT $expired))",
            "(NEW.kind='reverse' AND NEW.sequence>1 AND $reserve AND $source AND $reverseAllowed AND NOT EXISTS (SELECT 1 FROM membership_credit_events WHERE source_event_id=NEW.source_event_id AND kind='reverse'))",
            "(NEW.kind='expire' AND NEW.sequence>1 AND $expired AND NEW.amount=".$b['available'].' AND NEW.reservation_event_id IS NULL AND NEW.source_event_id IS NULL AND NEW.resource_hash IS NULL)',
        ];
        $deltas = [
            'available' => "CASE WHEN NEW.kind='grant' THEN NEW.amount WHEN NEW.kind IN ('reserve','expire') THEN -NEW.amount WHEN NEW.kind IN ('release','reverse') AND NOT $expired THEN NEW.amount ELSE 0 END",
            'reserved' => "CASE WHEN NEW.kind='reserve' THEN NEW.amount WHEN NEW.kind IN ('consume','release') THEN -NEW.amount ELSE 0 END",
            'consumed' => "CASE WHEN NEW.kind='consume' THEN NEW.amount WHEN NEW.kind='reverse' THEN -NEW.amount ELSE 0 END",
            'expired' => "CASE WHEN NEW.kind='expire' OR (NEW.kind IN ('release','reverse') AND $expired) THEN NEW.amount ELSE 0 END",
        ];
        foreach ($deltas as $key => $delta) {
            $shape[] = $a[$key].' != '.$b[$key]." + ($delta)";
        }
        $authority = "EXISTS (SELECT 1 FROM membership_credit_buckets AS b JOIN customer_accounts AS a ON a.id=b.customer_account_id JOIN users AS u ON u.id=NEW.actor_id WHERE b.id=NEW.membership_credit_bucket_id AND a.active=1 AND NEW.account_access_version=a.access_version AND u.email_verified_at IS NOT NULL AND ((NEW.kind IN ('grant','reverse','expire') AND u.is_admin=1) OR (NEW.kind IN ('reserve','consume','release') AND u.is_admin=0 AND u.id=a.user_id)))";
        $conditions['membership_credit_events'] = $collision('membership_credit_events', 'membership_credit_bucket_id=NEW.membership_credit_bucket_id AND (sequence=NEW.sequence OR key_hash=NEW.key_hash)')
            ." OR NEW.amount NOT BETWEEN 1 AND 1000000 OR NEW.sequence != $lastSequence+1 OR NEW.sequence>10000 OR NOT (".$this->equal('NEW.previous_event_id', $last).') OR NOT ('.$this->equal('NEW.previous_hash', $lastHash).')'
            .' OR '.$this->invalidHash('NEW.key_hash').' OR '.$this->invalidHash('NEW.request_hash').' OR '.$this->invalidHash('NEW.event_hash')
            .' OR (NEW.resource_hash IS NOT NULL AND '.$this->invalidHash('NEW.resource_hash').')'
            .' OR '.$this->count('NEW.before_balance').' != 4 OR '.$this->count('NEW.after_balance').' != 4 OR '.implode(' OR ', $shape)
            .' OR ('.implode('+', $a).") != $bucketAllowance OR NEW.created_at IS NULL OR (NEW.sequence>1 AND NEW.created_at<$lastAt)"
            .' OR NOT ('.implode(' OR ', $kinds).") OR NOT ($authority)";

        return $conditions;
    }

    private function guard(string $table, string $event, string $when): void
    {
        $name = $table.'_'.strtolower($event);
        $when = "COALESCE(($when),1)";
        DB::unprepared(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER $name BEFORE $event ON $table WHEN $when BEGIN SELECT RAISE(ABORT, 'Retain membership credit evidence'); END"
            : "CREATE TRIGGER $name BEFORE $event ON $table FOR EACH ROW BEGIN IF $when THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Retain membership credit evidence'; END IF; END");
    }

    private function json(string $column, string $field): string
    {
        return DB::getDriverName() === 'mysql'
            ? "CASE WHEN JSON_TYPE(JSON_EXTRACT($column, '$.$field'))='NULL' THEN NULL ELSE JSON_UNQUOTE(JSON_EXTRACT($column, '$.$field')) END"
            : "json_extract($column, '$.$field')";
    }

    private function type(string $column, string $field): string
    {
        return DB::getDriverName() === 'mysql' ? "JSON_TYPE(JSON_EXTRACT($column, '$.$field'))" : "json_type($column, '$.$field')";
    }

    private function count(string $column): string
    {
        return DB::getDriverName() === 'mysql' ? "JSON_LENGTH($column)" : "(SELECT COUNT(*) FROM json_each($column))";
    }

    private function equal(string $a, string $b): string
    {
        return DB::getDriverName() === 'mysql' ? "($a <=> $b)" : "($a IS $b)";
    }

    private function invalidHash(string $column): string
    {
        return DB::getDriverName() === 'mysql' ? "NOT REGEXP_LIKE($column, '^[a-f0-9]{64}$', 'c')" : "(length($column)!=64 OR $column GLOB '*[^a-f0-9]*')";
    }

    private function preflight(): void
    {
        $triggers = [];
        foreach (self::TABLES as $table) {
            foreach (['insert', 'update', 'delete'] as $event) {
                $triggers[] = $table.'_'.$event;
            }
        }
        $names = [...self::TABLES, ...self::INDEXES, ...$triggers];
        if (DB::getDriverName() === 'sqlite') {
            foreach (['sqlite_master', 'sqlite_temp_master'] as $catalog) {
                if (DB::table($catalog)->whereIn(DB::raw('lower(name)'), $names)->orWhereIn(DB::raw('lower(tbl_name)'), self::TABLES)->exists()) {
                    throw new LogicException('Existing or shadow membership objects require inspection; nothing was changed.');
                }
            }

            return;
        }
        $database = DB::getDatabaseName();
        if (DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', $database)->whereIn(DB::raw('LOWER(TABLE_NAME)'), self::TABLES)->exists()
            || DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', $database)->whereIn(DB::raw('LOWER(TRIGGER_NAME)'), $triggers)->exists()) {
            throw new LogicException('Existing membership objects require inspection; nothing was changed.');
        }
        foreach (self::TABLES as $table) {
            try {
                DB::selectOne('SHOW CREATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($table));
            } catch (QueryException $error) {
                if (($error->errorInfo[0] ?? null) === '42S02' && ($error->errorInfo[1] ?? null) === 1146) {
                    continue;
                }
                throw $error;
            }
            throw new LogicException('Temporary membership table requires inspection; nothing was changed.');
        }
    }

    public function down(): void
    {
        // Operational rollback is code-only. No anchor, source, movement, foreign object or guard is removed.
        // Destructive removal needs a separate independently reviewed retention migration.
    }
};
