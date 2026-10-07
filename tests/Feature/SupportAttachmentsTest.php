<?php

namespace Tests\Feature;

use App\Domain\Inquiries\InquiryAdministration;
use App\Domain\Media\MalwareScanner;
use App\Domain\SupportAttachments\AttachmentActor;
use App\Domain\SupportAttachments\AttachmentException;
use App\Domain\SupportAttachments\AttachmentFiles;
use App\Domain\SupportAttachments\AttachmentRegistry;
use App\Domain\SupportAttachments\AttachmentRemoval;
use App\Domain\SupportAttachments\FixtureAttachmentPolicy;
use App\Domain\SupportAttachments\InquiryAttachmentAuthority;
use App\Domain\SupportAttachments\SupportAttachments;
use Filament\Facades\Filament;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\InquiryConversationFixtures as Fixture;
use Tests\Support\TestOnlyMediaScanner;
use Tests\TestCase;

class SupportAttachmentsTest extends TestCase
{
    private array $fixture;

    private SupportAttachments $service;

    private TestOnlyMediaScanner $scanner;

    private string $input;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('S', 32))]);
        if (DB::getDriverName() === 'mysql') {
            if (getenv('ATTACHMENT_NATIVE_ISOLATED') !== '1') {
                $this->markTestSkipped('Native consumer fixtures require the dedicated attachment-author database and explicit isolated-test marker.');
            }
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        } else {
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        }
        $this->fakePrivateMediaStorage();
        $this->fixture = Fixture::create();
        config(['support-attachments.fixture_enabled' => true]);
        $this->scanner = app(TestOnlyMediaScanner::class);
        $this->service = new SupportAttachments(new AttachmentRegistry(['inquiry' => new InquiryAttachmentAuthority], ['original_inquiry_session_v1' => new FixtureAttachmentPolicy]), new AttachmentFiles, $this->scanner);
        $this->input = tempnam(sys_get_temp_dir(), 'synthetic-support-');
        file_put_contents($this->input, "SYNTHETIC ATTACHMENT FIXTURE.\nNo customer information.\n");
        $this->beforeApplicationDestroyed(fn () => @unlink($this->input));
    }

    public function test_owned_intake_scan_download_delete_preserves_encrypted_manifest_and_source_history(): void
    {
        $source = $this->fixture['inquiry']->getAttributes();
        $request = (string) Str::uuid();
        $saved = $this->intake($request);
        $id = $saved['attachment']['attachmentId'];
        $this->assertFalse($saved['replayed']);
        $this->assertSame('quarantined', $saved['attachment']['state']);
        $this->assertFalse($saved['attachment']['canDownload']);
        $this->assertSame(0, $saved['attachment']['attempt']);
        $this->assertSame($id, $this->intake($request)['attachment']['attachmentId']);
        $this->assertDatabaseCount('support_attachments', 1);
        $this->assertStringNotContainsString('synthetic.txt', DB::table('support_attachments')->first()->manifest);
        $ready = $this->process($id, 0);
        $this->assertSame('ready', $ready['state']);
        $this->assertTrue($ready['canDownload']);
        $download = $this->service->download('inquiry', $this->source(), $id, $this->owner());
        $output = fopen('php://temp', 'w+b');
        $download['stream']->writeTo($output);
        rewind($output);
        $this->assertSame(file_get_contents($this->input), stream_get_contents($output));
        fclose($output);
        $deleted = $this->service->delete('inquiry', $this->source(), $id, $this->owner());
        $this->assertSame('deleted', $deleted['state']);
        $this->assertSame('complete', $deleted['cleanup']);
        $this->assertSame('complete', $this->service->delete('inquiry', $this->source(), $id, $this->owner())['cleanup']);
        $this->refused(fn () => $this->service->download('inquiry', $this->source(), $id, $this->owner()), 409);
        $this->assertSame($source, $this->fixture['inquiry']->fresh()->getAttributes());
        $this->assertDatabaseCount('support_attachments', 1);
        $projection = json_encode($this->service->list('inquiry', $this->source(), $this->owner()));
        foreach (['owner_hash', 'actor_hash', 'source_binding', 'storage_path', 'original.bin', Fixture::OWNER] as $secret) {
            $this->assertStringNotContainsString($secret, $projection);
        }
    }

    public function test_archival_closes_new_intake_and_process_but_preserves_ready_original_and_exact_replay(): void
    {
        $request = (string) Str::uuid();
        $id = $this->intake($request)['attachment']['attachmentId'];
        $this->process($id, 0);
        app(InquiryAdministration::class)->transition($this->fixture['inquiry']->id, 'archived', 0, $this->fixture['actor']);
        $this->assertTrue($this->intake($request)['replayed']);
        $this->refused(fn () => $this->intake(), 409);
        $download = $this->service->download('inquiry', $this->source(), $id, $this->owner());
        $download['stream']->close();
        $this->assertSame('deleted', $this->service->delete('inquiry', $this->source(), $id, $this->owner())['state']);
    }

    public function test_wrong_owner_source_and_revoked_operator_cannot_read_or_mutate(): void
    {
        $id = $this->intake()['attachment']['attachmentId'];
        $this->refused(fn () => $this->service->list('inquiry', $this->source(), AttachmentActor::visitor(str_repeat('b', 64))), 404);
        $this->refused(fn () => $this->service->delete('inquiry', (string) Str::uuid(), $id, $this->owner()), 404);
        $actor = $this->fixture['actor'];
        $this->assertCount(1, $this->service->list('inquiry', $this->source(), AttachmentActor::operator($actor))['attachments']);
        $actor->fresh()->forceFill(['is_admin' => false])->save();
        $this->refused(fn () => $this->service->list('inquiry', $this->source(), AttachmentActor::operator($actor)), 403);
        $this->assertSame('quarantined', DB::table('support_attachments')->first()->state);
    }

    public function test_scanner_failure_is_truthful_and_explicit_retry_is_fenced(): void
    {
        $id = $this->intake()['attachment']['attachmentId'];
        $this->scanner->reject = true;
        $failed = $this->process($id, 0);
        $this->assertSame('failed', $failed['state']);
        $this->assertFalse($failed['canDownload']);
        $this->assertTrue($failed['canRetry']);
        $this->refused(fn () => $this->process($id, 0), 409);
        $this->scanner->reject = false;
        $this->assertSame('ready', $this->process($id, 1)['state']);
        $this->assertSame(2, $this->process($id, 2)['attempt']);
    }

    public function test_changed_private_original_never_becomes_ready_or_downloadable(): void
    {
        $id = $this->intake()['attachment']['attachmentId'];
        $path = Storage::disk('local')->path('support-attachments/originals/'.$id.'/original.bin');
        chmod($path, 0600);
        file_put_contents($path, 'changed synthetic bytes');
        chmod($path, 0400);
        $failed = $this->process($id, 0);
        $this->assertSame('failed', $failed['state']);
        $this->refused(fn () => $this->service->download('inquiry', $this->source(), $id, $this->owner()), 409);
    }

    public function test_unknown_intake_ack_exact_replay_retains_one_file_and_one_manifest(): void
    {
        $request = (string) Str::uuid();
        $fail = true;
        DB::connection()->setEventDispatcher(clone DB::connection()->getEventDispatcher());
        DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, function () use (&$fail): void {
            if ($fail && DB::table('support_attachments')->exists()) {
                $fail = false;
                throw new \RuntimeException('Synthetic unknown commit acknowledgement');
            }
        });
        try {
            $this->intake($request);
            $this->fail('Synthetic acknowledgement was not interrupted.');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertDatabaseCount('support_attachments', 1);
        $this->assertTrue($this->intake($request)['replayed']);
        $files = Storage::disk('local')->allFiles('support-attachments/originals');
        $this->assertCount(1, array_filter($files, fn ($file) => str_ends_with($file, '/original.bin')));
    }

    public function test_archive_and_owner_change_during_scanner_io_cannot_finish_claim(): void
    {
        $id = $this->intake()['attachment']['attachmentId'];
        $scanner = new class extends TestOnlyMediaScanner
        {
            public $callback;

            public function scan(string $path): array
            {
                ($this->callback)();

                return parent::scan($path);
            }
        };
        $scanner->callback = fn () => app(InquiryAdministration::class)->transition($this->fixture['inquiry']->id, 'archived', 0, $this->fixture['actor']);
        $service = new SupportAttachments(new AttachmentRegistry(['inquiry' => new InquiryAttachmentAuthority], ['original_inquiry_session_v1' => new FixtureAttachmentPolicy]), new AttachmentFiles, $scanner);
        $this->refused(fn () => $service->process('inquiry', $this->source(), 0, $id, 0, $this->owner()), 409);
        $this->assertSame('scanning', DB::table('support_attachments')->first()->state);
        $this->assertDatabaseCount('support_attachments', 1);
    }

    public function test_disabled_policy_and_production_environment_refuse_all_effects(): void
    {
        config(['support-attachments.fixture_enabled' => false]);
        $this->refused(fn () => $this->intake(), 503);
        $this->assertDatabaseCount('support_attachments', 0);
        config(['support-attachments.fixture_enabled' => true]);
        app()->detectEnvironment(fn () => 'production');
        $this->refused(fn () => $this->intake(), 503);
        $this->assertDatabaseCount('support_attachments', 0);
    }

    public function test_archive_executable_and_oversize_bytes_are_refused(): void
    {
        foreach (["PK\x03\x04synthetic archive", 'MZsynthetic executable', str_repeat('s', 5242881)] as $bytes) {
            file_put_contents($this->input, $bytes);
            $this->refused(fn () => $this->intake(), strlen($bytes) > 5242880 ? 422 : 422);
        }
        $this->assertDatabaseCount('support_attachments', 0);
    }

    public function test_file_copy_handoff_ambiguity_retains_bounded_receiving_intent_and_exact_retry(): void
    {
        $files = new class extends AttachmentFiles
        {
            public function preserve(string $id, string $path, int $max, ?array $expected = null): array
            {
                $result = parent::preserve($id, $path, $max, $expected);
                throw new \RuntimeException('Synthetic unknown file acknowledgement');
            }
        };
        $service = new SupportAttachments(new AttachmentRegistry(['inquiry' => new InquiryAttachmentAuthority], ['original_inquiry_session_v1' => new FixtureAttachmentPolicy]), $files, $this->scanner);
        $key = (string) Str::uuid();
        try {
            $service->intake('inquiry', $this->source(), 0, $this->owner(), $key, 'synthetic.txt', $this->input);
            $this->fail('Synthetic file handoff completed.');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertDatabaseCount('support_attachments', 1);
        $row = DB::table('support_attachments')->first();
        $this->assertSame('receiving', $row->state);
        $dto = $this->service->list('inquiry', $this->source(), $this->owner())['attachments'][0];
        $this->assertSame(0, $dto['receivedBytes']);
        $this->assertFalse($dto['canDownload']);
        $retried = $this->intake($key);
        $this->assertTrue($retried['replayed']);
        $this->assertSame('quarantined', $retried['attachment']['state']);
        $this->assertDatabaseCount('support_attachments', 1);
    }

    public function test_deleted_request_replay_and_changed_file_never_restore_or_replace_bytes(): void
    {
        $key = (string) Str::uuid();
        $id = $this->intake($key)['attachment']['attachmentId'];
        $this->service->delete('inquiry', $this->source(), $id, $this->owner());
        $this->assertSame('deleted', $this->intake($key)['attachment']['state']);
        $path = Storage::disk('local')->path('support-attachments/originals/'.$id.'/original.bin');
        $this->assertFileDoesNotExist($path);
        file_put_contents($this->input, 'Different synthetic file.');
        $this->refused(fn () => $this->intake($key), 409);
        $this->assertFileDoesNotExist($path);
        $this->assertDatabaseCount('support_attachments', 1);
    }

    public function test_unknown_scanner_claim_ack_requires_current_status_and_explicit_fenced_retry(): void
    {
        $id = $this->intake()['attachment']['attachmentId'];
        $fail = true;
        DB::connection()->setEventDispatcher(clone DB::connection()->getEventDispatcher());
        DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, function () use (&$fail): void {
            if ($fail && DB::table('support_attachments')->where('state', 'scanning')->exists()) {
                $fail = false;
                throw new \RuntimeException('Synthetic unknown scanner claim acknowledgement');
            }
        });
        try {
            $this->process($id, 0);
            $this->fail('Synthetic claim acknowledgement completed.');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('scanning', DB::table('support_attachments')->first()->state);
        $this->refused(fn () => $this->process($id, 0), 409);
        $this->refused(fn () => $this->process($id, 1), 409);
        $this->travel(61)->seconds();
        $this->assertSame('ready', $this->process($id, 1)['state']);
        $this->assertSame(2, DB::table('support_attachments')->first()->attempt);
    }

    public function test_expired_scanner_worker_cannot_overwrite_later_winner(): void
    {
        $id = $this->intake()['attachment']['attachmentId'];
        $scanner = new class extends TestOnlyMediaScanner
        {
            public $callback;

            public function scan(string $path): array
            {
                ($this->callback)();

                return parent::scan($path);
            }
        };
        $scanner->callback = function () use ($id): void {
            $this->travel(61)->seconds();
            $this->assertSame('ready', $this->process($id, 1)['state']);
        };
        $service = new SupportAttachments(new AttachmentRegistry(['inquiry' => new InquiryAttachmentAuthority], ['original_inquiry_session_v1' => new FixtureAttachmentPolicy]), new AttachmentFiles, $scanner);
        $this->refused(fn () => $service->process('inquiry', $this->source(), 0, $id, 0, $this->owner()), 409);
        $this->assertSame('ready', DB::table('support_attachments')->first()->state);
        $this->assertSame(2, DB::table('support_attachments')->first()->attempt);
    }

    public function test_expiry_closes_access_and_explicit_deletion_retains_manifest(): void
    {
        $id = $this->intake()['attachment']['attachmentId'];
        $this->process($id, 0);
        $this->travel(86401)->seconds();
        $dto = $this->service->list('inquiry', $this->source(), $this->owner())['attachments'][0];
        $this->assertSame('expired', $dto['state']);
        $this->assertFalse($dto['canDownload']);
        $this->assertFalse($dto['canRetry']);
        $this->refused(fn () => $this->service->download('inquiry', $this->source(), $id, $this->owner()), 409);
        $this->assertSame('expired', $this->service->delete('inquiry', $this->source(), $id, $this->owner())['state']);
        $this->assertDatabaseCount('support_attachments', 1);
    }

    public function test_last_staff_query_policy_withdrawal_refuses_before_retaining_or_mutating(): void
    {
        $actor = AttachmentActor::operator($this->fixture['actor']);
        $count = 0;
        DB::listen(function ($event) use (&$count): void {
            if (str_contains($event->sql, 'users') && str_starts_with($event->sql, 'select')) {
                $count++;
            }
        });
        $this->service->list('inquiry', $this->source(), $actor);
        $expected = $count;
        $count = 0;
        $armed = true;
        DB::listen(function ($event) use (&$count, &$armed, $expected): void {
            if ($armed && str_contains($event->sql, 'users') && str_starts_with($event->sql, 'select') && $count === $expected) {
                $armed = false;
                config(['support-attachments.fixture_enabled' => false]);
            }
        });
        $this->refused(fn () => $this->service->list('inquiry', $this->source(), $actor), 503);
        $this->assertFalse($armed);
        $this->assertDatabaseCount('support_attachments', 0);
    }

    public function test_missing_real_scanner_and_wrong_digest_remain_quarantined_failures(): void
    {
        config(['media.clamscan' => '/synthetic-uninstalled-scanner']);
        $id = $this->intake()['attachment']['attachmentId'];
        $service = new SupportAttachments(new AttachmentRegistry(['inquiry' => new InquiryAttachmentAuthority], ['original_inquiry_session_v1' => new FixtureAttachmentPolicy]), new AttachmentFiles, app(MalwareScanner::class));
        $this->assertSame('failed', $service->process('inquiry', $this->source(), 0, $id, 0, $this->owner())['state']);
        $scanner = new class extends TestOnlyMediaScanner
        {
            public function scan(string $path): array
            {
                return array_replace(parent::scan($path), ['sha256' => str_repeat('f', 64)]);
            }
        };
        $service = new SupportAttachments(new AttachmentRegistry(['inquiry' => new InquiryAttachmentAuthority], ['original_inquiry_session_v1' => new FixtureAttachmentPolicy]), new AttachmentFiles, $scanner);
        $this->assertSame('failed', $service->process('inquiry', $this->source(), 0, $id, 1, $this->owner())['state']);
    }

    public function test_three_snapshot_slots_are_bounded_and_source_inode_links_are_refused(): void
    {
        $id = $this->intake()['attachment']['attachmentId'];
        $this->process($id, 0);
        $held = [];
        for ($i = 0; $i < 3; $i++) {
            $held[] = $this->service->download('inquiry', $this->source(), $id, $this->owner())['stream'];
        }
        $this->refused(fn () => $this->service->download('inquiry', $this->source(), $id, $this->owner()), 503);
        foreach ($held as $snapshot) {
            $snapshot->close();
        }
        $path = Storage::disk('local')->path('support-attachments/originals/'.$id.'/original.bin');
        link($path, $path.'.linked');
        $this->refused(fn () => $this->service->download('inquiry', $this->source(), $id, $this->owner()), 503);
        unlink($path.'.linked');
    }

    public function test_scanner_policy_change_during_io_cannot_promote_old_claim(): void
    {
        $id = $this->intake()['attachment']['attachmentId'];
        $scanner = new class extends TestOnlyMediaScanner
        {
            public function scan(string $path): array
            {
                $result = parent::scan($path);
                config(['media.max_signature_age_seconds' => 1]);

                return $result;
            }
        };
        $service = new SupportAttachments(new AttachmentRegistry(['inquiry' => new InquiryAttachmentAuthority], ['original_inquiry_session_v1' => new FixtureAttachmentPolicy]), new AttachmentFiles, $scanner);
        $this->refused(fn () => $service->process('inquiry', $this->source(), 0, $id, 0, $this->owner()), 503);
        $this->assertSame('scanning', DB::table('support_attachments')->first()->state);
    }

    public function test_temporary_source_and_manifest_shadows_refuse_without_private_projection(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TEMPORARY TABLE customer_inquiries (id INTEGER)');
        $this->refused(fn () => $this->service->list('inquiry', $this->source(), $this->owner()), 503);
        $pdo->exec('DROP TABLE customer_inquiries');
        $pdo->exec('CREATE TEMPORARY TABLE support_attachments (id INTEGER)');
        $this->refused(fn () => $this->service->list('inquiry', $this->source(), $this->owner()), 503);
        $pdo->exec('DROP TABLE support_attachments');
    }

    public function test_real_immutable_manifest_and_tombstone_guards_refuse_updates_and_deletion(): void
    {
        $id = $this->intake()['attachment']['attachmentId'];
        $this->service->delete('inquiry', $this->source(), $id, $this->owner());
        foreach ([fn () => DB::table('support_attachments')->where('public_id', $id)->update(['bytes' => 7]), fn () => DB::table('support_attachments')->where('public_id', $id)->update(['state' => 'ready']), fn () => DB::table('support_attachments')->where('public_id', $id)->delete()] as $operation) {
            try {
                $operation();
                $this->fail('Immutable evidence or tombstone was mutated.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertDatabaseCount('support_attachments', 1);
        $this->assertSame('deleted', DB::table('support_attachments')->first()->state);
    }

    public function test_terminal_dto_encrypter_policy_withdrawal_cannot_commit_or_remove_original(): void
    {
        $id = $this->intake()['attachment']['attachmentId'];
        $this->cryptoCallback(8, fn () => config(['support-attachments.fixture_enabled' => false]));
        $this->refused(fn () => $this->service->delete('inquiry', $this->source(), $id, $this->owner()), 503);
        $this->assertSame('quarantined', DB::table('support_attachments')->first()->state);
        $this->assertFileExists(Storage::disk('local')->path('support-attachments/originals/'.$id.'/original.bin'));
    }

    public function test_terminal_dto_encrypter_row_mutation_cannot_return_stale_ready_state(): void
    {
        $id = $this->intake()['attachment']['attachmentId'];
        $this->process($id, 0);
        $this->cryptoCallback(4, fn () => DB::table('support_attachments')->where('public_id', $id)->update(['state' => 'failed']));
        $this->refused(fn () => $this->process($id, 1), 409);
        $this->assertSame('ready', DB::table('support_attachments')->first()->state);
    }

    public function test_storage_preparation_revoking_operator_cannot_authorize_a_tombstone(): void
    {
        $id = $this->intake()['attachment']['attachmentId'];
        $files = new class extends AttachmentFiles
        {
            public $callback;

            public function prepareRemoval(string $id, array $entry): AttachmentRemoval
            {
                $prepared = parent::prepareRemoval($id, $entry);
                ($this->callback)();

                return $prepared;
            }
        };
        $files->callback = fn () => $this->fixture['actor']->fresh()->forceFill(['is_admin' => false])->save();
        $service = new SupportAttachments(new AttachmentRegistry(['inquiry' => new InquiryAttachmentAuthority], ['original_inquiry_session_v1' => new FixtureAttachmentPolicy]), $files, $this->scanner);
        $this->refused(fn () => $service->delete('inquiry', $this->source(), $id, AttachmentActor::operator($this->fixture['actor'])), 403);
        $this->assertSame('quarantined', DB::table('support_attachments')->first()->state);
        $this->assertFileExists(Storage::disk('local')->path('support-attachments/originals/'.$id.'/original.bin'));
    }

    public function test_positive_tombstone_authorizes_exact_cleanup_after_commit_callback_revokes_actor(): void
    {
        $id = $this->intake()['attachment']['attachmentId'];
        $events = clone DB::connection()->getEventDispatcher();
        DB::connection()->setEventDispatcher($events);
        $revoked = false;
        $events->listen(TransactionCommitted::class, function () use (&$revoked): void {
            if (! $revoked && DB::table('support_attachments')->first()->state === 'deleted') {
                $revoked = true;
                $this->fixture['actor']->fresh()->forceFill(['is_admin' => false])->save();
            }
        });
        $deleted = $this->service->delete('inquiry', $this->source(), $id, AttachmentActor::operator($this->fixture['actor']));
        $this->assertTrue($revoked);
        $this->assertSame('deleted', $deleted['state']);
        $this->assertSame('complete', $deleted['cleanup']);
        $this->assertFalse($this->fixture['actor']->fresh()->is_admin);
        $this->assertFileDoesNotExist(Storage::disk('local')->path('support-attachments/originals/'.$id.'/original.bin'));
        $this->refused(fn () => $this->service->list('inquiry', $this->source(), AttachmentActor::operator($this->fixture['actor'])), 403);
    }

    public function test_unknown_tombstone_commit_never_erases_before_explicit_current_authorized_retry(): void
    {
        $id = $this->intake()['attachment']['attachmentId'];
        $events = clone DB::connection()->getEventDispatcher();
        DB::connection()->setEventDispatcher($events);
        $interrupted = false;
        $events->listen(TransactionCommitted::class, function () use (&$interrupted): void {
            if (! $interrupted && DB::table('support_attachments')->first()->state === 'deleted') {
                $interrupted = true;
                throw new \RuntimeException('Synthetic unknown tombstone acknowledgement');
            }
        });
        try {
            $this->service->delete('inquiry', $this->source(), $id, $this->owner());
            $this->fail('Unknown tombstone acknowledgement must stop native erasure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic unknown tombstone acknowledgement', $error->getMessage());
        }
        $this->assertSame('deleted', DB::table('support_attachments')->first()->state);
        $this->assertFileExists(Storage::disk('local')->path('support-attachments/originals/'.$id.'/original.bin'));
        $this->assertSame('complete', $this->service->delete('inquiry', $this->source(), $id, $this->owner())['cleanup']);
        $this->assertFileDoesNotExist(Storage::disk('local')->path('support-attachments/originals/'.$id.'/original.bin'));
    }

    public function test_changed_bytes_after_tombstone_commit_leave_truthful_pending_cleanup_and_never_erase_unknown_content(): void
    {
        $id = $this->intake()['attachment']['attachmentId'];
        $path = Storage::disk('local')->path('support-attachments/originals/'.$id.'/original.bin');
        $events = clone DB::connection()->getEventDispatcher();
        DB::connection()->setEventDispatcher($events);
        $events->listen(TransactionCommitted::class, function () use ($path): void {
            if (DB::table('support_attachments')->first()->state === 'deleted') {
                chmod($path, 0600);
                file_put_contents($path, str_repeat('X', filesize($path)));
                chmod($path, 0400);
            }
        });
        $result = $this->service->delete('inquiry', $this->source(), $id, $this->owner());
        $this->assertSame('deleted', $result['state']);
        $this->assertSame('pending', $result['cleanup']);
        $this->assertFileExists($path);
        $this->assertNotSame(hash_file('sha256', $this->input), hash_file('sha256', $path));
        $this->assertSame('deleted', DB::table('support_attachments')->first()->state);
    }

    public function test_storage_configuration_change_after_tombstone_commit_preserves_exact_original_pending_cleanup(): void
    {
        $id = $this->intake()['attachment']['attachmentId'];
        $path = Storage::disk('local')->path('support-attachments/originals/'.$id.'/original.bin');
        $events = clone DB::connection()->getEventDispatcher();
        DB::connection()->setEventDispatcher($events);
        $events->listen(TransactionCommitted::class, function (): void {
            if (DB::table('support_attachments')->first()->state === 'deleted') {
                config(['filesystems.disks.local.root' => config('filesystems.disks.local.root').'/synthetic-alternate']);
            }
        });
        $result = $this->service->delete('inquiry', $this->source(), $id, $this->owner());
        $this->assertSame('deleted', $result['state']);
        $this->assertSame('pending', $result['cleanup']);
        $this->assertFileExists($path);
        $this->assertSame(hash_file('sha256', $this->input), hash_file('sha256', $path));
    }

    public function test_terminal_inquiry_panel_callback_cannot_withdraw_intake_and_finish_scan(): void
    {
        $id = $this->intake()['attachment']['attachmentId'];
        $calls = 0;
        $scanner = new class extends TestOnlyMediaScanner
        {
            public $callback;

            public function scan(string $path): array
            {
                ($this->callback)();

                return parent::scan($path);
            }
        };
        $scanner->callback = function () use (&$calls): void {
            Filament::getPanel('admin')->requiresMultiFactorAuthentication(function () use (&$calls): bool {
                if (++$calls === 6) {
                    $this->assertSame(6, $calls);
                    config(['inquiries.enabled' => false]);
                }

                return false;
            });
        };
        $consumer = new SupportAttachments(new AttachmentRegistry(['inquiry' => new InquiryAttachmentAuthority], ['original_inquiry_session_v1' => new FixtureAttachmentPolicy]), new AttachmentFiles, $scanner);
        $this->refused(fn () => $consumer->process('inquiry', $this->source(), 0, $id, 0, AttachmentActor::operator($this->fixture['actor'])), 503);
        $this->assertSame(6, $calls);
        $this->assertFalse(config('inquiries.enabled'));
        $this->assertSame('scanning', DB::table('support_attachments')->first()->state);
    }

    private function cryptoCallback(int $target, callable $callback): void
    {
        $spy = new class(Crypt::getKey(), config('app.cipher')) extends Encrypter
        {
            public int $calls = 0;

            public int $target;

            public $callback;

            public function decryptString($payload)
            {
                $this->calls++;
                if ($this->calls === $this->target) {
                    ($this->callback)();
                }

                return parent::decryptString($payload);
            }
        };
        $spy->target = $target;
        $spy->callback = $callback;
        Crypt::swap($spy);
    }

    private function source(): string
    {
        return $this->fixture['inquiry']->public_id;
    }

    private function owner(): AttachmentActor
    {
        return AttachmentActor::visitor(Fixture::OWNER);
    }

    private function intake(?string $request = null): array
    {
        return $this->service->intake('inquiry', $this->source(), 0, $this->owner(), $request ?? (string) Str::uuid(), 'synthetic.txt', $this->input);
    }

    private function process(string $id, int $attempt): array
    {
        return $this->service->process('inquiry', $this->source(), 0, $id, $attempt, $this->owner());
    }

    private function refused(callable $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('Refused attachment operation completed.');
        } catch (AttachmentException $error) {
            $this->assertSame($status, $error->status);
        }
    }
}
