<?php

namespace Tests\Feature;

use App\Application\SiteBuilder\IngestSiteImage;
use App\Domain\Catalog\Models\Track;
use App\Domain\Media\BoundedMediaProcess;
use App\Domain\Media\MalwareScanner;
use App\Domain\Media\MediaFailure;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\MediaWorkflowBudget;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\SiteBuilder\SiteImageProcessor;
use App\Jobs\ProcessMedia;
use App\Jobs\ProcessSiteImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MediaFixtures;
use Tests\Support\SiteImageFixtures;
use Tests\Support\TestOnlyMediaScanner;
use Tests\TestCase;

class MediaWorkflowBudgetTest extends TestCase
{
    use RefreshDatabase;

    private MediaWorkflowBudget $budget;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->budget = new class extends MediaWorkflowBudget
        {
            public int $time = 1000000000;

            protected function now(): int
            {
                return $this->time;
            }
        };
        app()->instance(MediaWorkflowBudget::class, $this->budget);
    }

    public function test_budget_caps_whole_seconds_without_extending_an_enclosing_attempt(): void
    {
        $this->assertSame(300, $this->budget->limit(300));
        $outer = $this->budget->enter();
        $this->assertNull($outer);
        $this->assertSame(300, $this->budget->limit(300));
        $this->budget->time += 800000000000;
        $nested = $this->budget->enter();
        $this->assertSame(40, $this->budget->limit(300));
        $this->assertSame(15, $this->budget->limit(15));
        $this->budget->leave($nested);
        $this->assertSame(40, $this->budget->limit(300));
        $this->budget->time += 39100000000;
        try {
            $this->budget->limit(300);
            $this->fail('A process must not start with less than a whole second left.');
        } catch (MediaFailure $failure) {
            $this->assertSame('processor_timeout', $failure->failureCode);
        } finally {
            $this->budget->leave($outer);
        }
        $this->assertSame(300, $this->budget->limit(300));
        $this->assertLessThan((new ProcessMedia(1))->timeout, MediaWorkflowBudget::SECONDS);
        $this->assertLessThan((new ProcessSiteImage(1))->timeout, MediaWorkflowBudget::SECONDS);
    }

    public function test_all_runner_instances_and_resource_limited_clones_share_the_deadline(): void
    {
        $previous = $this->budget->enter();
        $this->budget->time += (MediaWorkflowBudget::SECONDS - 1) * 1000000000;
        $workspace = app(PrivateMediaFiles::class)->workspace();
        try {
            $runner = app(BoundedMediaProcess::class)->withLimits();
            try {
                $runner->run(['/bin/sleep', '2'], $workspace, 120);
                $this->fail('The runner ignored the remaining one-second workflow budget.');
            } catch (MediaFailure $failure) {
                $this->assertSame('processor_timeout', $failure->failureCode);
            }
            $this->budget->time += 1000000000;
            $marker = $workspace.'/must-not-exist';
            try {
                app(BoundedMediaProcess::class)->run(['/usr/bin/touch', $marker], $workspace);
                $this->fail('Another runner started a process after the deadline.');
            } catch (MediaFailure $failure) {
                $this->assertSame('processor_timeout', $failure->failureCode);
            }
            $this->assertFileDoesNotExist($marker);
        } finally {
            $this->budget->leave($previous);
            app(PrivateMediaFiles::class)->cleanup($workspace);
        }
    }

    /** Real tools still validate and write the synthetic files; only elapsed time is advanced without a long wait. */
    private function timedTools(int $scanSeconds, int $toolSeconds): object
    {
        MediaFixtures::configure();
        $timing = (object) ['scanSeconds' => $scanSeconds, 'toolSeconds' => $toolSeconds, 'scans' => 0, 'tools' => 0, 'timeouts' => []];
        app()->instance(MalwareScanner::class, new class($this->budget, $timing) extends TestOnlyMediaScanner
        {
            public function __construct(private MediaWorkflowBudget $budget, private object $timing) {}

            public function scan(string $path): array
            {
                $result = parent::scan($path);
                $this->timing->scans++;
                $this->budget->time += $this->timing->scanSeconds * 1000000000;

                return $result;
            }
        });
        app()->instance(BoundedMediaProcess::class, new class($this->budget, $timing) extends BoundedMediaProcess
        {
            public function __construct(private MediaWorkflowBudget $budget, private object $timing) {}

            public function run(array $arguments, string $cwd, int $timeout = 0, bool $ignoreErrorOutput = false): string
            {
                $effective = $this->budget->limit($timeout ?: (int) config('media.process_timeout_seconds'));
                $result = parent::run($arguments, $cwd, $timeout, $ignoreErrorOutput);
                $this->timing->tools++;
                $this->timing->timeouts[] = $effective;
                $this->budget->time += min($this->timing->toolSeconds, $effective) * 1000000000;

                return $result;
            }
        });

        return $timing;
    }

    private function actor(): User
    {
        $actor = User::factory()->create();
        $actor->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();

        return $actor;
    }

    public function test_master_scans_and_derivatives_share_one_budget_and_a_failed_attempt_does_not_poison_the_next(): void
    {
        $timing = $this->timedTools(200, 110);
        $track = Track::create(['title' => 'Synthetic budget fixture', 'slug' => 'synthetic-budget-fixture']);
        $source = MediaFixtures::source($track);
        $actor = $this->actor();
        $run = app(QueueMediaProcessing::class)->handle($source, $actor);
        try {
            app(MediaProcessor::class)->handle($run->id);
            $this->fail('Master processing exceeded the shared budget.');
        } catch (MediaFailure $failure) {
            $this->assertSame('processor_timeout', $failure->failureCode);
        }
        $this->assertSame(2, $timing->scans);
        $this->assertSame(4, $timing->tools);
        $this->assertSame([120, 120, 120, 110], $timing->timeouts);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('processor_timeout', $run->fresh()->failure_code);
        $this->assertSame(0, $run->outputs()->count());
        $this->assertSame('quarantined', $source->fresh()->status);
        $this->assertFileExists(Storage::disk('local')->path($source->storage_path));
        $this->assertSame([], glob(Storage::disk('local')->path('processing').'/*'));
        $this->assertSame(300, $this->budget->limit(300));
        $timing->scanSeconds = $timing->toolSeconds = 0;
        $next = app(QueueMediaProcessing::class)->handle(MediaFixtures::source($track), $actor);
        $this->assertSame('completed', app(MediaProcessor::class)->handle($next->id)->status);
        $this->assertSame(3, $next->outputs()->count());
    }

    public function test_site_image_scan_and_variants_share_one_budget_and_remain_retryable(): void
    {
        $timing = $this->timedTools(300, 100);
        $path = tempnam(sys_get_temp_dir(), 'site-budget-');
        file_put_contents($path, SiteImageFixtures::jpeg(1440, 630));
        $this->beforeApplicationDestroyed(fn () => @unlink($path));
        $image = app(IngestSiteImage::class)->handle('studio', new UploadedFile($path, 'synthetic.jpg', null, null, true), 'Synthetic fixture', true, $this->actor());
        $result = app(SiteImageProcessor::class)->handle($image->id);
        $this->assertSame(1, $timing->scans);
        $this->assertSame(6, $timing->tools);
        $this->assertSame([120, 120, 120, 120, 120, 40], $timing->timeouts);
        $this->assertSame('quarantined', $result->status);
        $this->assertSame('processor_timeout', $result->failure_code);
        $this->assertSame(0, $result->variants()->count());
        $this->assertFileExists(Storage::disk('local')->path($result->source_path));
        $this->assertSame([], glob(Storage::disk('local')->path('processing').'/*'));
        $this->assertSame(300, $this->budget->limit(300));
        $timing->scanSeconds = $timing->toolSeconds = 0;
        $this->assertSame('ready', app(SiteImageProcessor::class)->handle($image->id)->status);
        $this->assertGreaterThan(0, $image->variants()->count());
    }
}
