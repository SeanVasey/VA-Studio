<?php

declare(strict_types=1);

namespace App\Domain\Migration\History;

use App\Domain\Migration\CatalogOnboarding\NormalizedSourceSnapshot;
use App\Domain\Staff\StaffWriteAuthority;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Append-only links from archived legacy order lines to store tracks, through `catalog_import_mappings`.
 *
 * A candidate for a line is every catalog import mapping whose retained source record carries the line's
 * `track_source_id` as its source id, in any catalog source system. Nothing is guessed:
 *
 * - `linked`: a link row exists for the line version.
 * - `ambiguous`: more than one candidate. It stays ambiguous until staff resolve it.
 * - `unlinked`: no link and at most one candidate. Exactly one candidate in the line's own source system is the
 *   catalog importer's own identity (same system, same id) and is the only case `link()` records by itself; one
 *   candidate in another source system proves no identity and also waits for staff.
 *
 * Kit, service and membership lines (ProductFamilyAdapter) are not track lines and are never linked.
 *
 * A link is evidence about history only. Nothing here creates or changes an order, grant, entitlement, customer
 * account, consent, offer or download, and nothing reads a link to grant anything.
 */
final class LegacyTrackLinks
{
    public const PURPOSE = 'vasey-legacy-history-track-links-v1';

    public const TABLE = 'legacy_history_track_links';

    public const EXACT = 'exact_source_identity';

    public const STAFF = 'staff_resolution';

    public const SEGMENT_ACTION = 'legacy_history.track_link_segment';

    public const RESOLVE_ACTION = 'legacy_history.track_link_resolved';

    /** The console attestations one link segment and one staff resolution consume (`StaffWriteAuthority`). */
    public const ATTESTATION_ACTION = 'legacy-history.link-tracks';

    public const RESOLVE_ATTESTATION_ACTION = 'legacy-history.resolve-track-link';

    public static function attestationScope(string $snapshotSha256): string
    {
        return 'legacy-track-links:'.$snapshotSha256;
    }

    public static function resolveScope(string $lineRecordKey, int $mappingId): string
    {
        return 'legacy-track-link:'.$lineRecordKey.':'.$mappingId;
    }

    /**
     * Read-only: every track line of the snapshot as linked, unlinked or ambiguous, with counts per state.
     *
     * @return array{purpose: string, snapshot_sha256: string, lines: array<string, int>, states: array<string, int>,
     *     linked_by: array<string, int>, unlinked: array<string, int>, line_states: array<string, string>,
     *     needs_resolution: array<string, list<array{mapping_id: int, track_id: int, same_source_system: bool}>>,
     *     plan: array{create: int}, report_sha256: string}
     */
    public function report(array $snapshot, string $snapshotSha256): array
    {
        $survey = $this->survey($snapshot);
        $states = ['linked' => 0, 'unlinked' => 0, 'ambiguous' => 0];
        $linkedBy = [self::EXACT => 0, self::STAFF => 0];
        $unlinked = ['no_candidate' => 0, 'other_source_system_candidate' => 0, 'exact_candidate_pending' => 0];
        $lineStates = [];
        $resolution = [];
        $create = 0;
        foreach ($survey['track'] as $key => $line) {
            $lineStates[$key] = $line['state'];
            $states[$line['state']]++;
            if ($line['state'] === 'linked') {
                $linkedBy[$line['link']->method]++;

                continue;
            }
            if ($line['state'] === 'unlinked') {
                $unlinked[$line['reason']]++;
            }
            if ($line['linkable']) {
                $create++;
            } elseif ($line['candidates'] !== []) {
                $resolution[$key] = array_map(static fn (array $candidate): array => ['mapping_id' => $candidate['mapping_id'],
                    'track_id' => $candidate['track_id'], 'same_source_system' => $candidate['same_source_system']], $line['candidates']);
            }
        }
        $report = ['purpose' => self::PURPOSE, 'snapshot_sha256' => $snapshotSha256, 'source_system' => $snapshot['source_system'],
            'lines' => ['track' => count($survey['track']), 'non_track' => $survey['non_track'], 'not_archived' => $survey['not_archived']],
            'states' => $states, 'linked_by' => $linkedBy, 'unlinked' => $unlinked, 'line_states' => $lineStates,
            'needs_resolution' => $resolution, 'plan' => ['create' => $create]];
        $report['report_sha256'] = CanonicalJson::hash($report);

        return $report;
    }

