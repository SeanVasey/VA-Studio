<?php

declare(strict_types=1);

namespace App\Domain\Migration\History;

use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Restartable, idempotent archive of one normalized history snapshot into the immutable `legacy_history_*` tables,
 * plus the read-only plan and reconciliation over what is stored.
 *
 * A record's identity is its (record key, record hash): an identical record already archived by any batch is
 * skipped, and a changed record (a later snapshot) is a new immutable version beside the old one. Each segment
 * runs in one transaction under the batch row lock and inserts at most `limit` records, so an interrupted run rolls
 * its segment back and the next run resumes where the last committed segment ended. Original contract bytes are
 * stored unchanged on the private disk under their SHA-256 and re-verified; nothing is rendered or regenerated.
 *
 * Writes go only to the `legacy_history_*` tables, the private contract store and the audit log. Nothing here
 * creates or changes a customer account, order, grant, entitlement, consent, offer or download.
 */
final class LegacyHistoryArchive
{
    public const DISK = 'local';

    public const CONTRACT_PREFIX = 'legacy-history/contracts';

    public const SEGMENT_ACTION = 'legacy_history.import_segment';

    /** cohort => table, in insertion order (a line follows its order; a contract follows its line). */
    public const TABLES = ['customers' => 'legacy_history_customers', 'orders' => 'legacy_history_orders',
        'lines' => 'legacy_history_lines', 'contracts' => 'legacy_history_contracts'];

    /** Read and normalize one source package. Pure: no database, no write. */
    public function load(string $directory): array
    {
        $source = (new HistorySourceFiles)->read($directory);

        return ['source' => $source, 'result' => (new HistoryNormalizer)->normalize($source)];
    }

    /** Read-only plan: how many records each cohort would create or skip, and whether the batch already exists. */
    public function plan(array $snapshot, string $snapshotSha256): array
    {
        $cohorts = [];
        $pending = 0;
        foreach (self::TABLES as $cohort => $table) {
            $present = $this->present($table, $snapshot[$cohort]);
            $create = count(array_filter($snapshot[$cohort], static fn (array $record): bool => ! isset($present[$record['record_key']."\n".$record['record_sha256']])));
            $cohorts[$cohort] = ['create' => $create, 'skip' => count($snapshot[$cohort]) - $create];
            $pending += $create;
        }

        return ['snapshot_sha256' => $snapshotSha256,
            'batch' => DB::table('legacy_history_batches')->where('snapshot_sha256', $snapshotSha256)->exists() ? 'present' : 'absent',
            'cohorts' => $cohorts, 'pending' => $pending, 'total' => $this->total($snapshot)];
    }

