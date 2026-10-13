<?php

namespace Tests\Feature\ProductionMemberOriginals;

use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberOriginalArtifactReceipt;
use App\Domain\Grants\Member\MemberOriginalCandidate;
use App\Domain\Grants\Member\MemberOriginalIoFrame;
use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MemberGrantReceipt;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberOriginalFixtures;
use Tests\TestCase;
use TypeError;

/** Real synthetic member candidates only: no original retention, credit consumption or activation. */
class MemberOriginalPreparationIntegrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MemberOriginalFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->memberOriginalSetup();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_real_authored_sources_prepare_an_exact_repeat_without_original_or_credit_effects(): void
    {
        $fixture = $this->memberOriginalFixture();
        $before = $this->memberOriginalEffects();
        $audit = $this->ledgerSnapshot($fixture);
        $candidate = $this->prepareMemberOriginal($fixture);
        $this->assertInstanceOf(MemberOriginalCandidate::class, $candidate);
        $this->assertSame($fixture['reservation']['redemption_id'], $candidate->originId);
        $this->assertSame($candidate->originId, $candidate->redemptionId);
        $this->assertSame($fixture['member_authored']->definitionId, $candidate->definitionId);
        $this->assertSame($fixture['member_definition']->hash(), $candidate->definitionHash);
        $this->assertSame($fixture['member_profile']->hash(), $candidate->profileHash);
        $manifest = $candidate->artifactManifest;
        $this->assertSame($candidate->originId, $manifest->originId);
        $this->assertSame($candidate->artifactManifestHash, $manifest->manifestHash);
        $this->assertSame(CanonicalJson::hash(['schema_version' => 'member-original-artifact-manifest-v1',
            'family' => MemberGrantIntent::FAMILY, 'purpose' => MemberGrantIntent::PURPOSE,
            'origin_id' => $candidate->originId, 'originals' => $manifest->originals]), $manifest->manifestHash);
        $this->assertCount(1, $manifest->originals);
        $original = $manifest->originals[0];
        $this->assertSame('license_pdf', $original['role']);
        $this->assertSame(CanonicalJson::hash($fixture['member_definition']->value()['storage_policy']), $original['storage_policy_hash']);
        $path = $this->memberOriginalCandidatePath($candidate);
        $bytes = file_get_contents($path);
        $this->assertIsString($bytes);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertSame($original['sha256'], hash('sha256', $bytes));
        $this->assertSame($original['bytes'], strlen($bytes));
        $inode = fileinode($path);
        $this->assertMemberOriginalEffectsUnchanged($before);

        // A new invocation must capture, rerender and reobserve; the old candidate supplies no proof.
        $repeat = $this->prepareMemberOriginal($fixture);
        clearstatcache(true, $path);
        $this->assertEquals($candidate, $repeat);
        $this->assertSame($inode, fileinode($path));
        $this->assertSame($bytes, file_get_contents($path));
        $this->assertSame($audit, $this->ledgerSnapshot($fixture));
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    public function test_source_withdrawal_during_real_materialization_refuses_fresh_admission_and_leaves_only_a_candidate(): void
    {
        $fixture = $this->memberOriginalFixture();
        $before = $this->memberOriginalEffects();
        $calls = 0;
        $operation = $this->memberOriginalOperation(function (array $command, string $cwd, array $environment, string $payload) use ($fixture, &$calls): Process {
            $calls++;
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertSame(1, DB::table('tracks')->where('id', $fixture['catalog']['track']->id)->update(['status' => 'draft']));

            // The actual child still renders the previously admitted source. Fresh DB proof must refuse it.
            return new Process($command, $cwd, $environment, $payload);
        });
        $this->refused(fn () => $this->prepareMemberOriginal($fixture, $operation), 'source_refused');
        $this->assertSame(1, $calls);
        $paths = $this->candidateFiles($fixture['reservation']['redemption_id']);
        $this->assertCount(1, $paths);
        $this->assertStringStartsWith('%PDF-', file_get_contents($paths[0]));
        $this->assertSame('draft', DB::table('tracks')->where('id', $fixture['catalog']['track']->id)->value('status'));
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    public function test_after_commit_owner_withdrawal_returns_no_candidate_and_never_starts_the_renderer(): void
    {
        $fixture = $this->memberOriginalFixture();
        $before = $this->memberOriginalEffects();
        $childCalls = 0;
        $operation = $this->memberOriginalOperation(function (array $command, string $cwd, array $environment, string $payload) use (&$childCalls): Process {
            $childCalls++;

            return new Process($command, $cwd, $environment, $payload);
        });
        $armed = true;
        $withdrawals = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionBeginning::class, function () use ($fixture, &$armed, &$withdrawals): void {
            if (! $armed) {
                return;
            }
            $armed = false;
            DB::afterCommit(function () use ($fixture, &$withdrawals): void {
                $withdrawals++;
                DB::table('customer_accounts')->where('id', $fixture['buyer']['principal']->accountId)->update([
                    'active' => false, 'access_version' => DB::raw('access_version + 1'), 'updated_at' => CarbonImmutable::now('UTC'),
                ]);
            });
        });
        $this->refused(fn () => $this->prepareMemberOriginal($fixture, $operation), 'committed_source_refused');
        $this->assertSame(1, $withdrawals);
        $this->assertSame(0, $childCalls);
        $this->assertSame([], $this->candidateFiles($fixture['reservation']['redemption_id']));
        $this->assertFalse((bool) DB::table('customer_accounts')->where('id', $fixture['buyer']['principal']->accountId)->value('active'));
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    public function test_returned_candidate_values_cannot_be_reused_as_authority_after_source_withdrawal(): void
    {
        $fixture = $this->memberOriginalFixture();
        $before = $this->memberOriginalEffects();
        $operation = $this->memberOriginalOperation();
        $candidate = $this->prepareMemberOriginal($fixture, $operation);
        $this->assertNotInstanceOf(MemberOriginalArtifactReceipt::class, $candidate);
        $this->assertNotInstanceOf(MemberGrantReceipt::class, $candidate);
        $this->assertSame(['originId', 'redemptionId', 'definitionId', 'definitionHash', 'profileHash',
            'renderSourceHash', 'artifactManifestHash', 'artifactManifest'], array_keys(get_object_vars($candidate)));
        $values = get_object_vars($candidate);
        $this->assertSame(['originId', 'manifestHash', 'originals'], array_keys(get_object_vars($candidate->artifactManifest)));
        foreach (array_diff_key($values, ['artifactManifest' => true]) as $value) {
            $this->assertIsString($value);
            $this->assertStringNotContainsString(config('filesystems.disks.local.root'), $value);
        }
        try {
            $operation->artifactAuthority()->prepare($candidate, MemberOriginalIoFrame::captureIdle());
            $this->fail('A returned value must not be accepted as a source ticket.');
        } catch (TypeError $error) {
            $this->assertStringContainsString('MemberOriginalSource', $error->getMessage());
        }
        DB::table('tracks')->where('id', $fixture['catalog']['track']->id)->update(['status' => 'draft']);
        $this->refused(fn () => $this->prepareMemberOriginal($fixture, $operation), 'source_refused');
        $this->assertSame($values, get_object_vars($candidate));
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    private function candidateFiles(string $redemptionId): array
    {
        return glob(config('filesystems.disks.local.root').'/contracts/production-member-v1/synthetic_rehearsal/'
            .$redemptionId.'/*/original.pdf');
    }

    private function refused(callable $work, string $reason): void
    {
        try {
            $work();
            $this->fail('Expected explicit member-original refusal.');
        } catch (MemberGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