    /**
     * Record at most `$limit` links for the snapshot's archived track lines that have exactly one candidate, in the
     * line's own source system. Idempotent and restartable: a linked line is skipped, an interrupted segment rolls
     * back, and ambiguous or unmatched lines are left exactly as they are.
     *
     * @param  callable(int): void|null  $afterLink  called after each inserted link (interruption tests)
     * @return array{processed: int, pending: int, complete: bool, states: array<string, int>}
     */
    public function link(array $snapshot, string $snapshotSha256, User $actor, StaffWriteAuthority $attestation, int $limit, ?callable $afterLink = null): array
    {
        (new LegacyArchivePolicy)->admit();
        if ($limit < 1 || $limit > 1000) {
            throw new LegacyArchiveRefusal('limit_invalid');
        }
        if (! hash_equals(CanonicalJson::hash($snapshot), $snapshotSha256)) {
            throw new LegacyArchiveRefusal('snapshot_mismatch');
        }
        try {
            return DB::transaction(function () use ($snapshot, $snapshotSha256, $actor, $attestation, $limit, $afterLink): array {
                $current = LegacyHistoryArchive::authorize($actor, $attestation, self::ATTESTATION_ACTION, self::attestationScope($snapshotSha256), $snapshotSha256);
                // Segments of one snapshot serialize on its batch row; links follow a complete archive, never a partial one.
                $batch = DB::table('legacy_history_batches')->where('snapshot_sha256', $snapshotSha256)->lockForUpdate()->first();
                if ($batch === null || (new LegacyHistoryArchive)->plan($snapshot, $snapshotSha256)['pending'] !== 0) {
                    throw new LegacyArchiveRefusal('import_incomplete');
                }
                $processed = 0;
                $pending = 0;
                foreach ($this->survey($snapshot)['track'] as $line) {
                    if (! $line['linkable']) {
                        continue;
                    }
                    if ($processed >= $limit) {
                        $pending++;

                        continue;
                    }
                    $this->insert($line, $line['candidates'][0], self::EXACT, null, $current->id, $attestation->auditEventId);
                    $processed++;
                    if ($afterLink !== null) {
                        $afterLink($processed);
                    }
                }
                $states = $this->report($snapshot, $snapshotSha256)['states'];
                AuditEvent::create(['actor_id' => $current->id, 'action' => self::SEGMENT_ACTION, 'subject_type' => 'legacy_history_batch',
                    'subject_id' => (int) $batch->id, 'context' => ['snapshot_sha256' => $snapshotSha256, 'processed' => $processed, 'pending' => $pending,
                        'states' => $states, 'attestation_audit_id' => $attestation->auditEventId, 'entitlements_created' => 0]]);

                return ['processed' => $processed, 'pending' => $pending, 'complete' => $pending === 0, 'states' => $states];
            });
        } catch (QueryException $error) {
            if (in_array((string) $error->getCode(), ['23000', '40001'], true)) {
                throw new LegacyArchiveRefusal('track_links_changed_concurrently');
            }
            throw $error;
        }
    }

    /**
     * Read-only: the current version of one line, its state and its candidates.
     *
     * @return array{line_record_key: string, line_id: int, state: string, family: ?string,
     *     candidates: list<array{mapping_id: int, track_id: int, same_source_system: bool}>, linked_mapping_id: ?int}
     */
    public function inspect(string $lineRecordKey): array
    {
        $line = $this->currentLine($lineRecordKey, $this->candidates());

        return ['line_record_key' => $lineRecordKey, 'line_id' => (int) $line['row']->id, 'state' => $line['state'], 'family' => $line['family'],
            'candidates' => array_map(static fn (array $candidate): array => ['mapping_id' => $candidate['mapping_id'], 'track_id' => $candidate['track_id'],
                'same_source_system' => $candidate['same_source_system']], $line['candidates']),
            'linked_mapping_id' => $line['link'] === null ? null : (int) $line['link']->mapping_id];
    }

