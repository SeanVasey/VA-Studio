<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Commerce\Policy\Models\ProductionTrackPolicyDraft;
use App\Domain\Commerce\ProductionPolicy\CapabilityHistory;
use App\Domain\Commerce\ProductionPolicy\ProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\SourceCommitment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use PDO;
use ReflectionProperty;

/**
 * Fixed qualified raw SELECT plans on the captured internal primary of one original command frame.
 *
 * The plan, read, definition and identity/selection/history selectors deliberately reproduce the
 * frozen c6 CheckoutWriteAdmission private machinery byte-for-byte in behavior, so that frozen
 * capsule stays unchanged and independently reviewed. Consolidating CheckoutWriteAdmission onto
 * this class is a separate refactor that needs its own review.
 */
final class CheckoutRawPlans
{
    private array $plans = [];

    private array $definitions = [];

    private array $actors = [];

    public function __construct(private readonly CheckoutCommandFrame $frame) {}

    public function belongsTo(CheckoutCommandFrame $frame): bool
    {
        return $this->frame === $frame;
    }

    /** Retains a caller-supplied model's exact attribute array; a swapped or mutated model refuses. */
    public function actor(User $actor): void
    {
        $attributes = (new ReflectionProperty(Model::class, 'attributes'))->getValue($actor);
        CheckoutException::require(is_array($attributes) && $actor::class === User::class && $actor->exists, 'write_frame');
        $this->actors[] = [$actor, $attributes];
    }

    /** Buyer identity rows returned by ProductionCustomerAccess::lock(). */
    public function identity(array $identity): void
    {
        $this->plan('users', 'id = ?', [$identity['user']['id']], 2, [$identity['user']]);
        $this->plan('customer_accounts', 'user_id = ?', [$identity['user']['id']], 2, [$identity['account']]);
        $this->plan('production_identity_origins', 'account_id = ?', [$identity['account']['id']], 2, [$identity['origin']]);
        $this->plan('production_identity_verifications', 'origin_id = ?', [$identity['origin']['id']], 129, $identity['verification_history']);
        foreach (array_column($identity['verification_history'], 'challenge_id') as $id) {
            $this->plan('production_identity_challenges', 'id = ?', [$id], 2,
                $this->read('production_identity_challenges', 'id = ?', [$id], 2));
        }
    }

    /** Staff evidence returned by StaffProof::lock(): the exact users row (role, verification, MFA enrollment) and its audits. */
    public function staff(int $userId, array $staff): void
    {
        CheckoutException::require($userId > 0 && (string) ($staff['row']['id'] ?? '') === (string) $userId, 'write_frame');
        $this->plan('users', 'id = ?', [$userId], 2, [$staff['row']]);
        $this->plan('audit_events', 'subject_type = ? AND subject_id = ?', [User::class, $userId], 257, $staff['audits']);
    }

    /** Complete current catalog selection graph returned by CurrentSelection::load(). */
    public function selection(array $selection): void
    {
        $graph = $selection['graph'];
        $ids = array_column($selection['items'], 'trackId');
        foreach (['tracks' => ['tracks', 'id'], 'offers' => ['offers', 'track_id'], 'rights' => ['rights_declarations', 'track_id'],
            'media' => ['media_assets', 'track_id'], 'bindings' => ['stems_recordings', 'track_id']] as $key => [$table, $column]) {
            $this->set($table, $column, $ids, $graph[$key]);
        }
        $this->set('offer_revisions', 'offer_id', array_column($graph['offers'], 'id'), $graph['revisions']);
        $this->set('license_versions', 'id', array_unique(array_column($graph['revisions'], 'license_version_id')), $graph['licenses']);
        $this->set('license_templates', 'id', array_unique(array_column($graph['licenses'], 'license_template_id')), $graph['templates']);
        $this->set('license_review_evidence', 'license_version_id', array_column($graph['licenses'], 'id'), $graph['reviews']);
        $this->set('media_processing_runs', 'source_asset_id', array_column($graph['media'], 'id'), $graph['runs']);
        $this->set('media_assets', 'processing_run_id', array_column($graph['runs'], 'id'), $graph['outputs']);
        $this->set('rights_scope_offers', 'offer_revision_id', array_column($graph['revisions'], 'id'), $selection['links']);
        foreach ($graph['audits'] as [$type, $id, $rows]) {
            CheckoutException::require(in_array($type, [Track::class, Offer::class], true));
            $this->plan('audit_events', 'subject_type = ? AND subject_id = ?', [$type, $id], 257, $rows);
        }
    }

    /** Complete source policy and capability history returned as CurrentPolicy::load()['raw']. */
    public function history(array $history): void
    {
        $source = $history['source'];
        $id = $source['draft']['id'];
        $this->plan(SourceCommitment::DRAFTS, 'id = ?', [$id], 2, [$source['draft']]);
        $this->plan(SourceCommitment::VERSIONS, 'production_track_policy_draft_id = ?', [$id], 257, $source['versions']);
        $this->set(SourceCommitment::REVIEWS, 'production_track_policy_version_id', array_column($source['versions'], 'id'), $source['reviews']);
        $this->plan('audit_events', 'subject_type = ? AND subject_id = ?', [ProductionTrackPolicyDraft::class, $id], 513, $source['audits']);
        $this->plan(CapabilityHistory::CANDIDATES, 'production_track_policy_draft_id = ?', [$id], 257, $history['candidates']);
        $ids = array_column($history['candidates'], 'id');
        $this->set(CapabilityHistory::APPROVALS, 'production_track_capability_candidate_id', $ids, $history['approvals']);
        $this->set(CapabilityHistory::CLOSURES, 'production_track_capability_candidate_id', $ids, $history['closures']);
        $this->plan('audit_events', 'subject_type = ? AND subject_id = ?', [ProductionTrackCapabilities::class, $id], 769, $history['audits']);
    }

