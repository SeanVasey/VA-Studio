<?php

declare(strict_types=1);

namespace App\Domain\Migration\History;

use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Append-only ledger of which single system may sell exclusive rights during the BeatStars cutover (runbook §7,
 * §10). At any moment at most one authority sells: `beatstars`, `vasey_store`, or nobody while `frozen`.
 *
 * Allowed transitions, each one immutable event under the next sequence number:
 *
 * - `declare`  (empty ledger → beatstars): records that BeatStars is the current authority.
 * - `freeze`   (beatstars → frozen): BeatStars exclusive sales are stopped first; nobody may sell.
 * - `handover` (frozen → vasey_store): only after a complete export taken at or after the freeze is archived and
 *   reconciled, and its delta from the previous archived export has zero unexplained items.
 * - `revert`   (vasey_store → frozen): rollback starts by stopping the store; sold exclusives stay sold.
 * - `unfreeze` (frozen → beatstars): only for a freeze that followed BeatStars. After a store period, reopening
 *   BeatStars would first need proof it cannot resell what the store sold, so it is refused here.
 *
 * Every other request (a second declaration, a handover before the freeze, a second handover, two authorities) is
 * refused. Concurrent transitions serialize on the ledger head; the unique sequence number refuses whichever loses.
 */
final class ExclusivityAuthority
{
    public const TABLE = 'legacy_exclusivity_authority_events';

    public const TRANSITION_ACTION = 'legacy_history.exclusivity_authority';

    public const TRANSITIONS = ['declare', 'freeze', 'handover', 'unfreeze', 'revert'];

    /** The latest event, or null before any declaration. */
    public function current(): ?object
    {
        return DB::table(self::TABLE)->orderByDesc('sequence')->first();
    }

    /** True only while the store is the single exclusive authority. Unknown or frozen means no. */
    public function storeMaySellExclusives(): bool
    {
        return $this->current()?->authority === 'vasey_store';
    }

    /**
     * Evaluate (and with `$write`, record) one transition.
     *
     * @param  array{baseline: array, later: array, dispositions: ?string}|null  $handover  the two normalized snapshots for `handover`
     * @return array{transition: string, from: ?string, to: string, sequence: int, outcome: string, delta_sha256: ?string}
     */
    public function transition(string $transition, User $actor, string $reference, ?int $attestationAuditId, bool $write, ?array $handover = null): array
    {
        if (! in_array($transition, self::TRANSITIONS, true)) {
            throw new LegacyArchiveRefusal('transition_unknown');
        }
        if (($transition === 'handover') !== ($handover !== null)) {
            throw new LegacyArchiveRefusal('handover_evidence');
        }
        $plan = function (bool $lock) use ($transition, $actor, $handover): array {
            if (! Gate::forUser($actor)->allows('administer-catalog', $lock)) {
                throw new LegacyArchiveRefusal('actor_refused');
            }
            $head = DB::table(self::TABLE)->orderByDesc('sequence')->limit(1)->when($lock, fn ($query) => $query->lockForUpdate())->first();
            $previous = $head === null ? null : DB::table(self::TABLE)->where('sequence', $head->sequence - 1)->first();

            return $this->plan($transition, $head, $previous, $handover);
        };
        if (! $write) {
            return $plan(false) + ['outcome' => 'not_written'];
        }
        try {
            return DB::transaction(function () use ($plan, $transition, $actor, $reference, $attestationAuditId): array {
                $result = $plan(true);
                $id = DB::table(self::TABLE)->insertGetId(['sequence' => $result['sequence'], 'authority' => $result['to'],
                    'transition' => $transition, 'reference_hmac' => hash_hmac('sha256', 'vasey-exclusivity-authority-v1|'.$reference, (string) config('app.key')),
                    'baseline_snapshot_sha256' => $result['baseline_snapshot_sha256'], 'later_snapshot_sha256' => $result['later_snapshot_sha256'],
                    'delta_sha256' => $result['delta_sha256'], 'actor_id' => $actor->id, 'attestation_audit_id' => $attestationAuditId,
                    'created_at' => CarbonImmutable::now()->utc()->startOfSecond()]);
                AuditEvent::create(['actor_id' => $actor->id, 'action' => self::TRANSITION_ACTION, 'subject_type' => 'legacy_exclusivity_authority_event',
                    'subject_id' => $id, 'context' => ['transition' => $transition, 'from' => $result['from'], 'to' => $result['to'],
                        'sequence' => $result['sequence'], 'delta_sha256' => $result['delta_sha256'], 'attestation_audit_id' => $attestationAuditId]]);

                return $result + ['outcome' => 'recorded'];
            });
        } catch (QueryException $error) {
            // A concurrent transition from the same head committed first (unique sequence: 23000) or InnoDB chose this
            // one as the deadlock victim (40001). Nothing of this attempt was written; the operator re-reads the ledger.
            if (in_array((string) $error->getCode(), ['23000', '40001'], true)) {
                throw new LegacyArchiveRefusal('authority_changed_concurrently');
            }
            throw $error;
        }
    }

