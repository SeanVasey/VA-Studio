<?php

namespace App\Domain\Grants\Paid;

use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Models\User;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Throwable;

final class PaidGrantDownloads
{
    private const KINDS = ['contract', 'master_wav', 'download_mp3', 'stems_zip'];

    /** No token or path is returned, and DB preparation status never claims continuing physical availability. */
    public function status(string $batchId, ProductionCustomerPrincipal $principal, User $actor, ?PaidGrantProjectionRead $projectionRead = null): array
    {
        $snapshots = [];

        return (new PaidGrantCommands)->run($batchId, $principal, $actor, function (array $graph, PaidGrantRows $rows) use (&$snapshots): array {
            $at = CarbonImmutable::now('UTC');
            $lines = [];
            foreach ($graph['lines'] as $line) {
                $query = $rows->primary->prepare('SELECT * FROM '.$rows->table('paid_authorizations').' WHERE origin_id = ? ORDER BY id DESC LIMIT 20'
                    .($rows->primary->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
                $query->execute([$line['origin']['id']]);
                $auths = $query->fetchAll(PDO::FETCH_ASSOC);
                $redemptions = $this->attempts((int) $line['origin']['id'], $rows);
                $minimum = $auths === [] ? 0 : min(array_column($auths, 'id'));
                $maximum = $auths === [] ? 0 : max(array_column($auths, 'id'));
                $snapshots[] = ['paid_authorizations', 'origin_id = ? AND id BETWEEN ? AND ?', [$line['origin']['id'], $minimum, $maximum], 21, array_reverse($auths)];
                $snapshots[] = $this->attemptSnapshot((int) $line['origin']['id'], $rows, $redemptions);
                $history = [];
                foreach ($auths as $auth) {
                    PaidGrantException::require((int) $auth['account_id'] === (int) $graph['batch']['account_id'] && in_array($auth['target'], self::KINDS, true));
                    $matches = array_values(array_filter($redemptions, fn (array $row): bool => (int) $row['authorization_id'] === (int) $auth['id']));
                    $history[] = ['id' => $auth['public_id'], 'kind' => $auth['target'], 'issuedAt' => $auth['created_at'], 'expiresAt' => $auth['expires_at'],
                        'status' => $matches !== [] ? 'attempted' : ($at->lessThan($auth['expires_at']) ? 'unused' : 'expired'), 'attemptedAt' => $matches[0]['created_at'] ?? null];
                }
                $work = $line['work'];
                $lines[] = ['id' => $line['origin']['public_id'], 'attemptCount' => count($redemptions), 'maxDownloads' => $graph['payload']['delivery_policy']['max_downloads'],
                    'historyLimit' => 20, 'history' => $history, 'renderRetryAllowed' => (int) $work['attempts'] < PaidGrantDocuments::MAX_ATTEMPTS
                        && ($work['state'] === 'pending' || $work['state'] === 'failed' || $work['state'] === 'claimed' && $at->greaterThanOrEqualTo($work['expires_at'])),
                    'renderRetryAfter' => $work['state'] === 'claimed' ? $work['expires_at'] : null];
            }

            return ['schemaVersion' => 1, 'originId' => $graph['batch']['public_id'], 'fulfilled' => $graph['complete'] !== null, 'lines' => $lines];
        }, additionalSnapshots: function () use (&$snapshots): array {
            return $snapshots;
        }, projectionRead: $projectionRead);
    }

    public function authorize(string $batchId, string $lineId, array $input, ProductionCustomerPrincipal $principal, User $actor, ?PaidGrantProjectionRead $projectionRead = null): array
    {
        PaidGrantInput::uuid($lineId);
        PaidGrantInput::keys($input, ['requestKey', 'originHash', 'kind', 'nonce']);
        PaidGrantInput::uuid($input['requestKey']);
        PaidGrantInput::hash($input['originHash']);
        PaidGrantInput::hash($input['nonce']);
        PaidGrantException::require(in_array($input['kind'], self::KINDS, true), 422);
        $requestHash = CanonicalJson::hash($input);
        $auth = null;
        $deadline = PaidGrantDeadline::start();

        return (new PaidGrantCommands)->run($batchId, $principal, $actor, function (array $graph, PaidGrantRows $rows) use ($lineId, $input, $requestHash, &$auth, &$deadline): array {
            $line = $this->line($graph, $lineId);
            PaidGrantException::require($graph['complete'] !== null && hash_equals($line['origin']['payload_hash'], $input['originHash']), 409);
            $auth = $rows->one('paid_authorizations', 'account_id = ? AND request_key = ?', [$graph['batch']['account_id'], $input['requestKey']]);
            if ($auth !== []) {
                PaidGrantException::require((int) $auth['origin_id'] === (int) $line['origin']['id'] && hash_equals($auth['request_hash'], $requestHash), 409);
                $this->live($auth, $rows);
            } else {
                PaidGrantException::require(count($this->attempts((int) $line['origin']['id'], $rows)) < $graph['payload']['delivery_policy']['max_downloads'], 409);
                $at = CarbonImmutable::now('UTC')->startOfSecond();
                $recent = $rows->rows('paid_authorizations', 'origin_id = ? AND created_at > ?', [$line['origin']['id'], $at->subSeconds(60)->format('Y-m-d H:i:s')], 4);
                PaidGrantException::require(count($recent) < 3, 429);
                $id = (string) Str::uuid();
                $target = $this->target($graph, $line, $input['kind']);
                $token = $this->token($id, $input['nonce']);
                $payload = ['schema_version' => 'paid-authorization-v1', 'batch_hash' => $graph['batch']['payload_hash'], 'origin_hash' => $line['origin']['payload_hash'],
                    'fulfillment_hash' => $graph['fulfillment']['payload_hash'], 'original_hash' => $line['original']['payload_hash'], 'kind' => $input['kind'], 'target' => $target];
                $auth = PaidGrantRecords::insert('paid_authorizations', ['public_id' => $id, 'origin_id' => (int) $line['origin']['id'], 'account_id' => (int) $graph['batch']['account_id'],
                    'request_key' => $input['requestKey'], 'request_hash' => $requestHash, 'token_hash' => hash('sha256', $token), 'target' => $input['kind'],
                    ...PaidGrantRecords::encode($payload), 'created_at' => $at->format('Y-m-d H:i:s'),
                    'expires_at' => $at->addSeconds($graph['payload']['delivery_policy']['authorization_seconds'])->format('Y-m-d H:i:s')], $rows);
            }
            $deadline->shortenTo($this->deadline($auth));
            $token = $this->token($auth['public_id'], $input['nonce']);
            PaidGrantException::require(hash_equals($auth['token_hash'], hash('sha256', $token)));

            return ['id' => $auth['public_id'], 'token' => $token, 'expiresAt' => $auth['expires_at'], 'kind' => $auth['target'],
                'filename' => $this->filename($lineId, $auth['target']), 'mimeType' => $this->mime($auth['target'])];
        }, $deadline, additionalSnapshots: function (array $graph, PaidGrantRows $rows) use (&$auth): array {
            return $this->authSnapshots($auth, $rows);
        }, projectionRead: $projectionRead);
    }

    /** Exact physical snapshot between two freshly authenticated producer/owner frames; one committed attempt. */
    public function redeem(string $id, string $token, ProductionCustomerPrincipal $principal, User $actor): PaidGrantTransfer
    {
        PaidGrantInput::uuid($id);
        PaidGrantException::require(preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $token) === 1, 403);
        $deadline = PaidGrantDeadline::start();
        $locator = $this->locate($id, $principal, $actor);
        $inspect = function (array $graph, PaidGrantRows $rows) use ($locator, $id, $token): array {
            $matches = array_values(array_filter($graph['lines'], fn (array $line): bool => (int) $line['origin']['id'] === (int) $locator['origin_id']));
            PaidGrantException::require(count($matches) === 1 && $graph['complete'] !== null, 404);
            $line = $matches[0];
            $auth = $rows->one('paid_authorizations', 'public_id = ? AND account_id = ?', [$id, $graph['batch']['account_id']]);
            PaidGrantException::require($auth === $locator['auth'] && hash_equals($auth['token_hash'], hash('sha256', $token)), 403);
            $this->live($auth, $rows);
            $payload = PaidGrantRecords::decode($auth);
            PaidGrantException::require($payload['schema_version'] === 'paid-authorization-v1' && $payload['batch_hash'] === $graph['batch']['payload_hash']
                && $payload['origin_hash'] === $line['origin']['payload_hash'] && $payload['fulfillment_hash'] === $graph['fulfillment']['payload_hash']
                && $payload['original_hash'] === $line['original']['payload_hash'] && $payload['kind'] === $auth['target']
                && CanonicalJson::encode($payload['target']) === CanonicalJson::encode($this->target($graph, $line, $auth['target'])), 409);

            return compact('graph', 'line', 'auth', 'payload');
        };
        $commands = new PaidGrantCommands;
        $before = $commands->run($locator['batch_id'], $principal, $actor, $inspect, $deadline,
            additionalSnapshots: fn (array $graph, PaidGrantRows $rows): array => $this->authSnapshots($locator['auth'], $rows));
        $deadline->shortenTo($this->deadline($before['auth']));
        $prepared = app(PaidGrantPrepareStream::class)->handle($before['payload']['target'], $deadline->value());
        $projectionRead = PaidGrantProjectionRead::begin();
        try {
            $commands->run($locator['batch_id'], $principal, $actor, function (array $graph, PaidGrantRows $rows) use ($inspect, $before): array {
                $current = $inspect($graph, $rows);
                PaidGrantException::require($current === $before, 409);
                PaidGrantException::require(count($this->attempts((int) $current['line']['origin']['id'], $rows)) < $graph['payload']['delivery_policy']['max_downloads'], 409);
                PaidGrantRecords::insert('paid_redemptions', ['authorization_id' => (int) $current['auth']['id'], 'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s')], $rows);

                return [];
            }, $deadline, additionalSnapshots: fn (array $graph, PaidGrantRows $rows): array => $this->authSnapshots($before['auth'], $rows),
                projectionRead: $projectionRead);

            return new PaidGrantTransfer($prepared, $this->filename($before['line']['origin']['public_id'], $before['auth']['target']), $this->mime($before['auth']['target']),
                $projectionRead, $deadline->value());
        } catch (Throwable $error) {
            $prepared->close();
            throw $error;
        }
    }

