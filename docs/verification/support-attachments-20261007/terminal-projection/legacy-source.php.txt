<?php

namespace App\Domain\SupportAttachments;

use App\Domain\Media\MalwareScanner;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Source lock precedes every attachment lock; file/scanner I/O occurs outside transactions. */
final class SupportAttachments
{
    public function __construct(private AttachmentRegistry $registry, private AttachmentFiles $files, private MalwareScanner $scanner) {}

    public function list(string $kind, string $sourceId, AttachmentActor $actor): array
    {
        return $this->transaction(function (AttachmentRows $rows) use ($kind, $sourceId, $actor): array {
            [$authority, $proof, $policy] = $this->source($kind, $sourceId, null, 'list', $actor, $rows);
            $attachments = $rows->rows('support_attachments', 'source_kind = ? AND source_id = ?', [$kind, $sourceId], 11);
            AttachmentException::require(count($attachments) <= $policy->maxFiles());
            $items = array_map(fn ($row) => array_replace($this->dto($this->retained($row, $proof, $policy)),
                ['canRetry' => ($proof->token->binding()['intake_open'] ?? false) === true && $this->dto($row)['canRetry']]), $attachments);
            $this->terminal($authority, $proof, $policy, $rows);

            return ['sourceVersion' => $proof->token->version(), 'canIntake' => ($proof->token->binding()['intake_open'] ?? false) === true && count($attachments) < $policy->maxFiles(), 'attachments' => $items, 'policy' => ['maxBytes' => $policy->maxBytes(), 'maxFiles' => $policy->maxFiles(), 'retentionSeconds' => $policy->lifetimeSeconds(), 'provenance' => $policy->commitment()['provenance'] ?? 'unavailable']];
        });
    }

