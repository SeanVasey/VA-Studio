<?php

namespace Tests\Feature;

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\Models\GrantContract;
use App\Models\User;
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
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); DeliveryFixtures::configure();
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer());
        $migration = $this->migration(); $migration->down();
        // The ordinary pre-upgrade domain flow creates all original payment/license/contract
        // evidence. Upgrading must retain every byte and keep later valid authorization possible.
        $fixture = DeliveryFixtures::ready($gateway);
        $before = DeliveryFixtures::retained();
        $migration->up();
        $this->assertSame($before, DeliveryFixtures::retained());
        $control = $fixture['delivery_control']; $contract = GrantContract::where('license_grant_id', $fixture['grant']->id)->sole();
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
        $migration = $this->migration(); $migration->down();
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
        try { $migration->up(); $this->fail('Malformed retained evidence was silently accepted.'); }
        catch (LogicException $error) {
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
        sort($expected); $this->assertSame($expected, $this->triggers());
        DB::unprepared('DROP TRIGGER hash_bytes_site_images_update');
        $migration->up(); $migration->up();
        $this->assertSame($expected, $this->triggers());
        $migration->down(); $this->assertSame([], $this->triggers());
        $id = DB::table('site_images')->insertGetId($this->image(User::factory()->create()->id));
        $this->rejected(fn () => DB::table('site_images')->where('id', $id)->delete());
        $migration->up(); $this->assertSame($expected, $this->triggers());
    }

    public function test_retry_refuses_a_same_named_trigger_with_a_different_definition(): void
    {
        $migration = $this->migration(); $migration->down();
        $name = 'hash_bytes_license_versions_update';
        $statement = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE UPDATE ON license_versions BEGIN SELECT 1; END"
            : "CREATE TRIGGER {$name} BEFORE UPDATE ON license_versions FOR EACH ROW BEGIN IF 1 = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic collision'; END IF; END";
        DB::unprepared($statement);
        try { $migration->up(); $this->fail('A same-named unrelated trigger was trusted.'); }
        catch (LogicException $error) {
            $this->assertSame("Unexpected hash guard definition for {$name}; the existing guard is unchanged. Investigate before retrying the migration.", $error->getMessage());
        }
        $this->assertSame([$name], $this->triggers());
        $actual = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('name', $name)->value('sql')
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', $name)->value('ACTION_STATEMENT');
        $this->assertStringContainsString(DB::getDriverName() === 'sqlite' ? 'SELECT 1;' : 'Synthetic collision', $actual);
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
        if (DB::getDriverName() === 'sqlite') { $values['BLOB'] = DB::raw("CAST('".str_repeat('a', 64)."' AS BLOB)"); }

        return $values;
    }

    /** The invariant is stored bytes: MySQL converts excess whitespace before BEFORE triggers. */
    private function whitespaceWrites(string $table, string $column, string $expected, callable $write): void
    {
        $retained = fn (): array => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        foreach (['newline suffix' => "\n", 'CRLF suffix' => "\r\n", 'space suffix' => ' '] as $case => $suffix) {
            $before = $retained(); $label = $table.'.'.$column.': '.$case;
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
            } finally { DB::rollBack(); }
            $this->assertSame($before, $retained(), $label.' rollback must preserve every existing row.');
        }
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
        try { $operation(); $this->fail('Malformed or immutable evidence was accepted: '.$case); }
        catch (QueryException) { $this->addToAssertionCount(1); }
    }
}
