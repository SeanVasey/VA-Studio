<?php

namespace Tests\Feature;

use App\Domain\Inquiries\InquiryException;
use App\Domain\Inquiries\InquiryPolicy;
use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\Inquiries\SubmitInquiry;
use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InquiryOperatorRace;
use Tests\Support\InquiryRace;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/** Committed synthetic fixtures; the workers use independent PHP processes and MySQL connections. */
class CustomerInquiryConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent inquiry admission locking requires MySQL, not SQLite.');
        }
    }

    /** @return array{User, SiteRelease} */
    private function publishedContact(): array
    {
        $operator = LicenseFixtures::admin();
        $content = SiteContentSchema::forEditing(SiteContentSchema::defaults());
        $content['contact'] = ['title' => 'Synthetic race contact', 'description' => 'Disposable race fixture.',
            'paragraphs' => ['Synthetic private inquiries only.'], 'email' => 'operator@example.test'];
        $site = app(SiteContent::class);
        $release = $site->create($content, 'Synthetic inquiry race', $operator);
        $site->publish($release->id, 0, $operator);
        config(['inquiries.enabled' => true, 'inquiries.privacy_notice' => 'SYNTHETIC RACE PRIVACY NOTICE',
            'inquiries.retention_policy_reference' => 'SYNTHETIC-RACE-RETENTION', 'inquiries.operator_user_id' => $operator->id]);

        return [$operator, $release];
    }

    private function payload(): array
    {
        return ['name' => 'Synthetic Race Buyer', 'email' => 'race-buyer@example.test', 'subject' => 'Synthetic concurrency inquiry',
            'message' => "Synthetic private message.\nRetain exact input.", 'website' => '', 'requestKey' => (string) Str::uuid()];
    }

    private function inquiryJob(array $payload, string $owner, User $operator): array
    {
        return ['operation' => 'submit', 'payload' => $payload, 'owner_hash' => $owner, 'operator_id' => $operator->id];
    }

    public function test_competing_owners_with_one_global_request_key_save_exactly_one_inquiry_and_receipt_audit(): void
    {
        [$operator, $release] = $this->publishedContact();
        $payload = $this->payload();
        $owners = [hash('sha256', 'synthetic-original-session'), hash('sha256', 'synthetic-rotated-session')];
        $race = InquiryRace::run($this, array_map(fn ($owner) => $this->inquiryJob($payload, $owner, $operator), $owners));
        $winner = $race['winner'];
        $loser = 1 - $winner;
        $saved = $race['results'][$winner];
        $conflict = $race['results'][$loser];
        $this->assertSame(['saved', false], [$saved['state'], $saved['replayed']]);
        $this->assertSame(['rejected', 409], [$conflict['result'], $conflict['status']]);
        $this->assertArrayNotHasKey('receipt', $conflict);
        $this->assertArrayNotHasKey('state', $conflict);
        $inquiry = CustomerInquiry::sole();
        $this->assertSame($saved['receipt'], $inquiry->public_id);
        $this->assertSame($owners[$winner], $inquiry->owner_hash);
        $this->assertSame($payload['requestKey'], $inquiry->request_key);
        $this->assertSame(array_diff_key($payload, ['requestKey' => true]), $inquiry->payload);
        $this->assertSame($release->id, $inquiry->site_release_id);
        $audit = AuditEvent::where('action', 'inquiry.received')->sole();
        $this->assertSame($inquiry->id, (int) $audit->subject_id);
        $this->assertSame($inquiry->public_id, $audit->context['receipt']);
        $before = $inquiry->getAttributes();
        $replay = app(SubmitInquiry::class)->handle($payload, $owners[$winner]);
        $this->assertSame(['state' => 'saved', 'receipt' => $inquiry->public_id, 'replayed' => true], $replay);
        $this->assertDenied($payload, $owners[$loser], 409);
        $this->assertSame($before, $inquiry->fresh()->getAttributes());
        $this->assertDatabaseCount('customer_inquiries', 1);
        $this->assertSame(1, AuditEvent::where('action', 'inquiry.received')->count());
    }

    public static function lockOrders(): array
    {
        return ['admission takes the lock first' => [0], 'withdrawal takes the lock first' => [1]];
    }

    #[DataProvider('lockOrders')]
    public function test_publication_withdrawal_and_admission_share_one_mutex_without_stale_admission(int $first): void
    {
        [$operator, $release] = $this->publishedContact();
        $content = SiteContentSchema::forEditing($release->content);
        $content['contact'] = null;
        $withdrawal = app(SiteContent::class)->create($content, 'Synthetic contact withdrawal', $operator);
        $payload = $this->payload();
        $owner = hash('sha256', 'synthetic-withdrawal-session');
        $race = InquiryRace::run($this, [
            $this->inquiryJob($payload, $owner, $operator),
            ['operation' => 'withdraw', 'release_id' => $withdrawal->id, 'revision' => 1, 'operator_id' => $operator->id],
        ], $first);
        $this->assertSame($first, $race['winner']);
        $this->assertSame('published', $race['results'][1]['result']);
        $this->assertSame([2, $withdrawal->id], [SitePublication::findOrFail(1)->revision, SitePublication::findOrFail(1)->active_release_id]);
        $this->assertNull(app(SiteContent::class)->current()['contact']);
        if ($first === 0) {
            $this->assertSame(['saved', false], [$race['results'][0]['state'], $race['results'][0]['replayed']]);
            $inquiry = CustomerInquiry::sole();
            $this->assertSame($race['results'][0]['receipt'], $inquiry->public_id);
            $this->assertSame([$release->id, $release->content_hash], [$inquiry->site_release_id, $inquiry->site_content_hash]);
            $before = $inquiry->getAttributes();
            $this->assertDenied($payload, $owner, 404);
            $this->assertSame($before, $inquiry->fresh()->getAttributes());
        } else {
            $this->assertSame(['rejected', 404], [$race['results'][0]['result'], $race['results'][0]['status']]);
            $this->assertArrayNotHasKey('receipt', $race['results'][0]);
            $this->assertArrayNotHasKey('state', $race['results'][0]);
            $this->assertDenied($payload, $owner, 404);
        }
        $this->assertDenied($this->payload(), $owner, 404);
        $this->assertDatabaseCount('customer_inquiries', $first === 0 ? 1 : 0);
        $this->assertSame($first === 0 ? 1 : 0, AuditEvent::where('action', 'inquiry.received')->count());
        $this->assertSame(2, AuditEvent::where('action', 'site.release.publish')->count());
        $this->assertSame($release->content_hash, $release->fresh()->content_hash);
    }

    public static function operatorRevocations(): array
    {
        return [
            'admin admission first' => ['is_admin', false, 0],
            'admin revocation first' => ['is_admin', false, 1],
            'verified email admission first' => ['email_verified_at', null, 0],
            'verified email revocation first' => ['email_verified_at', null, 1],
            'MFA enrollment admission first' => ['app_authentication_secret', null, 0],
            'MFA enrollment revocation first' => ['app_authentication_secret', null, 1],
        ];
    }

    #[DataProvider('operatorRevocations')]
    public function test_operator_authority_revocation_and_admission_share_the_current_user_lock(string $field, mixed $value, int $first): void
    {
        [$operator, $release] = $this->publishedContact();
        $operator->saveAppAuthenticationSecret('ABCDEFGHIJKLMNOP');
        $panel = Filament::getPanel('admin');
        $required = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);

        try {
            $this->assertNotNull(app(InquiryPolicy::class)->publicSetup(app(SiteContent::class)->current()));
            $this->assertFalse(Gate::forUser($operator)->allows('administer-catalog', [true]));
            $this->assertFalse(AdminMultiFactor::satisfiedBy($operator, lockForUpdate: true));
            $this->assertNull(app(InquiryPolicy::class)->publicSetup(app(SiteContent::class)->current(), lockForUpdate: true));
            $before = $this->operatorRaceEvidence();
            $operatorBefore = (array) DB::table('users')->where('id', $operator->id)->first();
            $payload = $this->payload();
            $owner = hash('sha256', 'synthetic-operator-revocation-session');
            $race = InquiryOperatorRace::run($this, $payload, $owner, $operator->id, $field, $first);
            $this->assertSame($first, $race['winner']);
            $this->assertSame(['revoked', $field], [$race['results'][1]['result'], $race['results'][1]['field']]);
            $expectedOperator = $operatorBefore;
            $expectedOperator[$field] = $value === false ? 0 : $value;
            $this->assertSame($expectedOperator, (array) DB::table('users')->where('id', $operator->id)->first());
            $after = $this->operatorRaceEvidence();

            if ($first === 0) {
                $this->assertSame(['saved', false], [$race['results'][0]['state'], $race['results'][0]['replayed']]);
                $inquiry = CustomerInquiry::sole();
                $this->assertSame($race['results'][0]['receipt'], $inquiry->public_id);
                $this->assertSame($owner, $inquiry->owner_hash);
                $this->assertSame($payload['requestKey'], $inquiry->request_key);
                $expectedPayload = array_diff_key($payload, ['requestKey' => true]);
                $actualPayload = $inquiry->payload;
                ksort($expectedPayload, SORT_STRING);
                ksort($actualPayload, SORT_STRING);
                $this->assertSame($expectedPayload, $actualPayload);
                $this->assertSame(CanonicalJson::hash($expectedPayload), $inquiry->payload_hash);
                $this->assertSame($operator->id, $inquiry->operator_user_id);
                $this->assertSame([$release->id, $release->content_hash], [$inquiry->site_release_id, $inquiry->site_content_hash]);
                $this->assertSame('SYNTHETIC RACE PRIVACY NOTICE', $inquiry->privacy_notice);
                $this->assertSame(hash('sha256', 'SYNTHETIC RACE PRIVACY NOTICE'), $inquiry->privacy_notice_hash);
                $this->assertSame('SYNTHETIC-RACE-RETENTION', $inquiry->retention_policy_reference);
                $this->assertSame(['new', 0], [$inquiry->state, $inquiry->version]);
                $this->assertCount(1, $after['customer_inquiries']);
                $audit = AuditEvent::where('action', 'inquiry.received')->sole();
                $this->assertNull($audit->actor_id);
                $this->assertSame(CustomerInquiry::class, $audit->subject_type);
                $this->assertSame($inquiry->id, $audit->subject_id);
                $expectedContext = ['receipt' => $inquiry->public_id, 'site_release_id' => $release->id,
                    'privacy_notice_hash' => $inquiry->privacy_notice_hash];
                $actualContext = $audit->context;
                ksort($expectedContext, SORT_STRING);
                ksort($actualContext, SORT_STRING);
                $this->assertSame($expectedContext, $actualContext);
                $this->assertCount(count($before['audit_events']) + 1, $after['audit_events']);
                $this->assertSame($before['audit_events'], array_slice($after['audit_events'], 0, count($before['audit_events'])));
                foreach (['site_publications', 'site_releases', 'site_publication_revisions'] as $table) {
                    $this->assertSame($before[$table], $after[$table]);
                }
            } else {
                $this->assertSame(['rejected', 404], [$race['results'][0]['result'], $race['results'][0]['status']]);
                $this->assertArrayNotHasKey('receipt', $race['results'][0]);
                $this->assertArrayNotHasKey('state', $race['results'][0]);
                $this->assertSame($before, $after);
            }
            // Once revocation has committed, neither new admission nor an exact replay can proceed.
            $this->assertDenied($payload, $owner, 404);
            $this->assertDenied($this->payload(), $owner, 404);
            $this->assertSame($after, $this->operatorRaceEvidence());
            $this->assertSame($expectedOperator, (array) DB::table('users')->where('id', $operator->id)->first());
            $this->assertDatabaseCount('customer_inquiries', $first === 0 ? 1 : 0);
            $this->assertSame($first === 0 ? 1 : 0, AuditEvent::where('action', 'inquiry.received')->count());
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
        }
    }

    /** Complete persisted raw columns/values; rejected admission and later replays cannot alter retained evidence. */
    private function operatorRaceEvidence(): array
    {
        $evidence = [];
        foreach (['customer_inquiries', 'audit_events', 'site_publications', 'site_releases', 'site_publication_revisions'] as $table) {
            $evidence[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $evidence;
    }

    private function assertDenied(array $payload, string $owner, int $status): void
    {
        try {
            app(SubmitInquiry::class)->handle($payload, $owner);
            $this->fail('Inquiry admission should have been denied.');
        } catch (InquiryException $error) {
            $this->assertSame($status, $error->status);
        }
    }
}