    public function intake(string $kind, string $sourceId, int $sourceVersion, AttachmentActor $actor, string $requestKey, string $name, string $trustedUploadPath): array
    {
        $this->uuid($requestKey);
        $this->name($name);
        $bounds = $this->transaction(function (AttachmentRows $rows) use ($kind, $sourceId, $sourceVersion, $actor, $requestKey): array {
            [$authority, $proof, $policy] = $this->source($kind, $sourceId, null, 'list', $actor, $rows);
            $existing = $rows->one('support_attachments', 'source_kind = ? AND source_id = ? AND actor_hash = ? AND request_key = ?', [$kind, $sourceId, $proof->token->actorBinding(), $requestKey]);
            if ($existing === [] || $existing['state'] === 'receiving') {
                AttachmentException::require($authority instanceof AttachmentMutationAuthority);
                $authority->authorizeMutation($proof, $sourceVersion, 'intake', $rows);
                if ($existing === []) {
                    AttachmentException::require(count($rows->rows('support_attachments', 'source_kind = ? AND source_id = ?', [$kind, $sourceId], 11)) < $policy->maxFiles(), 409, 'file_limit');
                }
            }
            $this->terminal($authority, $proof, $policy, $rows);

            return ['maxBytes' => $policy->maxBytes(), 'policyHash' => AttachmentRegistry::hash($policy->commitment())];
        });
        // Inspect the exact server-owned transport descriptor before reserving any original file.
        $file = $this->files->inspect($trustedUploadPath, $bounds['maxBytes']) + ['name' => $name];
        $reservation = $this->transaction(function (AttachmentRows $rows) use ($kind, $sourceId, $sourceVersion, $actor, $requestKey, $bounds, $file): array {
            [$authority, $proof, $policy] = $this->source($kind, $sourceId, null, 'list', $actor, $rows);
            AttachmentException::require($bounds['policyHash'] === AttachmentRegistry::hash($policy->commitment()));
            AttachmentException::require($policy->allowsMime($file['mime']), 422, 'file_type');
            $this->nameMime($file['name'], $file['mime']);
            $existing = $rows->one('support_attachments', 'source_kind = ? AND source_id = ? AND actor_hash = ? AND request_key = ?', [$kind, $sourceId, $proof->token->actorBinding(), $requestKey]);
            if ($existing !== []) {
                $this->retained($existing, $proof, $policy);
                AttachmentException::require(hash_equals($existing['manifest_hash'], AttachmentRegistry::hash($file)), 409, 'changed_request');
                if ($existing['state'] === 'receiving') {
                    AttachmentException::require($authority instanceof AttachmentMutationAuthority);
                    $authority->authorizeMutation($proof, $sourceVersion, 'intake', $rows);
                    AttachmentException::require((int) $existing['expires_at'] > $this->now(), 409, 'expired');
                }
                $this->terminal($authority, $proof, $policy, $rows);

                return ['replayed' => true, 'row' => $existing];
            }
            AttachmentException::require($authority instanceof AttachmentMutationAuthority);
            $authority->authorizeMutation($proof, $sourceVersion, 'intake', $rows);
            AttachmentException::require(count($rows->rows('support_attachments', 'source_kind = ? AND source_id = ?', [$kind, $sourceId], 11)) < $policy->maxFiles(), 409, 'file_limit');
            $now = $this->now();
            $row = ['public_id' => $this->identity($kind, $sourceId, $proof->token->actorBinding(), $requestKey), 'source_kind' => $kind, 'source_id' => $sourceId, 'source_family' => $proof->token->binding()['family'],
                'source_binding' => $this->encrypt($proof->token->binding()), 'source_hash' => AttachmentRegistry::hash($proof->token->binding()), 'origin_hash' => AttachmentRegistry::hash($proof->token->originBinding()),
                'source_version' => $proof->token->version(), 'actor_hash' => $proof->token->actorBinding(), 'request_key' => $requestKey, 'policy_binding' => $this->encrypt($policy->commitment()),
                'policy_hash' => $bounds['policyHash'], 'manifest' => $this->encrypt($file), 'manifest_hash' => AttachmentRegistry::hash($file), 'bytes' => $file['sizeBytes'],
                'expires_at' => $now + $policy->lifetimeSeconds(), 'state' => 'receiving', 'attempt' => 0, 'lease_token' => null, 'lease_until' => null, 'scan_evidence' => null,
                'failure_code' => null, 'created_at' => $now, 'updated_at' => $now];
            $rows->execute('INSERT INTO '.$rows->table('support_attachments').' ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')', array_values($row));
            $saved = $rows->one('support_attachments', 'public_id = ?', [$row['public_id']]);
            AttachmentException::require($saved !== [] && $this->sameRow($saved, $row));
            $this->terminal($authority, $proof, $policy, $rows);

            return ['replayed' => false, 'row' => $saved];
        });
        $row = $reservation['row'];
        if ($row['state'] !== 'receiving') {
            // A retained or deleted exact replay never creates or restores private bytes.
            return ['replayed' => true, 'attachment' => $this->dto($row)];
        }
        // A positively acknowledged, bounded reservation precedes the file effect. Unknown acknowledgements stop here.
        $this->files->preserve($row['public_id'], $trustedUploadPath, $bounds['maxBytes'], $file);

        return $this->transaction(function (AttachmentRows $rows) use ($kind, $sourceId, $sourceVersion, $actor, $row, $reservation): array {
            [$authority, $proof, $policy] = $this->source($kind, $sourceId, null, 'list', $actor, $rows);
            $current = $this->attachment($rows, $kind, $sourceId, $row['public_id'], $proof, $policy);
            if ($current['state'] === 'receiving') {
                AttachmentException::require($current === $row && (int) $current['expires_at'] > $this->now() && $authority instanceof AttachmentMutationAuthority, 409, 'reload');
                $authority->authorizeMutation($proof, $sourceVersion, 'intake', $rows);
                $current = $this->update($rows, $current, ['state' => 'quarantined', 'updated_at' => $this->now()]);
            } else {
                AttachmentException::require(! in_array($current['state'], ['deleted', 'expired'], true), 409, 'reload');
            }
            $this->terminal($authority, $proof, $policy, $rows);

            return ['replayed' => $reservation['replayed'], 'attachment' => $this->dto($current)];
        });
    }

