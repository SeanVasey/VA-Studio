<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Media\MediaFailure;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\Memberships\Production\CurrentEligibleLicenses;
use App\Domain\Memberships\Production\MembershipCatalogEvidence;
use App\Domain\Memberships\Production\MembershipEligibleLicenseProof;
use App\Domain\Memberships\Production\MembershipEligibleLicenseToken;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPolicy;
use App\Domain\Memberships\Production\MembershipRows;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\ProductionIdentityFixture;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class MembershipEligibleLicenseProofTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionIdentityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        CarbonImmutable::setTestNow('2026-10-13 12:00:00 UTC');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('E', 32)),
            'production-memberships.enabled' => true, 'production-memberships.provenance' => IdentityPolicy::REHEARSAL,
            'production-memberships.approved_policy_hash' => hash('sha256', 'SYNTHETIC eligibility proof fixture policy'),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_real_current_catalog_derives_exact_identity_manifest_and_member_license_terms(): void
    {
        $f = $this->fixture();
        DB::transaction(function () use ($f): void {
            $rows = new MembershipRows;
            $authority = new CurrentEligibleLicenses;
            $proof = $this->lock($authority, $rows, $f);
            $snapshot = $authority->snapshot($proof, $f['buyer']['principal'], $f['buyer']['user'], $rows);
            $binding = $proof->binding();
            $line = $snapshot->selection['selection']['lines'][0];
            $license = $line['offer_snapshot']['license'];
            $this->assertSame([$f['track']->id, $f['offer']->id, $f['revision']->id, $f['items'][0]['licenseVersionId']],
                [$binding->trackId, $binding->offerId, $binding->offerRevisionId, $binding->licenseVersionId]);
            $this->assertSame($snapshot->selection['selection_hash'], $binding->selectionHash);
            $this->assertSame(CanonicalJson::hash($license), $binding->licenseManifestHash);
            $this->assertSame(CanonicalJson::hash($line['offer_snapshot']['assets']), $binding->assetManifestHash);
            $this->assertSame(CanonicalJson::hash(['purpose' => 'membership-license-original-terms-v1',
                'authored_source' => $license['authored_source'], 'structured_terms' => $license['structured_terms'],
                'renderer_version' => $license['renderer_version'], 'render_fixture_hash' => $license['render_fixture_hash']]), $binding->originalTermsHash);
            $this->assertSame((int) CarbonImmutable::now('UTC')->addSeconds(60)->format('Uu'), $binding->validUntilUnixMicros);
            $this->assertNotSame(config('production-memberships.approved_policy_hash'), $binding->originalTermsHash);
        });
        $this->refused(fn () => (new MembershipPolicy)->current(), 'source_capability_absent');
        $this->noMembershipEffects();
    }

    public function test_locator_and_expected_revision_have_one_unambiguous_bounded_encoding(): void
    {
        $f = $this->fixture();
        foreach (['0', '01', '+1', ' 1', '1 ', '1e0', '9007199254740992', '999999999999999999999'] as $locator) {
            DB::transaction(fn () => $this->refused(fn () => (new CurrentEligibleLicenses)->lock($locator, $f['revision']->id,
                $f['buyer']['principal'], $f['buyer']['user'], new MembershipRows), 'invalid_selection'));
        }
        DB::transaction(fn () => $this->refused(fn () => (new CurrentEligibleLicenses)->lock((string) $f['offer']->id, 0,
            $f['buyer']['principal'], $f['buyer']['user'], new MembershipRows), 'invalid_selection'));
        $this->noMembershipEffects();
    }

    public function test_tokens_require_original_issuer_owner_rows_and_frame_and_cannot_be_copied(): void
    {
        $f = $this->fixture();
        $other = $this->enrollThroughLocalSmtp('another-eligible-buyer@example.test');
        $authority = new CurrentEligibleLicenses;
        $proof = DB::transaction(function () use ($authority, $f, $other) {
            $rows = new MembershipRows;
            $proof = $this->lock($authority, $rows, $f);
            $this->refused(fn () => (new CurrentEligibleLicenses)->proveCurrent($proof, $f['buyer']['principal'], $f['buyer']['user'], $rows), 'foreign_license_proof');
            $this->refused(fn () => $authority->proveCurrent(MembershipEligibleLicenseToken::value($proof->binding()), $f['buyer']['principal'], $f['buyer']['user'], $rows), 'foreign_license_proof');
            $this->refused(fn () => $authority->proveCurrent($proof, $other['principal'], $other['user'], $rows), 'foreign_or_expired_license_proof');
            $this->refused(fn () => clone $proof, 'clone_refused');
            $this->refused(fn () => serialize($proof), 'serialization_refused');
            $this->refused(fn () => json_encode($proof), 'serialization_refused');
            $this->refused(fn () => clone $authority, 'clone_refused');

            return $proof;
        });
        DB::transaction(fn () => $this->refused(fn () => $authority->proveCurrent($proof, $f['buyer']['principal'], $f['buyer']['user'], new MembershipRows), 'foreign_or_expired_license_proof'));
        $this->noMembershipEffects();
    }

    public function test_a_new_published_revision_does_not_admit_an_old_selection_locator(): void
    {
        $f = $this->fixture();
        $offer = app(SaveOfferDraft::class)->handle($f['offer'], ['track_id' => $f['track']->id,
            'license_version_id' => $f['items'][0]['licenseVersionId'], 'price_minor' => 5100, 'currency' => 'USD',
            'deliverable_asset_ids' => [$f['media']['master_wav']->id]], $f['actor']);
        $revision = app(PublishOffer::class)->handle($offer, $f['actor']);
        $this->assertNotSame($f['revision']->id, $revision->id);
        DB::transaction(fn () => $this->refused(fn () => $this->lock(new CurrentEligibleLicenses, new MembershipRows, $f), 'stale_selection'));
        $this->noMembershipEffects();
    }

    public function test_unpublished_track_and_new_unverified_rights_refuse_catalog_authority(): void
    {
        $f = $this->fixture();
        DB::table('tracks')->where('id', $f['track']->id)->update(['status' => 'draft']);
        DB::transaction(fn () => $this->refused(fn () => $this->lock(new CurrentEligibleLicenses, new MembershipRows, $f)));
        DB::table('tracks')->where('id', $f['track']->id)->update(['status' => 'published']);
        DB::table('rights_declarations')->insert(['track_id' => $f['track']->id, 'provenance_reference' => 'SYNTHETIC LATER RIGHTS',
            'sample_disclosure' => 'Test only', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        DB::transaction(fn () => $this->refused(fn () => $this->lock(new CurrentEligibleLicenses, new MembershipRows, $f)));
        $this->noMembershipEffects();
    }

    public function test_private_bytes_are_rechecked_without_minting_a_new_readiness_deadline(): void
    {
        $f = $this->fixture();
        DB::transaction(function () use ($f): void {
            $rows = new MembershipRows;
            $authority = new CurrentEligibleLicenses;
            $proof = $this->lock($authority, $rows, $f);
            $path = app(PrivateMediaFiles::class)->resolve($f['media']['master_wav']->storage_path);
            $bytes = file_get_contents($path);
            chmod($path, 0600);
            file_put_contents($path, str_repeat('x', strlen($bytes)));
            $this->refused(fn () => $authority->proveCurrent($proof, $f['buyer']['principal'], $f['buyer']['user'], $rows), 'private_bytes');
        });
        $this->noMembershipEffects();
    }

    public function test_storage_callback_cannot_authenticate_a_later_graph_instead_of_the_selected_graph(): void
    {
        $f = $this->fixture();
        $calls = 0;
        $this->app->instance(PrivateMediaFiles::class, new class($f['track']->id, $calls) extends PrivateMediaFiles
        {
            public function __construct(private int $trackId, private int &$calls) {}

            public function resolve(string $relative): string
            {
                if ($this->calls++ === 0) {
                    DB::table('tracks')->where('id', $this->trackId)->update(['title' => 'SYNTHETIC CALLBACK CHANGED TITLE']);
                }

                return parent::resolve($relative);
            }
        });
        DB::transaction(fn () => $this->refused(fn () => $this->lock(new CurrentEligibleLicenses, new MembershipRows, $f), 'changed_catalog'));
        $this->assertGreaterThan(0, $calls);
        $this->noMembershipEffects();
    }

    public function test_temporary_catalog_shadow_is_refused_even_when_its_rows_are_identical(): void
    {
        $f = $this->fixture();
        $pdo = DB::connection()->getPdo();
        if (DB::getDriverName() === 'mysql') {
            $pdo->exec('CREATE TEMPORARY TABLE tracks LIKE tracks');
        } else {
            $pdo->exec('CREATE TEMP TABLE tracks AS SELECT * FROM main.tracks');
        }
        try {
            DB::transaction(fn () => $this->refused(fn () => $this->lock(new CurrentEligibleLicenses, new MembershipRows, $f)));
        } finally {
            $pdo->exec(DB::getDriverName() === 'mysql' ? 'DROP TEMPORARY TABLE tracks' : 'DROP TABLE temp.tracks');
        }
        $this->noMembershipEffects();
    }

    public function test_terminal_raw_evidence_uses_no_file_callback_and_refuses_changed_complete_footprint(): void
    {
        $f = $this->fixture();
        DB::transaction(function () use ($f): void {
            $rows = new MembershipRows;
            $authority = new CurrentEligibleLicenses;
            $proof = $this->lock($authority, $rows, $f);
            $snapshot = $authority->snapshot($proof, $f['buyer']['principal'], $f['buyer']['user'], $rows);
            $this->app->bind(PrivateMediaFiles::class, fn () => throw new \LogicException('A terminal raw proof called the file resolver.'));
            MembershipCatalogEvidence::assertCurrent($snapshot, $rows->identity(), $rows->driver());
            $this->assertSame($proof->binding(), $snapshot->binding);
            DB::table('tracks')->where('id', $f['track']->id)->update(['title' => 'SYNTHETIC TERMINAL CHANGE']);
            $this->refused(fn () => MembershipCatalogEvidence::assertCurrent($snapshot, $rows->identity(), $rows->driver()), 'changed_catalog');
        });
        $this->noMembershipEffects();
    }

    public function test_catalog_snapshot_is_verified_nonlocking_inside_committed_read_only_frame(): void
    {
        $f = $this->fixture();
        $snapshot = DB::transaction(function () use ($f) {
            $rows = new MembershipRows;
            $authority = new CurrentEligibleLicenses;
            $proof = $this->lock($authority, $rows, $f);

            return $authority->snapshot($proof, $f['buyer']['principal'], $f['buyer']['user'], $rows);
        });
        $pdo = DB::connection()->getPdo();
        $frame = IdentityCommittedFrame::begin(new CurrentRows($pdo, DB::getDriverName()), $snapshot->deadlineNs);
        try {
            MembershipCatalogEvidence::proveBytes($snapshot);
            MembershipCatalogEvidence::assertCurrent($snapshot, $pdo, DB::getDriverName(), false);
            $frame->finish();
        } finally {
            $frame->close();
        }
        $this->assertFalse($pdo->inTransaction());
        $this->noMembershipEffects();
    }

    public function test_original_readiness_boundary_and_configuration_change_refuse_existing_proof(): void
    {
        $f = $this->fixture();
        DB::transaction(function () use ($f): void {
            $rows = new MembershipRows;
            $authority = new CurrentEligibleLicenses;
            $proof = $this->lock($authority, $rows, $f);
            config(['production-memberships.enabled' => false]);
            $this->refused(fn () => $authority->proveCurrent($proof, $f['buyer']['principal'], $f['buyer']['user'], $rows), 'changed_policy');
            config(['production-memberships.enabled' => true]);
            CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(60));
            $this->refused(fn () => $authority->proveCurrent($proof, $f['buyer']['principal'], $f['buyer']['user'], $rows), 'expired_license_proof');
        });
        $this->noMembershipEffects();
    }

    public function test_original_license_effective_until_caps_wall_and_monotonic_readiness(): void
    {
        $f = $this->fixture();
        $until = CarbonImmutable::now('UTC')->addSeconds(30);
        $license = LicenseFixtures::published($f['actor'], content: ['effective_until' => $until->format('Y-m-d H:i:s')]);
        $f['offer'] = app(SaveOfferDraft::class)->handle($f['offer'], ['track_id' => $f['track']->id,
            'license_version_id' => $license->id, 'price_minor' => 4999, 'currency' => 'USD',
            'deliverable_asset_ids' => [$f['media']['master_wav']->id]], $f['actor']);
        $f['revision'] = app(PublishOffer::class)->handle($f['offer'], $f['actor']);
        DB::transaction(function () use ($f, $until): void {
            $rows = new MembershipRows;
            $authority = new CurrentEligibleLicenses;
            $before = hrtime(true);
            $proof = $this->lock($authority, $rows, $f);
            $snapshot = $authority->snapshot($proof, $f['buyer']['principal'], $f['buyer']['user'], $rows);
            $this->assertSame((int) $until->format('Uu'), $proof->binding()->validUntilUnixMicros);
            $this->assertGreaterThanOrEqual($before + 30_000_000_000, $snapshot->deadlineNs);
            $this->assertLessThanOrEqual(hrtime(true) + 30_000_000_000, $snapshot->deadlineNs);
            $this->assertLessThan($before + 60_000_000_000, $snapshot->deadlineNs);
            CarbonImmutable::setTestNow($until);
            $this->refused(fn () => $authority->proveCurrent($proof, $f['buyer']['principal'], $f['buyer']['user'], $rows));
        });
        $this->noMembershipEffects();
    }

    public function test_clock_callback_after_file_proof_cannot_change_private_bytes_before_authority_returns(): void
    {
        $f = $this->fixture();
        DB::transaction(function () use ($f): void {
            $rows = new MembershipRows;
            $authority = new CurrentEligibleLicenses;
            $proof = $this->lock($authority, $rows, $f);
            $state = $this->mutateBytesFromClockAfterResolve($f);
            try {
                $this->refused(fn () => $authority->proveCurrent($proof, $f['buyer']['principal'], $f['buyer']['user'], $rows), 'private_bytes');
                $this->assertTrue($state->mutated);
                $this->assertSame(2, $state->resolves);
            } finally {
                CarbonImmutable::setTestNow('2026-10-13 12:00:00 UTC');
            }
        });
        $this->noMembershipEffects();
    }

    public function test_committed_snapshot_checks_native_bytes_after_later_clock_callback(): void
    {
        $f = $this->fixture();
        $snapshot = DB::transaction(function () use ($f) {
            $rows = new MembershipRows;
            $authority = new CurrentEligibleLicenses;
            $proof = $this->lock($authority, $rows, $f);

            return $authority->snapshot($proof, $f['buyer']['principal'], $f['buyer']['user'], $rows);
        });
        $pdo = DB::connection()->getPdo();
        $frame = IdentityCommittedFrame::begin(new CurrentRows($pdo, DB::getDriverName()), $snapshot->deadlineNs);
        try {
            $state = $this->mutateBytesFromClockAfterResolve($f);
            MembershipCatalogEvidence::proveBytes($snapshot);
            CarbonImmutable::now('UTC'); // Later callback-capable consumer work, after file proof.
            $this->assertTrue($state->mutated);
            $this->refused(fn () => MembershipCatalogEvidence::assertCurrent($snapshot, $pdo, DB::getDriverName(), false), 'private_bytes');
        } finally {
            CarbonImmutable::setTestNow('2026-10-13 12:00:00 UTC');
            $frame->close();
        }
        $this->assertFalse($pdo->inTransaction());
        $this->noMembershipEffects();
    }

    public function test_final_clock_callback_cannot_withdraw_the_current_buyer_after_identity_proof(): void
    {
        $f = $this->fixture();
        DB::transaction(function () use ($f): void {
            $rows = new MembershipRows;
            $authority = new CurrentEligibleLicenses;
            $proof = $this->lock($authority, $rows, $f);
            $at = CarbonImmutable::now('UTC');
            $directClocks = 0;
            CarbonImmutable::setTestNow(function () use ($at, $f, &$directClocks) {
                $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
                $classes = array_column($frames, 'class');
                if (in_array(CurrentEligibleLicenses::class, $classes, true)
                    && ! in_array(ProductionCustomerAccess::class, $classes, true) && ++$directClocks === 2) {
                    DB::table('customer_accounts')->where('id', $f['buyer']['binding']['account_id'])
                        ->update(['active' => false, 'access_version' => DB::raw('access_version + 1')]);
                }

                return $at;
            });
            try {
                $this->refused(fn () => $authority->proveCurrent($proof, $f['buyer']['principal'], $f['buyer']['user'], $rows));
                $this->assertSame(2, $directClocks);
                $this->assertSame(0, (int) DB::table('customer_accounts')->where('id', $f['buyer']['binding']['account_id'])->value('active'));
            } finally {
                CarbonImmutable::setTestNow($at);
            }
        });
        $this->noMembershipEffects();
    }

    public function test_terminal_file_admission_refuses_a_one_shot_non_native_stat_wrapper(): void
    {
        $f = $this->fixture();
        DB::transaction(function () use ($f): void {
            $rows = new MembershipRows;
            $authority = new CurrentEligibleLicenses;
            $proof = $this->lock($authority, $rows, $f);
            $snapshot = $authority->snapshot($proof, $f['buyer']['principal'], $f['buyer']['user'], $rows);
            $pdo = $rows->identity();
            $statement = $pdo->prepare('UPDATE customer_accounts SET active = 0, access_version = access_version + 1 WHERE id = ?');
            $accountId = $f['buyer']['binding']['account_id'];
            EligibilityOneShotFileWrapper::$callback = static fn () => $statement->execute([$accountId]);
            EligibilityOneShotFileWrapper::$statCalls = EligibilityOneShotFileWrapper::$openCalls = 0;
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', EligibilityOneShotFileWrapper::class);
            EligibilityOneShotFileWrapper::$installed = true;
            try {
                $this->refused(fn () => MembershipCatalogEvidence::assertCurrent($snapshot, $pdo, $rows->driver()), 'private_bytes');
                $this->assertSame(0, EligibilityOneShotFileWrapper::$statCalls);
                $this->assertSame(1, EligibilityOneShotFileWrapper::$openCalls);
                $this->assertSame(1, (int) DB::table('customer_accounts')->where('id', $accountId)->value('active'));
            } finally {
                EligibilityOneShotFileWrapper::restore();
                EligibilityOneShotFileWrapper::$callback = null;
            }
        });
        $this->noMembershipEffects();
    }

    private function mutateBytesFromClockAfterResolve(array $f): object
    {
        $at = CarbonImmutable::now('UTC');
        $path = app(PrivateMediaFiles::class)->resolve($f['media']['master_wav']->storage_path);
        $state = (object) ['resolves' => 0, 'mutated' => false];
        $this->app->instance(PrivateMediaFiles::class, new class($state) extends PrivateMediaFiles
        {
            public function __construct(private object $state) {}

            public function resolve(string $relative): string
            {
                $this->state->resolves++;

                return parent::resolve($relative);
            }
        });
        CarbonImmutable::setTestNow(function () use ($state, $at, $path) {
            if ($state->resolves > 0 && ! $state->mutated) {
                $state->mutated = true;
                $bytes = file_get_contents($path);
                chmod($path, 0600);
                file_put_contents($path, str_repeat('x', strlen($bytes)));
                chmod($path, 0400);
            }

            return $at;
        });

        return $state;
    }

    private function fixture(): array
    {
        return QuoteFixtures::selection() + ['buyer' => $this->enrollThroughLocalSmtp()];
    }

    private function lock(CurrentEligibleLicenses $authority, MembershipRows $rows, array $f): MembershipEligibleLicenseProof
    {
        return $authority->lock((string) $f['offer']->id, $f['revision']->id, $f['buyer']['principal'], $f['buyer']['user'], $rows);
    }

    private function noMembershipEffects(): void
    {
        foreach (['production_membership_paid_periods', 'production_membership_redemptions', 'production_membership_credit_events'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }

    private function refused(callable $work, ?string $reason = null): void
    {
        try {
            $work();
            $this->fail('Expected current catalog proof refusal.');
        } catch (MembershipException|CheckoutException|IdentityException|ValidationException|MediaFailure $error) {
            $reason === null ? $this->assertNotEmpty($error->getMessage()) : $this->assertSame($reason, $error->reason);
        }
    }
}

/** One-shot synthetic wrapper: a pre-open stat would run a callback then restore native files. */
class EligibilityOneShotFileWrapper
{
    public mixed $context;

    public static ?\Closure $callback = null;

    public static int $statCalls = 0;

    public static int $openCalls = 0;

    public static bool $installed = false;

    public static function restore(): void
    {
        if (self::$installed) {
            stream_wrapper_restore('file');
            self::$installed = false;
        }
    }

    public function url_stat(string $path, int $flags): array|false
    {
        self::$statCalls++;
        EligibilityOneShotFileWrapper::restore();
        (self::$callback)();

        return lstat($path);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$openCalls++;
        EligibilityOneShotFileWrapper::restore();

        return false;
    }
}
