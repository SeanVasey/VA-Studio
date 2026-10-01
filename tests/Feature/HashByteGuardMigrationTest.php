<?php

namespace Tests\Feature;

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\Models\GrantContract;
use App\Models\User;
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

class HashByteGuardMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_hash_predicates_count_bytes_and_retain_each_existing_format_policy(): void
    {
        $migration = $this->migration();
        foreach ([
            'lowercase' => [str_repeat('a', 64), true, true],
            'uppercase length-only' => [str_repeat('A', 64), true, false],
            'nonhex length-only' => [str_repeat('g', 64), true, false],
            'short' => [str_repeat('a', 63), false, false],
            'long' => [str_repeat('a', 65), false, false],
            'NUL suffix' => [str_repeat('a', 64)."\0suffix", false, false],
            'NUL terminator' => [str_repeat('a', 64)."\0", false, false],
            'newline suffix' => [str_repeat('a', 64)."\n", false, false],
            'CRLF suffix' => [str_repeat('a', 64)."\r\n", false, false],
            'space suffix' => [str_repeat('a', 64).' ', false, false],
            'multibyte characters' => [str_repeat('é', 64), false, false],
            'null' => [null, false, false],
        ] as $case => [$value, $lengthOnly, $hexadecimal]) {
            foreach ([false => $lengthOnly, true => $hexadecimal] as $hex => $expected) {
                $predicate = $migration->hashBytes('candidate', (bool) $hex);
                $actual = DB::selectOne("SELECT COALESCE(({$predicate}), 0) AS accepted FROM (SELECT ? AS candidate) probe", [$value]);
                $this->assertSame($expected, (bool) $actual->accepted, $case.'; hexadecimal='.(int) $hex);
            }
        }
        if (DB::getDriverName() === 'sqlite') {
            foreach ([false, true] as $hex) {
                $predicate = $migration->hashBytes('candidate', $hex);
                $actual = DB::selectOne("SELECT COALESCE(({$predicate}), 0) AS accepted FROM (SELECT CAST(? AS BLOB) AS candidate) probe", [str_repeat('a', 64)]);
                $this->assertFalse((bool) $actual->accepted, 'A SQLite BLOB must not impersonate a text hash.');
            }
        }
    }

    public function test_direct_image_inserts_reject_nul_suffixes_and_multibyte_lengths_and_preserve_mysql_text_coercion(): void
    {
        $uploader = User::factory()->create()->id;
        foreach ($this->malformedHashes() as $case => $hash) {
            $this->rejected(fn () => DB::table('site_images')->insert($this->image($uploader, ['source_sha256' => $hash])), 'site_images.source_sha256: '.$case);
        }
        $this->whitespaceWrites('site_images', 'source_sha256', str_repeat('a', 64),
            fn ($hash) => DB::table('site_images')->insertGetId($this->image($uploader, ['source_sha256' => $hash])));
        $binary = DB::raw("CAST('".str_repeat('a', 64)."' AS ".(DB::getDriverName() === 'sqlite' ? 'BLOB' : 'BINARY').')');
        if (DB::getDriverName() === 'sqlite') {
            $this->rejected(fn () => DB::table('site_images')->insert($this->image($uploader, ['source_sha256' => $binary])));
        } else {
            // MySQL converts an ASCII binary expression to the declared character column. That
            // valid stored text remains supported; SQLite preserves BLOB storage and must reject it.
            $id = DB::table('site_images')->insertGetId($this->image($uploader, ['source_sha256' => $binary]));
            $this->assertSame(str_repeat('a', 64), DB::table('site_images')->where('id', $id)->value('source_sha256'));
        }
        $id = DB::table('site_images')->insertGetId($this->image($uploader));
        $this->assertSame(str_repeat('a', 64), DB::table('site_images')->where('id', $id)->value('source_sha256'));
    }

    public function test_ready_transition_and_variant_inserts_require_byte_exact_hashes(): void
    {
        $id = DB::table('site_images')->insertGetId($this->image(User::factory()->create()->id));
        DB::table('site_images')->where('id', $id)->update(['status' => 'processing', 'attempts' => 1,
            'claim_token' => (string) Str::uuid(), 'claimed_until' => now()->addMinutes(5)]);
        $variant = ['site_image_id' => $id, 'format' => 'jpeg', 'width' => 1200, 'height' => 630,
            'storage_path' => 'site-images/revisions/'.Str::uuid().'/share.jpg', 'sha256' => str_repeat('b', 64),
            'size_bytes' => 10, 'created_at' => now()];
        foreach ($this->malformedHashes() as $case => $hash) {
            $this->rejected(fn () => DB::table('site_image_variants')->insert([...$variant, 'sha256' => $hash]), 'site_image_variants.sha256: '.$case);
        }
        $this->whitespaceWrites('site_image_variants', 'sha256', $variant['sha256'],
            fn ($hash) => DB::table('site_image_variants')->insertGetId([...$variant, 'sha256' => $hash]));
        DB::table('site_image_variants')->insert($variant);
        $ready = ['status' => 'ready', 'claim_token' => null, 'claimed_until' => null, 'failure_code' => null,
            'manifest_sha256' => str_repeat('c', 64), 'profile_fingerprint' => str_repeat('d', 64),
            'profile_version' => 'synthetic-schema-test', 'processed_at' => now(), 'evidence' => '{}'];
        foreach (['manifest_sha256', 'profile_fingerprint'] as $column) {
            foreach ($this->malformedHashes() as $case => $hash) {
                $this->rejected(fn () => DB::table('site_images')->where('id', $id)->update([...$ready, $column => $hash]), 'site_images.'.$column.': '.$case);
            }
            $this->whitespaceWrites('site_images', $column, $ready[$column],
                fn ($hash) => tap($id, fn () => DB::table('site_images')->where('id', $id)->update([...$ready, $column => $hash])));
            $this->assertSame('processing', DB::table('site_images')->where('id', $id)->value('status'));
        }
        DB::table('site_images')->where('id', $id)->update($ready);
        $this->assertSame('ready', DB::table('site_images')->where('id', $id)->value('status'));
    }

    public function test_populated_upgrade_preserves_originals_and_delivery_guards_reject_malformed_new_evidence(): void
    {
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        DeliveryFixtures::configure();
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer());
        $migration = $this->migration();
        $migration->down();
        // The ordinary pre-upgrade domain flow creates all original payment/license/contract
        // evidence. Upgrading must retain every byte and keep later valid authorization possible.
        $fixture = DeliveryFixtures::ready($gateway);
        $before = DeliveryFixtures::retained();
        $migration->up();
        $this->assertSame($before, DeliveryFixtures::retained());
        $control = $fixture['delivery_control'];
        $contract = GrantContract::where('license_grant_id', $fixture['grant']->id)->sole();
        $attributes = ['public_id' => (string) Str::uuid(), 'order_id' => $fixture['order']->id,
            'test_fulfillment_activation_id' => $fixture['fulfillment_activation']->id, 'test_delivery_control_id' => $control->id,
            'control_version' => $control->control_version, 'owner_key' => $fixture['order']->owner_key,
            'token_hash' => hash('sha256', 'synthetic-token'), 'idempotency_key_hash' => hash('sha256', 'synthetic-idempotency'),
            'request_hash' => hash('sha256', 'synthetic-request'), 'kind' => 'contract', 'license_grant_id' => $fixture['grant']->id,
            'grant_contract_id' => $contract->id, 'pending_entitlement_id' => null,
            'policy_version' => 'test-owner-delivery-v1', 'issued_at' => now(), 'expires_at' => now()->addSeconds(60),
            'evidence_ciphertext' => 'synthetic schema evidence', 'evidence_hash' => hash('sha256', 'synthetic schema evidence'),
            'canonicalization_version' => 'vasey-json-v1'];
        foreach (['token_hash', 'idempotency_key_hash', 'request_hash', 'evidence_hash'] as $column) {
            foreach ($this->malformedHashes(true) as $case => $hash) {
                $this->rejected(fn () => DB::table('test_delivery_authorizations')->insert([...$attributes, $column => $hash]), 'test_delivery_authorizations.'.$column.': '.$case);
            }
            $this->whitespaceWrites('test_delivery_authorizations', $column, $attributes[$column],
                fn ($hash) => DB::table('test_delivery_authorizations')->insertGetId([...$attributes, $column => $hash]));
            $this->assertSame($before, DeliveryFixtures::retained(), 'Authorization probes must not change historical evidence.');
        }
        $authorization = DB::table('test_delivery_authorizations')->insertGetId($attributes);
        $redemption = ['public_id' => (string) Str::uuid(), 'test_delivery_authorization_id' => $authorization,
            'control_version' => $control->control_version, 'content_hash' => $contract->pdf_hash, 'size_bytes' => $contract->size_bytes,
            'evidence_ciphertext' => 'synthetic schema evidence', 'evidence_hash' => hash('sha256', 'synthetic schema evidence'),
            'canonicalization_version' => 'vasey-json-v1', 'redeemed_at' => now()];
        foreach ($this->malformedHashes(true) as $case => $hash) {
            $this->rejected(fn () => DB::table('test_delivery_redemptions')->insert([...$redemption, 'evidence_hash' => $hash]), 'test_delivery_redemptions.evidence_hash: '.$case);
        }
        $this->whitespaceWrites('test_delivery_redemptions', 'evidence_hash', $redemption['evidence_hash'],
            fn ($hash) => DB::table('test_delivery_redemptions')->insertGetId([...$redemption, 'evidence_hash' => $hash]));
        $this->assertSame($before, DeliveryFixtures::retained(), 'Redemption probes must not change historical evidence.');
        DB::table('test_delivery_redemptions')->insert($redemption);
        $this->assertSame($before, DeliveryFixtures::retained());
        $migration->down();
        $this->rejected(fn () => DB::table('test_delivery_authorizations')->where('id', $authorization)->delete());
        $this->rejected(fn () => DB::table('grant_contracts')->where('id', $contract->id)->update(['pdf_hash' => str_repeat('f', 64)]));
        $migration->up();
        $this->assertSame($before, DeliveryFixtures::retained());
    }

    public function test_preflight_reports_malformed_retained_evidence_before_installing_any_guard(): void
    {
        $migration = $this->migration();
        $migration->down();
        // SQLite's original length-only guard actually admits this NUL-suffixed value. MySQL's
        // byte length already rejects it; simulate a missing legacy guard there to exercise
        // the same preflight boundary without claiming the SQLite bypass exists on MySQL.
        $hash = str_repeat('a', 64)."\0suffix";
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER site_images_valid_insert');
            $hash = 'retained-invalid-hash';
        }
        $id = DB::table('site_images')->insertGetId($this->image(User::factory()->create()->id, ['source_sha256' => $hash]));
        $before = (array) DB::table('site_images')->where('id', $id)->sole();
        try {
            $migration->up();
            $this->fail('Malformed retained evidence was silently accepted.');
        } catch (LogicException $error) {
            $this->assertSame('Invalid retained hash bytes in site_images.source_sha256; evidence is unchanged. Investigate before retrying the migration.', $error->getMessage());
        }
        $this->assertSame([], $this->triggers());
        $this->assertSame($before, (array) DB::table('site_images')->where('id', $id)->sole());
        $this->rejected(fn () => DB::table('site_images')->where('id', $id)->delete());
    }

    public function test_partial_installation_can_resume_and_rollback_only_removes_supplemental_guards(): void
    {
        $migration = $this->migration();
        $expected = [
            'hash_bytes_contract_render_requests_insert', 'hash_bytes_grant_contracts_insert',
            'hash_bytes_license_grants_insert', 'hash_bytes_license_review_evidence_insert', 'hash_bytes_license_versions_update',
            'hash_bytes_order_finalizations_insert', 'hash_bytes_pending_entitlements_insert',
            'hash_bytes_site_image_variants_insert', 'hash_bytes_site_images_insert', 'hash_bytes_site_images_update',
            'hash_bytes_test_delivery_authorizations_insert', 'hash_bytes_test_delivery_redemptions_insert',
            'hash_bytes_test_fulfillment_activations_insert',
        ];
        sort($expected);
        $this->assertSame($expected, $this->triggers());
        DB::unprepared('DROP TRIGGER hash_bytes_site_images_update');
        $migration->up();
        $migration->up();
        $this->assertSame($expected, $this->triggers());
        $migration->down();
        $this->assertSame([], $this->triggers());
        $id = DB::table('site_images')->insertGetId($this->image(User::factory()->create()->id));
        $this->rejected(fn () => DB::table('site_images')->where('id', $id)->delete());
        $migration->up();
        $this->assertSame($expected, $this->triggers());
    }

    public function test_retry_refuses_a_same_named_trigger_with_a_different_definition(): void
    {
        $migration = $this->migration();
        $migration->down();
        $name = 'hash_bytes_license_versions_update';
        $statement = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE UPDATE ON license_versions BEGIN SELECT 1; END"
            : "CREATE TRIGGER {$name} BEFORE UPDATE ON license_versions FOR EACH ROW BEGIN IF 1 = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic collision'; END IF; END";
        DB::unprepared($statement);
        try {
            $migration->up();
            $this->fail('A same-named unrelated trigger was trusted.');
        } catch (LogicException $error) {
            $this->assertSame("Unexpected hash guard definition for {$name}; the existing guard is unchanged. Investigate before retrying the migration.", $error->getMessage());
        }
        $this->assertSame([$name], $this->triggers());
        $actual = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('name', $name)->value('sql')
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', $name)->value('ACTION_STATEMENT');
        $this->assertStringContainsString(DB::getDriverName() === 'sqlite' ? 'SELECT 1;' : 'Synthetic collision', $actual);
    }

    public function test_rollback_preflights_late_guard_drift_before_any_drop_and_preserves_all_retained_rows(): void
    {
        $id = DB::table('site_images')->insertGetId($this->image(User::factory()->create()->id));
        DB::unprepared('CREATE TABLE synthetic_hash_rollback_owner (sha256 VARCHAR(64))');
        DB::table('synthetic_hash_rollback_owner')->insert(['sha256' => str_repeat('a', 64)]);
        $migration = $this->migration();
        $name = 'hash_bytes_site_image_variants_insert';
        $original = $this->triggerStatement($name);
        foreach (['body', 'table', 'timing', 'operation'] as $part) {
            $this->dropGuard($name);
            $changed = match ($part) {
                'body' => str_replace('Invalid hash byte representation', 'Synthetic rollback drift', $original),
                'table' => str_replace('ON site_image_variants', 'ON synthetic_hash_rollback_owner', $original),
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
        $this->rejected(fn () => DB::table('site_images')->where('id', $id)->delete());
    }

    public function test_rollback_refuses_a_late_differently_cased_guard_without_adopting_or_dropping_it(): void
    {
        $id = DB::table('site_images')->insertGetId($this->image(User::factory()->create()->id));
        $migration = $this->migration();
        $name = 'hash_bytes_site_image_variants_insert';
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
        $this->rejected(fn () => DB::table('site_images')->where('id', $id)->delete());
    }

    public function test_rollback_retries_missing_guards_without_dropping_original_or_temporary_guards(): void
    {
        $id = DB::table('site_images')->insertGetId($this->image(User::factory()->create()->id));
        $migration = $this->migration();
        $expected = $this->triggers();
        $originalGuards = $this->originalGuards();
        $rows = $this->retainedRows();
        $this->dropGuard('hash_bytes_license_review_evidence_insert');
        $temporary = [];
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('CREATE TEMP TABLE synthetic_hash_rollback_shadow (value TEXT)');
            DB::table('synthetic_hash_rollback_shadow')->insert(['value' => 'retained temporary evidence']);
            DB::unprepared('CREATE TEMP TRIGGER hash_bytes_license_versions_update BEFORE UPDATE ON synthetic_hash_rollback_shadow BEGIN SELECT 1; END');
            $temporary = [$this->temporarySchema(), DB::table('synthetic_hash_rollback_shadow')->get()->map(fn ($row): array => (array) $row)->all()];
        }
        $migration->down();
        $this->assertSame([], $this->triggers());
        $this->assertSame($originalGuards, $this->originalGuards());
        $this->assertSame($rows, $this->retainedRows());
        $beforeRetry = [$this->schemaRows(), $this->retainedRows()];
        $migration->down();
        $this->assertSame($beforeRetry, [$this->schemaRows(), $this->retainedRows()]);
        $this->rejected(fn () => DB::table('site_images')->where('id', $id)->delete());
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame($temporary, [$this->temporarySchema(), DB::table('synthetic_hash_rollback_shadow')->get()->map(fn ($row): array => (array) $row)->all()]);
        }
        $migration->up();
        $migration->up();
        $this->assertSame($expected, $this->triggers());
        $this->assertSame($originalGuards, $this->originalGuards());
        $this->assertSame($rows, $this->retainedRows());
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame($temporary, [$this->temporarySchema(), DB::table('synthetic_hash_rollback_shadow')->get()->map(fn ($row): array => (array) $row)->all()]);
        }
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_01_000029_byte_exact_hash_guards.php');
    }

    private function image(int $uploader, array $overrides = []): array
    {
        return $overrides + ['slot' => 'share', 'original_name' => 'synthetic.jpg',
            'source_path' => 'site-images/quarantine/'.Str::uuid().'/source.upload', 'source_sha256' => str_repeat('a', 64),
            'size_bytes' => 10, 'mime_type' => 'image/jpeg', 'width' => 1200, 'height' => 630,
            'credit' => 'Synthetic schema test', 'rights_confirmed_at' => now(), 'uploaded_by' => $uploader,
            'created_at' => now(), 'updated_at' => now()];
    }

    private function malformedHashes(bool $hexadecimal = false): array
    {
        $values = ['NUL suffix' => str_repeat('a', 64)."\0suffix", 'NUL terminator' => str_repeat('a', 64)."\0",
            'ordinary overflow' => str_repeat('a', 65), 'multibyte' => str_repeat('é', 64), 'short' => str_repeat('a', 63)];
        if ($hexadecimal) {
            $values += ['embedded NUL' => str_repeat('a', 31)."\0".str_repeat('a', 32),
                'uppercase' => str_repeat('A', 64), 'nonhex' => str_repeat('g', 64)];
        }
        if (DB::getDriverName() === 'sqlite') {
            $values['BLOB'] = DB::raw("CAST('".str_repeat('a', 64)."' AS BLOB)");
        }

        return $values;
    }

    /** The invariant is stored bytes: MySQL converts excess whitespace before BEFORE triggers. */
    private function whitespaceWrites(string $table, string $column, string $expected, callable $write): void
    {
        $retained = fn (): array => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        foreach (['newline suffix' => "\n", 'CRLF suffix' => "\r\n", 'space suffix' => ' '] as $case => $suffix) {
            $before = $retained();
            $label = $table.'.'.$column.': '.$case;
            DB::beginTransaction();
            try {
                if (DB::getDriverName() === 'sqlite') {
                    $this->rejected(fn () => $write($expected.$suffix), $label);
                } else {
                    $id = $write($expected.$suffix);
                    $stored = DB::selectOne("SELECT {$column} AS value, HEX({$column}) AS bytes_hex, OCTET_LENGTH({$column}) AS byte_length FROM {$table} WHERE id = ?", [$id]);
                    $this->assertNotNull($stored, $label);
                    $this->assertSame($expected, $stored->value, $label.' must retain only the exact valid prefix.');
                    $this->assertSame(strtoupper(bin2hex($expected)), $stored->bytes_hex, $label.' stored HEX');
                    $this->assertSame(64, (int) $stored->byte_length, $label.' stored byte count');
                }
            } finally {
                DB::rollBack();
            }
            $this->assertSame($before, $retained(), $label.' rollback must preserve every existing row.');
        }
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
            $this->fail('An unowned hash guard was removed during rollback.');
        } catch (LogicException $error) {
            $this->assertSame("Unexpected hash guard definition for {$name}; the existing guard is unchanged. Investigate before retrying the migration.", $error->getMessage());
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

        $guards = array_values(array_filter($guards, fn (array $guard): bool => ! str_starts_with($guard[DB::getDriverName() === 'sqlite' ? 'name' : 'TRIGGER_NAME'], 'hash_bytes_')));
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
            ? DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', 'hash_bytes_%')->pluck('name')->all()
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
                ->where('TRIGGER_NAME', 'like', 'hash_bytes_%')->pluck('TRIGGER_NAME')->all();
        sort($names);

        return $names;
    }

    private function rejected(callable $operation, string $case = 'immutable evidence'): void
    {
        try {
            $operation();
            $this->fail('Malformed or immutable evidence was accepted: '.$case);
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
