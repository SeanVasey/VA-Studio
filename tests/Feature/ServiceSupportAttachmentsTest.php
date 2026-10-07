<?php

namespace Tests\Feature;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Services\Projects\Attachments\ServiceProjectAttachmentAuthority;
use App\Domain\Services\Projects\ServiceProjectPolicy;
use App\Domain\SupportAttachments\AttachmentActor;
use App\Domain\SupportAttachments\AttachmentException;
use App\Domain\SupportAttachments\AttachmentFiles;
use App\Domain\SupportAttachments\AttachmentRegistry;
use App\Domain\SupportAttachments\AttachmentRemoval;
use App\Domain\SupportAttachments\FixtureAttachmentPolicy;
use App\Domain\SupportAttachments\SupportAttachments;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CustomerFixtures;
use Tests\Support\ServiceProjectFixtures as F;
use Tests\Support\TestOnlyMediaScanner;
use Tests\TestCase;

/** Ordinary composed consumer boot: actual source adapter, domain pipeline and private original files. */
final class ServiceSupportAttachmentsTest extends TestCase
{
    private array $fixture;

    private SupportAttachments $attachments;

    private TestOnlyMediaScanner $scanner;

    private string $input;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('S', 32))]);
        if (DB::getDriverName() === 'mysql') {
            if (getenv('ATTACHMENT_NATIVE_ISOLATED') !== '1') {
                $this->markTestSkipped('Requires the dedicated attachment-author database and explicit isolated-test marker.');
            }
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        } else {
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        }
        $this->fakePrivateMediaStorage();
        $this->fixture = F::setup();
        config(['support-attachments.fixture_enabled' => true]);
        $this->scanner = app(TestOnlyMediaScanner::class);
        $this->attachments = $this->consumer(new AttachmentFiles);
        $this->input = tempnam(sys_get_temp_dir(), 'synthetic-service-support-');
        file_put_contents($this->input, "SYNTHETIC SERVICE ATTACHMENT.\nNo customer information.\n");
        $this->beforeApplicationDestroyed(fn () => @unlink($this->input));
    }

    public function test_current_buyer_and_operator_use_actual_pipeline_without_exposing_or_changing_source(): void
    {
        $before = DB::table('service_projects')->first();
        $id = $this->intake()['attachment']['attachmentId'];
        $this->assertSame('ready', $this->attachments->process('project', $this->source(), 0, $id, 0, $this->owner())['state']);
        foreach ([$this->owner(), AttachmentActor::operator($this->fixture['operator'])] as $actor) {
            $read = $this->attachments->download('project', $this->source(), $id, $actor);
            $output = fopen('php://temp', 'w+b');
            $read['stream']->writeTo($output);
            rewind($output);
            $this->assertSame(file_get_contents($this->input), stream_get_contents($output));
            fclose($output);
        }
        $other = CustomerFixtures::account();
        $this->refused(fn () => $this->attachments->list('project', $this->source(), AttachmentActor::customer($other['user'], $other['principal'])), 404);
        $this->refused(fn () => $this->attachments->download('project', (string) Str::uuid(), $id, $this->owner()), 404);
        $this->assertEquals($before, DB::table('service_projects')->first());
        $this->assertDatabaseCount('service_project_events', 0);
        $dto = json_encode($this->attachments->list('project', $this->source(), $this->owner()), JSON_THROW_ON_ERROR);
        foreach (['owner_key', 'owner_binding', 'source_binding', 'credential', 'original.bin', 'account_id', $this->fixture['customer']['user']->email] as $secret) {
            $this->assertStringNotContainsString($secret, $dto);
        }
        $this->assertSame('complete', $this->attachments->delete('project', $this->source(), $id, $this->owner())['cleanup']);
    }

    public function test_withdrawal_closes_intake_and_pending_scan_but_keeps_owned_ready_original_and_exact_replay(): void
    {
        $request = (string) Str::uuid();
        $ready = $this->intake($request)['attachment']['attachmentId'];
        $this->attachments->process('project', $this->source(), 0, $ready, 0, $this->owner());
        $pending = $this->intake()['attachment']['attachmentId'];
        $this->fixture['journey']->customerCommand($this->source(), F::command($this->fixture['project'], 'withdraw', ['reason' => 'Synthetic explicit withdrawal']), $this->fixture['customer']['principal'], $this->fixture['customer']['user']);
        $list = $this->attachments->list('project', $this->source(), $this->owner());
        $this->assertSame(1, $list['sourceVersion']);
        $this->assertFalse($list['canIntake']);
        $this->assertFalse($list['attachments'][1]['canRetry']);
        $this->assertTrue($this->intake($request)['replayed']);
        $this->refused(fn () => $this->attachments->intake('project', $this->source(), 1, $this->owner(), (string) Str::uuid(), 'synthetic.txt', $this->input), 409);
        $this->refused(fn () => $this->attachments->process('project', $this->source(), 1, $pending, 0, $this->owner()), 409);
        $this->attachments->download('project', $this->source(), $ready, $this->owner())['stream']->close();
        $this->assertSame('complete', $this->attachments->delete('project', $this->source(), $ready, $this->owner())['cleanup']);
        $this->assertDatabaseCount('service_project_events', 1);
    }

    public function test_account_withdrawal_during_scanner_work_cannot_complete_or_read_claim(): void
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
        $scanner->callback = fn () => CustomerFixtures::withdraw($this->fixture['customer']);
        $consumer = $this->consumer(new AttachmentFiles, $scanner);
        $this->refused(fn () => $consumer->process('project', $this->source(), 0, $id, 0, $this->owner()), 403);
        $this->assertSame('scanning', DB::table('support_attachments')->first()->state);
        $this->refused(fn () => $consumer->list('project', $this->source(), $this->owner()), 403);
        $this->assertFileExists(Storage::disk('local')->path('support-attachments/originals/'.$id.'/original.bin'));
    }

    public function test_credential_change_during_storage_preparation_cannot_delete_retained_original(): void
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
        $files->callback = fn () => $this->fixture['customer']['user']->fresh()->forceFill(['password' => 'Synthetic replacement credential only!'])->save();
        $consumer = $this->consumer($files);
        $this->refused(fn () => $consumer->delete('project', $this->source(), $id, $this->owner()), 403);
        $this->assertSame('quarantined', DB::table('support_attachments')->first()->state);
        $this->assertFileExists(Storage::disk('local')->path('support-attachments/originals/'.$id.'/original.bin'));
    }

    public function test_final_staff_source_query_cannot_withdraw_fixture_policy_and_return_private_projection(): void
    {
        $this->intake();
        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, DB::connection()->getQueryGrammar()->wrapTable('users')) && ++$queries === 6) {
                config(['support-attachments.fixture_enabled' => false]);
            }
        });
        $this->refused(fn () => $this->attachments->list('project', $this->source(), AttachmentActor::operator($this->fixture['operator'])), 503);
        $this->assertSame(6, $queries);
        $this->assertFalse(config('support-attachments.fixture_enabled'));
        $this->assertSame('quarantined', DB::table('support_attachments')->first()->state);
    }

    public function test_changed_graph_requires_current_version_for_new_intake_but_retains_old_manifest(): void
    {
        $id = $this->intake()['attachment']['attachmentId'];
        F::author($this->fixture);
        $this->refused(fn () => $this->intake(), 409);
        $list = $this->attachments->list('project', $this->source(), $this->owner());
        $this->assertSame(1, $list['sourceVersion']);
        $this->assertSame(0, $list['attachments'][0]['sourceVersion']);
        $this->assertSame($id, $list['attachments'][0]['attachmentId']);
        $next = $this->attachments->intake('project', $this->source(), 1, $this->owner(), (string) Str::uuid(), 'synthetic.txt', $this->input);
        $this->assertSame(1, $next['attachment']['sourceVersion']);
        $this->assertDatabaseCount('support_attachments', 2);
    }

    public function test_terminal_customer_stamp_resolution_cannot_withdraw_credential_and_return_private_projection(): void
    {
        $this->intake();
        $resolutions = 0;
        app()->afterResolving(CustomerAccess::class, function () use (&$resolutions): void {
            if (++$resolutions === 3) {
                $this->assertSame(3, $resolutions);
                $this->fixture['customer']['user']->fresh()->forceFill(['password' => 'Synthetic late stamp credential only!'])->save();
            }
        });
        $this->refused(fn () => $this->attachments->list('project', $this->source(), $this->owner()), 403);
        $this->assertSame(3, $resolutions);
        $this->assertSame('quarantined', DB::table('support_attachments')->first()->state);
    }

    public function test_terminal_service_policy_resolution_cannot_withdraw_account_and_return_private_projection(): void
    {
        $this->intake();
        $resolutions = 0;
        app()->afterResolving(ServiceProjectPolicy::class, function () use (&$resolutions): void {
            if (++$resolutions === 3) {
                $this->assertSame(3, $resolutions);
                CustomerFixtures::withdraw($this->fixture['customer']);
            }
        });
        $this->refused(fn () => $this->attachments->list('project', $this->source(), $this->owner()), 403);
        $this->assertSame(3, $resolutions);
        $this->assertSame('quarantined', DB::table('support_attachments')->first()->state);
    }

    private function consumer(AttachmentFiles $files, ?TestOnlyMediaScanner $scanner = null): SupportAttachments
    {
        return new SupportAttachments(new AttachmentRegistry(['project' => new ServiceProjectAttachmentAuthority], ['test_service_project_v1' => new FixtureAttachmentPolicy]), $files, $scanner ?? $this->scanner);
    }

    private function source(): string
    {
        return $this->fixture['project']['id'];
    }

    private function owner(): AttachmentActor
    {
        return AttachmentActor::customer($this->fixture['customer']['user'], $this->fixture['customer']['principal']);
    }

    private function intake(?string $request = null): array
    {
        return $this->attachments->intake('project', $this->source(), 0, $this->owner(), $request ?? (string) Str::uuid(), 'synthetic.txt', $this->input);
    }

    private function refused(callable $call, int $status): void
    {
        try {
            $call();
            $this->fail('Current source authority must refuse the attachment operation.');
        } catch (AttachmentException $error) {
            $this->assertSame($status, $error->status);
        }
    }
}
