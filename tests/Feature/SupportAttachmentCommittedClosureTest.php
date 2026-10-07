<?php

namespace Tests\Feature;

use App\Domain\Inquiries\InquiryAdministration;
use App\Domain\SupportAttachments\AttachmentActor;
use App\Domain\SupportAttachments\AttachmentException;
use App\Domain\SupportAttachments\AttachmentFiles;
use App\Domain\SupportAttachments\AttachmentRegistry;
use App\Domain\SupportAttachments\AttachmentRows;
use App\Domain\SupportAttachments\FixtureAttachmentPolicy;
use App\Domain\SupportAttachments\InquiryAttachmentAuthority;
use App\Domain\SupportAttachments\InquiryCommittedReadReceipt;
use App\Domain\SupportAttachments\SupportAttachments;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InquiryConversationFixtures;
use Tests\Support\TestOnlyMediaScanner;
use Tests\TestCase;

class SupportAttachmentCommittedClosureTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private array $fixture;

    private SupportAttachments $attachments;

    private string $input;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('S', 32))]);
        $this->fakePrivateMediaStorage();
        $this->fixture = InquiryConversationFixtures::create();
        config(['support-attachments.fixture_enabled' => true]);
        $this->attachments = new SupportAttachments(new AttachmentRegistry(['inquiry' => new InquiryAttachmentAuthority], ['original_inquiry_session_v1' => new FixtureAttachmentPolicy]), new AttachmentFiles, app(TestOnlyMediaScanner::class));
        $this->input = tempnam(sys_get_temp_dir(), 'synthetic-committed-');
        file_put_contents($this->input, 'Synthetic original; no customer data.');
        $this->beforeApplicationDestroyed(fn () => @unlink($this->input));
        $this->travelTo(now()->startOfSecond());
    }

    private function source(): string
    {
        return $this->fixture['inquiry']->public_id;
    }

    private function owner(): AttachmentActor
    {
        return AttachmentActor::visitor(InquiryConversationFixtures::OWNER);
    }

    private function ready(): string
    {
        $saved = $this->attachments->intake('inquiry', $this->source(), 0, $this->owner(), (string) Str::uuid(), 'synthetic.txt', $this->input);
        $id = $saved['attachment']['attachmentId'];
        $this->attachments->process('inquiry', $this->source(), 0, $id, 0, $this->owner());

        return $id;
    }

    private function refuses(callable $action): void
    {
        try {
            $action();
            $this->fail('Committed callback released stale private read.');
        } catch (AttachmentException) {
            $this->assertSame(0, DB::transactionLevel());
        }
    }

    private function onFinalDownloadCommit(callable $callback): object
    {
        $count = (object) ['value' => 0];
        $this->app['events']->listen(TransactionCommitted::class, function () use ($callback, $count): void {
            if (++$count->value === 2) {
                $callback();
            }
        });

        return $count;
    }

    public function test_final_inquiry_source_change_refuses_without_touching_original_or_manifest(): void
    {
        $id = $this->ready();
        $before = DB::table('support_attachments')->first();
        $count = $this->onFinalDownloadCommit(fn () => app(InquiryAdministration::class)->transition($this->fixture['inquiry']->id, 'archived', 0, $this->fixture['actor']));
        $this->refuses(fn () => $this->attachments->download('inquiry', $this->source(), $id, $this->owner()));
        $this->assertSame(3, $count->value);
        $this->assertEquals($before, DB::table('support_attachments')->first());
    }

    public function test_final_exact_attachment_row_change_refuses_prepared_original(): void
    {
        $id = $this->ready();
        $count = $this->onFinalDownloadCommit(fn () => DB::table('support_attachments')->where('public_id', $id)->update(['state' => 'deleted']));
        $this->refuses(fn () => $this->attachments->download('inquiry', $this->source(), $id, $this->owner()));
        $this->assertSame(2, $count->value);
        $this->assertSame('deleted', DB::table('support_attachments')->value('state'));
    }

    public function test_final_filesystem_configuration_withdrawal_refuses_prepared_original(): void
    {
        $id = $this->ready();
        $before = DB::table('support_attachments')->first();
        $count = $this->onFinalDownloadCommit(fn () => config(['filesystems.disks.local.root' => '/synthetic-changed-root']));
        $this->refuses(fn () => $this->attachments->download('inquiry', $this->source(), $id, $this->owner()));
        $this->assertSame(2, $count->value);
        $this->assertEquals($before, DB::table('support_attachments')->first());
    }

    public function test_final_original_expiry_crossing_refuses_prepared_original(): void
    {
        $id = $this->ready();
        $expiry = CarbonImmutable::createFromTimestampUTC((int) DB::table('support_attachments')->value('expires_at'));
        $count = $this->onFinalDownloadCommit(fn () => $this->travelTo($expiry));
        $this->refuses(fn () => $this->attachments->download('inquiry', $this->source(), $id, $this->owner()));
        $this->assertSame(2, $count->value);
        $this->assertSame('ready', DB::table('support_attachments')->value('state'));
    }

    public function test_second_authentic_scan_decrypt_cannot_move_original_deadline_before_receipt_creation(): void
    {
        $id = $this->ready();
        $row = DB::table('support_attachments')->first();
        $expiry = CarbonImmutable::createFromTimestampUTC((int) $row->expires_at);
        $count = (object) ['value' => 0];
        $advance = fn () => $this->travelTo($expiry);
        Crypt::swap(new class(str_repeat('S', 32), $row->scan_evidence, $count, $advance) extends Encrypter
        {
            public function __construct(string $key, private string $target, private object $count, private \Closure $advance)
            {
                parent::__construct($key, 'AES-256-CBC');
            }

            public function decryptString($payload)
            {
                $result = parent::decryptString($payload);
                if ($payload === $this->target && ++$this->count->value === 2) {
                    ($this->advance)();
                }

                return $result;
            }
        });
        $this->refuses(fn () => $this->attachments->download('inquiry', $this->source(), $id, $this->owner()));
        $this->assertSame(2, $count->value);
        $this->assertSame('ready', DB::table('support_attachments')->value('state'));
    }

    public function test_list_final_commit_policy_and_range_changes_cannot_release_old_private_projection(): void
    {
        $this->ready();
        $count = (object) ['value' => 0];
        $this->app['events']->listen(TransactionCommitted::class, function () use ($count): void {
            if (++$count->value === 1) {
                config(['support-attachments.fixture_enabled' => false]);
            }
        });
        $this->refuses(fn () => $this->attachments->list('inquiry', $this->source(), $this->owner()));
        $this->assertSame(1, $count->value);
        $this->assertSame('ready', DB::table('support_attachments')->value('state'));
    }

    public function test_rollback_receipt_cannot_be_adopted_after_an_unrelated_later_commit(): void
    {
        $authority = new InquiryAttachmentAuthority;
        DB::beginTransaction();
        $rows = new AttachmentRows;
        $proof = $authority->lock($this->source(), null, 'list', $this->owner(), $rows);
        $receipt = $authority->committedReadReceipt($proof, $rows);
        DB::rollBack();
        DB::transaction(fn () => null);
        $this->refuses(fn () => $receipt->proveClosed());
        $this->refuses(fn () => InquiryCommittedReadReceipt::issued($authority)->proveClosed());
    }

    public function test_source_receipt_mint_after_direct_commit_reopen_is_not_a_new_transaction_grant(): void
    {
        $authority = new InquiryAttachmentAuthority;
        DB::beginTransaction();
        $rows = new AttachmentRows;
        $proof = $authority->lock($this->source(), null, 'list', $this->owner(), $rows);
        $pdo = $rows->identity();
        $pdo->commit();
        $pdo->beginTransaction();
        try {
            $authority->committedReadReceipt($proof, $rows);
            $this->fail('Direct commit/reopen adopted old source token.');
        } catch (\PDOException|AttachmentException) {
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
    }
}
