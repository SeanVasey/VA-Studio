<?php

namespace App\Domain\Delivery;

use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\PendingEntitlement;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Contracts\Models\ContractRenderRequest;
use App\Domain\Contracts\Models\GrantContract;
use App\Domain\Contracts\ReadGrantContract;
use App\Domain\Delivery\Models\TestFulfillmentActivation;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/** Acyclic retained-graph reconstruction. No private file bytes or current catalogue are consulted. */
final class ActivationEvidence
{
    public const MAX_BYTES = 2097152;
    public const MAX_CIPHERTEXT_BYTES = 4194304;

    public function source(Order $order, ?string $account = null): array
    {
        try {
            $payment = VerifiedPayment::where('order_id', $order->id)->first();
            if ($account !== null && $payment && ($payment->account_id !== $account || $payment->mode !== 'test')) {
                throw new DeliveryException('unavailable');
            }
            $original = app(ReadOrder::class)->verify($order);
            $finalization = OrderFinalization::where('order_id', $order->id)->first();
            if (! $finalization || $finalization->outcome !== 'paid') { throw new DeliveryException('ineligible'); }
            if (! $payment || $payment->mode !== 'test' || $finalization->mode !== 'test'
                || $finalization->verified_payment_id !== $payment->id) { throw new DeliveryException('changed'); }
            $grants = LicenseGrant::where('order_finalization_id', $finalization->id)->get()->keyBy('order_line_id');
            $references = $order->lines()->orderBy('position')->get();
            if ($references->isEmpty() || $references->count() !== $grants->count()) { throw new DeliveryException('changed'); }
            $documents = []; $requests = []; $members = [];
            foreach ($references as $line) {
                $grant = $grants->get($line->id);
                if (! $grant) { throw new DeliveryException('changed'); }
                $request = ContractRenderRequest::where('license_grant_id', $grant->id)->first();
                if (! $request) {
                    if (GrantContract::where('license_grant_id', $grant->id)->exists()) { throw new DeliveryException('changed'); }
                    throw new DeliveryException('pending_contracts');
                }
                $document = app(ReadGrantContract::class)->forRequest($request, $account);
                if (! $document) { throw new DeliveryException('pending_contracts'); }
                $requests[] = $request; $documents[] = $document;
                $work = $request->work()->sole();
                if ($work->state !== 'completed' || ! $work->updated_at->equalTo($document->issued_at)) { throw new DeliveryException('changed'); }
                $entitlements = PendingEntitlement::where('license_grant_id', $grant->id)->orderBy('role')->orderBy('media_asset_id')->get();
                $manifest = $original['lines'][$line->position]['selection']['offer_snapshot']['assets'];
                if ($entitlements->isEmpty() || $entitlements->count() !== count($manifest)) { throw new DeliveryException('changed'); }
                foreach ($manifest as $entry) {
                    $matches = $entitlements->where('media_asset_id', $entry['id'])->where('role', $entry['role']);
                    if ($matches->count() !== 1) { throw new DeliveryException('changed'); }
                    $retained = $matches->sole();
                    if ($retained->license_grant_id !== $grant->id || $retained->state !== 'pending'
                        || $retained->asset_hash !== $entry['sha256'] || $retained->size_bytes !== $entry['size_bytes']
                        || ! $retained->created_at->equalTo($finalization->finalized_at)) { throw new DeliveryException('changed'); }
                }
                $members[] = ['position' => $line->position, 'grant_id' => $grant->id, 'grant_public_id' => $grant->public_id,
                    'order_line_id' => $line->id, 'render_input_hash' => $grant->render_input_hash,
                    'request' => ['id' => $request->id, 'public_id' => $request->public_id, 'fulfillment_outbox_id' => $request->fulfillment_outbox_id,
                        'input_hash' => $request->input_hash, 'profile_hash' => $request->profile_hash,
                        'canonicalization_version' => $request->canonicalization_version, 'created_at' => $request->created_at->toIso8601ZuluString()],
                    'work' => ['id' => $work->id, 'state' => $work->state, 'attempts' => $work->attempts,
                        'created_at' => $work->created_at->toIso8601ZuluString(), 'updated_at' => $work->updated_at->toIso8601ZuluString()],
                    'original' => ['id' => $document->id, 'public_id' => $document->public_id, 'claim_token' => $document->claim_token,
                        'input_hash' => $document->input_hash, 'profile_hash' => $document->profile_hash, 'disk' => $document->disk,
                        'storage_path' => $document->storage_path, 'pdf_hash' => $document->pdf_hash,
                        'size_bytes' => $document->size_bytes, 'page_count' => $document->page_count,
                        'issued_at' => $document->issued_at->toIso8601ZuluString()],
                    'entitlements' => $entitlements->map(fn ($entry) => ['id' => $entry->id, 'media_asset_id' => $entry->media_asset_id,
                        'role' => $entry->role, 'asset_hash' => $entry->asset_hash, 'size_bytes' => $entry->size_bytes,
                        'state' => $entry->state, 'created_at' => $entry->created_at->toIso8601ZuluString()])->all()];
            }
            $assets = app(DeliveryAssets::class)->inspect($original);
            $snapshot = ['order_id' => $order->public_id, 'order_payload_hash' => $order->payload_hash,
                'finalization_id' => $finalization->public_id, 'finalization_evidence_hash' => $finalization->evidence_hash,
                'verified_payment_id' => $payment->id, 'payment_evidence_hash' => $payment->evidence_hash,
                'account_id' => $payment->account_id, 'members' => $members, 'assets' => $assets];
            if (strlen(CanonicalJson::encode($snapshot)) > self::MAX_BYTES) { throw new DeliveryException('changed'); }

            return compact('order', 'original', 'finalization', 'payment', 'grants', 'requests', 'documents', 'assets', 'snapshot');
        } catch (DeliveryException|QueryException $error) { throw $error; }
        catch (Throwable) { throw new DeliveryException('changed'); }
    }

