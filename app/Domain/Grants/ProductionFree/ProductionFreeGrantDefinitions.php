<?php

namespace App\Domain\Grants\ProductionFree;

use App\Domain\Delivery\DeliveryAssetFiles;
use App\Models\User;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Staff side of family 256: an author proposes one sealed definition, a different current staff member approves
 * that exact hash, and availability is an append-only open/closed event chain. No Filament UI calls these yet.
 */
final class ProductionFreeGrantDefinitions
{
    public const ASSET_ROLES = ['master_wav' => 'audio/wav', 'download_mp3' => 'audio/mpeg', 'stems_zip' => 'application/zip'];

    public const ASSET_EXTENSIONS = ['master_wav' => 'wav', 'download_mp3' => 'mp3', 'stems_zip' => 'zip'];

    public const MAX_ASSET_BYTES = DeliveryAssetFiles::MAX_BYTES;

    /** @param array{title:string,termsReference:string,termsText:string,assentText:string,assets:list<array>,source:array<string,string>,maxOrigins:int} $input */
    public function propose(array $input, User $author): array
    {
        ProductionFreeGrantInput::keys($input, ['title', 'termsReference', 'termsText', 'assentText', 'assets', 'source', 'maxOrigins']);
        $title = ProductionFreeGrantInput::text($input['title'], 160);
        $reference = ProductionFreeGrantInput::text($input['termsReference'], 160);
        $terms = ProductionFreeGrantInput::text($input['termsText'], 65536, true);
        $assent = ProductionFreeGrantInput::text($input['assentText'], 2000, true);
        $maxOrigins = ProductionFreeGrantInput::integer($input['maxOrigins'], 1, 1000000);
        $assets = $this->assets($input['assets']);
        $source = $this->source($input['source']);
        ProductionFreeGrantRenderable::require(title: $title, termsReference: $reference, termsText: $terms, assentText: $assent);

        return $this->staffCommand($author, function (array $policy, ProductionFreeGrantRows $rows) use ($title, $reference, $terms, $assent, $maxOrigins, $assets, $source, $author): array {
            $proof = $this->prove($policy, $source, $assets);
            $at = ProductionFreeGrantInput::now();
            $id = (string) Str::uuid();
            $profile = ProductionFreeGrantRenderProfile::current($policy['provenance']);
            $payload = ['schema_version' => 'production-free-definition-v1', 'definition_id' => $id,
                'family' => ProductionFreeGrantSchema::FAMILY, 'purpose' => ProductionFreeGrantSchema::PURPOSE, 'version' => 1,
                'provenance' => $policy['provenance'], 'title' => $title, 'terms_reference' => $reference, 'terms_text' => $terms,
                'terms_hash' => hash('sha256', $terms), 'assent_text' => $assent, 'template' => ProductionFreeGrantRenderProfile::TEMPLATE,
                'profile' => $profile, 'assets' => $assets, 'source' => $source, 'source_proof' => $proof, 'max_origins' => $maxOrigins,
                'author_user_id' => (int) $author->getKey(), 'proposed_at' => ProductionFreeGrantInput::iso($at)];
            $rows->insert('production_free_definitions', $this->columns($payload, $at), $payload);

            return $this->graph($id, $rows);
        }, fn (array $graph): array => $this->project($graph));
    }

    /** The reviewer approves the exact sealed hash it was shown; self-review and stale hashes are refused. */
    public function approve(string $definitionId, array $input, User $reviewer): array
    {
        ProductionFreeGrantInput::uuid($definitionId);
        ProductionFreeGrantInput::keys($input, ['definitionHash']);
        $hash = ProductionFreeGrantInput::hash($input['definitionHash']);

        return $this->staffCommand($reviewer, function (array $policy, ProductionFreeGrantRows $rows) use ($definitionId, $hash, $reviewer): array {
            $graph = $this->graph($definitionId, $rows);
            ProductionFreeGrantException::require(hash_equals($graph['definition']['definition_hash'], $hash), 'stale_definition');
            ProductionFreeGrantException::require($graph['review'] === [], 'already_reviewed');
            ProductionFreeGrantException::require((int) $graph['payload']['author_user_id'] !== (int) $reviewer->getKey(), 'self_review');
            ProductionFreeGrantRenderable::requirePayload($graph['payload']);
            $proof = $this->prove($policy, $graph['payload']['source'], $graph['payload']['assets']);
            $at = ProductionFreeGrantInput::now();
            $id = (string) Str::uuid();
            $payload = ['schema_version' => 'production-free-review-v1', 'review_id' => $id, 'definition_id' => $definitionId,
                'definition_hash' => $hash, 'terms_hash' => $graph['payload']['terms_hash'], 'decision' => 'approved',
                'reviewer_user_id' => (int) $reviewer->getKey(), 'source_proof' => $proof, 'reviewed_at' => ProductionFreeGrantInput::iso($at)];
            $rows->insert('production_free_reviews', ['id' => $id, 'definition_id' => $definitionId, 'definition_hash' => $hash,
                'terms_hash' => $graph['payload']['terms_hash'], 'reviewer_user_id' => (int) $reviewer->getKey(), 'decision' => 'approved',
                'provenance' => $policy['provenance'], 'created_at' => ProductionFreeGrantInput::stored($at)], $payload);

            return $this->graph($definitionId, $rows);
        }, fn (array $graph): array => $this->project($graph));
    }

