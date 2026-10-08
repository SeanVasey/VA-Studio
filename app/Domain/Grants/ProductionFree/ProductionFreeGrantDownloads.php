<?php

namespace App\Domain\Grants\ProductionFree;

use App\Domain\Contracts\ContractIo;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Delivery\PreparedDeliveryStream;
use App\Models\User;
use App\Support\CanonicalJson;
use Closure;
use Illuminate\Support\Str;
use Throwable;

/**
 * Short-lived, one-use owner authorization per artifact and its redemption. Bytes are copied into an unlinked
 * private snapshot and hash-verified before the first byte can leave; the authorization deadline bounds the
 * copy and the stream. Snapshots are bounded by `ProductionFreeGrantSpool` (held slots, free-space reserve,
 * read-only reopen and read-back hash). No HTTP route exists here: root mounts the delivery endpoint after review.
 */
final class ProductionFreeGrantDownloads
{
    public const ROLES = ['contract', 'master_wav', 'download_mp3', 'stems_zip'];

    /** @param  ?Closure():int  $clock  Monotonic nanoseconds for the transfer deadline; the system clock when null. */
    public function __construct(private readonly ?Closure $clock = null) {}

    /** Mint one authorization; the bearer token is returned once and only its hash is retained. */
    public function authorize(string $originId, array $input, ProductionCustomerPrincipal $principal, User $actor): array
    {
        ProductionFreeGrantInput::uuid($originId);
        ProductionFreeGrantInput::keys($input, ['originSeal', 'role']);
        ProductionFreeGrantInput::hash($input['originSeal']);
        ProductionFreeGrantException::require(is_string($input['role']) && in_array($input['role'], self::ROLES, true), 'invalid_input');

        return (new ProductionFreeGrants)->customerCommand($principal, $actor, function (array $policy, ProductionFreeGrantRows $rows, array $binding) use ($originId, $input): array {
            $graph = (new ProductionFreeGrants)->originGraph($originId, (int) $binding['account_id'], $rows);
            ProductionFreeGrantException::require(hash_equals($graph['origin']['seal'], $input['originSeal']), 'stale_origin');
            $this->entitled($graph, $binding);
            $target = $this->target($graph, $input['role']);
            $at = ProductionFreeGrantInput::now();
            ProductionFreeGrantException::require($rows->count('production_free_authorizations', 'origin_id = ? AND created_at > ?',
                [$originId, ProductionFreeGrantInput::stored($at->subSeconds(60))]) < 5, 'rate_limited');
            $id = (string) Str::uuid();
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $expires = $at->addSeconds($policy['authorization_ttl_seconds']);
            $rows->insert('production_free_authorizations', ['id' => $id, 'origin_id' => $originId, 'account_id' => (int) $binding['account_id'],
                'user_id' => (int) $binding['user_id'], 'role' => $input['role'], 'artifact_sha256' => $target['sha256'],
                'token_hash' => hash('sha256', $token), 'expires_at' => ProductionFreeGrantInput::stored($expires),
                'created_at' => ProductionFreeGrantInput::stored($at)],
                ['schema_version' => 'production-free-authorization-v1', 'authorization_id' => $id, 'origin_seal' => $graph['origin']['seal'],
                    'original_sha256' => $graph['original']['sha256'], 'role' => $input['role'], 'target' => $target]);

            return ['id' => $id, 'token' => $token, 'role' => $input['role'], 'expiresAt' => ProductionFreeGrantInput::iso($expires),
                'sha256' => $target['sha256'], 'bytes' => $target['bytes'], 'filename' => $target['filename'], 'mimeType' => $target['mime_type']];
        });
    }