    public function capture(array $source, string $publicId, array $policy, CarbonImmutable $from,
        CarbonImmutable $through, CarbonImmutable $at): array
    {
        ActivationPolicy::validate($policy);
        if (! OrderRequest::uuid($publicId) || ! $this->window($source, $from, $through, $at)) { throw new DeliveryException('changed'); }
        return ['schema_version' => 1, 'purpose' => 'test_fulfillment_activation', 'mode' => 'test',
            'activation_id' => $publicId, 'policy' => $policy, 'snapshot' => $source['snapshot'],
            'verified_from' => $from->toIso8601ZuluString(), 'verified_through' => $through->toIso8601ZuluString(),
            'activated_at' => $at->toIso8601ZuluString()];
    }

    public function window(array $source, CarbonImmutable $from, CarbonImmutable $through, CarbonImmutable $at): bool
    {
        if ($from->micro !== 0 || $through->micro !== 0 || $at->micro !== 0
            || $from->lessThan($source['finalization']->finalized_at) || $through->lessThan($from)
            || $at->lessThan($through) || $at->greaterThan($from->addSeconds(300))) { return false; }
        foreach ($source['documents'] as $document) {
            if ($from->lessThan($document->issued_at)) { return false; }
        }
        return true;
    }

    public function encrypt(array $payload): array
    {
        $canonical = CanonicalJson::encode($payload);
        if (strlen($canonical) > self::MAX_BYTES) { throw new DeliveryException('changed'); }
        $ciphertext = Crypt::encryptString($canonical);
        if (strlen($ciphertext) > self::MAX_CIPHERTEXT_BYTES) { throw new DeliveryException('changed'); }
        return [$ciphertext, hash('sha256', $ciphertext)];
    }

    public function verify(TestFulfillmentActivation $activation, array $source): array
    {
        try {
            if ($activation->canonicalization_version !== CanonicalJson::VERSION
                || strlen($activation->evidence_ciphertext) > self::MAX_CIPHERTEXT_BYTES
                || ! hash_equals($activation->evidence_hash, hash('sha256', $activation->evidence_ciphertext))) {
                throw new DeliveryException('changed');
            }
            $canonical = Crypt::decryptString($activation->evidence_ciphertext);
            if (strlen($canonical) > self::MAX_BYTES) { throw new DeliveryException('changed'); }
            $decoded = json_decode($canonical, true, 128, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || CanonicalJson::encode($decoded) !== $canonical
                || $activation->order_id !== $source['order']->id || $activation->order_finalization_id !== $source['finalization']->id
                || $activation->policy_version !== ActivationPolicy::CONTRACT['version']) { throw new DeliveryException('changed'); }
            $expected = $this->capture($source, $activation->public_id, ActivationPolicy::validate($decoded['policy'] ?? []),
                $activation->verified_from, $activation->verified_through, $activation->activated_at);
            if (CanonicalJson::encode($expected) !== $canonical) { throw new DeliveryException('changed'); }
            return $decoded;
        } catch (Throwable) { throw new DeliveryException('changed'); }
    }
}
