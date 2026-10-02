<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\FulfillmentOutbox;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Support\CanonicalJson;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\ContractFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class UuidByteGuardMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    public function test_uuid_predicates_count_stored_bytes_and_preserve_existing_format_policies(): void
    {
        $migration = $this->migration();
        foreach ([
            'canonical' => [self::UUID, true, true],
            'nil UUID remains allowed' => ['00000000-0000-0000-0000-000000000000', true, true],
            'uppercase length-only' => [strtoupper(self::UUID), true, false],
            'arbitrary length-only' => [str_repeat('g', 36), true, false],
            'wrong separators' => [str_replace('-', '_', self::UUID), true, false],
            'short' => [substr(self::UUID, 0, 35), false, false],
            'long' => [self::UUID.'a', false, false],
            'NUL suffix' => [self::UUID."\0suffix", false, false],
            'NUL terminator' => [self::UUID."\0", false, false],
            'embedded NUL' => [substr(self::UUID, 0, 20)."\0".substr(self::UUID, 21), DB::getDriverName() === 'mysql', false],
            'newline suffix' => [self::UUID."\n", false, false],
            'space suffix' => [self::UUID.' ', false, false],
            'multibyte characters' => [str_repeat('é', 36), false, false],
            'null' => [null, false, false],
        ] as $case => [$value, $lengthOnly, $canonical]) {
            foreach ([false => $lengthOnly, true => $canonical] as $strict => $expected) {
                $predicate = $migration->uuidBytes('candidate', (bool) $strict);
                $actual = DB::selectOne("SELECT COALESCE(({$predicate}), 0) AS accepted FROM (SELECT ? AS candidate) probe", [$value]);
                $this->assertSame($expected, (bool) $actual->accepted, $case.'; canonical='.(int) $strict);
            }
        }
        if (DB::getDriverName() === 'sqlite') {
            foreach ([false, true] as $strict) {
                $predicate = $migration->uuidBytes('candidate', $strict);
                $actual = DB::selectOne("SELECT COALESCE(({$predicate}), 0) AS accepted FROM (SELECT CAST(? AS BLOB) AS candidate) probe", [self::UUID]);
                $this->assertFalse((bool) $actual->accepted);
            }
        }
    }

    public function test_request_and_render_claim_writes_reject_nul_tails_and_blob_values_while_nullable_states_remain_valid(): void
    {
        $fixture = ContractFixtures::paid($this->gateway());
        $attributes = $this->request($fixture);
        foreach (['public_id', 'document_public_id'] as $column) {
            foreach ($this->malformedUuids(true) as $case => $uuid) {
                $this->rejected(fn () => DB::table('contract_render_requests')->insert([...$attributes, $column => $uuid]), $case);
            }
            $this->whitespaceWrites('contract_render_requests', $column, self::UUID,
                fn (string $uuid): int => DB::table('contract_render_requests')->insertGetId([...$attributes, $column => $uuid]));
        }
        $request = DB::table('contract_render_requests')->insertGetId($attributes);
        $work = DB::table('contract_render_work')->insertGetId(['contract_render_request_id' => $request,
            'state' => 'pending', 'attempts' => 0, 'claim_token' => null, 'created_at' => now(), 'updated_at' => now()]);
        $this->migration()->up();
        $this->assertNull(DB::table('contract_render_work')->where('id', $work)->value('claim_token'));
        $claim = ['state' => 'processing', 'attempts' => 1, 'claim_token' => self::UUID,
            'lease_expires_at' => now()->addSeconds(300), 'next_attempt_at' => null, 'reason' => null, 'updated_at' => now()];
        foreach ($this->malformedUuids(true) as $case => $uuid) {
            $this->rejected(fn () => DB::table('contract_render_work')->where('id', $work)->update([...$claim, 'claim_token' => $uuid]), $case);
        }
        $this->whitespaceWrites('contract_render_work', 'claim_token', self::UUID,
            fn (string $uuid): int => tap($work, fn () => DB::table('contract_render_work')->where('id', $work)->update([...$claim, 'claim_token' => $uuid])));
        DB::table('contract_render_work')->where('id', $work)->update($claim);
        $this->migration()->up();
        $this->assertSame(self::UUID, DB::table('contract_render_work')->where('id', $work)->value('claim_token'));
        DB::table('contract_render_work')->where('id', $work)->update(['state' => 'retry', 'claim_token' => null,
            'lease_expires_at' => null, 'reason' => 'render_failed', 'next_attempt_at' => now()->addMinute(), 'updated_at' => now()]);
        $this->migration()->up();
        $this->assertNull(DB::table('contract_render_work')->where('id', $work)->value('claim_token'));
    }

    public function test_receipt_claims_keep_nullable_states_length_only_policy_and_mysql_character_coercion(): void
    {
        $work = $this->receiptWork();
        $this->assertNull(DB::table('stripe_receipt_work')->where('id', $work)->value('claim_token'));
        $claim = ['state' => 'processing', 'attempts' => 1, 'claim_token' => str_repeat('g', 36), 'lease_expires_at' => now()->addMinute()];
        foreach ($this->malformedUuids() as $case => $uuid) {
            $this->rejected(fn () => DB::table('stripe_receipt_work')->where('id', $work)->update([...$claim, 'claim_token' => $uuid]), $case);
        }
        $this->whitespaceWrites('stripe_receipt_work', 'claim_token', self::UUID,
            fn (string $uuid): int => tap($work, fn () => DB::table('stripe_receipt_work')->where('id', $work)->update([...$claim, 'claim_token' => $uuid])));
        DB::table('stripe_receipt_work')->where('id', $work)->update($claim);
        $this->migration()->up();
        $this->assertSame(str_repeat('g', 36), DB::table('stripe_receipt_work')->where('id', $work)->value('claim_token'));
        if (DB::getDriverName() === 'mysql') {
            DB::table('stripe_receipt_work')->where('id', $work)->update(['claim_token' => DB::raw("CAST('".self::UUID."' AS BINARY)")]);
            $this->assertSame(self::UUID, DB::table('stripe_receipt_work')->where('id', $work)->value('claim_token'));
        }
        DB::table('stripe_receipt_work')->where('id', $work)->update(['state' => 'processed', 'claim_token' => null, 'lease_expires_at' => null]);
        $this->migration()->up();
        $this->assertNull(DB::table('stripe_receipt_work')->where('id', $work)->value('claim_token'));
    }

    public function test_populated_upgrade_retains_originals_and_canonical_identifiers_without_weakening_lifecycle_guards(): void
    {
        $migration = $this->migration();
        $migration->down();
        $fixture = DeliveryFixtures::ready($this->gateway());
        $before = DeliveryFixtures::retained();
        $control = (array) DB::table('test_delivery_controls')->where('id', $fixture['delivery_control']->id)->sole();
        $migration->up();
        $migration->up();
        $this->assertSame($before, DeliveryFixtures::retained());
        $this->assertSame($control, (array) DB::table('test_delivery_controls')->where('id', $control['id'])->sole());
        foreach ($this->malformedUuids(true) as $case => $uuid) {
            $this->rejected(fn () => DB::table('test_delivery_controls')->where('id', $control['id'])
                ->update(['public_id' => $uuid, 'blocked' => true, 'control_version' => $control['control_version'] + 1]), $case);
        }
        $this->whitespaceWrites('test_delivery_controls', 'public_id', $control['public_id'],
            fn (string $uuid): int => tap($control['id'], fn () => DB::table('test_delivery_controls')->where('id', $control['id'])
                ->update(['public_id' => $uuid, 'blocked' => true, 'control_version' => $control['control_version'] + 1])));
        $this->assertSame($before, DeliveryFixtures::retained());
        DB::table('test_delivery_controls')->where('id', $control['id'])
            ->update(['blocked' => true, 'control_version' => $control['control_version'] + 1]);
        $this->assertSame($control['public_id'], DB::table('test_delivery_controls')->where('id', $control['id'])->value('public_id'));
        $migration->down();
        $this->rejected(fn () => DB::table('grant_contracts')->delete());
        $this->rejected(fn () => DB::table('test_delivery_controls')->where('id', $control['id'])->delete());
        $migration->up();
        $this->assertSame($before, DeliveryFixtures::retained());
    }

    public function test_preflight_reports_a_retained_request_uuid_with_no_rewrite_or_partial_installation(): void
    {
        $migration = $this->migration();
        $migration->down();
        $fixture = ContractFixtures::paid($this->gateway());
        $uuid = self::UUID."\0suffix";
        if (DB::getDriverName() === 'mysql') {
            // MySQL's declared VARCHAR(36) already rejects the SQLite NUL-tail bypass.
            // A missing legacy guard and a short value exercise retained corruption there.
            DB::unprepared('DROP TRIGGER contract_render_requests_valid_insert');
            $uuid = 'retained-invalid-uuid';
        }
        $id = DB::table('contract_render_requests')->insertGetId([...$this->request($fixture), 'public_id' => $uuid]);
        $before = (array) DB::table('contract_render_requests')->where('id', $id)->sole();
        $this->preflightRejected($migration, 'contract_render_requests.public_id');
        $this->assertSame($before, (array) DB::table('contract_render_requests')->where('id', $id)->sole());
        $this->rejected(fn () => DB::table('contract_render_requests')->where('id', $id)->delete());
    }

    public function test_preflight_reports_a_retained_nullable_claim_with_no_rewrite(): void
    {
        $migration = $this->migration();
        $migration->down();
        $id = $this->receiptWork();
        $uuid = self::UUID."\0suffix";
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER stripe_receipt_work_valid_update');
            $uuid = 'retained-invalid-claim';
        }
        DB::table('stripe_receipt_work')->where('id', $id)->update(['state' => 'processing', 'claim_token' => $uuid,
            'lease_expires_at' => now()->addMinute(), 'attempts' => 1]);
        $before = (array) DB::table('stripe_receipt_work')->where('id', $id)->sole();
        $this->preflightRejected($migration, 'stripe_receipt_work.claim_token');
        $this->assertSame($before, (array) DB::table('stripe_receipt_work')->where('id', $id)->sole());
        $this->rejected(fn () => DB::table('stripe_receipt_work')->where('id', $id)->delete());
    }

    public function test_partial_installation_retries_verify_the_expected_trigger_set_and_down_preserves_original_guards(): void
    {
        $expected = [
            'uuid_bytes_stripe_receipt_work_insert', 'uuid_bytes_stripe_receipt_work_update',
            'uuid_bytes_order_finalizations_insert', 'uuid_bytes_license_grants_insert', 'uuid_bytes_fulfillment_outbox_insert',
            'uuid_bytes_contract_render_requests_insert', 'uuid_bytes_contract_render_work_insert', 'uuid_bytes_contract_render_work_update',
            'uuid_bytes_grant_contracts_insert', 'uuid_bytes_test_fulfillment_activations_insert',
            'uuid_bytes_test_delivery_controls_insert', 'uuid_bytes_test_delivery_controls_update',
            'uuid_bytes_test_delivery_authorizations_insert', 'uuid_bytes_test_delivery_redemptions_insert',
        ];
        sort($expected);
        $this->assertSame($expected, $this->triggers());
        $migration = $this->migration();
        DB::unprepared('DROP TRIGGER uuid_bytes_contract_render_work_update');
        $migration->up();
        $migration->up();
        $this->assertSame($expected, $this->triggers());
        $migration->down();
        $this->assertSame([], $this->triggers());
        $work = $this->receiptWork();
        $this->rejected(fn () => DB::table('stripe_receipt_work')->where('id', $work)->delete());
        $migration->up();
        $this->assertSame($expected, $this->triggers());
    }

    public function test_retry_refuses_a_same_named_unrelated_trigger_without_replacing_it(): void
    {
        $migration = $this->migration();
        $migration->down();
        $name = 'uuid_bytes_stripe_receipt_work_insert';
        DB::unprepared(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE INSERT ON stripe_receipt_work BEGIN SELECT 1; END"
            : "CREATE TRIGGER {$name} BEFORE INSERT ON stripe_receipt_work FOR EACH ROW BEGIN IF 1 = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic collision'; END IF; END");
        try {
            $migration->up();
            $this->fail('An unrelated UUID trigger was trusted.');
        } catch (LogicException $error) {
            $this->assertSame("Unexpected UUID guard definition for {$name}; the existing guard is unchanged. Investigate before retrying the migration.", $error->getMessage());
        }
        $this->assertSame([$name], $this->triggers());
    }

    private function gateway(): StripePaymentGateway
    {
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        DeliveryFixtures::configure();
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer());

        return $gateway;
    }

    private function request(array $fixture): array
    {
        $profile = ['schema_version' => 1, 'purpose' => 'synthetic_uuid_schema_test'];
        $grant = $fixture['grant'];

        return ['public_id' => (string) Str::uuid(), 'license_grant_id' => $grant->id,
            'fulfillment_outbox_id' => FulfillmentOutbox::where('license_grant_id', $grant->id)->sole()->id,
            'input_hash' => $grant->render_input_hash, 'profile' => CanonicalJson::encode($profile), 'profile_hash' => CanonicalJson::hash($profile),
            'canonicalization_version' => CanonicalJson::VERSION, 'document_public_id' => (string) Str::uuid(), 'created_at' => now()];
    }

    private function receiptWork(): int
    {
        $receipt = DB::table('stripe_webhook_receipts')->insertGetId(['account_id' => 'acct_SYNTHETICUUID', 'livemode' => false,
            'event_id' => 'evt_'.Str::uuid(), 'event_type' => 'checkout.session.completed', 'object_type' => 'checkout.session',
            'provider_created_at' => time(), 'signature_timestamp' => time(), 'payload_sha256' => str_repeat('a', 64),
            'event_fingerprint' => str_repeat('b', 64), 'fingerprint_version' => 'synthetic-uuid-test',
            'payload_ciphertext' => 'synthetic schema evidence', 'received_at' => now()]);

        return DB::table('stripe_receipt_work')->insertGetId(['stripe_webhook_receipt_id' => $receipt, 'state' => 'pending',
            'claim_token' => null, 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function malformedUuids(bool $canonical = false): array
    {
        $values = [
            'NUL suffix' => self::UUID."\0suffix",
            'NUL terminator' => self::UUID."\0",
            'ordinary overflow' => self::UUID.'a',
            'multibyte characters' => str_repeat('é', 36),
            'short' => substr(self::UUID, 0, 35),
        ];
        if ($canonical) {
            $values += [
                'embedded NUL' => substr(self::UUID, 0, 20)."\0".substr(self::UUID, 21),
                'uppercase' => strtoupper(self::UUID),
                'non-hex characters' => str_repeat('g', 36),
                'wrong separators' => str_replace('-', '_', self::UUID),
            ];
        }
        if (DB::getDriverName() === 'sqlite') {
            $values['BLOB'] = DB::raw("CAST('".self::UUID."' AS BLOB)");
        }

        return $values;
    }

    /** MySQL converts a value to VARCHAR(36) before BEFORE triggers observe NEW. */
    private function whitespaceWrites(string $table, string $column, string $expected, callable $write): void
    {
        $before = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        foreach (['newline' => "\n", 'CRLF' => "\r\n", 'space' => ' '] as $case => $suffix) {
            DB::beginTransaction();
            try {
                if (DB::getDriverName() === 'sqlite') {
                    $this->rejected(fn () => $write($expected.$suffix), $table.'.'.$column.' '.$case);
                } else {
                    $id = $write($expected.$suffix);
                    $stored = DB::selectOne("SELECT {$column} AS value, HEX({$column}) AS bytes, OCTET_LENGTH({$column}) AS size FROM {$table} WHERE id = ?", [$id]);
                    $this->assertSame($expected, $stored->value, $table.'.'.$column.' '.$case);
                    $this->assertSame(strtoupper(bin2hex($expected)), $stored->bytes, $table.'.'.$column.' '.$case.' bytes');
                    $this->assertSame(36, (int) $stored->size, $table.'.'.$column.' '.$case.' size');
                }
            } finally {
                DB::rollBack();
            }
            $this->assertSame($before, DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
                $table.'.'.$column.' '.$case.' rollback');
        }
    }

    private function preflightRejected(object $migration, string $field): void
    {
        try {
            $migration->up();
            $this->fail('Malformed retained UUID was accepted.');
        } catch (LogicException $error) {
            $this->assertSame("Invalid retained UUID bytes in {$field}; evidence is unchanged. Investigate before retrying the migration.", $error->getMessage());
        }
        $this->assertSame([], $this->triggers());
    }

    public function test_rollback_preflights_late_guard_drift_before_any_drop_and_preserves_all_retained_rows(): void
    {
        $id = $this->receiptWork();
        DB::unprepared('CREATE TABLE synthetic_uuid_rollback_owner (public_id VARCHAR(36))');
        DB::table('synthetic_uuid_rollback_owner')->insert(['public_id' => self::UUID]);
        $migration = $this->migration();
        $name = 'uuid_bytes_test_delivery_redemptions_insert';
        $original = $this->triggerStatement($name);
        foreach (['body', 'table', 'timing', 'operation'] as $part) {
            $this->dropGuard($name);
            $changed = match ($part) {
                'body' => str_replace('Invalid UUID byte representation', 'Synthetic rollback drift', $original),
                'table' => str_replace('ON test_delivery_redemptions', 'ON synthetic_uuid_rollback_owner', $original),
                'timing' => str_replace('BEFORE INSERT', 'AFTER INSERT', $original),
                'operation' => str_replace('BEFORE INSERT', 'BEFORE UPDATE', $original),
            };
            $this->assertNotSame($original, $changed, $part);
            DB::unprepared($changed);
            $before = [$this->schemaRows(), $this->retainedRows()];
            $this->rollbackRejected($migration, $name);
            $this->assertSame($before, [$this->schemaRows(), $this->retainedRows()], $part.' must not remove any earlier guard or change retained evidence.');
            $this->dropGuard($name);
            DB::unprepared($original);
        }
        $migration->up();
        $this->rejected(fn () => DB::table('stripe_receipt_work')->where('id', $id)->delete());
    }

    public function test_rollback_refuses_a_late_differently_cased_guard_without_adopting_or_dropping_it(): void
    {
        $id = $this->receiptWork();
        $migration = $this->migration();
        $name = 'uuid_bytes_test_delivery_redemptions_insert';
        $original = $this->triggerStatement($name);
        $this->dropGuard($name);
        $foreign = strtoupper($name);
        DB::unprepared(str_replace($name, $foreign, $original));
        $before = [$this->schemaRows(), $this->retainedRows()];
        $this->rollbackRejected($migration, $name);
        $this->assertSame($before, [$this->schemaRows(), $this->retainedRows()]);
        $this->dropGuard($foreign);
        DB::unprepared($original);
        $migration->up();
        $this->rejected(fn () => DB::table('stripe_receipt_work')->where('id', $id)->delete());
    }

    public function test_rollback_retries_missing_guards_without_dropping_original_or_temporary_guards(): void
    {
        $id = $this->receiptWork();
        $migration = $this->migration();
        $expected = $this->triggers();
        $originalGuards = $this->originalGuards();
        $rows = $this->retainedRows();
        $this->dropGuard('uuid_bytes_order_finalizations_insert');
        $temporary = [];
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('CREATE TEMP TABLE synthetic_uuid_rollback_shadow (value TEXT)');
            DB::table('synthetic_uuid_rollback_shadow')->insert(['value' => 'retained temporary evidence']);
            DB::unprepared('CREATE TEMP TRIGGER uuid_bytes_stripe_receipt_work_insert BEFORE UPDATE ON synthetic_uuid_rollback_shadow BEGIN SELECT 1; END');
            $temporary = [$this->temporarySchema(), DB::table('synthetic_uuid_rollback_shadow')->get()->map(fn ($row): array => (array) $row)->all()];
        }
        $migration->down();
        $this->assertSame([], $this->triggers());
        $this->assertSame($originalGuards, $this->originalGuards());
        $this->assertSame($rows, $this->retainedRows());
        $beforeRetry = [$this->schemaRows(), $this->retainedRows()];
        $migration->down();
        $this->assertSame($beforeRetry, [$this->schemaRows(), $this->retainedRows()]);
        $this->rejected(fn () => DB::table('stripe_receipt_work')->where('id', $id)->delete());
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame($temporary, [$this->temporarySchema(), DB::table('synthetic_uuid_rollback_shadow')->get()->map(fn ($row): array => (array) $row)->all()]);
        }
        $migration->up();
        $migration->up();
        $this->assertSame($expected, $this->triggers());
        $this->assertSame($originalGuards, $this->originalGuards());
        $this->assertSame($rows, $this->retainedRows());
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame($temporary, [$this->temporarySchema(), DB::table('synthetic_uuid_rollback_shadow')->get()->map(fn ($row): array => (array) $row)->all()]);
        }
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_01_000030_byte_exact_uuid_guards.php');
    }

    private function rollbackRejected(object $migration, string $name): void
    {
        $ddl = [];
        $checking = true;
        DB::listen(function (QueryExecuted $event) use (&$ddl, &$checking): void {
            if ($checking && preg_match('/\A\s*(?:CREATE|DROP|ALTER)\b/i', $event->sql) === 1) {
                $ddl[] = $event->sql;
            }
        });
        try {
            $migration->down();
            $this->fail('An unowned UUID guard was removed during rollback.');
        } catch (LogicException $error) {
            $this->assertSame("Unexpected UUID guard definition for {$name}; the existing guard is unchanged. Investigate before retrying the migration.", $error->getMessage());
        } finally {
            $checking = false;
        }
        $this->assertSame([], $ddl, 'The complete guard set must pass ownership preflight before any DDL.');
    }

    private function dropGuard(string $name): void
    {
        $qualified = DB::getDriverName() === 'sqlite' ? 'main.'.$name : DB::getDatabaseName().'.'.$name;
        DB::unprepared('DROP TRIGGER '.DB::connection()->getQueryGrammar()->wrapTable($qualified));
    }

    private function triggerStatement(string $name): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->value('sql');
        }
        $guard = DB::table('information_schema.TRIGGERS')->whereRaw('CAST(TRIGGER_SCHEMA AS BINARY) = ?', [DB::getDatabaseName()])
            ->whereRaw('CAST(TRIGGER_NAME AS BINARY) = ?', [$name])->sole();

        return "CREATE TRIGGER {$name} {$guard->ACTION_TIMING} {$guard->EVENT_MANIPULATION} ON {$guard->EVENT_OBJECT_TABLE} FOR EACH ROW {$guard->ACTION_STATEMENT}";
    }

    private function schemaRows(): array
    {
        if (DB::getDriverName() === 'sqlite') {
            return DB::table('sqlite_master')->orderBy('type')->orderBy('name')->get()->map(fn ($row): array => (array) $row)->all();
        }
        $tables = DB::table('information_schema.TABLES')->whereRaw('CAST(TABLE_SCHEMA AS BINARY) = ?', [DB::getDatabaseName()])
            ->orderBy('TABLE_NAME')->pluck('TABLE_NAME')->all();

        return [array_map(fn (string $table): array => (array) DB::selectOne('SHOW CREATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($table)), $tables),
            DB::table('information_schema.TRIGGERS')->whereRaw('CAST(TRIGGER_SCHEMA AS BINARY) = ?', [DB::getDatabaseName()])
                ->orderBy('TRIGGER_NAME')->get()->map(fn ($row): array => (array) $row)->all()];
    }

    private function retainedRows(): array
    {
        $tables = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'table')->orderBy('name')->pluck('name')->all()
            : DB::table('information_schema.TABLES')->whereRaw('CAST(TABLE_SCHEMA AS BINARY) = ?', [DB::getDatabaseName()])
                ->orderBy('TABLE_NAME')->pluck('TABLE_NAME')->all();
        $retained = [];
        foreach ($tables as $table) {
            $rows = DB::table($table)->get()->map(fn ($row): array => (array) $row)->all();
            usort($rows, fn (array $left, array $right): int => strcmp(serialize($left), serialize($right)));
            $retained[$table] = $rows;
        }

        return $retained;
    }

    private function originalGuards(): array
    {
        $guards = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->orderBy('name')->get()->map(fn ($row): array => (array) $row)->all()
            : DB::table('information_schema.TRIGGERS')->whereRaw('CAST(TRIGGER_SCHEMA AS BINARY) = ?', [DB::getDatabaseName()])
                ->orderBy('TRIGGER_NAME')->get()->map(fn ($row): array => (array) $row)->all();

        $guards = array_values(array_filter($guards, fn (array $guard): bool => ! str_starts_with($guard[DB::getDriverName() === 'sqlite' ? 'name' : 'TRIGGER_NAME'], 'uuid_bytes_')));
        if (DB::getDriverName() === 'sqlite') {
            return $guards;
        }

        // MySQL renumbers ACTION_ORDER after a DROP. Preserve every other raw catalog
        // field and verify the surviving execution order within each table/event/timing.
        $definitions = [];
        $order = [];
        foreach ($guards as $guard) {
            $group = json_encode([$guard['EVENT_OBJECT_SCHEMA'], $guard['EVENT_OBJECT_TABLE'], $guard['ACTION_TIMING'], $guard['EVENT_MANIPULATION']], JSON_THROW_ON_ERROR);
            $ordinal = (int) $guard['ACTION_ORDER'];
            $this->assertGreaterThan(0, $ordinal);
            $this->assertArrayNotHasKey($ordinal, $order[$group] ?? []);
            $order[$group][$ordinal] = $guard['TRIGGER_NAME'];
            unset($guard['ACTION_ORDER']);
            $definitions[] = $guard;
        }
        ksort($order);
        foreach ($order as &$names) {
            ksort($names, SORT_NUMERIC);
            $names = array_values($names);
        }
        unset($names);

        return [$definitions, $order];
    }

    private function temporarySchema(): array
    {
        return DB::table('sqlite_temp_master')->orderBy('type')->orderBy('name')->get()->map(fn ($row): array => (array) $row)->all();
    }

    private function triggers(): array
    {
        $names = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', 'uuid_bytes_%')->pluck('name')->all()
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
                ->where('TRIGGER_NAME', 'like', 'uuid_bytes_%')->pluck('TRIGGER_NAME')->all();
        sort($names);

        return $names;
    }

    private function rejected(callable $operation, string $case = ''): void
    {
        try {
            $operation();
            $this->fail('Invalid or immutable UUID evidence was accepted. '.$case);
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