    /** An explicit request claims one attempt. Unknown handoff requires status/retry, never silent duplication. */
    public function process(string $kind, string $sourceId, int $sourceVersion, string $id, int $expectedAttempt, AttachmentActor $actor): array
    {
        $this->uuid($id);
        AttachmentException::require($expectedAttempt >= 0 && $expectedAttempt <= 3, 422);
        $token = bin2hex(random_bytes(32));
        $claimed = $this->transaction(function (AttachmentRows $rows) use ($kind, $sourceId, $sourceVersion, $id, $expectedAttempt, $actor, $token): array {
            [$authority, $proof, $policy] = $this->source($kind, $sourceId, null, 'list', $actor, $rows);
            $row = $this->attachment($rows, $kind, $sourceId, $id, $proof, $policy);
            if ($row['state'] === 'ready') {
                $this->terminal($authority, $proof, $policy, $rows);

                return ['finished' => $this->dto($row)];
            }
            AttachmentException::require($authority instanceof AttachmentMutationAuthority);
            $authority->authorizeMutation($proof, $sourceVersion, 'process', $rows);
            AttachmentException::require((int) $row['expires_at'] > $this->now() && (int) $row['attempt'] === $expectedAttempt && $expectedAttempt < 3
                && in_array($row['state'], ['quarantined', 'failed', 'scanning'], true), 409, 'reload');
            AttachmentException::require($row['state'] !== 'scanning' || (int) $row['lease_until'] <= $this->now(), 409, 'scan_pending');
            $changes = ['state' => 'scanning', 'attempt' => $expectedAttempt + 1, 'lease_token' => hash('sha256', $token), 'lease_until' => $this->now() + 60, 'failure_code' => null, 'scan_evidence' => null, 'updated_at' => $this->now()];
            $updated = $this->update($rows, $row, $changes);
            $this->terminal($authority, $proof, $policy, $rows);

            return ['row' => $updated, 'file' => $this->manifest($row), 'policyHash' => $row['policy_hash']];
        });
        if (isset($claimed['finished'])) {
            return $claimed['finished'];
        }
        $snapshot = null;
        $evidence = null;
        $failed = false;
        try {
            $snapshot = $this->files->snapshot($id, $claimed['file']);
            $this->scanner->boundBy(30);
            $evidence = $this->scanner->scan($snapshot->scannerPath());
            $snapshot->verify();
            $this->files->verify($id, $claimed['file']);
        } catch (Throwable) {
            $failed = true;
        } finally {
            $this->scanner->boundBy(null);
            $snapshot?->close();
        }

        return $this->transaction(function (AttachmentRows $rows) use ($kind, $sourceId, $sourceVersion, $id, $actor, $claimed, $token, $evidence, $failed): array {
            [$authority, $proof, $policy] = $this->source($kind, $sourceId, $sourceVersion, 'process', $actor, $rows);
            $row = $this->attachment($rows, $kind, $sourceId, $id, $proof, $policy);
            AttachmentException::require($row === $claimed['row'] && $row['state'] === 'scanning' && (int) $row['lease_until'] > $this->now()
                && (int) $row['expires_at'] > $this->now() && hash_equals((string) $row['lease_token'], hash('sha256', $token)), 409, 'reload');
            $clean = ! $failed && is_array($evidence) && ($evidence['status'] ?? null) === 'clean' && is_string($evidence['engine'] ?? null)
                && $policy->allowsScanEngine($evidence['engine']) && is_string($evidence['version'] ?? null) && $evidence['version'] !== '' && strlen($evidence['version']) <= 512
                && ($evidence['sha256'] ?? null) === $claimed['file']['sha256'];
            $scan = $clean ? ['engine' => $evidence['engine'], 'version' => $evidence['version'], 'status' => 'clean', 'sha256' => $evidence['sha256'], 'manifest_hash' => $row['manifest_hash'], 'policy_hash' => $row['policy_hash'], 'attempt' => (int) $row['attempt']] : null;
            $updated = $this->update($rows, $row, ['state' => $clean ? 'ready' : 'failed', 'lease_token' => null, 'lease_until' => null,
                'scan_evidence' => $scan === null ? null : $this->encrypt($scan), 'failure_code' => $clean ? null : 'scan_unavailable_or_unsafe', 'updated_at' => $this->now()]);
            $this->terminal($authority, $proof, $policy, $rows);

            return $this->dto($updated);
        });
    }

    public function download(string $kind, string $sourceId, string $id, AttachmentActor $actor): array
    {
        $this->uuid($id);
        $prepare = fn (AttachmentRows $rows) => $this->readReady($rows, $kind, $sourceId, $id, $actor);
        $first = $this->transaction($prepare);
        $snapshot = $this->files->snapshot($id, $first['file']);
        try {
            $snapshot->seal();
            $current = $this->transaction($prepare);
            AttachmentException::require($current === $first);

            return ['stream' => $snapshot, 'file' => $first['file']];
        } catch (Throwable $error) {
            $snapshot->close();
            throw $error;
        }
    }