    /**
     * Archive at most `$limit` pending records of the snapshot in one transaction.
     *
     * @param  array<string, string>  $contractFiles  original contract bytes by source file name
     * @param  callable(int): void|null  $afterRecord  called after each inserted record (interruption tests)
     * @return array{batch_id: int, processed: int, pending: int, total: int, complete: bool}
     */
    public function import(array $snapshot, string $snapshotSha256, array $contractFiles, User $actor, ?int $attestationAuditId,
        int $limit, ?callable $afterRecord = null): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new LegacyArchiveRefusal('limit_invalid');
        }
        if (! hash_equals(CanonicalJson::hash($snapshot), $snapshotSha256)) {
            throw new LegacyArchiveRefusal('snapshot_mismatch');
        }

        return DB::transaction(function () use ($snapshot, $snapshotSha256, $contractFiles, $actor, $attestationAuditId, $limit, $afterRecord): array {
            // Current, locked staff authority: a revoked operator cannot continue an import it started.
            if (! Gate::forUser($actor)->allows('administer-catalog', true)) {
                throw new LegacyArchiveRefusal('actor_refused');
            }
            $batch = $this->batch($snapshot, $snapshotSha256, $actor, $attestationAuditId);
            $processed = 0;
            $pendingTotal = 0;
            foreach (self::TABLES as $cohort => $table) {
                $present = $this->present($table, $snapshot[$cohort]);
                foreach ($snapshot[$cohort] as $record) {
                    if (isset($present[$record['record_key']."\n".$record['record_sha256']])) {
                        continue;
                    }
                    if ($processed >= $limit) {
                        $pendingTotal++;

                        continue;
                    }
                    $this->insert($cohort, $record, $batch, $contractFiles);
                    $processed++;
                    if ($afterRecord !== null) {
                        $afterRecord($processed);
                    }
                }
            }
            AuditEvent::create(['actor_id' => $actor->id, 'action' => self::SEGMENT_ACTION, 'subject_type' => 'legacy_history_batch',
                'subject_id' => $batch, 'context' => ['snapshot_sha256' => $snapshotSha256, 'processed' => $processed,
                    'pending' => $pendingTotal, 'attestation_audit_id' => $attestationAuditId]]);

            return ['batch_id' => $batch, 'processed' => $processed, 'pending' => $pendingTotal, 'total' => $this->total($snapshot),
                'complete' => $pendingTotal === 0];
        });
    }

    /** Read-only reconciliation of the snapshot against the archive (counts, hashes, originals, money, integrity). */
    public function reconcile(array $snapshot, string $snapshotSha256): array
    {
        return (new HistoryReconciliation)->reconcile($snapshot, $snapshotSha256, $this->observe($snapshot, $snapshotSha256));
    }

    /** What the archive holds for this snapshot, in the shape HistoryReconciliation expects. Read-only. */
    public function observe(array $snapshot, string $snapshotSha256): array
    {
        $batchId = DB::table('legacy_history_batches')->where('snapshot_sha256', $snapshotSha256)->value('id');
        $records = [];
        $unexpected = [];
        $integrity = [];
        foreach (self::TABLES as $cohort => $table) {
            $expected = [];
            foreach ($snapshot[$cohort] as $record) {
                $expected[$record['record_key']."\n".$record['record_sha256']] = true;
            }
            $records[$cohort] = [];
            $rows = [];
            foreach (array_chunk(array_column($snapshot[$cohort], 'record_key'), 500) as $keys) {
                foreach (DB::table($table)->whereIn('record_key', $keys)->orderBy('id')->get() as $row) {
                    $rows[$row->id] = $row;
                }
            }
            if ($batchId !== null) {
                foreach (DB::table($table)->where('batch_id', $batchId)->orderBy('id')->get() as $row) {
                    $rows[$row->id] = $row;
                }
            }
            ksort($rows);
            foreach ($rows as $row) {
                if (! $this->intact($row)) {
                    $integrity[] = ['cohort' => $cohort, 'record_key' => $row->record_key, 'code' => 'stored_record_altered'];

                    continue;
                }
                $pair = $row->record_key."\n".$row->record_sha256;
                if (isset($expected[$pair])) {
                    $records[$cohort][$row->record_key] = $this->projection($cohort, $row);
                } elseif ($batchId !== null && (int) $row->batch_id === (int) $batchId) {
                    $unexpected[$cohort][] = $row->record_key;
                }
            }
        }

        return ['records' => $records, 'unexpected' => $unexpected, 'integrity_failures' => $integrity];
    }

    /** Storage path of one original, addressed by its SHA-256 only (no source name, no customer detail). */
    public static function contractPath(string $sha256): string
    {
        return self::CONTRACT_PREFIX.'/'.substr($sha256, 0, 2).'/'.$sha256;
    }

    /** Keyed hash for exact-address matching later; the address itself is only in the encrypted record. */
    public static function emailHmac(string $normalized): string
    {
        return hash_hmac('sha256', 'vasey-legacy-history-email-v1|'.$normalized, (string) config('app.key'));
    }

    /** Re-read one stored original and compare it to its recorded hash and size. */
    public static function artifactState(string $path, string $sha256, int $bytes): string
    {
        $disk = Storage::disk(self::DISK);
        if (! $disk->exists($path)) {
            return 'missing';
        }
        $content = $disk->get($path);

        return is_string($content) && strlen($content) === $bytes && hash_equals($sha256, hash('sha256', $content)) ? 'verified' : 'hash_mismatch';
    }

    /** Decrypt one stored row and prove its record against the row's key and hash columns. */
    public static function decrypt(object $row): ?array
    {
        try {
            $record = json_decode(Crypt::decryptString($row->record_ciphertext), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (! is_array($record) || ($record['record_key'] ?? null) !== $row->record_key || ($record['record_sha256'] ?? null) !== $row->record_sha256
            || HistoryNormalizer::recordHash($record) !== $row->record_sha256) {
            return null;
        }

        return $record;
    }

    private function intact(object $row): bool
    {
        $record = self::decrypt($row);
        if ($record === null) {
            return false;
        }

        return match (true) {
            isset($row->marketing_consent) => $row->marketing_consent === HistoryNormalizer::CONSENT && $record['marketing_consent'] === HistoryNormalizer::CONSENT
                && $row->source_customer_id === $record['source_customer_id'],
            isset($row->total_minor) && isset($row->customer_record_key) => (int) $row->total_minor === $record['total_minor']
                && (int) $row->refunded_minor === $record['refunded_minor'] && $row->status === $record['status'] && $row->currency === $record['currency'],
            isset($row->amount_minor) => (int) $row->amount_minor === $record['amount_minor'] && (int) $row->refunded_minor === $record['refunded_minor']
                && (bool) $row->exclusive === $record['exclusive'] && $row->classification === HistoryNormalizer::CLASSIFICATION,
            isset($row->original_sha256) => $row->original_sha256 === $record['sha256'] && (int) $row->original_bytes === $record['bytes']
                && $row->storage_path === self::contractPath($record['sha256']),
            default => false,
        };
    }

    private function projection(string $cohort, object $row): array
    {
        $projection = ['record_sha256' => $row->record_sha256];
        if ($cohort === 'orders') {
            $projection += ['total_minor' => (int) $row->total_minor, 'refunded_minor' => (int) $row->refunded_minor, 'currency' => $row->currency];
        }
        if ($cohort === 'contracts') {
            $projection['artifact'] = self::artifactState($row->storage_path, $row->original_sha256, (int) $row->original_bytes);
        }

        return $projection;
    }

    /** @return array<string, true> "record_key\nrecord_sha256" pairs already archived (by any batch) */
    private function present(string $table, array $records): array
    {
        $present = [];
        foreach (array_chunk(array_column($records, 'record_key'), 500) as $keys) {
            foreach (DB::table($table)->whereIn('record_key', $keys)->get(['record_key', 'record_sha256']) as $row) {
                $present[$row->record_key."\n".$row->record_sha256] = true;
            }
        }

        return $present;
    }

    private function total(array $snapshot): int
    {
        return array_sum(array_map(static fn (string $cohort): int => count($snapshot[$cohort]), array_keys(self::TABLES)));
    }

    /** The batch row is created by the first segment and locked by every segment; its identity can never change. */
    private function batch(array $snapshot, string $snapshotSha256, User $actor, ?int $attestationAuditId): int
    {
        $identity = ['snapshot_id' => $snapshot['snapshot_id'], 'source_system' => $snapshot['source_system'],
            'acquisition_method' => $snapshot['acquisition_method'], 'acquired_at' => $snapshot['acquired_at'],
            'source_as_of' => $snapshot['source_as_of'], 'transform_version' => $snapshot['transform_version'],
            'expected_records' => $this->total($snapshot)];
        $row = DB::table('legacy_history_batches')->where('snapshot_sha256', $snapshotSha256)->lockForUpdate()->first();
        if ($row === null) {
            return (int) DB::table('legacy_history_batches')->insertGetId(['snapshot_sha256' => $snapshotSha256] + $identity
                + ['actor_id' => $actor->id, 'attestation_audit_id' => $attestationAuditId, 'created_at' => now()]);
        }
        foreach ($identity as $column => $value) {
            if ((string) $row->{$column} !== (string) $value) {
                throw new LegacyArchiveRefusal('batch_identity_mismatch');
            }
        }

        return (int) $row->id;
    }

    private function insert(string $cohort, array $record, int $batch, array $contractFiles): void
    {
        $common = ['batch_id' => $batch, 'record_key' => $record['record_key'], 'record_sha256' => $record['record_sha256'],
            'record_ciphertext' => Crypt::encryptString(CanonicalJson::encode($record)), 'created_at' => now()];
        $row = match ($cohort) {
            'customers' => ['source_customer_id' => $record['source_customer_id'], 'email_hmac' => self::emailHmac($record['email_normalized']),
                'marketing_consent' => $record['marketing_consent']],
            'orders' => ['source_order_id' => $record['source_order_id'], 'customer_record_key' => $record['customer_record_key'],
                'status' => $record['status'], 'currency' => $record['currency'], 'total_minor' => $record['total_minor'],
                'refunded_minor' => $record['refunded_minor'], 'ordered_at' => $record['ordered_at']],
            'lines' => ['order_record_key' => $record['order_record_key'], 'source_order_id' => $record['source_order_id'],
                'source_line_id' => $record['source_line_id'], 'track_source_id' => $record['track_source_id'],
                'license_label' => $record['license_label'], 'exclusive' => $record['exclusive'], 'amount_minor' => $record['amount_minor'],
                'refunded_minor' => $record['refunded_minor'], 'currency' => $record['currency'], 'classification' => $record['classification']],
            'contracts' => ['line_record_key' => $record['line_record_key'], 'source_contract_id' => $record['source_contract_id'],
                'original_sha256' => $record['sha256'], 'original_bytes' => $record['bytes'], 'media_type' => $record['media_type'],
                'storage_path' => $this->storeOriginal($record, $contractFiles)],
        };
        DB::table(self::TABLES[$cohort])->insert($common + $row);
    }

    /**
     * Store the exact original bytes under their SHA-256 on the private disk. An existing file must already hold
     * exactly those bytes; a different file at that address is refused and left untouched.
     */
    private function storeOriginal(array $record, array $contractFiles): string
    {
        $bytes = $contractFiles[$record['file_name']] ?? null;
        if (! is_string($bytes) || strlen($bytes) !== $record['bytes'] || ! hash_equals($record['sha256'], hash('sha256', $bytes))) {
            throw new LegacyArchiveRefusal('contract_source_changed');
        }
        $path = self::contractPath($record['sha256']);
        $disk = Storage::disk(self::DISK);
        if (! $disk->exists($path)) {
            if (! $disk->put($path, $bytes, ['visibility' => 'private'])) {
                throw new LegacyArchiveRefusal('contract_store_failed');
            }
        }
        if (self::artifactState($path, $record['sha256'], $record['bytes']) !== 'verified') {
            throw new LegacyArchiveRefusal('contract_store_conflict');
        }

        return $path;
    }
}