    private function plan(string $transition, ?object $head, ?object $previous, ?array $handover): array
    {
        $from = $head?->authority;
        $to = match ($transition) {
            'declare' => $head === null ? 'beatstars' : throw new LegacyArchiveRefusal('authority_already_declared'),
            'freeze' => $from === 'beatstars' ? 'frozen' : throw new LegacyArchiveRefusal($from === null ? 'authority_not_declared' : 'freeze_requires_beatstars'),
            'handover' => $from === 'frozen' ? 'vasey_store' : throw new LegacyArchiveRefusal(match ($from) {
                null => 'authority_not_declared', 'vasey_store' => 'second_authority_refused', default => 'handover_requires_freeze',
            }),
            'revert' => $from === 'vasey_store' ? 'frozen' : throw new LegacyArchiveRefusal('revert_requires_store'),
            'unfreeze' => $from === 'frozen' && $previous?->authority === 'beatstars' && $head->transition === 'freeze'
                ? 'beatstars' : throw new LegacyArchiveRefusal($from === 'frozen' ? 'source_resale_unproven' : 'unfreeze_requires_freeze'),
        };
        $evidence = ['baseline_snapshot_sha256' => null, 'later_snapshot_sha256' => null, 'delta_sha256' => null];
        if ($transition === 'handover') {
            $evidence = $this->handoverEvidence($head, $handover);
        }

        return ['transition' => $transition, 'from' => $from, 'to' => $to, 'sequence' => $head === null ? 1 : (int) $head->sequence + 1] + $evidence;
    }

    /**
     * The store may take over only when nothing sold on BeatStars can be missing: the later export was taken at or
     * after the freeze, it and the previous archived export are fully archived and reconciled, and their delta has
     * zero unexplained items.
     */
    private function handoverEvidence(object $freeze, array $handover): array
    {
        $archive = new LegacyHistoryArchive;
        $baseline = $handover['baseline'];
        $later = $handover['later'];
        $frozenAt = CarbonImmutable::parse($freeze->created_at, 'UTC')->utc()->format('Y-m-d\TH:i:s\Z');
        if ($later['source_as_of'] < $frozenAt) {
            throw new LegacyArchiveRefusal('later_export_precedes_freeze');
        }
        $hashes = [];
        foreach (['baseline' => $baseline, 'later' => $later] as $side => $snapshot) {
            $hash = CanonicalJson::hash($snapshot);
            if (! DB::table('legacy_history_batches')->where('snapshot_sha256', $hash)->exists()) {
                throw new LegacyArchiveRefusal($side.'_not_archived');
            }
            if ($archive->reconcile($snapshot, $hash)['result'] !== 'reconciled') {
                throw new LegacyArchiveRefusal($side.'_not_reconciled');
            }
            $hashes[$side] = $hash;
        }
        // The baseline must be the latest archived export of this source before the later one: no interval is skipped.
        $latestBefore = DB::table('legacy_history_batches')->where('source_system', $later['source_system'])
            ->where('source_as_of', '<', $later['source_as_of'])->max('source_as_of');
        if ($latestBefore !== $baseline['source_as_of']) {
            throw new LegacyArchiveRefusal('baseline_not_latest');
        }
        $delta = (new HistoryDelta)->compare($baseline, $later, $handover['dispositions'] ?? null);
        if ($delta['result'] !== 'reconciled') {
            throw new LegacyArchiveRefusal('delta_unreconciled');
        }

        return ['baseline_snapshot_sha256' => $hashes['baseline'], 'later_snapshot_sha256' => $hashes['later'], 'delta_sha256' => $delta['delta_sha256']];
    }
}
