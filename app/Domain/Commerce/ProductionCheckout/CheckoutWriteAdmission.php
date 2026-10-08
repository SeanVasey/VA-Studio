<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Commerce\Policy\Models\ProductionTrackPolicyDraft;
use App\Domain\Commerce\ProductionPolicy\CapabilityHistory;
use App\Domain\Commerce\ProductionPolicy\ProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\SourceCommitment;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use PDO;
use ReflectionProperty;

/** Sealed server-authenticated NEW write admission. No caller snapshot or renewed identity proof at commit. */
final class CheckoutWriteAdmission implements CheckoutCommitAdmission
{
    private array $plans = [];

    private array $definitions = [];

    private array $actorAttributes;

    private function __construct(private readonly CheckoutCommandFrame $frame, private readonly FreshCheckoutPolicy $fresh, private readonly User $actor)
    {
        $attributes = (new ReflectionProperty(Model::class, 'attributes'))->getValue($actor);
        CheckoutException::require(is_array($attributes) && $actor::class === User::class && $actor->exists, 'write_frame');
        $this->actorAttributes = $attributes;
    }

    /** All extensible interpretation and private byte work completes before retaining the fixed raw plans. */
    public static function capture(Records $rows, ProductionCustomerAccess $access, ProductionCustomerPrincipal $principal,
        User $buyer, FreshCheckoutPolicy $fresh, string $kind, string $publicId): self
    {
        CheckoutException::require(in_array($kind, ['review', 'order'], true), 'write_frame');
        $frame = $rows->commandFrame();
        $frame->proveAnchor();
        $identity = $access->lock($principal, $buyer, $rows->current);
        $record = $rows->one($kind, $publicId);
        $retained = $kind === 'review' ? OrderEvidence::review($rows, $record) : OrderEvidence::order($rows, $record);
        $review = $kind === 'review' ? $retained : $retained['review'];
        Evidence::same($access->durableBinding($principal), $review['body']['buyer']);
        $current = CurrentPolicy::load($rows->current, $review['row']['candidate_id']);
        Evidence::same($current['binding'], $review['body']['candidate']);
        Evidence::same($current['context']->binding(), $review['body']['execution_context']);
        $at = CarbonImmutable::now('UTC');
        $selection = CurrentSelection::load($rows->current, $review['body']['request']['items'], $at);
        Evidence::same($selection, $review['body']['selection']);
        $basis = TaxExemptions::basis($rows, $review['body']['basis_public_id'], $current, $identity['durable_binding'], $selection, $at);
        CurrentSelection::proveBytes($selection);
        OrderEvidence::proveRetained($rows, $retained['raw']);
        TaxExemptions::proveRetained($rows, $basis);
        CurrentSelection::proveCurrent($rows->current, $selection, CarbonImmutable::now('UTC'));
        CurrentPolicy::proveCurrent($rows->current, $current);
        $access->proveCurrent($principal, $buyer, $rows->current, $identity);

        // Convert authenticated temporal bounds once, using the ORIGINAL command budget, never at commit.
        $until = [$review['body']['expires_at'], $basis['body']['request']['attestation']['effective_until']];
        if ($kind === 'order') {
            $until[] = $retained['body']['expires_at'];
        }
        $authority = Evidence::open($basis['authority'], 'production_checkout_exemption_authority');
        $until[] = $authority['policy']['effective_until'];
        foreach ($selection['graph']['offers'] as $offer) {
            if ((string) $offer['is_active'] !== '1') {
                continue;
            }
            $revision = self::one($selection['graph']['revisions'], $offer['current_revision_id']);
            $license = self::one($selection['graph']['licenses'], $revision['license_version_id']);
            if ($license['effective_until'] !== null) {
                $until[] = $license['effective_until'];
            }
        }
        $expires = min(array_map(fn (string $value): float => (float) CarbonImmutable::parse($value, 'UTC')->format('U.u'), $until));
        $remaining = $expires - (float) CarbonImmutable::now('UTC')->format('U.u');
        CheckoutException::require($remaining > 0, 'expired');
        $frame->capDeadline(hrtime(true) + (int) floor($remaining * 1_000_000_000));
        $admission = new self($frame, $fresh, $buyer);
        $admission->captureIdentity($identity);
        $admission->captureSelection($selection);
        $admission->captureHistory($current['raw']);
        foreach (['authority', 'basis', 'review', 'order', 'attempt'] as $tableKind) {
            if (isset($retained['raw'][$tableKind])) {
                $row = $retained['raw'][$tableKind];
                $admission->plan(CheckoutSchema::TABLES[$tableKind], 'id = ?', [$row['id']], 2, [$row]);
            }
        }
        $admission->plan(CheckoutSchema::TABLES[$kind], 'buyer_origin_id = ? AND request_key = ?',
            [$record['buyer_origin_id'], $record['request_key']], 2, [$record]);
        if ($kind === 'order') {
            $admission->plan(CheckoutSchema::TABLES['line'], 'order_id = ?', [$record['id']], 11, $retained['lines']);
            $admission->plan(CheckoutSchema::TABLES['attempt'], 'order_id = ?', [$record['id']], 2, [$retained['attempt']]);
        }
        // No resolver, decryptor, clock factory, renderer or identity verifier follows this original raw proof.
        $admission->proveCurrent();
        $admission->proveFresh();
        $frame->register($admission);

        return $admission;
    }

    public function belongsTo(CheckoutCommandFrame $frame): bool
    {
        return $this->frame === $frame;
    }

    public function proveCurrent(): void
    {
        $this->frame->prove(1);
        CheckoutException::require($this->actor::class === User::class && $this->actor->exists
            && (new ReflectionProperty(Model::class, 'attributes'))->getValue($this->actor) === $this->actorAttributes, 'write_source_changed');
        foreach ($this->definitions as $table => $definition) {
            CheckoutException::require($this->definition($table) === $definition, 'write_source_changed');
        }
        foreach ($this->plans as $plan) {
            CheckoutException::require($this->read($plan['table'], $plan['where'], $plan['bindings'], $plan['limit']) === $plan['raw'], 'write_source_changed');
        }
        $this->frame->prove(1);
    }

    public function proveFresh(): void
    {
        $this->fresh->prove();
    }

    private function captureIdentity(array $identity): void
    {
        $this->plan('users', 'id = ?', [$identity['user']['id']], 2, [$identity['user']]);
        $this->plan('customer_accounts', 'user_id = ?', [$identity['user']['id']], 2, [$identity['account']]);
        $this->plan('production_identity_origins', 'account_id = ?', [$identity['account']['id']], 2, [$identity['origin']]);
        $this->plan('production_identity_verifications', 'origin_id = ?', [$identity['origin']['id']], 129, $identity['verification_history']);
        $ids = array_column($identity['verification_history'], 'challenge_id');
        foreach ($ids as $id) {
            $this->plan('production_identity_challenges', 'id = ?', [$id], 2,
                $this->read('production_identity_challenges', 'id = ?', [$id], 2));
        }
    }

    private function captureSelection(array $selection): void
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

    private function captureHistory(array $history): void
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

    private static function one(array $rows, int $id): array
    {
        foreach ($rows as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        CheckoutException::require(false);
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
        throw new \LogicException('Checkout write admission is an internal server capability.');
    }

    public function __debugInfo(): array
    {
        return ['authority' => 'original_checkout_new_write_admission'];
    }
}