    /** Tombstone first, then exact physical erasure. Original manifest and source history remain. */
    public function delete(string $kind, string $sourceId, string $id, AttachmentActor $actor): array
    {
        $this->uuid($id);
        $terminal = $this->transaction(function (AttachmentRows $rows) use ($kind, $sourceId, $id, $actor): array {
            [$authority, $proof, $policy] = $this->source($kind, $sourceId, null, 'delete', $actor, $rows);
            $row = $this->attachment($rows, $kind, $sourceId, $id, $proof, $policy);
            $file = $this->manifest($row);
            if (! in_array($row['state'], ['deleted', 'expired'], true)) {
                $row = $this->update($rows, $row, ['state' => (int) $row['expires_at'] <= $this->now() ? 'expired' : 'deleted', 'lease_token' => null, 'lease_until' => null, 'updated_at' => $this->now()]);
            }
            $this->terminal($authority, $proof, $policy, $rows);

            return ['dto' => $this->dto($row), 'file' => $file];
        });
        // A failed/unknown transaction never reaches this erasure. Explicit retry may finish an already committed tombstone.
        try {
            $this->files->remove($id, $terminal['file']);

            return $terminal['dto'] + ['cleanup' => 'complete'];
        } catch (Throwable) {
            return $terminal['dto'] + ['cleanup' => 'pending'];
        }
    }

    private function readReady(AttachmentRows $rows, string $kind, string $sourceId, string $id, AttachmentActor $actor): array
    {
        [$authority, $proof, $policy] = $this->source($kind, $sourceId, null, 'download', $actor, $rows);
        $row = $this->attachment($rows, $kind, $sourceId, $id, $proof, $policy);
        $file = $this->manifest($row);
        AttachmentException::require($row['state'] === 'ready' && (int) $row['expires_at'] > $this->now(), 409, 'not_ready');
        $scan = $this->decrypt((string) $row['scan_evidence']);
        AttachmentException::require(($scan['status'] ?? null) === 'clean' && $policy->allowsScanEngine($scan['engine'] ?? '')
            && ($scan['sha256'] ?? null) === $file['sha256'] && ($scan['manifest_hash'] ?? null) === $row['manifest_hash']
            && ($scan['policy_hash'] ?? null) === $row['policy_hash'] && ($scan['attempt'] ?? null) === (int) $row['attempt']);
        $this->terminal($authority, $proof, $policy, $rows);

        return ['row' => $row, 'file' => $file];
    }

    private function source(string $kind, string $sourceId, ?int $version, string $purpose, AttachmentActor $actor, AttachmentRows $rows): array
    {
        $authority = $this->registry->source($kind);
        $proof = $authority->lock($sourceId, $version, $purpose, $actor, $rows);
        $binding = $proof->token->binding();
        AttachmentException::require(is_string($binding['family'] ?? null) && strlen(CanonicalJson::encode($binding)) <= 8192
            && preg_match('/\A[a-f0-9]{64}\z/D', $proof->token->actorBinding()) === 1 && $proof->token->version() >= 0);

        return [$authority, $proof, $this->registry->policy($binding)];
    }

    private function terminal(AttachmentSourceAuthority $authority, AttachmentSourceProof $proof, AttachmentPolicy $policy, AttachmentRows $rows): void
    {
        $hash = AttachmentRegistry::hash($policy->commitment());
        AttachmentException::require($this->registry->policy($proof->token->binding(), $hash) === $policy);
        $authority->proveCurrent($proof, $rows);
        // Concrete policy checks are callback-free; repeat AFTER the adapter's last framework callback.
        AttachmentException::require($this->registry->policy($proof->token->binding(), $hash) === $policy);
        $rows->assertCurrent();
    }

    private function attachment(AttachmentRows $rows, string $kind, string $sourceId, string $id, AttachmentSourceProof $proof, AttachmentPolicy $policy): array
    {
        $row = $rows->one('support_attachments', 'public_id = ? AND source_kind = ? AND source_id = ?', [$id, $kind, $sourceId]);
        AttachmentException::require($row !== [], 404);

        return $this->retained($row, $proof, $policy);
    }