    /** Opening needs the approved review, Sean-approved terms bytes and current source readiness. */
    public function open(string $definitionId, array $input, User $actor): array
    {
        return $this->availability($definitionId, $input, $actor, 'open');
    }

    /** Closing stops new assent only; every earlier origin and original stays retained and usable. */
    public function close(string $definitionId, array $input, User $actor): array
    {
        return $this->availability($definitionId, $input, $actor, 'closed');
    }

    /** Staff read of one definition with its review and availability chain. */
    public function read(string $definitionId, User $actor): array
    {
        ProductionFreeGrantInput::uuid($definitionId);

        return $this->staffCommand($actor, fn (array $policy, ProductionFreeGrantRows $rows): array => $this->graph($definitionId, $rows),
            fn (array $graph): array => $this->project($graph));
    }

    /** Verified definition graph: sealed rows, recomputed hashes and the ordered availability chain. */
    public function graph(string $definitionId, ProductionFreeGrantRows $rows): array
    {
        $definition = $rows->one('production_free_definitions', 'id = ?', [$definitionId]);
        ProductionFreeGrantException::require($definition !== [], 'not_found');
        $payload = $definition['payload'];
        $expected = $this->columns($payload, ProductionFreeGrantInput::parse($definition['created_at']));
        $actual = array_diff_key($definition, ['payload' => true, 'payload_ciphertext' => true, 'seal' => true]);
        ProductionFreeGrantException::require(($payload['schema_version'] ?? null) === 'production-free-definition-v1'
            && $payload['definition_id'] === $definitionId && $payload['proposed_at'] === ProductionFreeGrantInput::iso(ProductionFreeGrantInput::parse($definition['created_at']))
            && ProductionFreeGrantRecords::strings($actual) === ProductionFreeGrantRecords::strings($expected)
            && hash_equals(hash('sha256', $payload['terms_text']), $payload['terms_hash']), 'tampered');
        // The stored profile is checked against the release registry, never against the current runtime, so a later
        // renderer revision does not strand existing grants. Render, recover and new assent check the runtime.
        try {
            ProductionFreeGrantRenderProfile::validateStored($payload['profile']);
            ProductionFreeGrantException::require($payload['profile']['provenance'] === $payload['provenance'], 'profile_unregistered');
        } catch (Throwable) {
            throw new ProductionFreeGrantException('profile_unregistered');
        }
        $review = $rows->one('production_free_reviews', 'definition_id = ?', [$definitionId]);
        if ($review !== []) {
            $r = $review['payload'];
            ProductionFreeGrantException::require(($r['schema_version'] ?? null) === 'production-free-review-v1' && $r['review_id'] === $review['id']
                && $r['definition_hash'] === $definition['definition_hash'] && $r['terms_hash'] === $definition['terms_hash']
                && $r['decision'] === 'approved' && $r['reviewer_user_id'] === (int) $review['reviewer_user_id']
                && $r['reviewer_user_id'] !== $payload['author_user_id'], 'tampered');
        }
        $events = $rows->chain('production_free_availability', 'definition_id = ?', [$definitionId], ProductionFreeGrantSchema::MAX_AVAILABILITY_EVENTS);
        foreach ($events as $ordinal => $event) {
            $e = $event['payload'];
            ProductionFreeGrantException::require((int) $event['ordinal'] === $ordinal && $review !== [] && $event['review_id'] === $review['id']
                && ($e['schema_version'] ?? null) === 'production-free-availability-v1' && $e['availability_id'] === $event['id']
                && $e['kind'] === $event['kind'] && $e['definition_hash'] === $definition['definition_hash']
                && $e['ordinal'] === $ordinal && $e['actor_user_id'] === (int) $event['actor_user_id'], 'tampered');
        }
        $current = $events === [] ? null : $events[array_key_last($events)];

        return ['definition' => array_diff_key($definition, ['payload' => true]), 'payload' => $payload,
            'review' => $review === [] ? [] : array_diff_key($review, ['payload' => true]), 'events' => array_map(fn (array $e): array => array_diff_key($e, ['payload' => true]), $events),
            'open' => $current !== null && $current['kind'] === 'open', 'current' => $current === null ? null : array_diff_key($current, ['payload' => true])];
    }

