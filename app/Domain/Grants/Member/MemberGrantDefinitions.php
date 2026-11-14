<?php

namespace App\Domain\Grants\Member;

use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Memberships\Production\MembershipAwardConfiguration;
use App\Domain\Memberships\Production\MembershipCommitWitness;
use App\Domain\Memberships\Production\MembershipPolicyStaff;
use App\Domain\Memberships\Production\MembershipPolicyVersions;
use App\Models\User;
use App\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use Filament\Facades\Filament;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use ReflectionProperty;
use Throwable;

/** Standalone default-off immutable operator authoring; no provisional success escapes a frame. */
final class MemberGrantDefinitions
{
    public function author(MemberGrantProfileDocument $profile, MemberGrantDefinitionDocument $definition, User $author): MemberGrantAuthored
    {
        $deadline = \hrtime(true) + 60_000_000_000;
        $policy = new MemberGrantPolicy;
        $baseline = $policy->authoringBaseline();
        MemberGrantException::require($baseline['authoring_enabled'] === true, 'disabled');
        $connection = DB::connection();
        $primary = $connection->getPdo();
        foreach (DB::getConnections() as $source) {
            $pdo = $source->getPdo();
            MembershipAwardConfiguration::plainPrimary($pdo);
            MemberGrantException::require($source->transactionLevel() === 0 && ! $pdo->inTransaction(), 'transaction_forbidden');
        }
        $finish = MemberOriginalFinishBoundary::capture();
        $events = $connection->getEventDispatcher();
        $originalManager = (new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($connection);
        MemberGrantException::require($originalManager === null || get_class($originalManager) === DatabaseTransactionsManager::class, 'changed_transaction');
        $manager = new MembershipCommitWitness;
        $observer = new MemberGrantAuthoringObserver($connection, $primary, $events, $manager);
        $connection->setTransactionManager($manager);
        $connection->setEventDispatcher($observer);
        try {
            $connection->beginTransaction();
            $rows = $observer->rows();
            $configuration = $policy->authoringFor($rows);
            MemberGrantException::require($configuration === $baseline, 'changed_policy');
            $schema = MemberGrantCurrentSchema::pin($rows);
            $reader = new MemberGrantRows($rows, $schema);
            $staff = new MembershipPolicyStaff;
            $staffRow = $staff->lock($author, $rows);
            $p = $profile->value();
            $d = $definition->value();
            MemberGrantException::require($p['provenance'] === $configuration['provenance'] && $d['provenance'] === $configuration['provenance']
                && $d['profile_hash'] === $profile->hash() && CanonicalJson::hash($p['original_terms']) === $d['original_terms_hash']
                && CanonicalJson::encode($p['original_terms']) === CanonicalJson::encode(MemberGrantDocumentValues::termsProjection($d['license_manifest'])), 'conflicting_facts');
            $planRow = $reader->plan($d['plan_version_id']);
            MemberGrantException::require($planRow !== [], 'plan_absent');
            $plan = MembershipPolicyVersions::document($planRow);
            MemberGrantException::require($plan->hash() === $d['policy_hash'] && $plan->facts['provenance'] === $d['provenance'], 'conflicting_plan');
            $created = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $authorId = (int) $staffRow['id'];
            $authorHash = CanonicalJson::hash(MemberGrantRecords::strings($staffRow));
            $profileRow = $reader->profileByHash($profile->hash());
            if ($profileRow === []) {
                $profileRow = MemberGrantRecords::profile($profile, (string) Str::uuid(), $authorId, $authorHash, $created);
                $staff->proveCurrent($author, $rows, $staffRow);
                $policy->proveAuthoringCurrent($configuration, $rows);
                MemberGrantCurrentSchema::assertCurrent($schema, $rows);
                MemberGrantRows::equal($planRow, $reader->plan($planRow['id']));
                MemberGrantRows::equal($staffRow, $reader->user($authorId));
                $reader->insertProfile($profileRow);
            }
            MemberGrantException::require(MemberGrantRecords::readProfile($profileRow)->hash() === $profile->hash(), 'conflicting_facts');
            $definitionRow = $reader->definitionByHash($definition->hash());
            if ($definitionRow === []) {
                $definitionRow = MemberGrantRecords::definition($definition, (string) Str::uuid(), $profileRow['id'], $authorId, $authorHash, $created);
                $staff->proveCurrent($author, $rows, $staffRow);
                $policy->proveAuthoringCurrent($configuration, $rows);
                MemberGrantCurrentSchema::assertCurrent($schema, $rows);
                MemberGrantRows::equal($profileRow, $reader->profile($profileRow['id']));
                MemberGrantRows::equal($planRow, $reader->plan($planRow['id']));
                MemberGrantRows::equal($staffRow, $reader->user($authorId));
                $reader->insertDefinition($definitionRow);
            }
            $snapshot = MemberGrantRecords::snapshot($profileRow, $definitionRow);
            MemberGrantException::require($snapshot->definitionDocument->hash() === $definition->hash(), 'conflicting_facts');
            $result = new MemberGrantAuthored($profileRow['id'], $profile->hash(), $definitionRow['id'], $definition->hash(), $d['provenance']);
            $prove = function () use ($staff, $author, $rows, $staffRow, $policy, $configuration, $schema, $reader, $profileRow, $definitionRow, $planRow, $deadline, $finish): void {
                $staff->proveCurrent($author, $rows, $staffRow);
                MemberGrantRecords::snapshot($reader->profile($profileRow['id']), $reader->definition($definitionRow['id']));
                MembershipPolicyVersions::document($reader->plan($planRow['id']));
                $policy->proveAuthoringCurrent($configuration, $rows);
                $finish->assertCurrent();
                MemberGrantCurrentSchema::assertCurrent($schema, $rows);
                self::raw($reader, $profileRow, $definitionRow, $planRow, $staffRow, false);
                $policy->proveAuthoringConfiguration($configuration);
                MemberGrantCurrentSchema::assertCurrent($schema, $rows);
                MemberGrantException::require(\hrtime(true) < $deadline, 'operation_deadline');
            };
            $prove();
            $current = $rows->current();
            $observer->prepare($prove);
            $manager->arm($connection, $primary);
            $connection->commit();
            $observer->requireProof();
        } catch (Throwable $error) {
            if ($manager->physicallyCommitted()) {
                // A throwing afterCommit callback or committed delegate followed the witnessed owned commit.
                // Immutable rows are durable; this is a refused response, never a pre-commit refusal.
                throw new MemberGrantException('committed_receipt_refused');
            }
            $observer->abort();
            if ($error instanceof MemberGrantException) {
                throw $error;
            }
            throw new MemberGrantException('authoring_refused');
        } finally {
            if ($events === null) {
                $connection->unsetEventDispatcher();
            } else {
                $connection->setEventDispatcher($events);
            }
            if ($originalManager === null) {
                $connection->unsetTransactionManager();
            } else {
                $connection->setTransactionManager($originalManager);
            }
        }
        // Normal committed delegates/afterCommit callbacks have all completed before this read frame.
        try {
            $frame = IdentityCommittedFrame::begin($current, $deadline);
            try {
                self::committedStaff($author, $staffRow);
                MemberGrantRecords::snapshot($reader->profile($profileRow['id'], true), $reader->definition($definitionRow['id'], true));
                MembershipPolicyVersions::document($reader->plan($planRow['id'], true));
                $policy->proveAuthoringConfiguration($configuration);
                $finish->assertCurrent();
                MemberGrantCurrentSchema::assertCommitted($schema, $frame);
                self::raw($reader, $profileRow, $definitionRow, $planRow, $staffRow, true);
                $policy->proveAuthoringConfiguration($configuration);
                $finish->assertCurrent();
                MemberGrantCurrentSchema::assertCommitted($schema, $frame);
                MemberGrantException::require(\hrtime(true) < $deadline, 'operation_deadline');
                $finish->assertCurrent();
                $frame->finish();
                $finish->assertCurrent();

                return $result;
            } finally {
                $frame->close();
            }
        } catch (Throwable) {
            // Immutable rows may already be durable; response refusal is not an automatic retry.
            throw new MemberGrantException('committed_receipt_refused');
        }
    }

    private static function raw(MemberGrantRows $reader, array $profile, array $definition, array $plan, array $staff, bool $committed): void
    {
        MemberGrantRows::equal($profile, $reader->profile($profile['id'], $committed));
        MemberGrantRows::equal($definition, $reader->definition($definition['id'], $committed));
        MemberGrantRows::equal($plan, $reader->plan($plan['id'], $committed));
        MemberGrantRows::equal($staff, $reader->user((int) $staff['id'], $committed));
    }

    private static function committedStaff(User $actor, array $expected): void
    {
        MemberGrantException::require($actor->exists && (int) $actor->getKey() === (int) $expected['id'], 'staff_refused');
        $current = User::query()->find($expected['id']);
        MemberGrantException::require($current !== null && Gate::forUser($current)->allows('administer-catalog', [false]), 'staff_refused');
        $enrolled = false;
        foreach (Filament::getPanel('admin')->getMultiFactorAuthenticationProviders() as $provider) {
            $enrolled = $provider->isEnabled($current) || $enrolled;
        }
        MemberGrantException::require($enrolled, 'mfa_required');
    }
}
