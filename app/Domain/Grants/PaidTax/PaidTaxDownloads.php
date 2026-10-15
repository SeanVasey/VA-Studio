<?php

namespace App\Domain\Grants\PaidTax;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Grants\Paid\PaidGrantRequestInstant;
use App\Models\User;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PDO;
use Throwable;

final class PaidTaxDownloads
{
    private const KINDS = ['contract', 'master_wav', 'download_mp3', 'stems_zip'];

    /**
     * The authorize frame's observation budget. The row is inserted inside the frame, but the token reaches the customer
     * only after the frame commits and passes its post-commit proofs, up to this many seconds later. The authorization
     * therefore expires this much after `created_at + authorization_seconds`, so the policy lifetime counts from the latest
     * moment the token can be in the customer's hands (Codex 4223825193).
     */
    public const AUTHORIZE_BUDGET_SECONDS = 60;

    /**
     * How long before admission the request may have started (Codex 4224514947). The server's own request-start time is
     * taken before the identity proof; between it and the controller there is only request parsing and middleware (the
     * session lock waits at most 5 s), so one observation budget is generous. An older value is treated as broken. It
     * is checked once, at capture; the identity proof that follows is not counted against it (Codex 4224939409).
     */
    public const ADMISSION_MAX_AGE_SECONDS = 60;

    /**
     * The instant the server began this request, validated once, when captured (`PaidGrantRequestInstant::capture()`):
     * the SAPI's `REQUEST_TIME_FLOAT`, else `LARAVEL_START`, else now, within ADMISSION_MAX_AGE_SECONDS of now.
     */
    public static function receivedAt(mixed $requestTime): PaidGrantRequestInstant
    {
        return PaidGrantRequestInstant::capture($requestTime);
    }

    /** @param  ?Closure():int  $clock  Monotonic nanoseconds for the transfer deadline; the system clock when null. */
    public function __construct(private readonly ?Closure $clock = null) {}

