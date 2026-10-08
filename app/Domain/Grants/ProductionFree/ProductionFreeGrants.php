<?php

namespace App\Domain\Grants\ProductionFree;

use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Support\Str;

/**
 * Customer review and literal assent for one open definition, plus the verified origin graph every later read,
 * render and delivery uses. The principal comes from `ProductionCustomerSessions::principal(Request)`; the
 * trusted actor is `request->user('customer')`. Neither is inferred from email or client input.
 */
final class ProductionFreeGrants
{
    /** Typed review of the exact open definition; the returned display hash is what assent must echo. */
    public function review(string $definitionId, string $declaredName, ProductionCustomerPrincipal $principal, User $actor): array
    {
        ProductionFreeGrantInput::uuid($definitionId);
        ProductionFreeGrantInput::text($declaredName, 120);
        ProductionFreeGrantRenderable::require(declaredName: $declaredName);

        return $this->customerCommand($principal, $actor, function (array $policy, ProductionFreeGrantRows $rows) use ($definitionId, $declaredName): array {
            $graph = $this->admissible($definitionId, $policy, $rows);
            $display = $this->display($graph);

            return ['definition' => $display, 'declaredName' => $declaredName, 'displayHash' => $this->displayHash($display, $declaredName)];
        });
    }

    /** One original grant per account and definition. The same request key replays the same origin, nothing more. */
    public function accept(string $definitionId, array $input, ProductionCustomerPrincipal $principal, User $actor): array
    {
        ProductionFreeGrantInput::uuid($definitionId);
        ProductionFreeGrantInput::keys($input, ['requestKey', 'definitionHash', 'termsHash', 'availabilityId', 'declaredName', 'affirmed', 'displayHash']);
        ProductionFreeGrantInput::uuid($input['requestKey']);
        ProductionFreeGrantInput::uuid($input['availabilityId']);
        foreach (['definitionHash', 'termsHash', 'displayHash'] as $field) {
            ProductionFreeGrantInput::hash($input[$field]);
        }
        ProductionFreeGrantInput::text($input['declaredName'], 120);
        ProductionFreeGrantRenderable::require(declaredName: $input['declaredName']);
        // Literal affirmative assent only: never true-ish, never defaulted, never inferred from a purchase or import.
        ProductionFreeGrantException::require($input['affirmed'] === true, 'assent_required');
        $requestHash = CanonicalJson::hash(['definition_id' => $definitionId, ...$input]);

        return $this->customerCommand($principal, $actor, function (array $policy, ProductionFreeGrantRows $rows, array $binding) use ($definitionId, $input, $requestHash): array {
            // Replays are found under the current key or any previous key; new rows always use the current key.
            $hashes = $this->requestKeyHashes((int) $binding['account_id'], $input['requestKey']);
            $keyHash = $hashes[0];
            $existing = $rows->one('production_free_origins', 'request_key_hash IN ('.implode(', ', array_fill(0, count($hashes), '?')).')', $hashes);
            if ($existing !== []) {
                ProductionFreeGrantException::require(hash_equals($existing['payload']['request_hash'], $requestHash), 'request_key_reused');

                return $this->project($this->originGraph($existing['id'], (int) $binding['account_id'], $rows));
            }
            $graph = $this->admissible($definitionId, $policy, $rows);
            ProductionFreeGrantException::require(hash_equals($graph['definition']['definition_hash'], $input['definitionHash'])
                && hash_equals($graph['payload']['terms_hash'], $input['termsHash']) && $graph['current']['id'] === $input['availabilityId'], 'stale_definition');
            $display = $this->display($graph);
            ProductionFreeGrantException::require(hash_equals($this->displayHash($display, $input['declaredName']), $input['displayHash']), 'stale_display');
            ProductionFreeGrantException::require($rows->count('production_free_origins', 'account_id = ? AND definition_id = ?',
                [(int) $binding['account_id'], $definitionId]) === 0, 'already_granted');
            $count = $rows->count('production_free_origins', 'definition_id = ?', [$definitionId]);
            ProductionFreeGrantException::require($count < $graph['payload']['max_origins'], 'cap_reached');
            $proof = (new ProductionFreeGrantDefinitions)->prove($policy, $graph['payload']['source'], $graph['payload']['assets']);
            $at = ProductionFreeGrantInput::now();
            $id = (string) Str::uuid();
            $p = $graph['payload'];
            $assent = ['affirmed' => true, 'purpose' => ProductionFreeGrantSchema::PURPOSE, 'definition_hash' => $input['definitionHash'],
                'terms_hash' => $input['termsHash'], 'availability_id' => $input['availabilityId'], 'display_hash' => $input['displayHash'],
                'declared_name' => $input['declaredName'], 'at' => ProductionFreeGrantInput::iso($at)];
            $payload = ['schema_version' => 'production-free-origin-v1', 'origin_id' => $id, 'definition_id' => $definitionId,
                'definition_hash' => $graph['definition']['definition_hash'], 'review_id' => $graph['review']['id'],
                'availability_id' => $input['availabilityId'], 'provenance' => $p['provenance'], 'purpose' => ProductionFreeGrantSchema::PURPOSE,
                'definition' => ['title' => $p['title'], 'terms_reference' => $p['terms_reference'], 'terms_text' => $p['terms_text'],
                    'terms_hash' => $p['terms_hash'], 'assent_text' => $p['assent_text'], 'assets' => $p['assets']],
                'profile' => $p['profile'], 'buyer_binding' => $binding, 'declared_name' => $input['declaredName'],
                'display_hash' => $input['displayHash'], 'assent' => $assent, 'accepted_at' => ProductionFreeGrantInput::iso($at),
                'request_hash' => $requestHash, 'source_proof' => $proof,
                // Marketing consent is unknown here and stays unknown; a free grant never establishes it.
                'marketing_consent' => 'unknown'];
            $rows->insert('production_free_origins', ['id' => $id, 'definition_id' => $definitionId, 'availability_id' => $input['availabilityId'],
                'account_id' => (int) $binding['account_id'], 'user_id' => (int) $binding['user_id'], 'identity_origin_id' => $binding['origin_id'],
                'owner_binding_hash' => CanonicalJson::hash($binding), 'request_key_hash' => $keyHash,
                'definition_hash' => $graph['definition']['definition_hash'], 'terms_hash' => $p['terms_hash'],
                'profile_hash' => CanonicalJson::hash($p['profile']), 'asset_manifest_hash' => CanonicalJson::hash($p['assets']),
                'assent_hash' => CanonicalJson::hash($assent), 'purpose' => ProductionFreeGrantSchema::PURPOSE, 'provenance' => $p['provenance'],
                'created_at' => ProductionFreeGrantInput::stored($at)], $payload);
            ProductionFreeGrantException::require($rows->count('production_free_origins', 'definition_id = ?', [$definitionId]) === $count + 1, 'cap_reached');

            return $this->project($this->originGraph($id, (int) $binding['account_id'], $rows));
        });
    }

