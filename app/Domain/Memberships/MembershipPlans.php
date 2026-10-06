<?php

namespace App\Domain\Memberships;

use App\Domain\Memberships\Models\MembershipPlan;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;

/** Private versioned synthetic plans; no price, active subscription or acquisition state. */
final class MembershipPlans
{
    public const REVIEW_KEYS = ['schema_version', 'intent', 'actor_id', 'plan_id', 'plan_hash', 'version_id', 'version_hash', 'history_hash', 'audit_id', 'replacement'];

    public function __construct(private MembershipPolicy $policy, private MembershipEvidence $evidence) {}

    public function createDraft(array $data, User $actor): array
    {
        $this->policy->standalone();
        $data = $this->policy->plan($data);

        return DB::transaction(function () use ($data, $actor): array {
            $users = $this->evidence->operator($actor);
            $id = DB::table('membership_plans')->insertGetId(['created_by' => $actor->getKey(), 'created_at' => $this->at()]);
            $parent = $this->evidence->row('membership_plans', $id);
            $version = $this->append($id, 1, $data, $actor);
            $audit = $this->evidence->audit('membership.test_plan.created', MembershipPlan::class, $id,
                ['schema_version' => 1, 'version_id' => (int) $version['id'], 'manifest_hash' => $version['manifest_hash'], 'test_only' => true], (int) $actor->getKey());
            $this->finish($actor, $users, $parent, [$version], $audit);

            return $this->projection($version);
        });
    }

    /** This explicit call is the review capture point; apply never captures a replacement baseline. */
    public function reviewRevision(MembershipPlan $plan, array $replacement, User $actor): array
    {
        $this->policy->standalone();
        $replacement = $this->policy->plan($replacement);

        return DB::transaction(function () use ($plan, $replacement, $actor): array {
            $users = $this->evidence->operator($actor);
            $parent = $this->evidence->row('membership_plans', $plan->exists ? (int) $plan->getKey() : 0);
            $versions = $this->versions((int) $parent['id']);
            $version = $versions[array_key_last($versions)];
            $cursor = $this->evidence->cursor(MembershipPlan::class, (int) $parent['id']);
            $review = ['schema_version' => 1, 'intent' => 'revise_membership_test_plan', 'actor_id' => (int) $actor->getKey(),
                'plan_id' => (int) $parent['id'], 'plan_hash' => CanonicalJson::hash($parent), 'version_id' => (int) $version['id'],
                'version_hash' => CanonicalJson::hash($version), 'history_hash' => CanonicalJson::hash($versions), 'audit_id' => $cursor, 'replacement' => $replacement];
            $this->finish($actor, $users, $parent, $versions, null, $cursor);

            return $review;
        });
    }

    public function applyReviewedRevision(array $review, User $actor): array
    {
        $this->policy->standalone();
        if (array_keys($review) !== self::REVIEW_KEYS || $review['schema_version'] !== 1 || $review['intent'] !== 'revise_membership_test_plan'
            || $review['actor_id'] !== $actor->getKey() || ! is_int($review['plan_id']) || $review['plan_id'] < 1
            || ! is_int($review['version_id']) || $review['version_id'] < 1 || ($review['audit_id'] !== null && (! is_int($review['audit_id']) || $review['audit_id'] < 1))
            || ! is_array($review['replacement'])) {
            $this->stale();
        }
        foreach (['plan_hash', 'version_hash', 'history_hash'] as $field) {
            if (! is_string($review[$field]) || ! preg_match('/\A[a-f0-9]{64}\z/D', $review[$field])) {
                $this->stale();
            }
        }
        $replacement = $this->policy->plan($review['replacement']);

        return DB::transaction(function () use ($review, $replacement, $actor): array {
            $users = $this->evidence->operator($actor);
            $parent = $this->evidence->row('membership_plans', $review['plan_id']);
            $versions = $this->versions($review['plan_id']);
            $version = $versions[array_key_last($versions)];
            $cursor = $this->evidence->cursor(MembershipPlan::class, $review['plan_id']);
            if ((int) $version['id'] !== $review['version_id'] || CanonicalJson::hash($parent) !== $review['plan_hash']
                || CanonicalJson::hash($version) !== $review['version_hash'] || CanonicalJson::hash($versions) !== $review['history_hash'] || $cursor !== $review['audit_id']) {
                $this->stale();
            }
            if ($this->manifest($version) === $replacement) {
                $this->finish($actor, $users, $parent, $versions, null, $cursor);

                return $this->projection($version);
            }
            if (count($versions) >= MembershipPolicy::MAX_EVENTS) {
                $this->policy->reject('plan', 'Retain this plan history and create a separately reviewed successor.');
            }
            $next = $this->append($review['plan_id'], (int) $version['number'] + 1, $replacement, $actor);
            $versions[] = $next;
            $audit = $this->evidence->audit('membership.test_plan.revised', MembershipPlan::class, $review['plan_id'],
                ['schema_version' => 1, 'version_id' => (int) $next['id'], 'before_hash' => $version['manifest_hash'],
                    'after_hash' => $next['manifest_hash'], 'review_hash' => CanonicalJson::hash($review), 'test_only' => true], (int) $actor->getKey());
            $this->finish($actor, $users, $parent, $versions, $audit);

            return $this->projection($next);
        });
    }