    /** No token or path is returned, and DB preparation status never claims continuing physical availability. */
    public function status(string $batchId, ProductionCustomerPrincipal $principal, User $actor, ?PaidTaxProjectionRead $projectionRead = null): array
    {
        $snapshots = [];

        return (new PaidTaxCommands)->run($batchId, $principal, $actor, function (array $graph, PaidTaxRows $rows) use (&$snapshots): array {
            $rows->admitDelivery();
            $at = CarbonImmutable::now('UTC');
            $lines = [];
            foreach ($graph['lines'] as $line) {
                $query = $rows->primary->prepare('SELECT * FROM '.$rows->table('paid_tax_authorizations').' WHERE origin_public_id = ? ORDER BY id DESC LIMIT 20'
                    .($rows->primary->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
                $query->execute([$line['origin']['public_id']]);
                $auths = $query->fetchAll(PDO::FETCH_ASSOC);
                $redemptions = $this->attempts($line['origin']['public_id'], $rows);
                $minimum = $auths === [] ? 0 : min(array_column($auths, 'id'));
                $snapshots[] = ['paid_tax_authorizations', 'origin_public_id = ? AND id >= ?', [$line['origin']['public_id'], $minimum], 21, array_reverse($auths)];
                $snapshots[] = $this->attemptSnapshot($line['origin']['public_id'], $rows, $redemptions);
                $history = [];
                foreach ($auths as $auth) {
                    PaidTaxException::require((int) $auth['account_id'] === (int) $graph['batch']['account_id'] && in_array($auth['target'], self::KINDS, true));
                    $matches = array_values(array_filter($redemptions, fn (array $row): bool => (int) $row['authorization_id'] === (int) $auth['id']));
                    $history[] = ['id' => $auth['public_id'], 'kind' => $auth['target'], 'issuedAt' => $auth['created_at'], 'expiresAt' => $auth['expires_at'],
                        'status' => $matches !== [] ? 'attempted' : ($at->lessThan($auth['expires_at']) ? 'unused' : 'expired'), 'attemptedAt' => $matches[0]['created_at'] ?? null];
                }
                $work = $line['work'];
                $lines[] = ['id' => $line['origin']['public_id'], 'attemptCount' => count($redemptions), 'maxDownloads' => $graph['payload']['delivery_policy']['max_downloads'],
                    'historyLimit' => 20, 'history' => $history, 'renderRetryAllowed' => (int) $work['attempts'] < PaidTaxDocuments::MAX_ATTEMPTS
                        && ($work['state'] === 'pending' || $work['state'] === 'failed' || $work['state'] === 'claimed' && $at->greaterThanOrEqualTo($work['expires_at'])),
                    'renderRetryAfter' => $work['state'] === 'claimed' ? $work['expires_at'] : null];
            }

            return ['schemaVersion' => 2, 'originId' => $graph['batch']['public_id'], 'fulfilled' => $graph['complete'] !== null, 'lines' => $lines];
        }, additionalSnapshots: function () use (&$snapshots): array {
            return $snapshots;
        }, projectionRead: $projectionRead);
    }

    public function authorize(string $batchId, string $lineId, array $input, ProductionCustomerPrincipal $principal, User $actor, ?PaidTaxProjectionRead $projectionRead = null): array
    {
        PaidTaxInput::uuid($lineId);
        PaidTaxInput::keys($input, ['requestKey', 'originHash', 'kind', 'nonce']);
        PaidTaxInput::uuid($input['requestKey']);
        PaidTaxInput::hash($input['originHash']);
        PaidTaxInput::hash($input['nonce']);
        PaidTaxException::require(in_array($input['kind'], self::KINDS, true), 422);
        $requestHash = CanonicalJson::hash($input);
        $auth = null;
        $recentSnapshot = [];
        $deadline = PaidTaxDeadline::start(self::AUTHORIZE_BUDGET_SECONDS);

        return (new PaidTaxCommands)->run($batchId, $principal, $actor, function (array $graph, PaidTaxRows $rows) use ($lineId, $input, $requestHash, &$auth, &$deadline, &$recentSnapshot): array {
            $rows->admitDelivery();
            $inputs = $rows->deliveryInputs();
            $line = $this->line($graph, $lineId);
            PaidTaxException::require($graph['complete'] !== null && hash_equals($line['origin']['payload_hash'], $input['originHash']), 409);
            $auth = $rows->one('paid_tax_authorizations', 'account_id = ? AND request_key = ?', [$graph['batch']['account_id'], $input['requestKey']]);
            if ($auth !== []) {
                PaidTaxException::require($auth['origin_public_id'] === $line['origin']['public_id'] && $auth['batch_public_id'] === $graph['batch']['public_id']
                    && $auth['origin_hash'] === $line['origin']['payload_hash'] && (int) $auth['actor_id'] === (int) $graph['batch']['actor_id'] && hash_equals($auth['request_hash'], $requestHash), 409);
                $this->live($auth, $rows);
            } else {
                PaidTaxException::require(count($this->attempts($line['origin']['public_id'], $rows)) < $graph['payload']['delivery_policy']['max_downloads'], 409);
                $at = CarbonImmutable::now('UTC')->startOfSecond();
                $recent = $rows->rows('paid_tax_authorizations', 'origin_public_id = ? AND created_at > ?', [$line['origin']['public_id'], $at->subSeconds(60)->format('Y-m-d H:i:s')], 4);
                PaidTaxException::require(count($recent) < 3, 429);
                $id = (string) Str::uuid();
                $target = $this->target($graph, $line, $input['kind']);
                $token = $this->token($id, $input['nonce'], $inputs['app.key']);
                $payload = ['schema_version' => 'paid-tax-authorization-v2', 'batch_hash' => $graph['batch']['payload_hash'], 'origin_hash' => $line['origin']['payload_hash'],
                    'fulfillment_hash' => $graph['fulfillment']['payload_hash'], 'original_hash' => $line['original']['payload_hash'], 'kind' => $input['kind'], 'target' => $target];
                $auth = PaidTaxDeliveryRecords::insert('paid_tax_authorizations', ['public_id' => $id, 'account_id' => (int) $graph['batch']['account_id'], 'actor_id' => (int) $graph['batch']['actor_id'],
                    'batch_public_id' => $graph['batch']['public_id'], 'origin_public_id' => $line['origin']['public_id'], 'origin_hash' => $line['origin']['payload_hash'],
                    'request_key' => $input['requestKey'], 'request_hash' => $requestHash, 'token_hash' => hash('sha256', $token), 'target' => $input['kind'],
                    ...PaidTaxRecords::encode($payload), 'created_at' => $at->format('Y-m-d H:i:s'),
                    'expires_at' => $at->addSeconds($graph['payload']['delivery_policy']['authorization_seconds'] + self::AUTHORIZE_BUDGET_SECONDS)->format('Y-m-d H:i:s')], $rows);
                $bindings = [$line['origin']['public_id'], $at->subSeconds(60)->format('Y-m-d H:i:s')];
                $issued = $rows->rows('paid_tax_authorizations', 'origin_public_id = ? AND created_at > ?', $bindings, 4);
                PaidTaxException::require(count($issued) <= 3, 429);
                $recentSnapshot = [['paid_tax_authorizations', 'origin_public_id = ? AND created_at > ?', $bindings, 4, $issued]];
            }
            $deadline->shortenTo($this->deadline($auth));
            $token = $this->token($auth['public_id'], $input['nonce'], $inputs['app.key']);
            PaidTaxException::require(hash_equals($auth['token_hash'], hash('sha256', $token)));

            return ['id' => $auth['public_id'], 'token' => $token, 'expiresAt' => $auth['expires_at'], 'kind' => $auth['target'],
                'filename' => $this->filename($lineId, $auth['target']), 'mimeType' => $this->mime($auth['target'])];
        }, $deadline, additionalSnapshots: function (array $graph, PaidTaxRows $rows) use (&$auth, &$recentSnapshot): array {
            return [...$this->authSnapshots($auth, $rows), ...$recentSnapshot];
        }, projectionRead: $projectionRead);
    }

    /** Two separately authenticated frames enclose bounded exact private snapshot preparation. */
    public function redeem(string $batchId, string $id, string $token, ProductionCustomerPrincipal $principal, User $actor,
        ?PaidGrantRequestInstant $receivedAt = null, ?PaidTaxRequestBudget $requestBudget = null): PaidTaxTransfer
    {
        PaidTaxInput::uuid($batchId);
        PaidTaxInput::uuid($id);
        PaidTaxException::require(preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $token) === 1, 403);
        PaidTaxException::require($requestBudget === null || $receivedAt === null || $receivedAt === $requestBudget->receivedAt);
        $requestBudget ??= PaidTaxRequestBudget::capture(null);
        $receivedAt ??= $requestBudget->receivedAt;
        PaidTaxException::require($receivedAt === $requestBudget->receivedAt);
        $admitted = $receivedAt->at;
        $deadline = $requestBudget->initialDeadline();
        $auth = null;
        $inspect = function (array $graph, PaidTaxRows $rows) use ($batchId, $id, $token, $admitted, &$auth): array {
            $rows->admitDelivery();
            $inputs = $rows->deliveryInputs();
            $auth = $rows->one('paid_tax_authorizations', 'public_id = ? AND account_id = ?', [$id, $graph['batch']['account_id']]);
            PaidTaxException::require($auth !== [] && $auth['batch_public_id'] === $batchId
                && (int) $auth['actor_id'] === (int) $graph['batch']['actor_id'], 404);
            $line = $this->line($graph, $auth['origin_public_id']);
            PaidTaxException::require($graph['complete'] !== null, 409);
            PaidTaxException::require(hash_equals($auth['token_hash'], hash('sha256', $token)), 403);
            $this->live($auth, $rows, $admitted);
            $this->deadline($auth, $admitted);
            $payload = PaidTaxRecords::decode($auth);
            PaidTaxException::require($auth['origin_hash'] === $line['origin']['payload_hash']
                && $payload['schema_version'] === 'paid-tax-authorization-v2' && $payload['batch_hash'] === $graph['batch']['payload_hash']
                && $payload['origin_hash'] === $line['origin']['payload_hash'] && $payload['fulfillment_hash'] === $graph['fulfillment']['payload_hash']
                && $payload['original_hash'] === $line['original']['payload_hash'] && $payload['kind'] === $auth['target']
                && CanonicalJson::encode($payload['target']) === CanonicalJson::encode($this->target($graph, $line, $auth['target'])), 409);

            return compact('graph', 'line', 'auth', 'payload', 'inputs');
        };
        $commands = new PaidTaxCommands;
        $before = $commands->run($batchId, $principal, $actor, $inspect, $deadline,
            additionalSnapshots: function (array $graph, PaidTaxRows $rows) use (&$auth): array {
                return $this->authSnapshots($auth, $rows);
            });
        $snapshotDeadline = $requestBudget->snapshotDeadline();
        $remaining = max(0, intdiv(max(0, $snapshotDeadline - hrtime(true)) + 999_999_999, 1_000_000_000));
        $lock = Cache::lock('paid-grant-heavy:'.hash('sha256', 'paid-heavy-v1:'.$principal->accountId), max(660, min(1860, $remaining + 60)));
        PaidTaxException::require($lock->get(), 409);
        try {
            $requestBudget->proveCurrent();
            $prepared = app(PaidTaxPrepareStream::class)->handle($before['payload']['target'], $snapshotDeadline,
                hash('sha256', 'paid-tax-spool-holder-v2:'.$principal->accountId), $requestBudget);
        } finally {
            $lock->release();
        }
        $projectionRead = PaidTaxProjectionRead::begin();
        try {
            PaidTaxException::require(hrtime(true) <= $snapshotDeadline, 410);
            $requestBudget->proveCurrent();
            $seconds = PaidTaxDeliveryPolicy::transferSeconds($before['inputs'], $prepared->sizeBytes);
            $commit = $requestBudget->commitDeadline();
            // Conservative original transfer birth BEFORE any physical commit or managed callbacks.
            $started = $this->tick();
            $commands->run($batchId, $principal, $actor, function (array $graph, PaidTaxRows $rows) use ($inspect, $before, $commit, $requestBudget): array {
                $current = $inspect($graph, $rows);
                PaidTaxException::require($current === $before, 409);
                PaidTaxException::require(count($this->attempts($current['line']['origin']['public_id'], $rows))
                    < $graph['payload']['delivery_policy']['max_downloads'], 409);
                $requestBudget->proveCurrent();
                $commit->proveCurrent();
                PaidTaxDeliveryRecords::insert('paid_tax_redemptions', ['authorization_id' => (int) $current['auth']['id'],
                    'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s')], $rows);

                return [];
            }, $commit, additionalSnapshots: fn (array $graph, PaidTaxRows $rows): array => $this->authSnapshots($before['auth'], $rows),
                projectionRead: $projectionRead);

            return new PaidTaxTransfer($prepared, $this->filename($before['line']['origin']['public_id'], $before['auth']['target']),
                $this->mime($before['auth']['target']), $projectionRead, $started + $seconds * 1_000_000_000, $this->clock, $requestBudget);
        } catch (Throwable $error) {
            $projectionRead->abandon();
            $prepared->close();
            throw $error;
        }
    }

