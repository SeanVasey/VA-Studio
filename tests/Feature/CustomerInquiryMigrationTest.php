<?php

namespace Tests\Feature;

use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/** Operational rollback and raw SQL guards run outside RefreshDatabase's transaction on a disposable database. */
class CustomerInquiryMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private User $operator;

    private SiteRelease $release;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        $this->operator = LicenseFixtures::admin();
        $content = SiteContentSchema::forEditing(SiteContentSchema::defaults());
        $content['contact'] = ['title' => 'Synthetic contact', 'description' => 'Migration fixture.',
            'paragraphs' => ['Synthetic records only.'], 'email' => 'operator@example.test'];
        $this->release = app(SiteContent::class)->create($content, 'Synthetic inquiry migration fixture', $this->operator);
    }

    private function attributes(array $overrides = []): array
    {
        $payload = ['name' => 'Synthetic Migration Buyer', 'email' => 'migration-buyer@example.test',
            'subject' => 'Synthetic migration inquiry', 'message' => 'Synthetic private message.', 'website' => ''];

        return array_replace([
            'public_id' => (string) Str::uuid(), 'owner_hash' => hash('sha256', 'synthetic-session-owner'),
            'request_key' => (string) Str::uuid(), 'payload_hash' => CanonicalJson::hash($payload), 'payload' => $payload,
            'privacy_notice' => 'SYNTHETIC PRIVATE NOTICE', 'privacy_notice_hash' => hash('sha256', 'SYNTHETIC PRIVATE NOTICE'),
            'retention_policy_reference' => 'SYNTHETIC-RETENTION-POLICY', 'operator_user_id' => $this->operator->id,
            'site_release_id' => $this->release->id, 'site_content_hash' => $this->release->content_hash,
            'state' => 'new', 'version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ], $overrides);
    }

    private function rejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Contradictory inquiry evidence or transition was accepted.');
        } catch (QueryException $error) {
            $this->assertNotSame('', $error->getMessage());
        }
    }

    public function test_empty_rollback_and_reapply_preserve_site_and_operator_evidence_and_restore_guards(): void
    {
        $release = $this->release->refresh()->getAttributes();
        $operator = $this->operator->refresh()->getAttributes();
        $audits = DB::table('audit_events')->count();
        $migration = require database_path('migrations/2026_10_01_000031_customer_inquiries.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('customer_inquiries'));
        $migration->up();
        $this->assertDatabaseCount('customer_inquiries', 0);
        $this->assertSame($release, $this->release->fresh()->getAttributes());
        $this->assertSame($operator, $this->operator->fresh()->getAttributes());
        $this->assertSame($audits, DB::table('audit_events')->count());
        $this->rejected(fn () => CustomerInquiry::create($this->attributes(['state' => 'read', 'version' => 1])));
        $inquiry = CustomerInquiry::create($this->attributes());
        $this->rejected(fn () => DB::table('customer_inquiries')->where('id', $inquiry->id)->delete());
        $this->assertDatabaseCount('customer_inquiries', 1);
    }

    public function test_populated_rollback_refuses_to_erase_inquiries_and_leaves_all_guards_in_place(): void
    {
        $inquiry = CustomerInquiry::create($this->attributes());
        $before = $inquiry->refresh()->getAttributes();
        $migration = require database_path('migrations/2026_10_01_000031_customer_inquiries.php');
        try {
            $migration->down();
            $this->fail('Retained private inquiries were dropped.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('retention', $error->getMessage());
        }
        $this->assertTrue(Schema::hasTable('customer_inquiries'));
        $this->rejected(fn () => DB::table('customer_inquiries')->where('id', $inquiry->id)->delete());
        $this->rejected(fn () => DB::table('customer_inquiries')->where('id', $inquiry->id)->update(['payload_hash' => str_repeat('b', 64)]));
        $this->assertSame($before, $inquiry->fresh()->getAttributes());
    }

    public function test_receipt_and_owner_request_uniqueness_do_not_merge_foreign_sessions_or_erase_parent_evidence(): void
    {
        $attributes = $this->attributes();
        $inquiry = CustomerInquiry::create($attributes);
        $this->rejected(fn () => CustomerInquiry::create($this->attributes(['public_id' => $inquiry->public_id])));
        $this->rejected(fn () => CustomerInquiry::create(array_replace($attributes, ['public_id' => (string) Str::uuid()])));
        $this->rejected(fn () => CustomerInquiry::create(array_replace($attributes, ['public_id' => (string) Str::uuid(), 'owner_hash' => hash('sha256', 'foreign-owner')])));
        CustomerInquiry::create($this->attributes(['owner_hash' => hash('sha256', 'foreign-owner')]));
        CustomerInquiry::create(array_replace($attributes, ['public_id' => (string) Str::uuid(), 'request_key' => (string) Str::uuid()]));
        foreach ([['operator_user_id' => 999999], ['site_release_id' => 999999]] as $orphan) {
            $this->rejected(fn () => CustomerInquiry::create($this->attributes($orphan)));
        }
        $this->rejected(fn () => DB::table('users')->where('id', $this->operator->id)->delete());
        $this->assertDatabaseCount('customer_inquiries', 3);
        foreach ([['public_id'], ['request_key'], ['owner_hash', 'request_key']] as $columns) {
            $this->assertCount(1, array_filter(Schema::getIndexes('customer_inquiries'),
                fn ($index) => $index['unique'] && $index['columns'] === $columns));
        }
        $keys = Schema::getForeignKeys('customer_inquiries');
        $this->assertCount(2, $keys);
        foreach ($keys as $key) {
            $this->assertContains(strtolower($key['on_delete']), ['restrict', 'no action']);
        }
    }

    public function test_sql_cannot_mutate_any_original_input_owner_policy_or_publication_evidence_even_with_a_valid_state_change(): void
    {
        $inquiry = CustomerInquiry::create($this->attributes());
        $before = $inquiry->refresh()->getAttributes();
        $changes = [
            'public_id' => (string) Str::uuid(), 'owner_hash' => str_repeat('b', 64), 'request_key' => (string) Str::uuid(),
            'payload_hash' => str_repeat('b', 64), 'payload' => 'different-private-ciphertext',
            'privacy_notice' => 'different-private-notice', 'privacy_notice_hash' => str_repeat('b', 64),
            'retention_policy_reference' => 'DIFFERENT-RETENTION', 'operator_user_id' => LicenseFixtures::admin()->id,
            'site_release_id' => 999999, 'site_content_hash' => str_repeat('b', 64),
            'created_at' => now()->subSecond(),
        ];
        foreach ($changes as $field => $value) {
            $this->rejected(fn () => DB::table('customer_inquiries')->where('id', $inquiry->id)
                ->update([$field => $value, 'state' => 'read', 'version' => 1, 'updated_at' => now()->addSecond()]));
            $this->assertSame($before, $inquiry->fresh()->getAttributes(), $field.' changed retained evidence.');
        }
        // On MySQL's usual case-insensitive default collation, policy references must still compare as exact bytes.
        $this->rejected(fn () => DB::table('customer_inquiries')->where('id', $inquiry->id)->update([
            'retention_policy_reference' => strtolower($inquiry->retention_policy_reference), 'state' => 'read', 'version' => 1,
        ]));
        try {
            $inquiry->forceFill(['payload' => ['email' => 'changed@example.test']])->save();
            $this->fail('The ORM changed immutable inquiry input.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($before, $inquiry->fresh()->getAttributes());
    }

    public function test_only_new_records_and_monotonic_versioned_read_or_archive_transitions_are_accepted(): void
    {
        foreach ([['state' => 'read'], ['state' => 'archived'], ['state' => 'NEW'], ['state' => 'new '],
            ['version' => 1], ['version' => -1], ['version' => 1.5]] as $invalid) {
            $this->rejected(fn () => CustomerInquiry::create($this->attributes($invalid)));
        }
        $inquiry = CustomerInquiry::create($this->attributes());
        $before = $inquiry->refresh()->getAttributes();
        foreach ([['state' => 'read'], ['version' => 1], ['state' => 'read', 'version' => 2],
            ['state' => 'new', 'version' => 1], ['state' => 'READ', 'version' => 1],
            ['state' => 'read ', 'version' => 1], ['state' => 'archived ', 'version' => 1], ['state' => 'read', 'version' => 2147483647],
            ['state' => 'read', 'version' => 1, 'updated_at' => now()->subSecond()]] as $invalid) {
            $this->rejected(fn () => DB::table('customer_inquiries')->where('id', $inquiry->id)->update($invalid));
        }
        $this->assertSame($before, $inquiry->fresh()->getAttributes());
        $inquiry->update(['state' => 'read', 'version' => 1, 'updated_at' => now()->addSecond()]);
        $this->assertSame(['read', 1], [$inquiry->fresh()->state, $inquiry->fresh()->version]);
        $this->rejected(fn () => DB::table('customer_inquiries')->where('id', $inquiry->id)
            ->update(['state' => 'archived ', 'version' => 2, 'updated_at' => now()->addSeconds(2)]));
        $this->rejected(fn () => DB::table('customer_inquiries')->where('id', $inquiry->id)
            ->update(['state' => 'archived', 'version' => 2, 'updated_at' => now()]));
        $inquiry->refresh()->update(['state' => 'archived', 'version' => 2, 'updated_at' => now()->addSeconds(2)]);
        $archived = $inquiry->fresh()->getAttributes();
        foreach (['new', 'read', 'archived'] as $state) {
            $this->rejected(fn () => DB::table('customer_inquiries')->where('id', $inquiry->id)->update(['state' => $state, 'version' => 3]));
        }
        $this->assertSame($archived, $inquiry->fresh()->getAttributes());
        $direct = CustomerInquiry::create($this->attributes());
        $direct->update(['state' => 'archived', 'version' => 1]);
        $this->assertSame('archived', $direct->fresh()->state);
    }

    public function test_original_input_is_encrypted_hidden_from_serialization_and_not_deletable_through_the_orm(): void
    {
        $inquiry = CustomerInquiry::create($this->attributes());
        $before = $inquiry->refresh()->getAttributes();
        $this->assertStringNotContainsString('migration-buyer@example.test', $inquiry->getRawOriginal('payload'));
        $this->assertStringNotContainsString('SYNTHETIC PRIVATE NOTICE', $inquiry->getRawOriginal('privacy_notice'));
        $this->assertSame('migration-buyer@example.test', $inquiry->fresh()->payload['email']);
        $this->assertSame('SYNTHETIC PRIVATE NOTICE', $inquiry->fresh()->privacy_notice);
        foreach (['payload', 'privacy_notice', 'owner_hash', 'request_key', 'payload_hash', 'retention_policy_reference'] as $field) {
            $this->assertArrayNotHasKey($field, $inquiry->attributesToArray());
        }
        try {
            $inquiry->delete();
            $this->fail('The ORM deleted retained private inquiry evidence.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($before, $inquiry->fresh()->getAttributes());
    }
}