    /** Staff projection; private source manifest values are shown to staff, never to customers. */
    public function project(array $graph): array
    {
        $p = $graph['payload'];

        return ['id' => $p['definition_id'], 'definitionHash' => $graph['definition']['definition_hash'], 'title' => $p['title'],
            'termsReference' => $p['terms_reference'], 'termsHash' => $p['terms_hash'], 'provenance' => $p['provenance'],
            'authorUserId' => $p['author_user_id'], 'reviewed' => $graph['review'] !== [],
            'reviewerUserId' => $graph['review'] === [] ? null : (int) $graph['review']['reviewer_user_id'],
            'open' => $graph['open'], 'availabilityOrdinal' => count($graph['events']) - 1,
            'availabilityId' => $graph['current']['id'] ?? null, 'maxOrigins' => $p['max_origins'],
            'assets' => array_map(fn (array $a): array => ['role' => $a['role'], 'sha256' => $a['sha256'], 'bytes' => $a['bytes']], $p['assets']),
            'source' => $p['source']];
    }

    public function prove(array $policy, array $source, array $assets): string
    {
        try {
            $proof = $policy === [] ? '' : (new ProductionFreeGrantPolicy)->sources($policy)->prove($source, $assets);
        } catch (ProductionFreeGrantException $error) {
            throw $error;
        } catch (Throwable) {
            throw new ProductionFreeGrantException('source_not_ready');
        }
        ProductionFreeGrantException::require(is_string($proof) && preg_match('/\A[a-f0-9]{64}\z/D', $proof) === 1, 'source_not_ready');

        return $proof;
    }

    /** One staff transaction: policy, locked current staff row first, MFA/gate re-proved after every callback. */
    public function staffCommand(User $actor, callable $work, callable $project): array
    {
        return ProductionFreeGrantTransactions::run(function () use ($actor, $work, $project): array {
            $policy = (new ProductionFreeGrantPolicy)->current();
            $rows = new ProductionFreeGrantRows;
            $staff = new ProductionFreeGrantStaff;
            $raw = $staff->lock($actor, $rows);
            $result = $work($policy, $rows);
            $staff->proveCurrent($actor, $rows, $raw);
            (new ProductionFreeGrantPolicy)->prove($policy);
            $rows->assertCurrent();

            return $project($result);
        });
    }

    private function availability(string $definitionId, array $input, User $actor, string $kind): array
    {
        ProductionFreeGrantInput::uuid($definitionId);
        ProductionFreeGrantInput::keys($input, ['definitionHash', 'expectedOrdinal']);
        $hash = ProductionFreeGrantInput::hash($input['definitionHash']);
        $expected = ProductionFreeGrantInput::integer($input['expectedOrdinal'], 0, ProductionFreeGrantSchema::MAX_AVAILABILITY_EVENTS);

        return $this->staffCommand($actor, function (array $policy, ProductionFreeGrantRows $rows) use ($definitionId, $hash, $expected, $kind, $actor): array {
            $graph = $this->graph($definitionId, $rows);
            ProductionFreeGrantException::require(hash_equals($graph['definition']['definition_hash'], $hash), 'stale_definition');
            ProductionFreeGrantException::require($graph['review'] !== [], 'not_reviewed');
            ProductionFreeGrantException::require(count($graph['events']) === $expected, 'stale_availability');
            ProductionFreeGrantException::require($kind === 'open' ? ! $graph['open'] : $graph['open'], 'stale_availability');
            // The chain is bounded by the schema (ordinals 0-999) and read completely; refuse before writing past it.
            ProductionFreeGrantException::require(count($graph['events']) < ProductionFreeGrantSchema::MAX_AVAILABILITY_EVENTS, 'availability_exhausted');
            if ($kind === 'open') {
                ProductionFreeGrantRenderProfile::requireCurrent($graph['payload']['profile']);
                ProductionFreeGrantRenderable::requirePayload($graph['payload']);
                (new ProductionFreeGrantPolicy)->requireApprovedTerms($policy, $graph['payload']['terms_hash']);
                $this->prove($policy, $graph['payload']['source'], $graph['payload']['assets']);
            }
            $at = ProductionFreeGrantInput::now();
            $id = (string) Str::uuid();
            $payload = ['schema_version' => 'production-free-availability-v1', 'availability_id' => $id, 'definition_id' => $definitionId,
                'definition_hash' => $hash, 'kind' => $kind, 'ordinal' => $expected, 'actor_user_id' => (int) $actor->getKey(),
                'at' => ProductionFreeGrantInput::iso($at)];
            $rows->insert('production_free_availability', ['id' => $id, 'definition_id' => $definitionId, 'review_id' => $graph['review']['id'],
                'ordinal' => $expected, 'kind' => $kind, 'actor_user_id' => (int) $actor->getKey(), 'created_at' => ProductionFreeGrantInput::stored($at)], $payload);

            return $this->graph($definitionId, $rows);
        }, fn (array $graph): array => $this->project($graph));
    }