    private function retained(array $row, AttachmentSourceProof $proof, AttachmentPolicy $policy): array
    {
        AttachmentException::require(hash_equals($row['origin_hash'], AttachmentRegistry::hash($proof->token->originBinding())), 404);
        AttachmentException::require($row['source_family'] === $proof->token->binding()['family']
            && $row['source_hash'] === AttachmentRegistry::hash($this->decrypt($row['source_binding']))
            && $row['policy_hash'] === AttachmentRegistry::hash($this->decrypt($row['policy_binding']))
            && $row['policy_hash'] === AttachmentRegistry::hash($policy->commitment()));
        $this->manifest($row);

        return $row;
    }

    private function manifest(array $row): array
    {
        $file = $this->decrypt($row['manifest']);
        AttachmentException::require($row['manifest_hash'] === AttachmentRegistry::hash($file) && ($file['sizeBytes'] ?? null) === (int) $row['bytes']
            && is_string($file['sha256'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $file['sha256']) === 1 && is_string($file['name'] ?? null) && is_string($file['mime'] ?? null));

        return $file;
    }

    private function dto(array $row): array
    {
        $expired = (int) $row['expires_at'] <= $this->now();
        $state = $expired && ! in_array($row['state'], ['deleted', 'expired'], true) ? 'expired' : $row['state'];

        return ['attachmentId' => $row['public_id'], 'sourceVersion' => (int) $row['source_version'], 'state' => $state, 'receivedBytes' => $row['state'] === 'receiving' ? 0 : (int) $row['bytes'],
            'totalBytes' => (int) $row['bytes'], 'file' => $this->manifest($row), 'expiresAt' => gmdate('c', (int) $row['expires_at']), 'attempt' => (int) $row['attempt'],
            'canRetry' => ! $expired && (int) $row['attempt'] < 3 && ($state === 'quarantined' || $state === 'failed' || ($state === 'scanning' && (int) $row['lease_until'] <= $this->now())),
            'canDownload' => ! $expired && $state === 'ready'];
    }

    private function update(AttachmentRows $rows, array $row, array $changes): array
    {
        $rows->execute('UPDATE '.$rows->table('support_attachments').' SET '.implode(', ', array_map(fn ($key) => $key.' = ?', array_keys($changes))).' WHERE id = ?', [...array_values($changes), $row['id']]);
        $updated = $rows->one('support_attachments', 'id = ?', [$row['id']]);
        AttachmentException::require($this->sameRow($updated, array_replace($row, $changes)));

        return $updated;
    }

    private function sameRow(array $actual, array $expected): bool
    {
        foreach ($expected as $key => $value) {
            if (($actual[$key] ?? null) != $value) {
                return false;
            }
        }

return true;
    }

    private function transaction(callable $operation): mixed
    {
        AttachmentException::require(DBOutside::check());

        return DB::transaction(function () use ($operation) {
            $rows = new AttachmentRows;
            try {
                (new AttachmentSchema)->assertOwned();
            } catch (Throwable) {
                throw new AttachmentException;
            }

            return $operation($rows);
        }, 1);
    }

    private function encrypt(array $value): string
    {
        return Crypt::encryptString(CanonicalJson::encode($value));
    }

    private function decrypt(string $value): array
    {
        try {
            $plain = Crypt::decryptString($value);
            AttachmentException::require(strlen($plain) <= 16384);
            $data = json_decode($plain, true, 10, JSON_THROW_ON_ERROR);
            AttachmentException::require(is_array($data));

            return $data;
        } catch (Throwable) {
            throw new AttachmentException;
        }
    }

    private function now(): int
    {
        return now()->getTimestamp();
    }

    private function uuid(string $value): void
    {
        AttachmentException::require(preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D', $value) === 1, 422);
    }

    private function name(string $name): void
    {
        AttachmentException::require(strlen($name) >= 1 && strlen($name) <= 160 && mb_check_encoding($name, 'UTF-8') && ! preg_match('/[\x00-\x1f\x7f\/\\\\]/', $name)
            && preg_match('/\.(?:txt|pdf|png|jpe?g)\z/iD', $name) === 1, 422, 'file_name');
    }

    private function nameMime(string $name, string $mime): void
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $wanted = ['txt' => 'text/plain', 'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
        AttachmentException::require(($wanted[$extension] ?? null) === $mime, 422, 'file_type');
    }

    private function identity(string $kind, string $source, string $actor, string $request): string
    {
        $hex = hash_hmac('sha256', CanonicalJson::encode([$kind, $source, $actor, $request]), (string) config('app.key'));

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3).'-'.dechex((hexdec($hex[16]) & 3) | 8).substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }
}