    private function locate(string $id, ProductionCustomerPrincipal $principal, User $actor): array
    {
        (new PaidGrants)->outsideTransactions();
        app(PaidGrantPolicy::class)->capture();
        try {
            app(ProductionCustomerAccess::class)->current($principal, $actor);
        } catch (IdentityException) {
            throw new PaidGrantException(403);
        }
        // A short owned current-buyer frame locates metadata only, never grants download authority.
        $receipt = null;
        $held = null;
        try {
            $result = DB::transaction(function () use ($id, $principal, $actor, &$receipt, &$held): array {
                $rows = new PaidGrantRows;
                $held = $rows;
                $policy = app(PaidGrantPolicy::class)->capture();
                $access = app(ProductionCustomerAccess::class);
                $authority = $access->lock($principal, $actor, $rows->current());
                $auth = $rows->one('paid_authorizations', 'public_id = ? AND account_id = ?', [$id, $principal->accountId]);
                PaidGrantException::require($auth !== [], 404);
                $origin = $rows->one('paid_grant_origins', 'id = ?', [$auth['origin_id']]);
                $batch = $rows->one('paid_order_origins', 'id = ? AND account_id = ?', [$origin['batch_id'] ?? 0, $principal->accountId]);
                PaidGrantException::require($origin !== [] && $batch !== [], 404);
                $receipt = PaidGrantReadReceipt::capture($rows, $principal, $actor, $authority, $policy,
                    [['paid_authorizations', 'id = ?', [$auth['id']], 2, [$auth]], ['paid_grant_origins', 'id = ?', [$origin['id']], 2, [$origin]], ['paid_order_origins', 'id = ?', [$batch['id']], 2, [$batch]]]);
                $receipt->proveLive();
                $access->proveCurrent($principal, $actor, $rows->current(), $authority);
                PaidGrantPolicy::provePure($policy, $rows->configuration, $rows->environment);
                $rows->finish();

                return ['batch_id' => $batch['public_id'], 'origin_id' => $origin['id'], 'auth' => $auth];
            });
            $receipt->proveClosed();

            return $result;
        } catch (Throwable $error) {
            $held?->abort();
            if ($error instanceof IdentityException) {
                throw new PaidGrantException(403);
            }
            throw $error;
        }
    }

