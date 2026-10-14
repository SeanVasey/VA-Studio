<?php

namespace Tests\Feature\ProductionMemberOriginals;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantRenderProfile;
use App\Domain\Grants\Member\MemberGrantRenderProfileRegistry;
use App\Domain\Grants\Member\MemberOriginalBudget;
use App\Domain\Grants\Member\MemberOriginalIoFrame;
use App\Domain\Grants\Member\MemberOriginalRendererProcess;
use App\Domain\Grants\Member\MemberOriginalRenderInput;
use App\Domain\Grants\Member\MemberOriginalText;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\MemberOriginalRendererFixtures;
use Tests\TestCase;

final class MemberOriginalRendererTest extends TestCase
{
    private MemberOriginalIoFrame $idle;

    private array $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        chmod(config('filesystems.disks.local.root'), 0700);
        DB::connection()->getPdo();
        DB::connection()->getReadPdo();
        $this->idle = MemberOriginalIoFrame::captureIdle();
        $this->profile = MemberGrantRenderProfile::current('synthetic_rehearsal', $this->idle);
    }

    public function test_two_real_children_produce_identical_pdf_bytes_and_exact_source_metadata(): void
    {
        $input = MemberOriginalRendererFixtures::input();
        $one = (new MemberOriginalRendererProcess)->render($input, $this->profile, $this->idle, MemberOriginalBudget::start());
        $timezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Auckland');
            $two = (new MemberOriginalRendererProcess)->render($input, $this->profile, $this->idle, MemberOriginalBudget::start());
        } finally {
            date_default_timezone_set($timezone);
        }
        $this->assertSame($one->pdfBytes, $two->pdfBytes);
        $this->assertSame($one->sha256, hash('sha256', $one->pdfBytes));
        $this->assertSame(strlen($one->pdfBytes), $one->sizeBytes);
        $this->assertSame(CanonicalJson::hash($this->profile), $one->profileHash);
        $this->assertSame((new MemberOriginalText)->build($input)['text_digest'], $one->textDigest);
        $this->assertStringStartsWith('%PDF-1.', $one->pdfBytes);
        $this->assertStringEndsWith('%%EOF', rtrim($one->pdfBytes));
        $this->assertStringContainsString('D:20261013000000', $one->pdfBytes);
        $this->assertGreaterThan(0, $one->pageCount);
        $this->assertSame([], glob(config('filesystems.disks.local.root').'/contracts/member-render-cache/*'));
    }

    public function test_auto_created_pages_are_also_deterministic_across_real_children(): void
    {
        $terms = MemberOriginalRendererFixtures::terms();
        $terms['authored_source'] = str_repeat("SYNTHETIC MULTIPAGE SOURCE. Exact retained text only. Ελληνικά Кириллица.\n", 100);
        $input = MemberOriginalRenderInput::from(MemberOriginalRendererFixtures::document($terms));
        $one = (new MemberOriginalRendererProcess)->render($input, $this->profile, $this->idle, MemberOriginalBudget::start());
        $two = (new MemberOriginalRendererProcess)->render($input, $this->profile, $this->idle, MemberOriginalBudget::start());
        $this->assertGreaterThan(1, $one->pageCount);
        $this->assertSame($one->pageCount, $two->pageCount);
        $this->assertSame($one->pdfBytes, $two->pdfBytes);
    }

    public function test_pure_released_profile_remains_available_inside_a_transaction(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->beginTransaction();
        try {
            $this->assertSame(CanonicalJson::encode($this->profile), CanonicalJson::encode(MemberGrantRenderProfile::released('synthetic_rehearsal')));
            $this->assertSame($this->profile, MemberGrantRenderProfile::validateStored($this->profile));
            $this->expectException(MemberGrantException::class);
            MemberGrantRenderProfile::verifyRuntime($this->profile, $this->idle);
        } finally {
            $this->assertTrue($pdo->inTransaction());
            $pdo->rollBack();
        }
    }

    public function test_changed_full_profile_is_refused_without_child_launch(): void
    {
        $profile = $this->profile;
        $profile['limits']['output_bytes']--;
        $launched = false;
        $renderer = new MemberOriginalRendererProcess(function () use (&$launched): never {
            $launched = true;
            throw new \RuntimeException('must not reach observation hook');
        });
        try {
            $renderer->render(MemberOriginalRendererFixtures::input(), $profile, $this->idle, MemberOriginalBudget::start());
            $this->fail('Changed descriptor was admitted.');
        } catch (ContractIssuanceException $error) {
            $this->assertSame('profile_changed', $error->reason);
        }
        $this->assertFalse($launched);
    }

    public function test_runtime_verifies_new_manifest_members_without_modifying_frozen_files(): void
    {
        foreach ($this->profile['implementation']['files'] as $path => $hash) {
            $this->assertSame($hash, hash_file('sha256', base_path($path)));
            $this->assertTrue(str_starts_with($path, 'app/Domain/Grants/Member/') || str_starts_with($path, 'scripts/'));
        }
        $this->assertSame(MemberGrantRenderProfileRegistry::RELEASED['r1']['synthetic_rehearsal'], CanonicalJson::hash($this->profile));
        $this->assertNotSame(CanonicalJson::hash($this->profile), CanonicalJson::hash(MemberGrantRenderProfile::released('verified_production')));
    }

    public function test_observation_hook_opening_a_native_transaction_refuses_without_closing_it(): void
    {
        $pdo = DB::connection()->getPdo();
        $called = false;
        $renderer = new MemberOriginalRendererProcess(function () use ($pdo, &$called): void {
            $called = true;
            $pdo->beginTransaction();
        });
        try {
            $renderer->render(MemberOriginalRendererFixtures::input(), $this->profile, $this->idle, MemberOriginalBudget::start());
            $this->fail('Native transaction admitted a child.');
        } catch (MemberGrantException $error) {
            $this->assertSame('io_transaction', $error->reason);
            $this->assertTrue($called);
            $this->assertTrue($pdo->inTransaction());
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    public function test_observation_hook_expiring_original_budget_refuses(): void
    {
        $budget = MemberOriginalBudget::start();
        $renderer = new MemberOriginalRendererProcess(function () use ($budget): void {
            $budget->shortenTo(hrtime(true) - 1);
        });
        try {
            $renderer->render(MemberOriginalRendererFixtures::input(), $this->profile, $this->idle, $budget);
            $this->fail('Expired budget admitted a child.');
        } catch (MemberGrantException $error) {
            $this->assertSame('workflow_deadline', $error->reason);
        }
    }

    public function test_original_budget_includes_time_spent_in_the_prelaunch_observation_hook(): void
    {
        $budget = MemberOriginalBudget::start();
        $renderer = new MemberOriginalRendererProcess(function () use ($budget): void {
            $budget->shortenTo(hrtime(true) + 5_000_000);
            usleep(20000);
        });
        try {
            $renderer->render(MemberOriginalRendererFixtures::input(), $this->profile, $this->idle, $budget);
            $this->fail('A delayed hook received a new workflow budget.');
        } catch (MemberGrantException $error) {
            $this->assertSame('workflow_deadline', $error->reason);
        }
    }

    public function test_real_child_refuses_a_supported_script_character_without_a_pinned_glyph(): void
    {
        $terms = MemberOriginalRendererFixtures::terms();
        $terms['authored_source'] = 'SYNTHETIC unsupported glyph 🦕';
        $input = MemberOriginalRenderInput::from(MemberOriginalRendererFixtures::document($terms));
        try {
            (new MemberOriginalRendererProcess)->render($input, $this->profile, $this->idle, MemberOriginalBudget::start());
            $this->fail('Missing glyph was silently substituted.');
        } catch (ContractIssuanceException $error) {
            $this->assertSame('unsupported_input', $error->reason);
        }
    }

    public function test_real_child_has_no_application_environment_and_transport_is_bounded(): void
    {
        $called = false;
        putenv('MEMBER_RENDER_TEST_SENTINEL=synthetic-not-a-secret');
        $renderer = new MemberOriginalRendererProcess(function () use (&$called): void {
            $called = true;
            putenv('MEMBER_RENDER_LATE_SENTINEL=synthetic-not-a-secret');
        });
        try {
            $rendered = $renderer->render(MemberOriginalRendererFixtures::input(), $this->profile, $this->idle, MemberOriginalBudget::start());
        } finally {
            putenv('MEMBER_RENDER_TEST_SENTINEL');
            putenv('MEMBER_RENDER_LATE_SENTINEL');
        }
        // The real child refuses every environment name outside its fixed technical allowlist.
        $this->assertTrue($called);
        $this->assertGreaterThan(32, $rendered->sizeBytes);
    }

    public function test_observation_hook_cannot_substitute_a_pdf_looking_transport_result(): void
    {
        $input = MemberOriginalRendererFixtures::input();
        $bytes = "%PDF-1.7\nNONBINDING substitute output, not the pinned renderer.\n%%EOF\n";
        $result = ['pdf_base64' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes),
            'page_count' => 1, 'text_digest' => (new MemberOriginalText)->build($input)['text_digest'],
            'profile_hash' => CanonicalJson::hash($this->profile)];
        $payload = base64_encode(json_encode($result, JSON_THROW_ON_ERROR));
        $substitute = null;
        $renderer = new MemberOriginalRendererProcess(function () use ($payload, &$substitute): Process {
            return $substitute = new Process([PHP_BINARY, '-n', '-r', 'fwrite(STDOUT, base64_decode("'.$payload.'"));']);
        });
        $rendered = $renderer->render($input, $this->profile, $this->idle, MemberOriginalBudget::start());
        $this->assertNotSame($bytes, $rendered->pdfBytes);
        $this->assertFalse($substitute->isStarted());
        $this->assertStringContainsString('D:20261013000000', $rendered->pdfBytes);
    }

    public function test_real_child_preserves_crlf_and_bare_cr_source_with_exact_text_digest(): void
    {
        $terms = MemberOriginalRendererFixtures::terms();
        $terms['authored_source'] = "NONBINDING synthetic first line\r\nSecond retained line\rThird retained line\nFinal line";
        $input = MemberOriginalRenderInput::from(MemberOriginalRendererFixtures::document($terms));
        $one = (new MemberOriginalRendererProcess)->render($input, $this->profile, $this->idle, MemberOriginalBudget::start());
        $two = (new MemberOriginalRendererProcess)->render($input, $this->profile, $this->idle, MemberOriginalBudget::start());
        $this->assertSame((new MemberOriginalText)->build($input)['text_digest'], $one->textDigest);
        $this->assertSame($one->pdfBytes, $two->pdfBytes);
    }
}