    private function columns(array $payload, CarbonImmutable $at): array
    {
        return ['id' => $payload['definition_id'], 'definition_hash' => CanonicalJson::hash($payload), 'family' => $payload['family'],
            'purpose' => $payload['purpose'], 'version' => $payload['version'], 'author_user_id' => $payload['author_user_id'],
            'terms_hash' => $payload['terms_hash'], 'template_hash' => CanonicalJson::hash(['template' => $payload['template']]),
            'profile_hash' => CanonicalJson::hash($payload['profile']), 'asset_manifest_hash' => CanonicalJson::hash($payload['assets']),
            'source_manifest_hash' => CanonicalJson::hash($payload['source']), 'asset_count' => count($payload['assets']),
            'max_origins' => $payload['max_origins'], 'provenance' => $payload['provenance'], 'created_at' => ProductionFreeGrantInput::stored($at)];
    }

    /** @return list<array{role:string,source_id:string,sha256:string,bytes:int,mime_type:string,filename:string}> */
    private function assets(mixed $assets): array
    {
        ProductionFreeGrantException::require(is_array($assets) && array_is_list($assets) && $assets !== [] && count($assets) <= 3, 'invalid_input');
        $result = [];
        foreach ($assets as $asset) {
            ProductionFreeGrantException::require(is_array($asset), 'invalid_input');
            ProductionFreeGrantInput::keys($asset, ['role', 'sourceId', 'sha256', 'bytes']);
            $role = $asset['role'];
            ProductionFreeGrantException::require(is_string($role) && isset(self::ASSET_ROLES[$role]) && ! isset($result[$role]), 'invalid_input');
            ProductionFreeGrantException::require(is_string($asset['sourceId']) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $asset['sourceId']) === 1, 'invalid_input');
            $result[$role] = ['role' => $role, 'source_id' => $asset['sourceId'], 'sha256' => ProductionFreeGrantInput::hash($asset['sha256']),
                'bytes' => ProductionFreeGrantInput::integer($asset['bytes'], 1, self::MAX_ASSET_BYTES), 'mime_type' => self::ASSET_ROLES[$role],
                'filename' => 'production-free-'.$role.'.'.self::ASSET_EXTENSIONS[$role]];
        }

        return array_values(array_filter(array_map(fn (string $role): ?array => $result[$role] ?? null, array_keys(self::ASSET_ROLES))));
    }

    /** Source IDs and hashes are preserved verbatim; they are references, never live catalog lookups. */
    private function source(mixed $source): array
    {
        ProductionFreeGrantException::require(is_array($source) && $source !== [] && ! array_is_list($source) && count($source) <= 32, 'invalid_input');
        foreach ($source as $key => $value) {
            ProductionFreeGrantException::require(is_string($key) && preg_match('/\A[a-z][a-z0-9_]{0,39}\z/D', $key) === 1
                && is_string($value) && preg_match('/\A[\x21-\x7E]{1,200}\z/D', $value) === 1, 'invalid_input');
        }
        ProductionFreeGrantException::require(isset($source['license_id'], $source['license_hash'], $source['track_id']), 'invalid_input');
        ksort($source, SORT_STRING);

        return $source;
    }
}