    /** Staff revocation stops future delivery; the original assent, render and earlier redemptions are retained. */
    public function revoke(string $originId, array $input, User $actor): array
    {
        ProductionFreeGrantInput::uuid($originId);
        ProductionFreeGrantInput::keys($input, ['originSeal', 'reason']);
        ProductionFreeGrantInput::hash($input['originSeal']);
        $reason = ProductionFreeGrantInput::text($input['reason'], 2000, true);

        return (new ProductionFreeGrantDefinitions)->staffCommand($actor, function (array $policy, ProductionFreeGrantRows $rows) use ($originId, $input, $reason, $actor): array {
            $origin = $rows->one('production_free_origins', 'id = ?', [$originId]);
            ProductionFreeGrantException::require($origin !== [], 'not_found');
            $graph = $this->originGraph($originId, (int) $origin['account_id'], $rows);
            ProductionFreeGrantException::require(hash_equals($graph['origin']['seal'], $input['originSeal']), 'stale_origin');
            ProductionFreeGrantException::require($graph['revocation'] === [], 'already_revoked');
            $at = ProductionFreeGrantInput::now();
            $id = (string) Str::uuid();
            $rows->insert('production_free_revocations', ['id' => $id, 'origin_id' => $originId, 'actor_user_id' => (int) $actor->getKey(),
                'reason_hash' => hash('sha256', $reason), 'created_at' => ProductionFreeGrantInput::stored($at)],
                ['schema_version' => 'production-free-revocation-v1', 'revocation_id' => $id, 'origin_id' => $originId,
                    'origin_seal' => $graph['origin']['seal'], 'reason' => $reason, 'actor_user_id' => (int) $actor->getKey(),
                    'at' => ProductionFreeGrantInput::iso($at)]);

            return $this->originGraph($originId, (int) $origin['account_id'], $rows);
        }, fn (array $graph): array => $this->project($graph));
    }