    private function line(array $graph, string $id): array
    {
        $matches = array_values(array_filter($graph['lines'], fn (array $line): bool => $line['origin']['public_id'] === $id));
        PaidTaxException::require(count($matches) === 1, 404);

        return $matches[0];
    }

    private function attempts(string $origin, PaidTaxRows $rows): array
    {
        return $rows->rows('paid_tax_redemptions', 'authorization_id IN (SELECT id FROM '.$rows->table('paid_tax_authorizations').' WHERE origin_public_id = ?)', [$origin], 101);
    }

    private function attemptSnapshot(string $origin, PaidTaxRows $rows, array $expected): array
    {
        return ['paid_tax_redemptions', 'authorization_id IN (SELECT id FROM '.$rows->table('paid_tax_authorizations').' WHERE origin_public_id = ?)', [$origin], 101, $expected];
    }

    private function authSnapshots(array $auth, PaidTaxRows $rows): array
    {
        $attempts = $this->attempts($auth['origin_public_id'], $rows);

        return [['paid_tax_authorizations', 'id = ?', [$auth['id']], 2, [$auth]], $this->attemptSnapshot($auth['origin_public_id'], $rows, $attempts)];
    }

    private function tick(): int
    {
        $tick = $this->clock === null ? hrtime(true) : ($this->clock)();
        PaidTaxException::require(is_int($tick) && $tick >= 0);

        return $tick;
    }

