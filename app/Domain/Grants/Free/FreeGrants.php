<?php

namespace App\Domain\Grants\Free;

use App\Domain\Grants\Free\Models\FreeDefinition;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FreeGrants
{
    public function accept(string $definitionId, array $input, object $principal, User $actor): array
    {
        FreeGrantInput::keys($input, ['requestKey', 'definitionHash', 'reviewHash', 'expectedVersion', 'declaredName', 'affirmed', 'assentHash']);
        FreeGrantInput::uuid($input['requestKey']);
        foreach (['definitionHash', 'reviewHash', 'assentHash'] as $field) {
            FreeGrantInput::hash($input[$field]);
        }
        FreeGrantInput::integer($input['expectedVersion'], 1, 1000);
        FreeGrantInput::text($input['declaredName'], 120);
        FreeGrantException::require($input['affirmed'] === true, 422);
        $requestHash = CanonicalJson::hash($input);
        $definitions = (new FreeGrantDefinitions);
        $locator = $definitions->locate($definitionId);

        return DB::transaction(function () use ($definitionId, $input, $principal, $actor, $requestHash, $definitions, $locator): array {
            $rows = new FreeGrantRows;
            $identity = (new FreeGrantPolicy)->identity();
            $authority = $identity->lock($principal, $actor, $rows);
            $binding = $identity->durableBinding($principal);
            $accountId = (int) $binding['account_id'];
            $rows->one('rights_scopes', 'id = ?', [(int) $locator['scope_id']]);
            $graph = $definitions->graph($definitionId, $rows);
            FreeGrantException::require($graph['definition'] === $locator, 409);
            $origin = $rows->one('free_origins', 'account_id = ? AND request_key = ?', [$accountId, $input['requestKey']]);
            if ($origin !== []) {
                FreeGrantException::require((int) $origin['definition_id'] === (int) $graph['definition']['id'] && hash_equals($origin['request_hash'], $requestHash), 409);
            } else {
                FreeGrantException::require($graph['review'] !== [] && $graph['open'] && $graph['version'] === $input['expectedVersion']
                    && hash_equals($graph['definition']['payload_hash'], $input['definitionHash'])
                    && hash_equals($graph['review']['payload_hash'], $input['reviewHash'])
                    && hash_equals($this->assentHash($definitions->project($graph), $input['declaredName']), $input['assentHash']), 409);
                FreeGrantException::require($rows->one('free_origins', 'account_id = ? AND definition_id = ?', [$accountId, (int) $graph['definition']['id']]) === [], 409);
                $beforeCount = $this->originCount((int) $graph['definition']['id'], $rows);
                FreeGrantException::require($beforeCount < $graph['payload']['max_origins'], 409);
                (new FreeGrantSources)->proveCurrent($graph['payload']['source'], $rows, true);
                $id = (string) Str::uuid();
                $at = now()->utc()->startOfSecond();
                $payload = ['schema_version' => 'free-origin-v1', 'family' => $graph['payload']['test_only'] ? 'test-free-grant-v1' : 'operative-free-grant-v1',
                    'purpose' => 'free-license-grant', 'origin_id' => $id, 'definition_id' => $definitionId,
                    'definition_hash' => $graph['definition']['payload_hash'], 'review_hash' => $graph['review']['payload_hash'],
                    'definition' => $graph['payload'], 'buyer_binding' => $binding, 'declared_name' => $input['declaredName'],
                    'assent' => ['affirmative' => true, 'purpose' => 'free-license-grant', 'definition_hash' => $input['definitionHash'],
                        'review_hash' => $input['reviewHash'], 'display_hash' => $input['assentHash'], 'availability_version' => $input['expectedVersion'], 'at' => $at->format('Y-m-d\TH:i:s\Z')],
                    'accepted_at' => $at->format('Y-m-d\TH:i:s\Z'), 'profile' => FreeGrantRenderProfile::current()];
                $origin = FreeGrantRecords::insert('free_origins', ['public_id' => $id, 'definition_id' => (int) $graph['definition']['id'], 'account_id' => $accountId,
                    'actor_id' => (int) $actor->id, 'request_key' => $input['requestKey'], 'request_hash' => $requestHash,
                    ...FreeGrantRecords::encode($payload), 'created_at' => $at->format('Y-m-d H:i:s')], $rows);
                FreeGrantRecords::insert('free_document_work', ['origin_id' => (int) $origin['id'], 'state' => 'pending', 'attempts' => 0,
                    'claim_id' => null, 'expires_at' => null, 'created_at' => $at->format('Y-m-d H:i:s')], $rows);
                $subject = new FreeDefinition;
                $subject->setRawAttributes($graph['definition'], true);
                $subject->exists = true;
                AuditEvent::recordAttributed('free_origin.assented', $subject, ['origin_hash' => $origin['payload_hash']], (int) $actor->id);
                FreeGrantException::require($this->originCount((int) $graph['definition']['id'], $rows) === $beforeCount + 1, 409);
            }
            $expected = $this->originGraph($origin['public_id'], $accountId, $rows);
            FreeGrantException::require($expected['definition'] === $graph, 409);
            $this->customerFence($principal, $actor, $identity, $authority, $expected, $rows, isset($beforeCount), isset($beforeCount) ? $beforeCount + 1 : null);

            return $this->originProject($expected);
        });
    }

    /** Exact displayed purpose, terms/source and technical caps are part of affirmative assent. */
    public function assentHash(array $definition, string $declaredName): string
    {
        return CanonicalJson::hash(['schema_version' => 'free-assent-display-v1', 'definition' => $definition,
            'declared_name' => $declaredName, 'declaration' => 'buyer-declared-name', 'purpose' => 'free-license-grant']);
    }

    public function readOrigin(string $id, object $principal, User $actor): array
    {
        FreeGrantInput::uuid($id);

        return DB::transaction(function () use ($id, $principal, $actor): array {
            $rows = new FreeGrantRows;
            $identity = (new FreeGrantPolicy)->identity();
            $authority = $identity->lock($principal, $actor, $rows);
            $binding = $identity->durableBinding($principal);
            $graph = $this->originGraph($id, (int) $binding['account_id'], $rows);
            $this->customerFence($principal, $actor, $identity, $authority, $graph, $rows, false);

            return $this->originProject($graph);
        });
    }

    public function originGraph(string $id, int $accountId, FreeGrantRows $rows): array
    {
        $origin = $rows->one('free_origins', 'public_id = ? AND account_id = ?', [$id, $accountId]);
        FreeGrantException::require($origin !== [], 404);
        $payload = FreeGrantRecords::decode($origin);
        $definitions = (new FreeGrantDefinitions);
        $definition = $definitions->graph($payload['definition_id'], $rows);
        FreeGrantException::require($payload['schema_version'] === 'free-origin-v1' && $payload['origin_id'] === $id
            && $payload['purpose'] === 'free-license-grant' && $payload['assent']['affirmative'] === true && $payload['assent']['purpose'] === 'free-license-grant'
            && $payload['buyer_binding']['account_id'] === $accountId && $payload['buyer_binding']['user_id'] === (int) $origin['actor_id']
            && $payload['definition_hash'] === $definition['definition']['payload_hash'] && $payload['review_hash'] === $definition['review']['payload_hash']
            && $payload['definition'] === $definition['payload'] && (int) $origin['definition_id'] === (int) $definition['definition']['id']
            && $payload['assent']['definition_hash'] === $payload['definition_hash'] && $payload['assent']['review_hash'] === $payload['review_hash']);
        FreeGrantRenderProfile::validate($payload['profile']);
        $originalDisplay = $definitions->project($definition);
        $originalDisplay['version'] = $payload['assent']['availability_version'];
        $originalDisplay['open'] = true;
        FreeGrantException::require(is_int($originalDisplay['version']) && $originalDisplay['version'] >= 1 && $originalDisplay['version'] <= $definition['version']
            && FreeGrantRecords::decode($definition['events'][$originalDisplay['version'] - 1])['open'] === true
            && hash_equals($payload['assent']['display_hash'], $this->assentHash($originalDisplay, $payload['declared_name'])));
        $work = $rows->one('free_document_work', 'origin_id = ?', [(int) $origin['id']]);
        $original = $rows->one('free_originals', 'origin_id = ?', [(int) $origin['id']]);
        FreeGrantException::require($work !== [] && in_array($work['state'], ['pending', 'claimed', 'failed', 'complete'], true)
            && (int) $work['attempts'] >= 0 && (int) $work['attempts'] <= 5 && ($work['state'] === 'complete') === ($original !== []));
        $manifest = null;
        if ($original !== []) {
            $manifest = FreeGrantRecords::decode($original);
            FreeGrantException::require($manifest['schema_version'] === 'free-original-v1' && $manifest['origin_hash'] === $origin['payload_hash']
                && $manifest['profile_hash'] === CanonicalJson::hash($payload['profile']) && $original['claim_id'] === $work['claim_id']
                && $manifest['input_hash'] === CanonicalJson::hash(FreeGrantRenderInput::fromOrigin($payload))
                && $manifest['text_digest'] === (new FreeGrantText)->build(FreeGrantRenderInput::fromOrigin($payload))['text_digest']
                && $manifest['asset_hashes'] === array_column($payload['definition']['source']['assets'], 'sha256')
                && $manifest['artifact']['profile_hash'] === $manifest['profile_hash']
                && $manifest['artifact']['storage_path'] === 'contracts/test/'.$id.'/'.$work['claim_id'].'/original.pdf');
        }

        return compact('origin', 'payload', 'definition', 'work', 'original', 'manifest');
    }

    public function customerFence(object $principal, User $actor, FreeGrantIdentity $identity, array $authority, array $graph, FreeGrantRows $rows, bool $newAdmission, ?int $expectedCount = null): void
    {
        $original = $identity instanceof FreeGrantOriginalIdentity
            ? $identity->lockOriginal($principal, $actor, $graph['payload']['buyer_binding'], $rows) : null;
        $identity->proveCurrent($principal, $actor, $rows, $authority);
        FreeGrantException::require($this->originGraph($graph['origin']['public_id'], (int) $graph['origin']['account_id'], $rows) === $graph, 409);
        (new FreeGrantSources)->proveCurrent($graph['payload']['definition']['source'], $rows, $newAdmission);
        if ($newAdmission) {
            FreeGrantException::require($graph['definition']['open'] && $graph['definition']['version'] === $graph['payload']['assent']['availability_version'], 409);
        }
        if ($expectedCount !== null) {
            FreeGrantException::require($this->originCount((int) $graph['origin']['definition_id'], $rows) === $expectedCount
                && $expectedCount <= $graph['payload']['definition']['max_origins'], 409);
        }
        (new FreeGrantPolicy)->requireDefinition($graph['payload']['definition']);
        if ($identity instanceof FreeGrantOriginalIdentity) {
            $identity->proveOriginalPrimary($principal, $actor, $graph['payload']['buyer_binding'], $rows, $original);
        }
        $identity->provePrimary($principal, $actor, $rows, $authority);
        $rows->assertCurrent();
    }

    public function originProject(array $graph): array
    {
        $p = $graph['payload'];
        $manifest = $graph['manifest'];
        $files = $manifest === null ? [] : array_map(fn (array $asset): array => ['kind' => $asset['role'], 'sha256' => $asset['sha256'],
            'sizeBytes' => $asset['size_bytes'], 'mimeType' => $asset['mime_type']], $p['definition']['source']['assets']);
        if ($manifest !== null) {
            $files[] = ['kind' => 'contract', 'sha256' => $manifest['artifact']['pdf_hash'], 'sizeBytes' => $manifest['artifact']['size_bytes'], 'mimeType' => 'application/pdf'];
        }

        return ['id' => $graph['origin']['public_id'], 'definitionId' => $p['definition_id'], 'title' => $p['definition']['title'],
            'originHash' => $graph['origin']['payload_hash'], 'purpose' => $p['purpose'], 'declaredName' => $p['declared_name'],
            'acceptedAt' => $p['accepted_at'], 'assentText' => $p['definition']['assent_text'], 'license' => $p['definition']['source']['disclosure'],
            'collection' => 'none', 'testOnly' => $p['definition']['test_only'], 'documentStatus' => $graph['work']['state'],
            'renderAttempts' => (int) $graph['work']['attempts'], 'maxDownloads' => $p['definition']['max_downloads'], 'files' => $files];
    }

    private function originCount(int $definitionId, FreeGrantRows $rows): int
    {
        $statement = $rows->identity()->prepare('SELECT COUNT(*) FROM '.$rows->table('free_origins').' WHERE definition_id = ?');
        $statement->execute([$definitionId]);

        return (int) $statement->fetchColumn();
    }
}