    private function line(array $graph, string $id): array
    {
        $matches = array_values(array_filter($graph['lines'], fn (array $line): bool => $line['origin']['public_id'] === $id));
        PaidGrantException::require(count($matches) === 1, 404);

        return $matches[0];
    }

    private function attempts(int $origin, PaidGrantRows $rows): array
    {
        return $rows->rows('paid_redemptions', 'authorization_id IN (SELECT id FROM '.$rows->table('paid_authorizations').' WHERE origin_id = ?)', [$origin], 101);
    }

    private function attemptSnapshot(int $origin, PaidGrantRows $rows, array $expected): array
    {
        return ['paid_redemptions', 'authorization_id IN (SELECT id FROM '.$rows->table('paid_authorizations').' WHERE origin_id = ?)', [$origin], 101, $expected];
    }

    private function authSnapshots(array $auth, PaidGrantRows $rows): array
    {
        $attempts = $this->attempts((int) $auth['origin_id'], $rows);

        return [['paid_authorizations', 'id = ?', [$auth['id']], 2, [$auth]], $this->attemptSnapshot((int) $auth['origin_id'], $rows, $attempts)];
    }

    private function live(array $auth, PaidGrantRows $rows): void
    {
        PaidGrantException::require(CarbonImmutable::now('UTC')->lessThan($auth['expires_at']), 410);
        PaidGrantException::require($rows->one('paid_redemptions', 'authorization_id = ?', [$auth['id']]) === [], 409);
    }