    private function live(array $auth, PaidTaxRows $rows, ?CarbonImmutable $at = null): void
    {
        PaidTaxException::require(($at ?? CarbonImmutable::now('UTC'))->lessThan($auth['expires_at']), 410);
        PaidTaxException::require($rows->one('paid_tax_redemptions', 'authorization_id = ?', [$auth['id']]) === [], 409);
    }

    /** Remaining lifetime from `$at` (now when null), at most the policy maximum (600 s) plus the authorize budget. */
    private function deadline(array $auth, ?CarbonImmutable $at = null): int
    {
        $remaining = ($at ?? CarbonImmutable::now('UTC'))->floatDiffInSeconds(CarbonImmutable::parse($auth['expires_at'], 'UTC'), false);
        PaidTaxException::require($remaining > 0 && $remaining <= 600 + self::AUTHORIZE_BUDGET_SECONDS, 410);

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
        throw new PaidTaxException(404);
    }

    private function token(string $id, string $nonce, string $key): string
    {
        PaidTaxException::require(is_string($key) && $key !== '');

        return rtrim(strtr(base64_encode(hash_hmac('sha256', "paid-tax-license-token-v2\0".$id."\0".$nonce, $key, true)), '+/', '-_'), '=');
    }

    private function filename(string $id, string $kind): string
    {
        return 'paid-tax-license-'.$id.'-'.$kind.match ($kind) {
            'contract' => '.pdf', 'master_wav' => '.wav', 'download_mp3' => '.mp3', 'stems_zip' => '.zip', default => throw new PaidTaxException(404),
        };
    }

    private function mime(string $kind): string
    {
        return match ($kind) {
            'contract' => 'application/pdf', 'master_wav' => 'audio/wav', 'download_mp3' => 'audio/mpeg', 'stems_zip' => 'application/zip', default => throw new PaidTaxException(404),
        };
    }
}
