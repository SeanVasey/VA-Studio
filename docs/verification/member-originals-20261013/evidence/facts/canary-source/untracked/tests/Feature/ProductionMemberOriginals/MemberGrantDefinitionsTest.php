<?php

namespace Tests\Feature\ProductionMemberOriginals;

use App\Domain\Grants\Member\MemberGrantDefinitionDocument;
use App\Domain\Grants\Member\MemberGrantDefinitions;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantProfileDocument;
use App\Domain\Grants\Member\MemberGrantRecords;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberGrantDocumentFixtures;
use Tests\Support\MembershipPolicyFixtures;
use Tests\TestCase;

/** Real operator/plan writers with explicitly synthetic retained-format catalog inputs. */
final class MemberGrantDefinitionsTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MembershipPolicyFixtures;

    private function inputs(bool $mfa = true): array
    {
        $this->policySetup();
        $author = $this->policyStaff($mfa);
        $plan = $this->createPolicy($mfa ? $author : $this->policyStaff());
        config(['member-grants.authoring_enabled' => true, 'member-grants.provenance' => 'synthetic_rehearsal']);
        $profile = MemberGrantProfileDocument::from(MemberGrantDocumentFixtures::profile());
        $document = MemberGrantDocumentFixtures::definition();
        $document['plan_version_id'] = $plan['id'];
        $document['policy_hash'] = $plan['policy_hash'];

        return [$profile, MemberGrantDefinitionDocument::from($document), $author];
    }

    public function test_real_enrolled_staff_authors_and_deduplicates_without_customer_gate_or_grant_writes(): void
    {
        [$profile, $definition, $author] = $this->inputs();
        $first = (new MemberGrantDefinitions)->author($profile, $definition, $author);
        $before = DB::table(MemberGrantRecords::DEFINITIONS)->get()->map(fn ($row) => (array) $row)->all();
        $second = (new MemberGrantDefinitions)->author($profile, $definition, $author);
        self::assertEquals($first, $second);
        self::assertSame($before, DB::table(MemberGrantRecords::DEFINITIONS)->get()->map(fn ($row) => (array) $row)->all());
        self::assertSame(1, DB::table(MemberGrantRecords::PROFILES)->count());
        self::assertFalse(config('member-grants.enabled'));
        foreach (['production_member_origins', 'production_member_artifacts', 'production_member_activations', 'production_membership_credit_events'] as $table) {
            self::assertSame(0, DB::table($table)->count());
        }
        self::assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_missing_real_mfa_refuses_before_member_rows_are_written(): void
    {
        [$profile, $definition, $author] = $this->inputs(false);
        $this->refuses(fn () => (new MemberGrantDefinitions)->author($profile, $definition, $author));
        $this->assertEmptyMembers();
    }

    public function test_authoring_flag_is_independent_and_defaults_off(): void
    {
        [$profile, $definition, $author] = $this->inputs();
        config(['member-grants.authoring_enabled' => false, 'member-grants.enabled' => true]);
        $this->refuses(fn () => (new MemberGrantDefinitions)->author($profile, $definition, $author));
        $this->assertEmptyMembers();
    }

    public function test_exact_authenticated_plan_hash_is_required_before_inserts(): void
    {
        [$profile, $definition, $author] = $this->inputs();
        $document = $definition->value();
        $document['policy_hash'] = str_repeat('0', 64);
        $this->refuses(fn () => (new MemberGrantDefinitions)->author($profile, MemberGrantDefinitionDocument::from($document), $author));
        $this->assertEmptyMembers();
    }

    public function test_committing_callback_configuration_change_rolls_back_both_immutable_rows(): void
    {
        [$profile, $definition, $author] = $this->inputs();
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function (): void {
            config(['member-grants.authoring_enabled' => false]);
        });
        $this->refuses(fn () => (new MemberGrantDefinitions)->author($profile, $definition, $author));
        $this->assertEmptyMembers();
    }

    public function test_committed_callback_staff_withdrawal_refuses_receipt_while_retaining_durable_rows(): void
    {
        [$profile, $definition, $author] = $this->inputs();
        $pdo = DB::connection()->getPdo();
        DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, function () use ($pdo, $author): void {
            $statement = $pdo->prepare('UPDATE users SET is_admin = 0 WHERE id = ?');
            $statement->execute([$author->getKey()]);
        });
        $this->refuses(fn () => (new MemberGrantDefinitions)->author($profile, $definition, $author), 'committed_receipt_refused');
        self::assertSame(1, DB::table(MemberGrantRecords::PROFILES)->count());
        self::assertSame(1, DB::table(MemberGrantRecords::DEFINITIONS)->count());
        self::assertFalse($pdo->inTransaction());
    }

    public function test_caller_outer_transaction_cannot_receive_provisional_authoring_identity(): void
    {
        [$profile, $definition, $author] = $this->inputs();
        DB::beginTransaction();
        try {
            $this->refuses(fn () => (new MemberGrantDefinitions)->author($profile, $definition, $author), 'transaction_forbidden');
            self::assertTrue(DB::connection()->getPdo()->inTransaction());
            $this->assertEmptyMembers();
        } finally {
            DB::rollBack();
        }
    }

    private function assertEmptyMembers(): void
    {
        self::assertSame(0, DB::table(MemberGrantRecords::PROFILES)->count());
        self::assertSame(0, DB::table(MemberGrantRecords::DEFINITIONS)->count());
    }

    private function refuses(callable $operation, ?string $reason = null): void
    {
        try {
            $operation();
            self::fail('Expected member authoring refusal.');
        } catch (MemberGrantException $error) {
            $reason === null ? $this->addToAssertionCount(1) : self::assertSame($reason, $error->reason);
        }
    }
}
