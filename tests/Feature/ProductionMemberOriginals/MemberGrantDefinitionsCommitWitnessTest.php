<?php

namespace Tests\Feature\ProductionMemberOriginals;

use App\Domain\Grants\Member\MemberGrantDefinitionDocument;
use App\Domain\Grants\Member\MemberGrantDefinitions;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantProfileDocument;
use App\Domain\Grants\Member\MemberGrantRecords;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberGrantDocumentFixtures;
use Tests\Support\MembershipPolicyFixtures;
use Tests\TestCase;

/** Immutable authoring keeps a committed classification when an ordinary afterCommit callback throws. */
final class MemberGrantDefinitionsCommitWitnessTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MembershipPolicyFixtures;

    public function test_throwing_after_commit_callback_reports_committed_authoring_and_retry_deduplicates(): void
    {
        $this->policySetup();
        $author = $this->policyStaff();
        $plan = $this->createPolicy($author);
        config(['member-grants.authoring_enabled' => true, 'member-grants.provenance' => 'synthetic_rehearsal']);
        $profile = MemberGrantProfileDocument::from(MemberGrantDocumentFixtures::profile());
        $document = MemberGrantDocumentFixtures::definition();
        $document['plan_version_id'] = $plan['id'];
        $document['policy_hash'] = $plan['policy_hash'];
        $definition = MemberGrantDefinitionDocument::from($document);
        $calls = 0;
        $armed = true;
        DB::connection()->getEventDispatcher()->listen(TransactionBeginning::class, function () use (&$calls, &$armed): void {
            if (! $armed) {
                return;
            }
            $armed = false;
            DB::afterCommit(function () use (&$calls): void {
                $calls++;
                throw new RuntimeException('Synthetic ordinary afterCommit failure.');
            });
        });
        try {
            (new MemberGrantDefinitions)->author($profile, $definition, $author);
            self::fail('Expected a committed authoring receipt refusal.');
        } catch (MemberGrantException $error) {
            self::assertSame('committed_receipt_refused', $error->reason);
        }
        self::assertSame(1, $calls);
        $profiles = DB::table(MemberGrantRecords::PROFILES)->get()->map(fn ($row) => (array) $row)->all();
        $definitions = DB::table(MemberGrantRecords::DEFINITIONS)->get()->map(fn ($row) => (array) $row)->all();
        self::assertCount(1, $profiles);
        self::assertCount(1, $definitions);
        self::assertSame(0, DB::transactionLevel());
        self::assertFalse(DB::connection()->getPdo()->inTransaction());
        // An operator retry deduplicates onto the committed immutable rows.
        $authored = (new MemberGrantDefinitions)->author($profile, $definition, $author);
        self::assertSame($definitions[0]['id'], $authored->definitionId);
        self::assertSame($profiles, DB::table(MemberGrantRecords::PROFILES)->get()->map(fn ($row) => (array) $row)->all());
        self::assertSame($definitions, DB::table(MemberGrantRecords::DEFINITIONS)->get()->map(fn ($row) => (array) $row)->all());
    }
}
