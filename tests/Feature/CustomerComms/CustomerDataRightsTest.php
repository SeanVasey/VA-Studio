<?php

namespace Tests\Feature\CustomerComms;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\DataRights\CustomerDataRights;
use App\Domain\Customers\DataRights\DataRightsException;
use App\Domain\Customers\DataRights\Models\CustomerDataExport;
use App\Domain\Customers\DataRights\Models\CustomerDataRequest;
use App\Domain\Customers\Listening\SavedListeningLibrary;
use App\Domain\Customers\Models\CustomerAccount;
use App\Domain\Customers\Preferences\CustomerConsentPreferences;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConsentFixtures;
use Tests\Support\CustomerFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\OrderFixtures;
use Tests\TestCase;

/** FP-082: own-records export, single-use download, staff-reviewed deletion that refuses while retention is unset. */
class CustomerDataRightsTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const IMMUTABLE = ['orders', 'order_lines', 'order_finalizations', 'license_grants', 'verified_payments', 'grant_contracts',
        'customer_purchase_claims', 'customer_consent_events', 'customer_consent_states', 'customer_inquiries', 'audit_events'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        F::configure();
        OrderFixtures::configure();
        $this->travelTo(now()->startOfSecond());
        config(['customer-data-rights.enabled' => true]);
    }

    private function retention(): void
    {
        config(['customer-data-rights.retention' => ['policy_reference' => 'Synthetic retention schedule SR-1 (test only)',
            'financial_records_years' => '7', 'consent_records_years' => 3, 'correspondence_records_years' => '2', 'security_records_years' => 1]]);
    }

    private function login(array $fixture): void
    {
        $principal = app(CustomerAccess::class)->principal($fixture['user']->fresh());
        $this->actingAs($fixture['user'], 'customer')->withSession(['_customer_access' => [
            'account_id' => $principal->accountId, 'access_version' => $principal->accessVersion, 'credential_stamp' => $principal->credentialStamp,
        ]]);
    }

    private function staff(bool $enrolled = true): User
    {
        $admin = LicenseFixtures::admin();
        if ($enrolled) {
            $admin->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        }

        return $admin->fresh();
    }

    private function snapshot(): array
    {
        $rows = [];
        foreach (self::IMMUTABLE as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $rows;
    }

    public function test_every_route_answers_404_until_enabled_and_customer_access_is_admitted(): void
    {
        $customer = F::account();
        $this->login($customer);
        foreach ([[false, true], [true, false]] as [$enabled, $accounts]) {
            config(['customer-data-rights.enabled' => $enabled, 'customer.test_accounts_enabled' => $accounts]);
            $this->getJson('/account/data-requests')->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
            $this->postJson('/account/data-requests/export', ['confirm' => true])->assertNotFound();
            $this->postJson('/account/data-requests/export/download', ['requestId' => '00000000-0000-4000-8000-000000000000', 'token' => str_repeat('a', 64)])->assertNotFound();
            $this->postJson('/account/data-requests/deletion', ['confirm' => true])->assertNotFound();
        }
        config(['customer-data-rights.enabled' => 'true', 'customer.test_accounts_enabled' => true]);
        $this->postJson('/account/data-requests/export', ['confirm' => true])->assertNotFound();
        foreach (['customer_data_requests', 'customer_data_request_events', 'customer_data_exports'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, AuditEvent::where('action', 'like', 'customer.data_%')->count());
    }

    public function test_guests_and_staff_sessions_cannot_use_customer_data_routes(): void
    {
        $this->postJson('/account/data-requests/export', ['confirm' => true])->assertForbidden();
        $this->actingAs($this->staff())->postJson('/account/data-requests/deletion', ['confirm' => true])->assertForbidden();
        $this->assertDatabaseCount('customer_data_requests', 0);
    }

    public function test_export_contains_only_the_requesters_records_and_is_encrypted_with_a_hashed_one_time_token(): void
    {
        $alice = F::account(['name' => 'Synthetic Alice', 'email' => 'alice.export@example.test']);
        $bob = F::account(['name' => 'Synthetic Bob', 'email' => 'bob.export@example.test']);
        $aliceOrder = F::ready($alice['user'], 'ALICE');
        $bobOrder = F::ready($bob['user'], 'BOB');
        ConsentFixtures::configure();
        app(CustomerConsentPreferences::class)->change($alice['principal'], $alice['user'], ConsentFixtures::grant());
        app(CustomerConsentPreferences::class)->change($bob['principal'], $bob['user'], ConsentFixtures::grant());
        $before = $this->snapshot();

        $this->login($alice);
        $ready = $this->postJson('/account/data-requests/export', ['confirm' => true])->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json('export');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $ready['downloadToken']);
        $raw = DB::table('customer_data_exports')->sole();
        $this->assertSame(hash('sha256', $ready['downloadToken']), $raw->token_hash);
        foreach (['alice.export@example.test', 'Synthetic Alice', $aliceOrder['order']->public_id, $ready['downloadToken']] as $plain) {
            $this->assertStringNotContainsString($plain, $raw->ciphertext);
            $this->assertStringNotContainsString($plain, DB::table('customer_data_request_events')->pluck('detail')->implode(' '));
            $this->assertStringNotContainsString($plain, AuditEvent::where('action', 'like', 'customer.data_%')->get()->toJson());
        }

        $response = $this->postJson('/account/data-requests/export/download', ['requestId' => $ready['requestId'], 'token' => $ready['downloadToken']])
            ->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="vasey-audio-data-export.json"')
            ->assertHeader('Cache-Control', 'no-store, private');
        $document = json_decode($response->getContent(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $document['exportSchema']);
        $this->assertSame(['id' => $alice['account']->public_id, 'name' => 'Synthetic Alice', 'email' => 'alice.export@example.test'],
            array_intersect_key($document['account'], array_flip(['id', 'name', 'email'])));
        $this->assertSame([$aliceOrder['order']->public_id], array_column($document['orders'], 'id'));
        $this->assertNotEmpty($document['licenseGrants']);
        $this->assertSame([$aliceOrder['order']->public_id], array_values(array_unique(array_column($document['licenseGrants'], 'orderId'))));
        $this->assertSame([['purpose' => 'email_marketing', 'status' => 'granted']], array_map(fn (array $row): array => array_intersect_key($row, array_flip(['purpose', 'status'])), $document['consent']));
        $json = $response->getContent();
        foreach (['bob.export@example.test', 'Synthetic Bob', $bobOrder['order']->public_id, $bob['account']->public_id] as $foreign) {
            $this->assertStringNotContainsString($foreign, $json);
        }
        $this->assertSame($before, $this->immutableAfter($before));
    }

    private function immutableAfter(array $before): array
    {
        // Only audit_events may grow (export audit rows); every earlier row is byte-identical.
        $after = $this->snapshot();
        $this->assertSame(array_slice($after['audit_events'], 0, count($before['audit_events'])), $before['audit_events']);
        $after['audit_events'] = $before['audit_events'];

        return $after;
    }

    public function test_download_is_single_use_bound_to_the_requesting_account_and_expires(): void
    {
        $alice = F::account();
        $bob = F::account();
        $this->login($alice);
        $ready = $this->postJson('/account/data-requests/export', ['confirm' => true])->assertCreated()->json('export');
        $this->postJson('/account/data-requests/export', ['confirm' => true])->assertStatus(409)->assertJsonPath('reason', 'export_pending');
        $body = ['requestId' => $ready['requestId'], 'token' => $ready['downloadToken']];

        $this->login($bob);
        $this->postJson('/account/data-requests/export/download', $body)->assertNotFound()->assertJsonPath('reason', 'export_not_found');
        $this->login($alice);
        $this->postJson('/account/data-requests/export/download', ['requestId' => $ready['requestId'], 'token' => str_repeat('0', 64)])->assertNotFound();
        $this->postJson('/account/data-requests/export/download', ['requestId' => $ready['requestId'], 'token' => strtoupper($ready['downloadToken'])])->assertNotFound();
        $this->assertNotNull(CustomerDataExport::sole()->getRawOriginal('ciphertext'));

        $this->postJson('/account/data-requests/export/download', $body)->assertOk();
        $this->postJson('/account/data-requests/export/download', $body)->assertStatus(410)->assertJsonPath('reason', 'export_unavailable');
        $export = CustomerDataExport::sole();
        $this->assertNull($export->getRawOriginal('ciphertext'));
        $this->assertNotNull($export->consumed_at);
        $this->assertSame('downloaded', CustomerDataRequest::sole()->state);

        // A new export may be requested; left unused past its window it expires and is purged.
        $second = $this->postJson('/account/data-requests/export', ['confirm' => true])->assertCreated()->json('export');
        $this->travel(15)->minutes();
        $this->postJson('/account/data-requests/export/download', ['requestId' => $second['requestId'], 'token' => $second['downloadToken']])
            ->assertStatus(410)->assertJsonPath('reason', 'export_expired');
        $expired = CustomerDataRequest::where('public_id', $second['requestId'])->sole();
        $this->assertSame('expired', $expired->state);
        $this->assertNull(CustomerDataExport::where('request_id', $expired->id)->sole()->getRawOriginal('ciphertext'));
        $this->getJson('/account/data-requests')->assertOk()->assertJsonPath('dataRequests.requests.0.state', 'expired')
            ->assertJsonPath('dataRequests.requests.1.state', 'downloaded');

        // An unused export left past its window is purged by the operator command without any customer request. The
        // hourly export limit (three attempts) has been used, so wait for its window first.
        $this->travel(46)->minutes();
        $third = $this->postJson('/account/data-requests/export', ['confirm' => true])->assertCreated()->json('export');
        $this->travel(16)->minutes();
        $this->artisan('vasey:customer-data-exports-prune')->expectsOutput('purged=1')->assertSuccessful();
        $this->assertSame('expired', CustomerDataRequest::where('public_id', $third['requestId'])->sole()->state);
        $this->assertSame(0, CustomerDataExport::whereNotNull('ciphertext')->count());
    }

    public static function malformedBodies(): array
    {
        return [
            'array body' => ['/account/data-requests/export', '[]'], 'empty object' => ['/account/data-requests/export', '{}'],
            'extra field' => ['/account/data-requests/export', '{"confirm":true,"accountId":"1"}'], 'false confirm' => ['/account/data-requests/export', '{"confirm":false}'],
            'duplicate key' => ['/account/data-requests/deletion', '{"confirm":true,"confirm":true}'],
            'escaped duplicate' => ['/account/data-requests/deletion', '{"confirm":true,"confirm":true}'],
            'string confirm' => ['/account/data-requests/deletion', '{"confirm":"true"}'],
            'missing confirm' => ['/account/data-requests/deletion', '{}'],
            'nested token' => ['/account/data-requests/export/download', '{"requestId":"x","token":["a"]}'],
            'missing token' => ['/account/data-requests/export/download', '{"requestId":"00000000-0000-4000-8000-000000000000"}'],
            'invalid json' => ['/account/data-requests/export', '{'],
        ];
    }

    #[DataProvider('malformedBodies')]
    public function test_malformed_bodies_are_refused_without_rows(string $path, string $body): void
    {
        $this->login(F::account());
        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertStatus(422)->assertJsonPath('reason', 'invalid_request');
        $this->assertDatabaseCount('customer_data_requests', 0);
    }

    public function test_public_endpoints_are_rate_limited(): void
    {
        $customer = F::account();
        $this->login($customer);
        foreach (range(1, 3) as $attempt) {
            $this->postJson('/account/data-requests/deletion', ['confirm' => true])->assertSuccessful();
        }
        $this->postJson('/account/data-requests/deletion', ['confirm' => true])->assertTooManyRequests();
        $this->assertDatabaseCount('customer_data_requests', 1);
        foreach (range(1, 10) as $attempt) {
            $this->postJson('/account/data-requests/export/download', ['requestId' => '00000000-0000-4000-8000-000000000000', 'token' => str_repeat('a', 64)])->assertNotFound();
        }
        $this->postJson('/account/data-requests/export/download', ['requestId' => '00000000-0000-4000-8000-000000000000', 'token' => str_repeat('a', 64)])->assertTooManyRequests();
    }

    public function test_deletion_request_is_idempotent_and_approval_refuses_while_any_retention_value_is_unset(): void
    {
        $customer = F::account();
        F::ready($customer['user'], 'RETAIN');
        $this->login($customer);
        $first = $this->postJson('/account/data-requests/deletion', ['confirm' => true])->assertCreated()->json('deletion');
        $this->postJson('/account/data-requests/deletion', ['confirm' => true])->assertOk()->assertJsonPath('deletion.requestId', $first['requestId'])
            ->assertJsonPath('deletion.replayed', true);
        $this->assertTrue(CustomerAccount::find($customer['account']->id)->active);
        $staff = $this->staff();
        $before = $this->snapshot();
        $user = DB::table('users')->where('id', $customer['user']->id)->first();
        $complete = ['policy_reference' => 'Synthetic SR-1', 'financial_records_years' => 7, 'consent_records_years' => 3,
            'correspondence_records_years' => 2, 'security_records_years' => 1];
        $cases = ['all unset' => null, 'empty reference' => ['policy_reference' => ''] + $complete, 'markup reference' => ['policy_reference' => '<b>x</b>'] + $complete];
        foreach (array_keys($complete) as $key) {
            if ($key !== 'policy_reference') {
                $cases["{$key} missing"] = array_diff_key($complete, [$key => true]);
                $cases["{$key} zero"] = [$key => 0] + $complete;
                $cases["{$key} over 100"] = [$key => 101] + $complete;
                $cases["{$key} decimal string"] = [$key => '7.5'] + $complete;
                $cases["{$key} padded string"] = [$key => '07'] + $complete;
            }
        }
        foreach ($cases as $case => $retention) {
            config(['customer-data-rights.retention' => $retention]);
            try {
                app(CustomerDataRights::class)->approveDeletion($first['requestId'], $staff, 0);
                $this->fail("Deletion ran with retention case: {$case}");
            } catch (DataRightsException $error) {
                $this->assertSame('retention_policy_unset', $error->reason, $case);
            }
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertEquals($user, DB::table('users')->where('id', $customer['user']->id)->first());
        $this->assertSame('received', CustomerDataRequest::sole()->state);
    }

    public function test_approval_requires_the_gate_mfa_enrollment_and_the_reviewed_version(): void
    {
        $this->retention();
        $customer = F::account();
        $this->login($customer);
        $request = $this->postJson('/account/data-requests/deletion', ['confirm' => true])->assertCreated()->json('deletion.requestId');
        $rights = app(CustomerDataRights::class);
        foreach (['customer' => $customer['user'], 'unenrolled admin' => $this->staff(false), 'non-admin' => User::factory()->create()] as $case => $actor) {
            try {
                $rights->approveDeletion($request, $actor, 0);
                $this->fail("Unauthorized approval accepted: {$case}");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $staff = $this->staff();
        $withdrawn = $this->staff();
        User::whereKey($withdrawn->id)->update(['is_admin' => false]);
        $this->expectRefusal(fn () => $rights->approveDeletion($request, $withdrawn, 0), AuthorizationException::class);
        $this->expectRefusal(fn () => $rights->approveDeletion($request, $staff, 1), DataRightsException::class, 'request_changed');
        $this->expectRefusal(fn () => $rights->approveDeletion('00000000-0000-4000-8000-000000000000', $staff, 0), DataRightsException::class, 'request_not_found');
        $this->expectRefusal(fn () => $rights->rejectDeletion($request, $staff, 0, '<script>'), ValidationException::class);
        $this->expectRefusal(fn () => $rights->rejectDeletion($request, $staff, 0, '  '), ValidationException::class);
        $this->assertSame('received', CustomerDataRequest::sole()->state);
        $this->assertSame(0, AuditEvent::where('action', 'customer.data_deletion.completed')->count());

        $rights->rejectDeletion($request, $staff, 0, 'Synthetic: request withdrawn by the customer by email.');
        $rejected = CustomerDataRequest::sole();
        $this->assertSame(['rejected', $staff->id, null], [$rejected->state, $rejected->resolved_by, $rejected->open_slot]);
        $this->assertTrue(CustomerAccount::find($customer['account']->id)->active);
        $this->expectRefusal(fn () => $rights->approveDeletion($request, $staff, 1), DataRightsException::class, 'request_changed');
        $this->assertSame(1, AuditEvent::where('action', 'customer.data_deletion.rejected')->where('actor_id', $staff->id)->count());
    }

    public function test_approval_withdraws_access_erases_mutable_profile_data_and_keeps_every_immutable_record(): void
    {
        $this->retention();
        $customer = F::account(['name' => 'Synthetic Erasable', 'email' => 'erasable@example.test']);
        $other = F::account();
        $order = F::ready($customer['user'], 'ERASE');
        ConsentFixtures::configure();
        app(CustomerConsentPreferences::class)->change($customer['principal'], $customer['user'], ConsentFixtures::grant());
        SavedListeningLibrary::create(['customer_account_id' => $customer['account']->id, 'version' => 1,
            'payload' => ['schema' => 1, 'accountId' => $customer['account']->id, 'version' => 1, 'favorites' => ['999'], 'playlists' => []]]);
        $this->login($customer);
        $request = $this->postJson('/account/data-requests/deletion', ['confirm' => true])->assertCreated()->json('deletion.requestId');
        $before = $this->snapshot();
        $otherBefore = [DB::table('users')->where('id', $other['user']->id)->first(), DB::table('customer_accounts')->where('id', $other['account']->id)->first()];
        $staff = $this->staff();

        $result = app(CustomerDataRights::class)->approveDeletion($request, $staff, 0);

        $this->assertSame(['customer_access' => 'withdrawn', 'listening_library' => 'erased', 'sessions' => 'none', 'sign_in_profile' => 'pseudonymized'], $result['actions']);
        $after = $this->snapshot();
        $this->assertSame(array_slice($after['audit_events'], 0, count($before['audit_events'])), $before['audit_events']);
        unset($after['audit_events'], $before['audit_events']);
        $this->assertSame($before, $after);
        $account = CustomerAccount::find($customer['account']->id);
        $this->assertFalse($account->active);
        $this->assertSame($customer['account']->access_version + 1, $account->access_version);
        $user = User::find($customer['user']->id);
        $this->assertSame(['Erased customer', 'erased+'.$account->public_id.'@erased.invalid', null], [$user->name, $user->email, $user->email_verified_at]);
        $this->assertDatabaseMissing('customer_saved_tracks', ['customer_account_id' => $account->id]);
        $this->assertEquals($otherBefore, [DB::table('users')->where('id', $other['user']->id)->first(), DB::table('customer_accounts')->where('id', $other['account']->id)->first()]);
        $this->assertSame(1, DB::table('orders')->where('public_id', $order['order']->public_id)->count());

        $completed = CustomerDataRequest::sole();
        $this->assertSame(['completed', $staff->id, $result['resolutionHash']], [$completed->state, $completed->resolved_by, $completed->resolution_hash]);
        $event = DB::table('customer_data_request_events')->where('request_id', $completed->id)->where('event', 'deletion_completed')->sole();
        $detail = json_decode($event->detail, true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame('Synthetic retention schedule SR-1 (test only)', $detail['retention']['policy_reference']);
        $this->assertSame(7, $detail['retention']['financial_records_years']);
        $retained = array_column($detail['retained'], null, 'record');
        $this->assertEquals(['retention' => 'financial_records_years', 'reason' => 'immutable_record_retained', 'count' => 1],
            array_diff_key($retained['orders'], ['record' => true]));
        $this->assertSame(1, $retained['customer_consent_events']['count']);
        $this->assertSame($staff->id, $event->actor_user_id);
        $this->assertSame(1, AuditEvent::where('action', 'customer.data_deletion.completed')->where('actor_id', $staff->id)->count());
        $this->assertStringNotContainsString('erasable@example.test', AuditEvent::where('action', 'like', 'customer.data_%')->get()->toJson());

        // The withdrawn session cannot act, and consent stays exactly as recorded (never inferred or rewritten).
        $this->postJson('/account/data-requests/export', ['confirm' => true])->assertForbidden();
        $this->assertSame($before['customer_consent_states'], DB::table('customer_consent_states')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->expectRefusal(fn () => app(CustomerDataRights::class)->approveDeletion($request, $staff, 1), DataRightsException::class, 'request_changed');
    }

    public function test_database_guards_keep_requests_forward_only_events_append_only_and_exports_purge_only(): void
    {
        $customer = F::account();
        $this->login($customer);
        $ready = $this->postJson('/account/data-requests/export', ['confirm' => true])->assertCreated()->json('export');
        $request = DB::table('customer_data_requests')->sole();
        $export = DB::table('customer_data_exports')->sole();
        $attempts = [
            'reopen state' => fn () => DB::table('customer_data_requests')->where('id', $request->id)->update(['state' => 'received', 'version' => 1]),
            'skip version' => fn () => DB::table('customer_data_requests')->where('id', $request->id)->update(['state' => 'downloaded', 'version' => 2, 'open_slot' => null, 'resolved_at' => now(), 'updated_at' => now()]),
            'change account' => fn () => DB::table('customer_data_requests')->where('id', $request->id)->update(['customer_account_id' => $customer['account']->id + 1]),
            'delete request' => fn () => DB::table('customer_data_requests')->where('id', $request->id)->delete(),
            'second open export' => fn () => DB::table('customer_data_requests')->insert(['public_id' => '11111111-1111-4111-8111-111111111111',
                'customer_account_id' => $customer['account']->id, 'kind' => 'export', 'state' => 'ready', 'open_slot' => 1, 'version' => 0, 'created_at' => now(), 'updated_at' => now()]),
            'resolved insert' => fn () => DB::table('customer_data_requests')->insert(['public_id' => '22222222-2222-4222-8222-222222222222',
                'customer_account_id' => $customer['account']->id, 'kind' => 'deletion', 'state' => 'completed', 'open_slot' => null, 'version' => 0, 'created_at' => now(), 'updated_at' => now()]),
            'edit event' => fn () => DB::table('customer_data_request_events')->update(['event' => 'export_downloaded']),
            'delete event' => fn () => DB::table('customer_data_request_events')->delete(),
            'event gap' => fn () => DB::table('customer_data_request_events')->insert(['request_id' => $request->id, 'sequence' => 9, 'event' => 'export_expired',
                'detail' => '{}', 'detail_hash' => hash('sha256', '{}'), 'created_at' => now()]),
            'swap token' => fn () => DB::table('customer_data_exports')->where('id', $export->id)->update(['token_hash' => str_repeat('b', 64)]),
            'extend expiry' => fn () => DB::table('customer_data_exports')->where('id', $export->id)->update(['expires_at' => now()->addDay()]),
            'consume without purge' => fn () => DB::table('customer_data_exports')->where('id', $export->id)->update(['consumed_at' => now()]),
            'delete export' => fn () => DB::table('customer_data_exports')->where('id', $export->id)->delete(),
        ];
        foreach ($attempts as $case => $attempt) {
            try {
                $attempt();
                $this->fail("The database accepted: {$case}");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertEquals($request, DB::table('customer_data_requests')->sole());
        $this->assertEquals($export, DB::table('customer_data_exports')->sole());
        $this->postJson('/account/data-requests/export/download', ['requestId' => $ready['requestId'], 'token' => $ready['downloadToken']])->assertOk();
        try {
            DB::table('customer_data_exports')->where('id', $export->id)->update(['ciphertext' => $export->ciphertext, 'purged_at' => null]);
            $this->fail('A purged export was restored.');
        } catch (QueryException) {
            $this->assertNull(DB::table('customer_data_exports')->value('ciphertext'));
        }
    }

    private function expectRefusal(callable $operation, string $class, ?string $reason = null): void
    {
        try {
            $operation();
            $this->fail("Expected {$class}.");
        } catch (\Throwable $error) {
            $this->assertInstanceOf($class, $error);
            if ($reason !== null) {
                $this->assertSame($reason, $error->reason);
            }
        }
    }
}