    /** Verified origin with its frozen definition copy, work chain, original and revocation, for one owner account. */
    public function originGraph(string $originId, int $accountId, ProductionFreeGrantRows $rows): array
    {
        $origin = $rows->one('production_free_origins', 'id = ? AND account_id = ?', [$originId, $accountId]);
        ProductionFreeGrantException::require($origin !== [], 'not_found');
        $p = $origin['payload'];
        $definition = (new ProductionFreeGrantDefinitions)->graph($origin['definition_id'], $rows);
        $d = $definition['payload'];
        $frozen = ['title' => $d['title'], 'terms_reference' => $d['terms_reference'], 'terms_text' => $d['terms_text'],
            'terms_hash' => $d['terms_hash'], 'assent_text' => $d['assent_text'], 'assets' => $d['assets']];
        ProductionFreeGrantException::require(($p['schema_version'] ?? null) === 'production-free-origin-v1' && $p['origin_id'] === $originId
            && $p['definition_id'] === $origin['definition_id'] && $p['definition_hash'] === $origin['definition_hash']
            && $p['definition_hash'] === $definition['definition']['definition_hash'] && $definition['review'] !== []
            && $p['review_id'] === $definition['review']['id'] && $p['availability_id'] === $origin['availability_id']
            && CanonicalJson::encode($p['definition']) === CanonicalJson::encode($frozen) && CanonicalJson::encode($p['profile']) === CanonicalJson::encode($d['profile']) && $p['provenance'] === $origin['provenance']
            && $p['buyer_binding']['account_id'] === (int) $origin['account_id'] && $p['buyer_binding']['user_id'] === (int) $origin['user_id']
            && $p['buyer_binding']['origin_id'] === $origin['identity_origin_id']
            && hash_equals(CanonicalJson::hash($p['profile']), $origin['profile_hash'])
            && hash_equals(CanonicalJson::hash($p['buyer_binding']), $origin['owner_binding_hash'])
            && hash_equals(CanonicalJson::hash($p['assent']), $origin['assent_hash']) && $p['assent']['affirmed'] === true
            && $p['assent']['display_hash'] === $p['display_hash'] && $p['assent']['declared_name'] === $p['declared_name']
            && $p['assent']['definition_hash'] === $p['definition_hash'] && $p['assent']['terms_hash'] === $origin['terms_hash']
            && $p['marketing_consent'] === 'unknown', 'tampered');
        $available = array_values(array_filter($definition['events'], fn (array $e): bool => $e['id'] === $origin['availability_id']));
        ProductionFreeGrantException::require(count($available) === 1 && $available[0]['kind'] === 'open', 'tampered');
        $originalDisplay = $this->display(['definition' => $definition['definition'], 'payload' => $d, 'current' => $available[0]]);
        ProductionFreeGrantException::require(hash_equals($this->displayHash($originalDisplay, $p['declared_name']), $p['display_hash']), 'tampered');
        $work = $rows->chain('production_free_document_work', 'origin_id = ?', [$originId], ProductionFreeGrantSchema::MAX_WORK_ROWS);
        foreach ($work as $ordinal => $item) {
            ProductionFreeGrantException::require((int) $item['ordinal'] === $ordinal && ($item['payload']['schema_version'] ?? null) === 'production-free-work-v1'
                && $item['payload']['claim_id'] === $item['claim_id'] && $item['payload']['kind'] === $item['kind']
                && $item['payload']['origin_seal'] === $origin['seal'], 'tampered');
        }
        $original = $rows->one('production_free_originals', 'origin_id = ?', [$originId]);
        if ($original !== []) {
            $m = $original['payload'];
            $input = ProductionFreeGrantRenderInput::fromOrigin($p);
            ProductionFreeGrantException::require(($m['schema_version'] ?? null) === 'production-free-original-v1'
                && $m['origin_seal'] === $origin['seal'] && $m['input_hash'] === CanonicalJson::hash($input)
                && $original['input_hash'] === $m['input_hash'] && $m['profile_hash'] === CanonicalJson::hash($p['profile'])
                && $original['profile_hash'] === $m['profile_hash'] && $m['artifact']['pdf_hash'] === $original['sha256']
                && $m['artifact']['size_bytes'] === (int) $original['bytes'] && $m['artifact']['profile_hash'] === $m['profile_hash']
                && $m['text_digest'] === $original['text_digest']
                && $m['artifact']['storage_path'] === ProductionFreeGrantFiles::path($originId, $original['claim_id']), 'tampered');
        }
        $revocation = $rows->one('production_free_revocations', 'origin_id = ?', [$originId]);
        if ($revocation !== []) {
            ProductionFreeGrantException::require(($revocation['payload']['schema_version'] ?? null) === 'production-free-revocation-v1'
                && $revocation['payload']['origin_seal'] === $origin['seal']
                && hash_equals(hash('sha256', $revocation['payload']['reason']), $revocation['reason_hash']), 'tampered');
        }
        $strip = fn (array $row): array => array_diff_key($row, ['payload' => true]);

        return ['origin' => $strip($origin), 'payload' => $p, 'definition' => $definition, 'work' => array_map($strip, $work),
            'original' => $original === [] ? [] : $strip($original), 'manifest' => $original['payload'] ?? null,
            'revocation' => $revocation === [] ? [] : $strip($revocation)];
    }

