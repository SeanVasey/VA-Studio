<?php

namespace App\Domain\Grants\Free;

use App\Domain\Grants\Free\Models\FreeDefinition;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FreeGrantDefinitions
{
    public function author(array $input, User $actor): array
    {
        (new FreeGrantPolicy)->requireEnabled();
        // The operative authoring/approved-free-terms adapter is a distinct pending successor.
        FreeGrantException::require(app()->environment('local', 'testing'), 404);
        FreeGrantInput::keys($input, ['requestKey', 'title', 'freePurpose', 'assentText', 'termsReference', 'licenseId', 'trackId', 'scopeId', 'assetIds', 'maxOrigins', 'maxDownloads', 'tokenTtlSeconds']);
        FreeGrantInput::uuid($input['requestKey']);
        foreach (['licenseId', 'trackId', 'scopeId'] as $field) {
            FreeGrantInput::integer($input[$field], 1, PHP_INT_MAX);
        }
        FreeGrantException::require($input['freePurpose'] === 'free-license-grant' && is_array($input['assetIds']) && array_is_list($input['assetIds']), 422);
        foreach ($input['assetIds'] as $id) {
            FreeGrantInput::integer($id, 1, PHP_INT_MAX);
        }
        FreeGrantInput::text($input['title'], 160);
        FreeGrantInput::text($input['assentText'], 8192);
        FreeGrantInput::text($input['termsReference'], 192);
        FreeGrantInput::integer($input['maxOrigins'], 1, 100000);
        FreeGrantInput::integer($input['maxDownloads'], 1, 1000);
        FreeGrantInput::integer($input['tokenTtlSeconds'], 30, 300);
        $requestHash = CanonicalJson::hash($input);

        return DB::transaction(function () use ($input, $actor, $requestHash): array {
            $rows = new FreeGrantRows;
            $staff = (new FreeGrantStaff)->lock($actor, $rows);
            $scope = $rows->one('rights_scopes', 'id = ?', [$input['scopeId']]);
            FreeGrantException::require($scope !== [], 404);
            $record = $rows->one('free_definitions', 'author_id = ? AND creation_key = ?', [(int) $actor->id, $input['requestKey']]);
            if ($record !== []) {
                FreeGrantException::require(hash_equals($record['request_hash'], $requestHash), 409);
            } else {
                $source = (new FreeGrantSources)->capture($input['licenseId'], $input['trackId'], $input['scopeId'], $input['assetIds'], $rows);
                $payload = ['schema_version' => 'free-definition-v1', 'test_only' => true, 'purpose' => 'free-license-grant',
                    'title' => $input['title'], 'assent_text' => $input['assentText'], 'terms_reference' => $input['termsReference'],
                    'max_origins' => $input['maxOrigins'], 'max_downloads' => $input['maxDownloads'], 'token_ttl_seconds' => $input['tokenTtlSeconds'],
                    'source' => $source, 'amount' => ['collection' => 'none', 'minor' => 0], 'marketing_assent' => 'unknown'];
                $record = FreeGrantRecords::insert('free_definitions', ['public_id' => (string) Str::uuid(), 'author_id' => (int) $actor->id,
                    'license_id' => $input['licenseId'], 'track_id' => $input['trackId'], 'scope_id' => $input['scopeId'],
                    'creation_key' => $input['requestKey'], 'request_hash' => $requestHash, ...FreeGrantRecords::encode($payload), 'created_at' => now()->utc()->format('Y-m-d H:i:s')], $rows);
                $this->audit('free_definition.authored', $record, $actor);
            }
            $graph = $this->graph($record['public_id'], $rows);
            $this->staffFence($actor, $staff, $graph, $rows, true);

            return $this->project($graph);
        });
    }

    public function review(string $id, array $input, User $actor): array
    {
        FreeGrantInput::keys($input, ['requestKey', 'definitionHash', 'reference', 'freeScopeConfirmed', 'scopeBindingConfirmed', 'assetManifestConfirmed']);
        FreeGrantInput::uuid($input['requestKey']);
        FreeGrantInput::hash($input['definitionHash']);
        FreeGrantInput::text($input['reference'], 192);
        foreach (['freeScopeConfirmed', 'scopeBindingConfirmed', 'assetManifestConfirmed'] as $field) {
            FreeGrantException::require($input[$field] === true, 422);
        }
        $requestHash = CanonicalJson::hash($input);
        $locator = $this->locate($id);

        return DB::transaction(function () use ($id, $input, $actor, $requestHash, $locator): array {
            $rows = new FreeGrantRows;
            $staff = (new FreeGrantStaff)->lock($actor, $rows);
            $rows->one('rights_scopes', 'id = ?', [$locator['scope_id']]);
            $graph = $this->graph($id, $rows);
            FreeGrantException::require($graph['definition'] === $locator && hash_equals($graph['definition']['payload_hash'], $input['definitionHash']), 409);
            $contributors = json_decode($graph['payload']['source']['raw']['license']['content_author_ids'], true, 8, JSON_THROW_ON_ERROR);
            FreeGrantException::require((int) $actor->id !== (int) $graph['definition']['author_id'] && ! in_array((int) $actor->id, $contributors, true), 403);
            if ($graph['review'] !== []) {
                FreeGrantException::require($graph['review']['request_key'] === $input['requestKey'] && hash_equals($graph['review']['request_hash'], $requestHash), 409);
            } else {
                $payload = ['schema_version' => 'free-definition-review-v1', 'definition_hash' => $input['definitionHash'],
                    'reviewer_id' => (int) $actor->id, 'reference' => $input['reference'], 'free_scope_confirmed' => true,
                    'scope_binding_confirmed' => true, 'asset_manifest_confirmed' => true];
                FreeGrantRecords::insert('free_reviews', ['definition_id' => (int) $graph['definition']['id'], 'reviewer_id' => (int) $actor->id,
                    'request_key' => $input['requestKey'], 'request_hash' => $requestHash, ...FreeGrantRecords::encode($payload), 'created_at' => now()->utc()->format('Y-m-d H:i:s')], $rows);
                $this->audit('free_definition.reviewed', $graph['definition'], $actor);
            }
            $graph = $this->graph($id, $rows);
            $this->staffFence($actor, $staff, $graph, $rows, true);

            return $this->project($graph);
        });
    }

    public function availability(string $id, array $input, User $actor): array
    {
        FreeGrantInput::keys($input, ['requestKey', 'expectedVersion', 'open', 'reason']);
        FreeGrantInput::uuid($input['requestKey']);
        FreeGrantInput::integer($input['expectedVersion'], 0, 999);
        FreeGrantInput::text($input['reason'], 1000);
        FreeGrantException::require(is_bool($input['open']), 422);
        $requestHash = CanonicalJson::hash($input);
        $locator = $this->locate($id);

        return DB::transaction(function () use ($id, $input, $actor, $requestHash, $locator): array {
            $rows = new FreeGrantRows;
            $staff = (new FreeGrantStaff)->lock($actor, $rows);
            $rows->one('rights_scopes', 'id = ?', [$locator['scope_id']]);
            $graph = $this->graph($id, $rows);
            FreeGrantException::require($graph['definition'] === $locator && $graph['review'] !== [], 409);
            $replay = $rows->one('free_availability', 'definition_id = ? AND request_key = ?', [(int) $graph['definition']['id'], $input['requestKey']]);
            if ($replay !== []) {
                FreeGrantException::require(hash_equals($replay['request_hash'], $requestHash), 409);
            } else {
                FreeGrantException::require($graph['version'] === $input['expectedVersion'] && $graph['open'] !== $input['open'], 409);
                $payload = ['schema_version' => 'free-availability-v1', 'definition_hash' => $graph['definition']['payload_hash'],
                    'review_hash' => $graph['review']['payload_hash'], 'number' => $graph['version'] + 1, 'open' => $input['open'], 'reason' => $input['reason']];
                FreeGrantRecords::insert('free_availability', ['definition_id' => (int) $graph['definition']['id'], 'number' => $graph['version'] + 1,
                    'actor_id' => (int) $actor->id, 'request_key' => $input['requestKey'], 'request_hash' => $requestHash,
                    ...FreeGrantRecords::encode($payload), 'created_at' => now()->utc()->format('Y-m-d H:i:s')], $rows);
                $this->audit('free_definition.availability', $graph['definition'], $actor);
            }
            $graph = $this->graph($id, $rows);
            $this->staffFence($actor, $staff, $graph, $rows, $input['open']);

            return $this->project($graph);
        });
    }

    /** Non-authoritative server locator released before actor/account -> scope -> definition locks. */
    public function locate(string $id): array
    {
        FreeGrantInput::uuid($id);
        (new FreeGrantPolicy)->requireEnabled();

        return DB::transaction(function () use ($id): array {
            $row = (new FreeGrantRows)->one('free_definitions', 'public_id = ?', [$id]);
            FreeGrantException::require($row !== [], 404);

            return $row;
        });
    }

    public function graph(string $id, FreeGrantRows $rows): array
    {
        $definition = $rows->one('free_definitions', 'public_id = ?', [$id]);
        FreeGrantException::require($definition !== [], 404);
        $payload = FreeGrantRecords::decode($definition);
        (new FreeGrantPolicy)->requireDefinition($payload);
        $review = $rows->one('free_reviews', 'definition_id = ?', [(int) $definition['id']]);
        if ($review !== []) {
            $reviewPayload = FreeGrantRecords::decode($review);
            FreeGrantException::require($reviewPayload['schema_version'] === 'free-definition-review-v1'
                && $reviewPayload['definition_hash'] === $definition['payload_hash']
                && $reviewPayload['reviewer_id'] === (int) $review['reviewer_id'] && (int) $review['reviewer_id'] !== (int) $definition['author_id']
                && $reviewPayload['free_scope_confirmed'] === true && $reviewPayload['scope_binding_confirmed'] === true && $reviewPayload['asset_manifest_confirmed'] === true);
        }
        $events = $rows->rows('free_availability', 'definition_id = ?', [(int) $definition['id']]);
        FreeGrantException::require(count($events) <= 1000);
        $version = 0;
        $open = false;
        foreach ($events as $event) {
            $eventPayload = FreeGrantRecords::decode($event);
            FreeGrantException::require($review !== [] && $eventPayload['schema_version'] === 'free-availability-v1'
                && $eventPayload['definition_hash'] === $definition['payload_hash'] && $eventPayload['review_hash'] === $review['payload_hash']
                && $eventPayload['number'] === ++$version && (int) $event['number'] === $version && is_bool($eventPayload['open']) && $eventPayload['open'] !== $open);
            $open = $eventPayload['open'];
        }

        return compact('definition', 'payload', 'review', 'events', 'version', 'open');
    }

    public function project(array $graph): array
    {
        $p = $graph['payload'];

        return ['id' => $graph['definition']['public_id'], 'definitionHash' => $graph['definition']['payload_hash'],
            'reviewHash' => $graph['review']['payload_hash'] ?? null, 'version' => $graph['version'], 'open' => $graph['open'],
            'title' => $p['title'], 'purpose' => $p['purpose'], 'assentText' => $p['assent_text'], 'termsReference' => $p['terms_reference'],
            'license' => $p['source']['disclosure'], 'product' => $p['source']['product'], 'testOnly' => $p['test_only'],
            'maxOrigins' => $p['max_origins'], 'maxDownloads' => $p['max_downloads'], 'tokenTtlSeconds' => $p['token_ttl_seconds'],
            'assets' => array_map(fn (array $asset): array => array_intersect_key($asset, array_flip(['id', 'role', 'sha256', 'mime_type', 'size_bytes'])), $p['source']['assets'])];
    }

    public function readStaff(string $id, User $actor): array
    {
        $locator = $this->locate($id);

        return DB::transaction(function () use ($id, $actor, $locator): array {
            $rows = new FreeGrantRows;
            $staff = (new FreeGrantStaff)->lock($actor, $rows);
            $rows->one('rights_scopes', 'id = ?', [(int) $locator['scope_id']]);
            $graph = $this->graph($id, $rows);
            FreeGrantException::require($graph['definition'] === $locator, 409);
            $this->staffFence($actor, $staff, $graph, $rows, false);

            return $this->project($graph);
        });
    }

    public function staffFence(User $actor, array $staff, array $graph, FreeGrantRows $rows, bool $admission): void
    {
        (new FreeGrantStaff)->proveCurrent($actor, $rows, $staff);
        FreeGrantException::require($this->graph($graph['definition']['public_id'], $rows) === $graph, 409);
        (new FreeGrantSources)->proveCurrent($graph['payload']['source'], $rows, $admission);
        (new FreeGrantPolicy)->requireEnabled();
        (new FreeGrantStaff)->provePrimary($actor, $rows, $staff);
        $rows->assertCurrent();
    }

    private function audit(string $action, array $row, User $actor): void
    {
        $subject = new FreeDefinition;
        $subject->setRawAttributes($row, true);
        $subject->exists = true;
        AuditEvent::recordAttributed($action, $subject, ['definition_hash' => $row['payload_hash']], (int) $actor->id);
    }
}