    /**
     * Two current-owner transactions surround the verified snapshot. The redemption row is the one-use attempt;
     * a failed snapshot records nothing and the authorization stays usable until it expires.
     */
    public function redeem(string $authorizationId, string $token, ProductionCustomerPrincipal $principal, User $actor): ProductionFreeGrantTransfer
    {
        ProductionFreeGrantInput::uuid($authorizationId);
        ProductionFreeGrantException::require(preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $token) === 1, 'token_refused');
        $grants = new ProductionFreeGrants;
        $requested = ProductionFreeGrantInput::now();
        $inspect = function (array $policy, ProductionFreeGrantRows $rows, array $binding) use ($authorizationId, $token, $grants, $requested): array {
            $auth = $rows->one('production_free_authorizations', 'id = ?', [$authorizationId]);
            ProductionFreeGrantException::require($auth !== [] && (int) $auth['account_id'] === (int) $binding['account_id']
                && (int) $auth['user_id'] === (int) $binding['user_id'], 'not_found');
            ProductionFreeGrantException::require(hash_equals($auth['token_hash'], hash('sha256', $token)), 'token_refused');
            ProductionFreeGrantException::require($requested->lessThan(ProductionFreeGrantInput::parse($auth['expires_at'])), 'expired');
            ProductionFreeGrantException::require($rows->count('production_free_redemptions', 'authorization_id = ?', [$authorizationId]) === 0, 'already_redeemed');
            $graph = $grants->originGraph($auth['origin_id'], (int) $binding['account_id'], $rows);
            $this->entitled($graph, $binding);
            $p = $auth['payload'];
            $target = $this->target($graph, $auth['role']);
            ProductionFreeGrantException::require(($p['schema_version'] ?? null) === 'production-free-authorization-v1' && $p['authorization_id'] === $authorizationId
                && $p['origin_seal'] === $graph['origin']['seal'] && $p['original_sha256'] === $graph['original']['sha256']
                && $p['role'] === $auth['role'] && $target['sha256'] === $auth['artifact_sha256']
                && CanonicalJson::encode($p['target']) === CanonicalJson::encode($target), 'entitlement_changed');

            return ['auth' => array_diff_key($auth, ['payload' => true]), 'graph' => $graph, 'target' => $target, 'policy' => $policy];
        };
        // The authorization TTL is the valid-to-start deadline: both checks below compare it with the moment this request began, so a slow
        // snapshot cannot turn a started redemption into an expired one. The snapshot has its own bound and the client stream a
        // transfer deadline derived from the size (see below).
        $before = $grants->customerCommand($principal, $actor, $inspect);
        $snapshotDeadline = hrtime(true) + $before['policy']['snapshot_seconds'] * 1000000000;
        $prepared = $this->snapshot($before, $snapshotDeadline);
        try {
            $grants->customerCommand($principal, $actor, function (array $policy, ProductionFreeGrantRows $rows, array $binding) use ($inspect, $before, $requested): array {
                $current = $inspect($policy, $rows, $binding);
                ProductionFreeGrantException::require(CanonicalJson::encode($current) === CanonicalJson::encode($before), 'entitlement_changed');
                $at = ProductionFreeGrantInput::now();
                $id = (string) Str::uuid();
                // The guarded timestamp is the moment the redemption began, the same instant both inspections compared with
                // the authorization expiry, so the database guard applies the same valid-to-start rule. The sealed payload
                // keeps the completion time.
                $rows->insert('production_free_redemptions', ['id' => $id, 'authorization_id' => $before['auth']['id'],
                    'origin_id' => $before['auth']['origin_id'], 'account_id' => (int) $binding['account_id'], 'user_id' => (int) $binding['user_id'],
                    'role' => $before['auth']['role'], 'artifact_sha256' => $before['target']['sha256'], 'bytes' => $before['target']['bytes'],
                    'created_at' => ProductionFreeGrantInput::stored($requested)],
                    ['schema_version' => 'production-free-redemption-v1', 'redemption_id' => $id, 'authorization_id' => $before['auth']['id'],
                        'origin_seal' => $before['graph']['origin']['seal'], 'role' => $before['auth']['role'], 'at' => ProductionFreeGrantInput::iso($at)]);

                return [];
            });
            $policy = $before['policy'];
            $seconds = min($policy['transfer_max_seconds'], $policy['transfer_base_seconds'] + intdiv($before['target']['bytes'] + $policy['transfer_min_bytes_per_second'] - 1, $policy['transfer_min_bytes_per_second']));

            return new ProductionFreeGrantTransfer($prepared, $before['target']['filename'], $before['target']['mime_type'], $this->tick() + $seconds * 1000000000, $this->clock);
        } catch (Throwable $error) {
            $prepared->close();
            throw $error;
        }
    }

    private function tick(): int
    {
        return $this->clock === null ? hrtime(true) : ($this->clock)();
    }

    /** Current entitlement: the owner, an unrevoked grant and a published original before any artifact leaves. */
    private function entitled(array $graph, array $binding): void
    {
        ProductionFreeGrantException::require((int) $graph['origin']['account_id'] === (int) $binding['account_id']
            && (int) $graph['origin']['user_id'] === (int) $binding['user_id'], 'not_found');
        ProductionFreeGrantException::require($graph['revocation'] === [], 'revoked');
        ProductionFreeGrantException::require($graph['original'] !== [], 'original_pending');
    }

    private function target(array $graph, string $role): array
    {
        if ($role === 'contract') {
            return ['role' => 'contract', 'sha256' => $graph['original']['sha256'], 'bytes' => (int) $graph['original']['bytes'],
                'mime_type' => 'application/pdf', 'filename' => 'production-free-grant-'.$graph['origin']['id'].'.pdf',
                'artifact' => $graph['manifest']['artifact']];
        }
        foreach ($graph['payload']['definition']['assets'] as $asset) {
            if ($asset['role'] === $role) {
                return $asset;
            }
        }
        throw new ProductionFreeGrantException('not_entitled');
    }

    /**
     * Copy while hashing into a bounded, slot-leased, read-only and unlinked private spool; refuse unless size and
     * SHA-256 match exactly on the write path and again on read-back from the spool itself.
     */
    private function snapshot(array $before, int $deadline): PreparedDeliveryStream
    {
        ContractIo::outsideTransactions();
        $target = $before['target'];
        try {
            $directory = (new ProductionFreeGrantFiles)->spoolDirectory();

            return app(ProductionFreeGrantSpool::class)->prepare($directory, $before['policy']['spool_slots'], $before['policy']['spool_reserve_bytes'],
                $target['sha256'], $target['bytes'], $deadline, function ($output) use ($before, $target, $deadline): void {
                    $input = null;
                    try {
                        $hash = hash_init('sha256');
                        $bytes = 0;
                        $write = function (string $chunk) use ($output, &$hash, &$bytes, $target, $deadline): void {
                            if (hrtime(true) >= $deadline) {
                                throw new ProductionFreeGrantException('expired');
                            }
                            $bytes += strlen($chunk);
                            if ($bytes > $target['bytes']) {
                                throw new \UnexpectedValueException;
                            }
                            hash_update($hash, $chunk);
                            for ($offset = 0; $offset < strlen($chunk);) {
                                $written = @fwrite($output, substr($chunk, $offset));
                                if (! is_int($written) || $written < 1) {
                                    throw new \UnexpectedValueException;
                                }
                                $offset += $written;
                            }
                        };
                        if ($target['role'] === 'contract') {
                            $write((new ProductionFreeGrantFiles)->verify($target['artifact']));
                        } else {
                            $input = (new ProductionFreeGrantPolicy)->sources($before['policy'])->open($target);
                            if (! is_resource($input) || get_resource_type($input) !== 'stream') {
                                throw new \UnexpectedValueException;
                            }
                            // A blocking read returns '' only at end of stream. '' without EOF is a stalled source and is refused at
                            // once; the deadline is checked before every read, not only when a chunk is written.
                            // A read that never returns cannot be interrupted by a deadline check, so each read is also time-boxed.
                            // A regular file (including php://temp and php://memory) cannot block indefinitely on a host-local
                            // disk and does not support read timeouts; any other stream (socket, pipe, FIFO, user wrapper) is
                            // refused unless it accepts both blocking mode and the read timeout, so no read can outlive the deadline.
                            ProductionFreeGrantException::require(hrtime(true) < $deadline, 'expired');
                            $mode = fstat($input)['mode'] ?? null;
                            if (! is_int($mode) || ($mode & 0170000) !== 0100000) {
                                if (! @stream_set_blocking($input, true)
                                    || ! @stream_set_timeout($input, max(1, min(5, intdiv($deadline - hrtime(true), 1_000_000_000))))) {
                                    throw new \UnexpectedValueException;
                                }
                            }
                            while (! feof($input)) {
                                ProductionFreeGrantException::require(hrtime(true) < $deadline, 'expired');
                                $chunk = @fread($input, 1048576);
                                if (! is_string($chunk) || (stream_get_meta_data($input)['timed_out'] ?? false) === true || ($chunk === '' && ! feof($input))) {
                                    throw new \UnexpectedValueException;
                                }
                                if ($chunk !== '') {
                                    $write($chunk);
                                }
                            }
                        }
                        ProductionFreeGrantException::require($bytes === $target['bytes'] && hash_equals($target['sha256'], hash_final($hash)), 'artifact_drift');
                    } finally {
                        if (is_resource($input)) {
                            fclose($input);
                        }
                    }
                });
        } catch (ProductionFreeGrantException $error) {
            throw $error;
        } catch (Throwable) {
            throw new ProductionFreeGrantException('artifact_unavailable');
        }
    }
}