    /** Customer projection: no private paths, source manifest, owner binding or token material. */
    public function project(array $graph): array
    {
        $p = $graph['payload'];
        $last = $graph['work'] === [] ? null : $graph['work'][array_key_last($graph['work'])];
        $status = $graph['original'] !== [] ? 'complete' : ($last === null ? 'pending' : $last['kind']);
        $artifacts = array_map(fn (array $a): array => ['role' => $a['role'], 'sha256' => $a['sha256'], 'bytes' => $a['bytes'], 'mimeType' => $a['mime_type']],
            $p['definition']['assets']);
        if ($graph['original'] !== []) {
            array_unshift($artifacts, ['role' => 'contract', 'sha256' => $graph['original']['sha256'], 'bytes' => (int) $graph['original']['bytes'], 'mimeType' => 'application/pdf']);
        }

        return ['id' => $graph['origin']['id'], 'definitionId' => $p['definition_id'], 'title' => $p['definition']['title'],
            'termsReference' => $p['definition']['terms_reference'], 'termsHash' => $p['definition']['terms_hash'],
            'purpose' => $p['purpose'], 'provenance' => $p['provenance'], 'declaredName' => $p['declared_name'], 'acceptedAt' => $p['accepted_at'],
            'collection' => 'none', 'amountMinor' => 0, 'documentStatus' => $status, 'renderAttempts' => count(array_filter($graph['work'], fn ($w) => $w['kind'] === 'claimed')),
            'revoked' => $graph['revocation'] !== [], 'deliverable' => $graph['revocation'] === [] && $graph['original'] !== [],
            'artifacts' => $artifacts];
    }