    /** One owned checkout row by primary key. */
    public function row(string $kind, array $row): void
    {
        $this->plan(CheckoutSchema::TABLES[$kind], 'id = ?', [$row['id']], 2, [$row]);
    }

    /** The exact complete set of owned checkout rows behind a fixed selector. */
    public function selector(string $kind, string $where, array $bindings, int $limit, array $expected): void
    {
        $this->plan(CheckoutSchema::TABLES[$kind], $where, $bindings, $limit, $expected);
    }

    /** Commit-time comparison: captured internal PDO and default statements only. */
    public function proveCurrent(): void
    {
        $this->frame->prove(1);
        foreach ($this->actors as [$actor, $attributes]) {
            CheckoutException::require($actor::class === User::class && $actor->exists
                && (new ReflectionProperty(Model::class, 'attributes'))->getValue($actor) === $attributes, 'write_source_changed');
        }
        foreach ($this->definitions as $table => $definition) {
            CheckoutException::require($this->definition($table) === $definition, 'write_source_changed');
        }
        foreach ($this->plans as $plan) {
            CheckoutException::require($this->read($plan['table'], $plan['where'], $plan['bindings'], $plan['limit']) === $plan['raw'], 'write_source_changed');
        }
        $this->frame->prove(1);
    }

    private function set(string $table, string $column, array $ids, array $expected): void
    {
        $ids = array_values($ids);
        if ($ids !== []) {
            $this->plan($table, $column.' IN ('.implode(',', array_fill(0, count($ids), '?')).')', $ids, 257, $expected);
        } else {
            CheckoutException::require($expected === []);
        }
    }

    private function plan(string $table, string $where, array $bindings, int $limit, array $expected): void
    {
        $definition = $this->definition($table);
        $raw = $this->read($table, $where, $bindings, $limit);
        $interpreted = $raw;
        if ($table === 'audit_events') {
            foreach ($interpreted as &$row) {
                $row['context'] = json_decode($row['context'], true, 8, JSON_THROW_ON_ERROR);
            }
        }
        CheckoutException::require(self::strings($interpreted) === self::strings($expected), 'write_source_changed');
        $this->definitions[$table] = $definition;
        $this->plans[] = compact('table', 'where', 'bindings', 'limit', 'raw');
    }

    private function read(string $table, string $where, array $bindings, int $limit): array
    {
        $this->frame->prove(1);
        $statement = $this->frame->primary()->prepare('SELECT * FROM '.$this->qualified($table).' WHERE '.$where.' ORDER BY id LIMIT '.$limit);
        $statement->execute($bindings);
        $this->frame->provePdo();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $this->frame->prove(1);

        return $rows;
    }

    private function definition(string $table): array
    {
        CheckoutException::require(preg_match('/\A[a-z][a-z0-9_]*\z/D', $table) === 1, 'write_frame');
        $this->frame->prove(1);
        $primary = $this->frame->primary();
        if ($this->frame->driver() === 'mysql') {
            $row = $primary->query('SHOW CREATE TABLE '.$this->qualified($table))->fetch(PDO::FETCH_NUM);
            CheckoutException::require(is_array($row) && is_string($row[1] ?? null) && str_starts_with($row[1], 'CREATE TABLE '), 'temporary_shadow');
            $result = $row;
        } else {
            $statement = $primary->prepare('SELECT name, type, sql FROM main.sqlite_schema WHERE name = ? COLLATE NOCASE');
            $statement->execute([$table]);
            $result = $statement->fetchAll(PDO::FETCH_ASSOC);
            CheckoutException::require(count($result) === 1 && $result[0]['type'] === 'table', 'temporary_shadow');
            $temporary = $primary->prepare('SELECT name, tbl_name FROM temp.sqlite_schema WHERE name = ? COLLATE NOCASE OR tbl_name = ? COLLATE NOCASE');
            $temporary->execute([$table, $table]);
            CheckoutException::require($temporary->fetchAll(PDO::FETCH_ASSOC) === [], 'temporary_shadow');
        }
        $this->frame->prove(1);

        return $result;
    }

    private function qualified(string $table): string
    {
        return $this->frame->driver() === 'mysql' ? '`'.str_replace('`', '``', $this->frame->database()).'`.`'.$table.'`' : 'main.'.$table;
    }

    private static function strings(array $values): array
    {
        foreach ($values as &$value) {
            $value = is_array($value) ? self::strings($value) : ($value === null ? null : (string) $value);
        }
        ksort($values);

        return $values;
    }

    public function __serialize(): never
    {
        throw new \LogicException('Checkout raw plans are an internal server capability.');
    }

    public function __debugInfo(): array
    {
        return ['authority' => 'original_checkout_raw_plans'];
    }
}
