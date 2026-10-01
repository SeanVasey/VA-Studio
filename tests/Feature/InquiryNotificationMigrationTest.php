<?php

namespace Tests\Feature;

use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\Inquiries\Models\InquiryNotificationIntent;
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

/** The same raw SQL cases run on disposable SQLite and MySQL; no provider is invoked. */
class InquiryNotificationMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private User $operator;

    private int $releaseId;

    private string $releaseHash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        $this->operator = LicenseFixtures::admin();
        $release = app(SiteContent::class)->create(SiteContentSchema::forEditing(SiteContentSchema::defaults()),
            'Synthetic notification migration fixture', $this->operator);
        $this->releaseId = $release->id;
        $this->releaseHash = $release->content_hash;
    }

    private function inquiry(): CustomerInquiry
    {
        $payload = ['name' => 'Synthetic Notification Buyer', 'email' => 'notification-buyer@example.test',
            'subject' => 'Synthetic notification subject', 'message' => 'Synthetic private message.', 'website' => ''];

        return CustomerInquiry::create([
            'public_id' => (string) Str::uuid(), 'owner_hash' => hash('sha256', 'synthetic-notification-owner'),
            'request_key' => (string) Str::uuid(), 'payload_hash' => CanonicalJson::hash($payload), 'payload' => $payload,
            'privacy_notice' => 'SYNTHETIC NOTICE', 'privacy_notice_hash' => hash('sha256', 'SYNTHETIC NOTICE'),
            'retention_policy_reference' => 'SYNTHETIC-RETENTION', 'operator_user_id' => $this->operator->id,
            'site_release_id' => $this->releaseId, 'site_content_hash' => $this->releaseHash,
            'state' => 'new', 'version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function attributes(CustomerInquiry $inquiry, array $overrides = []): array
    {
        return array_replace([
            'customer_inquiry_id' => $inquiry->id, 'operator_user_id' => $inquiry->operator_user_id,
            'kind' => 'operator_inbox_v1', 'state' => 'pending', 'attempts' => 0,
            'claim_token' => null, 'lease_expires_at' => null, 'next_attempt_at' => null, 'outcome' => null,
            'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
        ], $overrides);
    }

    private function intent(): InquiryNotificationIntent
    {
        return InquiryNotificationIntent::create($this->attributes($this->inquiry()));
    }

    /** Compare every raw column and its type; SQL column order is not retained evidence. */
    private function rawAttributes(CustomerInquiry|InquiryNotificationIntent $model): array
    {
        $attributes = $model->getRawOriginal();
        ksort($attributes, SORT_STRING);

        return $attributes;
    }

    private function rejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Invalid notification evidence or transition was accepted by SQL.');
        } catch (QueryException $error) {
            $this->assertNotSame('', $error->getMessage());
        }
    }

    private function update(InquiryNotificationIntent $intent, array $attributes): void
    {
        $this->assertSame(1, DB::table('inquiry_notification_intents')->where('id', $intent->id)->update($attributes));
        $intent->refresh();
    }

    private function claim(InquiryNotificationIntent $intent, int $seconds = 0): void
    {
        $this->update($intent, ['state' => 'processing', 'attempts' => $intent->attempts + 1,
            'claim_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addSeconds($seconds + 60),
            'next_attempt_at' => null, 'outcome' => null, 'updated_at' => now()->addSeconds($seconds)]);
    }

    private function finish(string $state, string $outcome, int $seconds = 1): array
    {
        return ['state' => $state, 'claim_token' => null, 'lease_expires_at' => null,
            'next_attempt_at' => null, 'outcome' => $outcome, 'updated_at' => now()->addSeconds($seconds)];
    }

    public function test_empty_rollback_reapply_retains_parent_records_and_restores_guards(): void
    {
        $inquiry = $this->inquiry();
        $before = $this->rawAttributes($inquiry);
        $migration = require database_path('migrations/2026_10_01_000033_inquiry_notification_intents.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('inquiry_notification_intents'));
        $migration->up();
        $this->assertSame($before, $this->rawAttributes($inquiry->fresh()));
        $this->rejected(fn () => DB::table('inquiry_notification_intents')->insert($this->attributes($inquiry, ['kind' => 'operator_inbox_v1 '])));
        InquiryNotificationIntent::create($this->attributes($inquiry));
        $this->assertDatabaseCount('inquiry_notification_intents', 1);
    }

    public function test_populated_rollback_refuses_deletion_and_leaves_identity_and_state_guards_active(): void
    {
        $intent = $this->intent();
        $before = $this->rawAttributes($intent);
        $migration = require database_path('migrations/2026_10_01_000033_inquiry_notification_intents.php');
        try {
            $migration->down();
            $this->fail('Retained notification evidence was erased.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('retention', $error->getMessage());
        }
        $this->assertTrue(Schema::hasTable('inquiry_notification_intents'));
        $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->delete());
        $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->update(['state' => 'submitted']));
        $this->assertSame($before, $this->rawAttributes($intent->fresh()));
    }

    public function test_one_id_only_intent_per_inquiry_has_the_original_operator_and_restrictive_foreign_keys(): void
    {
        $intent = $this->intent();
        $inquiry = CustomerInquiry::findOrFail($intent->customer_inquiry_id);
        $attributes = $this->attributes($inquiry);
        $this->rejected(fn () => DB::table('inquiry_notification_intents')->insert($attributes));
        $other = $this->inquiry();
        foreach ([['operator_user_id' => LicenseFixtures::admin()->id], ['operator_user_id' => 999999],
            ['customer_inquiry_id' => 999999]] as $invalid) {
            $this->rejected(fn () => DB::table('inquiry_notification_intents')->insert($this->attributes($other, $invalid)));
        }
        $this->rejected(fn () => DB::table('users')->where('id', $this->operator->id)->delete());
        $this->rejected(fn () => DB::table('customer_inquiries')->where('id', $inquiry->id)->delete());
        $columns = Schema::getColumnListing('inquiry_notification_intents');
        sort($columns);
        $expected = ['id', 'customer_inquiry_id', 'operator_user_id', 'kind', 'state', 'attempts', 'claim_token',
            'lease_expires_at', 'next_attempt_at', 'outcome', 'created_at', 'updated_at'];
        sort($expected);
        $this->assertSame($expected, $columns);
        $this->assertStringNotContainsString('notification-buyer@example.test', json_encode($this->rawAttributes($intent), JSON_THROW_ON_ERROR));
        $this->assertCount(1, array_filter(Schema::getIndexes('inquiry_notification_intents'),
            fn ($index) => $index['unique'] && $index['columns'] === ['customer_inquiry_id']));
        $keys = Schema::getForeignKeys('inquiry_notification_intents');
        $this->assertCount(2, $keys);
        foreach ($keys as $key) {
            $this->assertContains(strtolower($key['on_delete']), ['restrict', 'no action']);
        }
    }

    public function test_insert_guards_reject_nonempty_nonpending_padded_or_unknown_values(): void
    {
        $inquiry = $this->inquiry();
        foreach ([['kind' => 'operator_inbox_v2'], ['kind' => 'OPERATOR_INBOX_V1'], ['kind' => "operator_inbox_v1\0"],
            ['state' => 'PENDING'], ['state' => 'pending '], ['state' => 'retry'], ['state' => 'processing'],
            ['attempts' => 1], ['attempts' => -1], ['attempts' => 1.5], ['claim_token' => (string) Str::uuid()],
            ['lease_expires_at' => now()->addMinute()], ['next_attempt_at' => now()->addMinute()],
            ['outcome' => 'authority_withdrawn'], ['updated_at' => now()->addSecond()],
            ['created_at' => now()->subSecond()], ['created_at' => 'invalid-date', 'updated_at' => 'invalid-date'],
            ['created_at' => '2026-02-31 00:00:00', 'updated_at' => '2026-02-31 00:00:00']] as $invalid) {
            $this->rejected(fn () => DB::table('inquiry_notification_intents')->insert($this->attributes($inquiry, $invalid)));
            $this->assertDatabaseCount('inquiry_notification_intents', 0);
        }
    }

    public function test_identity_cannot_be_rewritten_even_with_an_otherwise_valid_claim(): void
    {
        $intent = $this->intent();
        $before = $this->rawAttributes($intent);
        $other = $this->inquiry();
        $claim = ['state' => 'processing', 'attempts' => 1, 'claim_token' => (string) Str::uuid(),
            'lease_expires_at' => now()->addMinute(), 'updated_at' => now()];
        foreach (['id' => $intent->id + 10000, 'customer_inquiry_id' => $other->id,
            'operator_user_id' => LicenseFixtures::admin()->id, 'kind' => 'OPERATOR_INBOX_V1',
            'created_at' => now()->subSecond()] as $column => $value) {
            $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->update(array_replace($claim, [$column => $value])));
            $this->assertSame($before, $this->rawAttributes($intent->fresh()), $column.' changed immutable evidence.');
        }
    }

    public function test_claim_requires_exact_uuid_one_attempt_and_an_unexpired_lease(): void
    {
        $intent = $this->intent();
        $before = $this->rawAttributes($intent);
        $uuid = '12345678-9abc-4def-8123-123456789abc';
        $claim = ['state' => 'processing', 'attempts' => 1, 'claim_token' => $uuid, 'lease_expires_at' => now()->addMinute()];
        foreach ([['state' => 'processing '], ['state' => 'PROCESSING'], ['attempts' => 0], ['attempts' => 2], ['attempts' => 1.5],
            ['claim_token' => null], ['claim_token' => str_repeat('a', 36)], ['claim_token' => strtoupper($uuid)],
            ['claim_token' => $uuid.' '], ['claim_token' => $uuid."\0"], ['claim_token' => $uuid."\n"],
            ['claim_token' => $uuid.str_repeat(' ', 28)], ['claim_token' => $uuid.str_repeat(' ', 29)],
            ['claim_token' => "\0".substr($uuid, 1)], ['claim_token' => 'é'.substr($uuid, 1)],
            ['lease_expires_at' => null], ['lease_expires_at' => 'invalid-date'], ['lease_expires_at' => '2027-02-31 00:00:00'],
            ['lease_expires_at' => now()], ['next_attempt_at' => now()->addMinute()],
            ['outcome' => 'handed_off'], ['updated_at' => now()->subSecond()]] as $invalid) {
            $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->update(array_replace($claim, $invalid)));
            $this->assertSame($before, $this->rawAttributes($intent->fresh()));
        }
        $this->claim($intent);
        $processing = $this->rawAttributes($intent);
        $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->update(['claim_token' => (string) Str::uuid(), 'attempts' => 2]));
        $this->assertSame($processing, $this->rawAttributes($intent->fresh()));
        $this->assertArrayNotHasKey('claim_token', $intent->attributesToArray());
    }

    public function test_only_definite_non_submission_may_retry_and_due_retry_stops_after_three_claims(): void
    {
        $intent = $this->intent();
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $start = ($attempt - 1) * 20;
            $this->claim($intent, $start);
            $before = $this->rawAttributes($intent);
            $retry = array_replace($this->finish('retry', 'definitely_not_submitted', $start + 1),
                ['next_attempt_at' => now()->addSeconds($start + 20)]);
            if ($attempt === 3) {
                $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->update($retry));
                $this->assertSame($before, $this->rawAttributes($intent->fresh()));
                $this->update($intent, $this->finish('blocked', 'retry_exhausted', $start + 1));
                break;
            }
            foreach ([['outcome' => 'handoff_uncertain'], ['outcome' => 'definitely_not_submitted '],
                ['next_attempt_at' => null], ['next_attempt_at' => now()->addSeconds($start + 1)],
                ['claim_token' => (string) Str::uuid()], ['attempts' => $attempt + 1]] as $invalid) {
                $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->update(array_replace($retry, $invalid)));
                $this->assertSame($before, $this->rawAttributes($intent->fresh()));
            }
            $this->update($intent, $retry);
            $retryBefore = $this->rawAttributes($intent);
            $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->update([
                'state' => 'processing', 'attempts' => $attempt + 1, 'claim_token' => (string) Str::uuid(),
                'lease_expires_at' => now()->addSeconds($start + 80), 'next_attempt_at' => null, 'outcome' => null,
                'updated_at' => now()->addSeconds($start + 19),
            ]));
            $this->assertSame($retryBefore, $this->rawAttributes($intent->fresh()));
        }
        $this->assertSame(['blocked', 3, 'retry_exhausted'], [$intent->state, $intent->attempts, $intent->outcome]);
    }

    public function test_active_claim_completes_once_and_ambiguous_outcomes_never_become_retryable(): void
    {
        foreach ([['submitted', 'handed_off'], ['unknown', 'handoff_uncertain'], ['blocked', 'authority_withdrawn'],
            ['blocked', 'configuration_withdrawn']] as [$state, $outcome]) {
            $intent = $this->intent();
            $this->claim($intent);
            $before = $this->rawAttributes($intent);
            $finish = $this->finish($state, $outcome);
            foreach ([['outcome' => 'private provider exception text'], ['outcome' => $outcome.' '],
                ['claim_token' => $intent->claim_token], ['lease_expires_at' => $intent->lease_expires_at],
                ['next_attempt_at' => now()->addMinute()], ['attempts' => 2], ['updated_at' => now()->addMinute()]] as $invalid) {
                $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->update(array_replace($finish, $invalid)));
                $this->assertSame($before, $this->rawAttributes($intent->fresh()));
            }
            $this->update($intent, $finish);
            $terminal = $this->rawAttributes($intent);
            foreach ([$finish, ['state' => 'processing', 'attempts' => 2, 'claim_token' => (string) Str::uuid(),
                'lease_expires_at' => now()->addMinutes(2), 'outcome' => null, 'updated_at' => now()->addSecond()],
                array_replace($this->finish('retry', 'definitely_not_submitted'), ['next_attempt_at' => now()->addMinute()])] as $invalid) {
                $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->update($invalid));
                $this->assertSame($terminal, $this->rawAttributes($intent->fresh()));
            }
        }
    }

    public function test_expired_claim_becomes_unknown_without_reclaim_or_definite_outcome(): void
    {
        $intent = $this->intent();
        $this->claim($intent);
        $before = $this->rawAttributes($intent);
        $expired = $this->finish('unknown', 'lease_expired', 60);
        foreach ([['updated_at' => now()->addSeconds(59)], ['outcome' => 'handoff_uncertain'],
            ['state' => 'submitted', 'outcome' => 'handed_off'], ['state' => 'blocked', 'outcome' => 'authority_withdrawn'],
            ['state' => 'processing', 'attempts' => 2, 'claim_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addMinutes(2), 'outcome' => null],
            ['state' => 'retry', 'outcome' => 'definitely_not_submitted', 'next_attempt_at' => now()->addMinutes(2)]] as $invalid) {
            $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->update(array_replace($expired, $invalid)));
            $this->assertSame($before, $this->rawAttributes($intent->fresh()));
        }
        $this->update($intent, $expired);
        $this->assertSame(['unknown', 1, 'lease_expired'], [$intent->state, $intent->attempts, $intent->outcome]);
    }

    public function test_authority_withdrawal_blocks_pending_or_scheduled_retry_without_consuming_an_attempt(): void
    {
        foreach ([false, true] as $retry) {
            $intent = $this->intent();
            if ($retry) {
                $this->claim($intent);
                $this->update($intent, array_replace($this->finish('retry', 'definitely_not_submitted'), ['next_attempt_at' => now()->addMinute()]));
            }
            $before = $this->rawAttributes($intent);
            $blocked = $this->finish('blocked', 'authority_withdrawn', 2);
            foreach ([['outcome' => 'retry_exhausted'], ['outcome' => 'configuration_withdrawn'], ['attempts' => $intent->attempts + 1],
                ['next_attempt_at' => now()->addMinute()]] as $invalid) {
                $this->rejected(fn () => DB::table('inquiry_notification_intents')->where('id', $intent->id)->update(array_replace($blocked, $invalid)));
                $this->assertSame($before, $this->rawAttributes($intent->fresh()));
            }
            $this->update($intent, $blocked);
            $this->assertSame($retry ? 1 : 0, $intent->attempts);
        }
    }

    public function test_orm_rejects_identity_deletion_and_invalid_state_changes_but_accepts_valid_claim_and_handoff(): void
    {
        $intent = $this->intent();
        $before = $this->rawAttributes($intent);
        foreach ([fn () => $intent->delete(), fn () => $intent->forceFill(['kind' => 'operator_inbox_v2'])->save(),
            fn () => $intent->fresh()->forceFill(['state' => 'pending '])->save(),
            fn () => $intent->fresh()->forceFill(['state' => 'submitted', 'outcome' => 'handed_off'])->save()] as $operation) {
            try {
                $operation();
                $this->fail('The ORM accepted contradictory notification evidence.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
            $this->assertSame($before, $this->rawAttributes($intent->fresh()));
        }
        $intent->refresh()->update(['state' => 'processing', 'attempts' => 1, 'claim_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addMinute()]);
        $intent->update($this->finish('submitted', 'handed_off'));
        $this->assertSame(['submitted', 1, 'handed_off'], [$intent->state, $intent->attempts, $intent->outcome]);
        $inquiry = $this->inquiry();
        foreach ([['operator_user_id' => LicenseFixtures::admin()->id], ['kind' => 'operator_inbox_v1 '], ['state' => 'processing'], ['attempts' => 1]] as $invalid) {
            try {
                InquiryNotificationIntent::create($this->attributes($inquiry, $invalid));
                $this->fail('The ORM accepted invalid notification creation.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_invalid_notification_insert_rolls_back_the_new_inquiry_in_the_same_transaction(): void
    {
        $inquiries = DB::table('customer_inquiries')->count();
        $audits = DB::table('audit_events')->count();
        $this->rejected(fn () => DB::transaction(function (): void {
            $inquiry = $this->inquiry();
            DB::table('inquiry_notification_intents')->insert($this->attributes($inquiry, ['operator_user_id' => 999999]));
        }));
        $this->assertDatabaseCount('customer_inquiries', $inquiries);
        $this->assertDatabaseCount('inquiry_notification_intents', 0);
        $this->assertSame($audits, DB::table('audit_events')->count());
    }
}
