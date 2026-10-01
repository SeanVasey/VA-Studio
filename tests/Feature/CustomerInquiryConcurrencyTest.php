<?php

namespace Tests\Feature;

use App\Domain\Inquiries\InquiryException;
use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\Inquiries\SubmitInquiry;
use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
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