    /** @internal Transaction caller already owns plan anchor; historical versions retain their exact identity. */
    public function retainedVersion(int $id): array
    {
        $this->policy->requireEnabled();
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Retained membership versions require a command transaction.');
        }
        $version = $this->evidence->row('membership_plan_versions', $id);
        $this->manifest($version);

        return $version;
    }

    private function versions(int $id): array
    {
        $rows = DB::table('membership_plan_versions')->where('membership_plan_id', $id)->orderBy('number')->limit(MembershipPolicy::MAX_EVENTS + 1)->lockForUpdate()->get()
            ->map(fn ($row) => (array) $row)->all();
        if ($rows === [] || count($rows) > MembershipPolicy::MAX_EVENTS) {
            $this->stale();
        }
        foreach ($rows as $index => $row) {
            if ((int) $row['number'] !== $index + 1) {
                $this->stale();
            }
            $this->manifest($row);
        }

        return $rows;
    }

    private function manifest(array $version): array
    {
        $data = $this->policy->plan(['title' => $version['title'], 'policy' => json_decode($version['policy'], true, 32, JSON_THROW_ON_ERROR)]);
        if ($version['manifest_hash'] !== CanonicalJson::hash($data)) {
            $this->stale();
        }

        return $data;
    }

    private function append(int $id, int $number, array $data, User $actor): array
    {
        $versionId = DB::table('membership_plan_versions')->insertGetId(['membership_plan_id' => $id, 'number' => $number, 'title' => $data['title'],
            'policy' => CanonicalJson::encode($data['policy']), 'manifest_hash' => CanonicalJson::hash($data), 'created_by' => $actor->getKey(), 'created_at' => $this->at()]);

        return $this->evidence->row('membership_plan_versions', $versionId);
    }

    private function finish(User $actor, array $users, array $parent, array $versions, ?array $audit, ?int $cursor = null): void
    {
        $this->evidence->recheckOperator($actor);
        $this->evidence->prove($users, null, [['membership_plans', (int) $parent['id'], $parent],
            ...array_map(fn ($row) => ['membership_plan_versions', (int) $row['id'], $row], $versions)], $audit);
        if ($this->versions((int) $parent['id']) !== $versions || ($audit === null && $this->evidence->cursor(MembershipPlan::class, (int) $parent['id']) !== $cursor)) {
            $this->stale();
        }
    }

    private function projection(array $row): array
    {
        return ['plan_id' => (int) $row['membership_plan_id'], 'version_id' => (int) $row['id'], 'number' => (int) $row['number'],
            ...$this->manifest($row), 'manifest_hash' => $row['manifest_hash']];
    }

    private function at(): string
    {
        return now()->utc()->startOfSecond()->format('Y-m-d H:i:s');
    }

    private function stale(): never
    {
        $this->policy->reject('plan', 'This private plan review changed. Close and explicitly review the current version.');
    }
}
