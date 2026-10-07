<?php

namespace App\Domain\Grants\Free;

use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FreeGrantDownloads
{
    public function authorize(string $originId, array $input, object $principal, User $actor): array
    {
        FreeGrantInput::uuid($originId);
        FreeGrantInput::keys($input, ['requestKey', 'originHash', 'kind', 'nonce']);
        FreeGrantInput::uuid($input['requestKey']);
        FreeGrantInput::hash($input['originHash']);
        FreeGrantInput::hash($input['nonce']);
        FreeGrantException::require(in_array($input['kind'], ['contract', 'master_wav', 'download_mp3'], true), 422);
        $requestHash = CanonicalJson::hash($input);

        return DB::transaction(function () use ($originId, $input, $principal, $actor, $requestHash): array {
            $rows = new FreeGrantRows;
            $identity = (new FreeGrantPolicy)->identity();
            $authority = $identity->lock($principal, $actor, $rows);
            $accountId = (int) $identity->durableBinding($principal)['account_id'];
            $grants = new FreeGrants;
            $graph = $grants->originGraph($originId, $accountId, $rows);
            FreeGrantException::require($graph['original'] !== [] && hash_equals($graph['origin']['payload_hash'], $input['originHash']), 409);
            $auth = $rows->one('free_authorizations', 'account_id = ? AND request_key = ?', [$accountId, $input['requestKey']]);
            if ($auth !== []) {
                FreeGrantException::require((int) $auth['origin_id'] === (int) $graph['origin']['id'] && hash_equals($auth['request_hash'], $requestHash), 409);
                $this->live($auth, $rows);
            } else {
                FreeGrantException::require($this->count((int) $graph['origin']['id'], $rows) < $graph['payload']['definition']['max_downloads'], 409);
                $recent = $rows->rows('free_authorizations', 'origin_id = ? AND created_at > ?', [(int) $graph['origin']['id'], now()->utc()->subSeconds(60)->format('Y-m-d H:i:s')], 4);
                FreeGrantException::require(count($recent) < 3, 429);
                $id = (string) Str::uuid();
                $target = $this->target($graph, $input['kind']);
                $token = $this->token($id, $input['nonce']);
                $payload = ['schema_version' => 'free-authorization-v1', 'origin_hash' => $graph['origin']['payload_hash'],
                    'original_hash' => $graph['original']['payload_hash'], 'kind' => $input['kind'], 'target' => $target];
                $auth = FreeGrantRecords::insert('free_authorizations', ['public_id' => $id, 'origin_id' => (int) $graph['origin']['id'], 'account_id' => $accountId,
                    'request_key' => $input['requestKey'], 'request_hash' => $requestHash, 'token_hash' => hash('sha256', $token), 'target' => $input['kind'],
                    ...FreeGrantRecords::encode($payload), 'created_at' => now()->utc()->format('Y-m-d H:i:s'),
                    'expires_at' => now()->utc()->addSeconds($graph['payload']['definition']['token_ttl_seconds'])->format('Y-m-d H:i:s')], $rows);
            }
            $token = $this->token($auth['public_id'], $input['nonce']);
            FreeGrantException::require(hash_equals($auth['token_hash'], hash('sha256', $token)));
            $grants->customerFence($principal, $actor, $identity, $authority, $graph, $rows, false);
            FreeGrantException::require($rows->one('free_authorizations', 'id = ?', [(int) $auth['id']]) === $auth, 409);
            $this->live($auth, $rows);
            (new FreeGrantPolicy)->requireEnabled();

            return ['id' => $auth['public_id'], 'token' => $token, 'expiresAt' => $auth['expires_at'],
                'kind' => $auth['target'], 'filename' => $this->filename($originId, $auth['target']), 'mimeType' => $this->mime($auth['target'])];
        });
    }

    /** Two current authority transactions surround exact private descriptor preparation; one committed attempt. */
    public function redeem(string $id, string $token, object $principal, User $actor): FreeGrantTransfer
    {
        FreeGrantInput::uuid($id);
        FreeGrantException::require(preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $token) === 1, 403);
        $locator = DB::transaction(function () use ($id): array {
            $row = (new FreeGrantRows)->one('free_authorizations', 'public_id = ?', [$id]);
            FreeGrantException::require($row !== [], 404);

            return $row;
        });
        $inspect = function () use ($id, $token, $principal, $actor, $locator): array {
            $rows = new FreeGrantRows;
            $identity = (new FreeGrantPolicy)->identity();
            $authority = $identity->lock($principal, $actor, $rows);
            $accountId = (int) $identity->durableBinding($principal)['account_id'];
            FreeGrantException::require($accountId === (int) $locator['account_id'], 404);
            $origin = $rows->one('free_origins', 'id = ? AND account_id = ?', [(int) $locator['origin_id'], $accountId]);
            FreeGrantException::require($origin !== [], 404);
            $graph = (new FreeGrants)->originGraph($origin['public_id'], $accountId, $rows);
            $auth = $rows->one('free_authorizations', 'public_id = ? AND account_id = ?', [$id, $accountId]);
            FreeGrantException::require($auth === $locator && hash_equals($auth['token_hash'], hash('sha256', $token)), 403);
            $this->live($auth, $rows);
            $payload = FreeGrantRecords::decode($auth);
            FreeGrantException::require($graph['original'] !== [] && $payload['schema_version'] === 'free-authorization-v1'
                && $payload['origin_hash'] === $graph['origin']['payload_hash'] && $payload['original_hash'] === $graph['original']['payload_hash']
                && $payload['kind'] === $auth['target'] && CanonicalJson::encode($payload['target']) === CanonicalJson::encode($this->target($graph, $auth['target'])));
            (new FreeGrants)->customerFence($principal, $actor, $identity, $authority, $graph, $rows, false);
            FreeGrantException::require($rows->one('free_authorizations', 'id = ?', [(int) $auth['id']]) === $auth, 409);
            $this->live($auth, $rows);

            return compact('rows', 'identity', 'authority', 'graph', 'auth', 'payload');
        };
        $before = DB::transaction($inspect);
        $prepared = app(PrepareTestDeliveryStream::class)->handle($before['payload']['target']);
        try {
            DB::transaction(function () use ($inspect, $before, $principal, $actor): void {
                $current = $inspect();
                FreeGrantException::require($current['authority'] === $before['authority'] && $current['graph'] === $before['graph'] && $current['auth'] === $before['auth'], 409);
                $rows = $current['rows'];
                $count = $this->count((int) $current['graph']['origin']['id'], $rows);
                FreeGrantException::require($count < $current['graph']['payload']['definition']['max_downloads'], 409);
                $attempt = FreeGrantRecords::insert('free_redemptions', ['authorization_id' => (int) $current['auth']['id'], 'created_at' => now()->utc()->format('Y-m-d H:i:s')], $rows);
                (new FreeGrants)->customerFence($principal, $actor, $current['identity'], $current['authority'], $current['graph'], $rows, false);
                FreeGrantException::require($rows->one('free_authorizations', 'id = ?', [(int) $current['auth']['id']]) === $current['auth']
                    && $rows->one('free_redemptions', 'id = ?', [(int) $attempt['id']]) === $attempt
                    && $this->count((int) $current['graph']['origin']['id'], $rows) === $count + 1, 409);
                FreeGrantException::require(now()->lessThan($current['auth']['expires_at']), 410);
                (new FreeGrantPolicy)->requireEnabled();
            });

            return new FreeGrantTransfer($prepared, $this->filename($before['graph']['origin']['public_id'], $before['auth']['target']), $this->mime($before['auth']['target']));
        } catch (\Throwable $error) {
            $prepared->close();
            throw $error;
        }
    }

    public function target(array $graph, string $kind): array
    {
        if ($kind === 'contract') {
            $artifact = FreeGrantRecords::decode($graph['original'])['artifact'];

            return ['kind' => 'contract', 'sha256' => $artifact['pdf_hash']] + $artifact;
        }
        foreach ($graph['payload']['definition']['source']['assets'] as $asset) {
            if ($asset['role'] === $kind) {
                return ['kind' => $kind] + $asset;
            }
        }
        throw new FreeGrantException(404);
    }

    public function filename(string $id, string $kind): string
    {
        return 'free-grant-'.$id.'-'.$kind.match ($kind) {
            'contract' => '.pdf', 'master_wav' => '.wav', 'download_mp3' => '.mp3', default => throw new FreeGrantException(404)
        };
    }

    private function mime(string $kind): string
    {
        return match ($kind) {
            'contract' => 'application/pdf', 'master_wav' => 'audio/wav', 'download_mp3' => 'audio/mpeg', default => throw new FreeGrantException(404)
        };
    }

    private function live(array $auth, FreeGrantRows $rows): void
    {
        FreeGrantException::require(now()->lessThan($auth['expires_at']), 410);
        FreeGrantException::require($rows->one('free_redemptions', 'authorization_id = ?', [(int) $auth['id']]) === [], 409);
    }

    private function count(int $originId, FreeGrantRows $rows): int
    {
        $statement = $rows->identity()->prepare('SELECT COUNT(*) FROM '.$rows->table('free_redemptions').' r JOIN '.$rows->table('free_authorizations').' a ON a.id = r.authorization_id WHERE a.origin_id = ?');
        $statement->execute([$originId]);

        return (int) $statement->fetchColumn();
    }

    private function token(string $id, string $nonce): string
    {
        $key = config('app.key');
        FreeGrantException::require(is_string($key) && $key !== '');

        return rtrim(strtr(base64_encode(hash_hmac('sha256', "free-delivery-token-v1\0".$id."\0".$nonce, $key, true)), '+/', '-_'), '=');
    }
}