    /** One customer transaction: policy, identity locked first and re-proved last with the original raw evidence. */
    public function customerCommand(ProductionCustomerPrincipal $principal, User $actor, callable $work): array
    {
        try {
            return ProductionFreeGrantTransactions::run(function () use ($principal, $actor, $work): array {
                $policy = (new ProductionFreeGrantPolicy)->current();
                $rows = new ProductionFreeGrantRows;
                $access = new ProductionCustomerAccess;
                $raw = $access->lock($principal, $actor, $rows->reader);
                $binding = $access->durableBinding($principal);
                ProductionFreeGrantException::require($principal->provenance === $policy['provenance'] && $binding['provenance'] === $policy['provenance']
                    && (int) $binding['account_id'] === $principal->accountId && (int) $binding['user_id'] === $principal->userId, 'provenance');
                $result = $work($policy, $rows, $binding);
                $access->proveCurrent($principal, $actor, $rows->reader, $raw);
                (new ProductionFreeGrantPolicy)->prove($policy);
                $rows->assertCurrent();

                return $result;
            });
        } catch (IdentityException) {
            throw new ProductionFreeGrantException('identity_refused');
        }
    }

    public function display(array $graph): array
    {
        $p = $graph['payload'];

        return ['schema_version' => 'production-free-display-v1', 'definition_id' => $p['definition_id'],
            'definition_hash' => $graph['definition']['definition_hash'], 'availability_id' => $graph['current']['id'],
            'title' => $p['title'], 'terms_reference' => $p['terms_reference'], 'terms_text' => $p['terms_text'], 'terms_hash' => $p['terms_hash'],
            'assent_text' => $p['assent_text'], 'purpose' => ProductionFreeGrantSchema::PURPOSE, 'provenance' => $p['provenance'],
            'collection' => 'none', 'amount_minor' => 0,
            'assets' => array_map(fn (array $a): array => ['role' => $a['role'], 'bytes' => $a['bytes'], 'mime_type' => $a['mime_type'], 'sha256' => $a['sha256']], $p['assets'])];
    }

    public function displayHash(array $display, string $declaredName): string
    {
        return CanonicalJson::hash(['schema_version' => 'production-free-assent-display-v1', 'display' => $display,
            'declared_name' => $declaredName, 'declaration' => 'buyer-declared-name']);
    }

    private function admissible(string $definitionId, array $policy, ProductionFreeGrantRows $rows): array
    {
        $graph = (new ProductionFreeGrantDefinitions)->graph($definitionId, $rows);
        ProductionFreeGrantException::require($graph['review'] !== [] && $graph['open'], 'not_open');
        ProductionFreeGrantException::require($graph['payload']['provenance'] === $policy['provenance'], 'provenance');
        // A new origin must be renderable, so its definition's profile has to be the one this runtime renders with.
        ProductionFreeGrantRenderProfile::requireCurrent($graph['payload']['profile']);
        ProductionFreeGrantRenderable::requirePayload($graph['payload']);
        (new ProductionFreeGrantPolicy)->requireApprovedTerms($policy, $graph['payload']['terms_hash']);
        (new ProductionFreeGrantDefinitions)->prove($policy, $graph['payload']['source'], $graph['payload']['assets']);

        return $graph;
    }

    /** @return non-empty-list<string> the current key's hash first, then one per previous key */
    private function requestKeyHashes(int $accountId, string $requestKey): array
    {
        return array_map(fn (string $key): string => hash_hmac('sha256', "production-free-request-v1\0".$accountId."\0".$requestKey, $key),
            ProductionFreeGrantRecords::keys());
    }
}