    /**
     * Staff resolution of one line the tool will not link by itself (ambiguous, or a single candidate from another
     * source system): the staff member names one of the line's candidates and the private evidence for the choice.
     * One immutable link row and one audit event; nothing else is written.
     *
     * @return array{line_record_key: string, line_id: int, mapping_id: int, track_id: int, outcome: string}
     */
    public function resolve(string $lineRecordKey, int $mappingId, User $actor, string $reference, StaffWriteAuthority $attestation): array
    {
        (new LegacyArchivePolicy)->admit();
        if ($mappingId < 1 || $reference === '' || strlen($reference) > 1000) {
            throw new LegacyArchiveRefusal('resolution_invalid');
        }
        try {
            return DB::transaction(function () use ($lineRecordKey, $mappingId, $actor, $reference, $attestation): array {
                $current = LegacyHistoryArchive::authorize($actor, $attestation, self::RESOLVE_ATTESTATION_ACTION,
                    self::resolveScope($lineRecordKey, $mappingId), $reference);
                $line = $this->currentLine($lineRecordKey, $this->candidates());
                $result = ['line_record_key' => $lineRecordKey, 'line_id' => (int) $line['row']->id, 'mapping_id' => $mappingId];
                if ($line['family'] !== null) {
                    throw new LegacyArchiveRefusal('not_a_track_line');
                }
                $existing = DB::table(self::TABLE)->where('line_id', $line['row']->id)->lockForUpdate()->first();
                if ($existing !== null) {
                    if ((int) $existing->mapping_id === $mappingId) {
                        return $result + ['track_id' => (int) $existing->track_id, 'outcome' => 'unchanged'];
                    }
                    throw new LegacyArchiveRefusal('already_linked');
                }
                if ($line['candidates'] === []) {
                    throw new LegacyArchiveRefusal('no_candidate');
                }
                if ($line['linkable']) {
                    // The unambiguous case belongs to `link()`; a staff choice is only for a line that needs one.
                    throw new LegacyArchiveRefusal('resolution_not_required');
                }
                $chosen = array_values(array_filter($line['candidates'], static fn (array $candidate): bool => $candidate['mapping_id'] === $mappingId));
                if (count($chosen) !== 1) {
                    throw new LegacyArchiveRefusal('candidate_refused');
                }
                $id = $this->insert($line, $chosen[0], self::STAFF,
                    hash_hmac('sha256', 'vasey-legacy-track-link-v1|'.$reference, (string) config('app.key')), $current->id, $attestation->auditEventId);
                AuditEvent::create(['actor_id' => $current->id, 'action' => self::RESOLVE_ACTION, 'subject_type' => 'legacy_history_track_link',
                    'subject_id' => $id, 'context' => ['line_record_key' => $lineRecordKey, 'line_record_sha256' => $line['row']->record_sha256,
                        'mapping_id' => $mappingId, 'track_id' => $chosen[0]['track_id'], 'candidates' => count($line['candidates']),
                        'candidates_sha256' => self::candidatesHash($line['candidates']), 'attestation_audit_id' => $attestation->auditEventId,
                        'entitlement_created' => false]]);

                return $result + ['track_id' => $chosen[0]['track_id'], 'outcome' => 'recorded'];
            });
        } catch (QueryException $error) {
            if (in_array((string) $error->getCode(), ['23000', '40001'], true)) {
                throw new LegacyArchiveRefusal('track_links_changed_concurrently');
            }
            throw $error;
        }
    }

    /** @param  list<array{mapping_id: int}>  $candidates */
    public static function candidatesHash(array $candidates): string
    {
        $ids = array_column($candidates, 'mapping_id');
        sort($ids, SORT_NUMERIC);

        return CanonicalJson::hash(['purpose' => 'vasey-legacy-track-link-candidates-v1', 'mapping_ids' => $ids]);
    }

    /**
     * Every catalog import mapping by the source id of its retained source record. A mapping whose evidence cannot
     * be read and proved against its record key could hide a candidate, so it refuses the whole read.
     *
     * @return array<string, list<array{mapping_id: int, track_id: int, source_system: string}>>
     */
    private function candidates(): array
    {
        $index = [];
        foreach (DB::table('catalog_import_mappings')->orderBy('id')->get(['id', 'record_key', 'track_id', 'evidence_ciphertext']) as $row) {
            try {
                $evidence = json_decode(Crypt::decryptString($row->evidence_ciphertext), true, 32, JSON_THROW_ON_ERROR);
                $system = $evidence['source_system'] ?? null;
                $sourceId = $evidence['record']['source_id'] ?? null;
                $proved = is_string($system) && is_string($sourceId)
                    && hash_equals((string) $row->record_key, (new NormalizedSourceSnapshot)->recordKey($system, $sourceId));
            } catch (Throwable) {
                $proved = false;
            }
            if (! $proved) {
                throw new LegacyArchiveRefusal('catalog_mapping_integrity');
            }
            $index[$sourceId][] = ['mapping_id' => (int) $row->id, 'track_id' => (int) $row->track_id, 'source_system' => $system];
        }

        return $index;
    }

