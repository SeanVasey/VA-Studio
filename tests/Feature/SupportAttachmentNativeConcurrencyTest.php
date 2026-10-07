<?php

namespace Tests\Feature;

use App\Domain\SupportAttachments\AttachmentActor;
use App\Domain\SupportAttachments\AttachmentFiles;
use App\Domain\SupportAttachments\AttachmentRegistry;
use App\Domain\SupportAttachments\FixtureAttachmentPolicy;
use App\Domain\SupportAttachments\InquiryAttachmentAuthority;
use App\Domain\SupportAttachments\SupportAttachments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\InquiryConversationFixtures as Fixture;
use Tests\Support\TestOnlyMediaScanner;
use Tests\TestCase;

class SupportAttachmentNativeConcurrencyTest extends TestCase
{
    public function test_native_last_file_reservation_serializes_real_contenders_and_retains_exact_originals(): void
    {
        if (DB::getDriverName() !== 'mysql' || getenv('ATTACHMENT_NATIVE_ISOLATED') !== '1') {
            $this->markTestSkipped('Requires explicitly isolated native attachment database and process lock observation.');
        }
        config(['app.key' => 'base64:'.base64_encode(str_repeat('S', 32)), 'support-attachments.fixture_enabled' => true]);
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->fakePrivateMediaStorage();
        $fixture = Fixture::create();
        $root = config('filesystems.disks.local.root');
        $sync = sys_get_temp_dir().'/synthetic-support-race-'.bin2hex(random_bytes(12));
        mkdir($sync, 0700);
        $input = $sync.'/synthetic.txt';
        file_put_contents($input, "SYNTHETIC ATTACHMENT RESERVATION RACE.\n");
        chmod($input, 0600);
        $service = new SupportAttachments(new AttachmentRegistry(['inquiry' => new InquiryAttachmentAuthority], ['original_inquiry_session_v1' => new FixtureAttachmentPolicy]), new AttachmentFiles, app(TestOnlyMediaScanner::class));
        for ($i = 0; $i < 9; $i++) {
            $service->intake('inquiry', $fixture['inquiry']->public_id, 0, AttachmentActor::visitor(Fixture::OWNER), (string) Str::uuid(), 'synthetic.txt', $input);
        }
        $workers = [];
        try {
            $arguments = [PHP_BINARY, base_path('tests/Support/support-attachment-race-worker.php'), $fixture['inquiry']->public_id, $sync, $root];
            $workers['first'] = new Process([...$arguments, 'first'], base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key')], null, 15);
            $workers['first']->start();
            $deadline = microtime(true) + 5;
            while (! file_exists($sync.'/held') && $workers['first']->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($sync.'/held', $workers['first']->getErrorOutput());
            $workers['second'] = new Process([...$arguments, 'second'], base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key')], null, 15);
            $workers['second']->start();
            $waits = 0;
            $deadline = microtime(true) + 5;
            while ($waits === 0 && $workers['second']->isRunning() && microtime(true) < $deadline) {
                $waits = (int) DB::selectOne('SELECT COUNT(*) AS waits FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_DB = ?', [DB::getDatabaseName()])->waits;
                usleep(10000);
            }
            $this->assertGreaterThan(0, $waits, 'No real native source row-lock wait was observed.');
            file_put_contents($sync.'/release', 'release');
            $results = [];
            foreach ($workers as $role => $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $results[$role] = json_decode(trim($worker->getOutput()), true, 8, JSON_THROW_ON_ERROR);
            }
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame([200, 409], $statuses);
            $this->assertDatabaseCount('support_attachments', 10);
            $list = $service->list('inquiry', $fixture['inquiry']->public_id, AttachmentActor::visitor(Fixture::OWNER));
            $this->assertFalse($list['canIntake']);
            $this->assertCount(10, $list['attachments']);
            $objects = glob($root.'/support-attachments/originals/*/original.bin');
            $this->assertCount(10, $objects);
            foreach ($objects as $path) {
                $this->assertSame(hash_file('sha256', $input), hash_file('sha256', $path));
            }
            file_put_contents(base_path('docs/verification/support-attachments-20261007/native-reservation-receipt.json'), json_encode(['mysql' => DB::selectOne('SELECT VERSION() AS version')->version,
                'isolation' => DB::selectOne('SELECT @@transaction_isolation AS isolation')->isolation, 'observed_waits' => $waits, 'results' => $results, 'retained_manifests' => 10, 'retained_originals' => 10], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
        } finally {
            file_put_contents($sync.'/release', 'release');
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            foreach (glob($sync.'/*') ?: [] as $path) {
                unlink($path);
            } rmdir($sync);
        }
    }
}