    private function deadline(array $auth): int
    {
        $remaining = CarbonImmutable::now('UTC')->floatDiffInSeconds(CarbonImmutable::parse($auth['expires_at'], 'UTC'), false);
        PaidGrantException::require($remaining > 0 && $remaining <= 600, 410);

        return hrtime(true) + (int) floor($remaining * 1_000_000_000);
    }

    private function target(array $graph, array $line, string $kind): array
    {
        if ($kind === 'contract') {
            $artifact = $line['manifest']['artifact'];

            return ['kind' => 'contract', 'sha256' => $artifact['pdf_hash']] + $artifact;
        }
        foreach ($line['body']['assets']['files'] as $file) {
            if ($file['role'] === $kind) {
                return ['kind' => $kind, 'provenance' => $graph['payload']['provenance']] + $file;
            }
        }
        throw new PaidGrantException(404);
    }

    private function token(string $id, string $nonce): string
    {
        $key = config('app.key');
        PaidGrantException::require(is_string($key) && $key !== '');

        return rtrim(strtr(base64_encode(hash_hmac('sha256', "paid-license-token-v1\0".$id."\0".$nonce, $key, true)), '+/', '-_'), '=');
    }

    private function filename(string $id, string $kind): string
    {
        return 'paid-license-'.$id.'-'.$kind.match ($kind) {
            'contract' => '.pdf', 'master_wav' => '.wav', 'download_mp3' => '.mp3', 'stems_zip' => '.zip', default => throw new PaidGrantException(404),
        };
    }

    private function mime(string $kind): string
    {
        return match ($kind) {
            'contract' => 'application/pdf', 'master_wav' => 'audio/wav', 'download_mp3' => 'audio/mpeg', 'stems_zip' => 'application/zip', default => throw new PaidGrantException(404),
        };
    }
}