    /**
     * @return array{track: array<string, array>, non_track: int, not_archived: int} track lines by record key, sorted
     */
    private function survey(array $snapshot): array
    {
        LegacyHistoryArchive::tablesForSnapshot($snapshot);
        $candidates = $this->candidates();
        $rows = [];
        foreach (array_chunk(array_column($snapshot['lines'], 'record_key'), 500) as $keys) {
            foreach (DB::table('legacy_history_lines')->whereIn('record_key', $keys)->get() as $row) {
                $rows[$row->record_key."\n".$row->record_sha256] = $row;
            }
        }
        $track = [];
        $nonTrack = 0;
        $notArchived = 0;
        foreach ($snapshot['lines'] as $record) {
            if (ProductFamilyAdapter::familyOf($record['track_source_id']) !== null) {
                $nonTrack++;

                continue;
            }
            $row = $rows[$record['record_key']."\n".$record['record_sha256']] ?? null;
            if ($row === null) {
                $notArchived++;
            }
            $track[$record['record_key']] = $this->state($row, $record['track_source_id'], $snapshot['source_system'], $candidates);
        }
        ksort($track, SORT_STRING);

        return ['track' => $track, 'non_track' => $nonTrack, 'not_archived' => $notArchived];
    }

    /** The line version the newest fully archived export of its source system contains. */
    private function currentLine(string $lineRecordKey, array $candidates): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $lineRecordKey) !== 1) {
            throw new LegacyArchiveRefusal('line_invalid');
        }
        $any = DB::table('legacy_history_lines')->where('record_key', $lineRecordKey)->orderByDesc('id')->first();
        if ($any === null) {
            throw new LegacyArchiveRefusal('line_not_archived');
        }
        $sourceSystem = (string) DB::table('legacy_history_batches')->where('id', $any->batch_id)->value('source_system');
        $batch = LegacyHistoryArchive::currentBatch($sourceSystem);
        $members = $batch === null ? null : LegacyHistoryArchive::completeMembers($batch);
        if ($members === null) {
            throw new LegacyArchiveRefusal('latest_export_not_archived');
        }
        $sha = $members['lines'][$lineRecordKey] ?? null;
        $row = $sha === null ? null : DB::table('legacy_history_lines')->where('record_key', $lineRecordKey)->where('record_sha256', $sha)->first();
        if ($row === null) {
            throw new LegacyArchiveRefusal('line_not_current');
        }

        return $this->state($row, (string) $row->track_source_id, $sourceSystem, $candidates);
    }

    /** One line's state from its archived row (null when not archived yet), its link and its candidates. */
    private function state(?object $row, string $trackSourceId, string $sourceSystem, array $candidates): array
    {
        $link = null;
        if ($row !== null) {
            $record = LegacyHistoryArchive::decrypt($row);
            if ($record === null || $record['track_source_id'] !== $row->track_source_id || $row->track_source_id !== $trackSourceId) {
                throw new LegacyArchiveRefusal('archive_integrity');
            }
            $link = DB::table(self::TABLE)->where('line_id', $row->id)->first();
        }
        $family = ProductFamilyAdapter::familyOf($trackSourceId);
        $found = $family === null ? array_map(static fn (array $candidate): array => $candidate + ['same_source_system' => $candidate['source_system'] === $sourceSystem],
            $candidates[$trackSourceId] ?? []) : [];
        if ($link !== null && ($link->line_record_key !== $row->record_key || $link->line_record_sha256 !== $row->record_sha256
            || ! in_array([(int) $link->mapping_id, (int) $link->track_id], array_map(static fn (array $candidate): array => [$candidate['mapping_id'], $candidate['track_id']], $found), true))) {
            // A stored link that no longer names one of the line's own candidates is not evidence of anything.
            throw new LegacyArchiveRefusal('track_link_integrity');
        }
        $exact = count($found) === 1 && $found[0]['same_source_system'];
        $state = match (true) {
            $link !== null => 'linked',
            count($found) > 1 => 'ambiguous',
            default => 'unlinked',
        };

        return ['row' => $row, 'link' => $link, 'family' => $family, 'state' => $state, 'candidates' => $found,
            'reason' => $state !== 'unlinked' ? null : ($found === [] ? 'no_candidate' : ($exact ? 'exact_candidate_pending' : 'other_source_system_candidate')),
            'linkable' => $link === null && $row !== null && $exact];
    }

    private function insert(array $line, array $candidate, string $method, ?string $referenceHmac, int $actorId, ?int $attestationAuditId): int
    {
        return (int) DB::table(self::TABLE)->insertGetId(['line_id' => $line['row']->id, 'line_record_key' => $line['row']->record_key,
            'line_record_sha256' => $line['row']->record_sha256, 'mapping_id' => $candidate['mapping_id'], 'track_id' => $candidate['track_id'],
            'method' => $method, 'candidates_sha256' => self::candidatesHash($line['candidates']), 'reference_hmac' => $referenceHmac,
            'actor_id' => $actorId, 'attestation_audit_id' => $attestationAuditId, 'created_at' => now()]);
    }
}
