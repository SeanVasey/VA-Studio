<?php

namespace App\Domain\Customers\DataRights;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerPrincipal;
use App\Domain\Customers\DataRights\Models\CustomerDataExport;
use App\Domain\Customers\DataRights\Models\CustomerDataRequest;
use App\Domain\Customers\DataRights\Models\CustomerDataRequestEvent;
use App\Domain\Customers\Models\CustomerAccount;
use App\Domain\Customers\Models\CustomerPurchaseClaim;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Throwable;

/**
 * Customer export and deletion requests (FP-082). Customers act only on their own account through the locked principal.
 * Staff resolve deletion requests; approval refuses until the retention schedule is configured and never deletes or
 * rewrites orders, contracts, grants, payment evidence, inquiries, consent or audit records.
 */
final class CustomerDataRights
{
    public const LIST_LIMIT = 20;

    /** Records a deletion keeps, with the retention class that governs each. */
    public const RETAINED = [
        'orders' => 'financial_records_years', 'license_grants' => 'financial_records_years',
        'grant_contracts' => 'financial_records_years', 'verified_payments' => 'financial_records_years',
        'customer_purchase_claims' => 'financial_records_years', 'delivery_records' => 'financial_records_years',
        'customer_consent_events' => 'consent_records_years', 'customer_suppression_records' => 'consent_records_years',
        'inquiries' => 'correspondence_records_years', 'audit_events' => 'security_records_years',
        'identity_challenges' => 'security_records_years', 'customer_data_requests' => 'security_records_years',
    ];

    /** @return array{requestId: string, downloadToken: string, expiresAt: string, sizeBytes: int} */
    public function requestExport(CustomerPrincipal $principal, User $actor): array
    {
        $policy = app(DataRightsPolicy::class);
        $policy->requireEnabled();
        $this->outside();
        [$ttl, $limit] = [$policy->exportTtlMinutes(), $policy->exportMaxBytes()];

        return DB::transaction(function () use ($principal, $actor, $ttl, $limit): array {
            [$user, $account] = $this->lockCustomer($principal, $actor);
            $at = $this->now();
            $this->expireDue($account->id, $at);
            if (CustomerDataRequest::where('customer_account_id', $account->id)->where('kind', 'export')->where('open_slot', 1)->lockForUpdate()->exists()) {
                throw new DataRightsException(409, 'export_pending');
            }
            $requestId = (string) Str::uuid();
            $document = app(CustomerDataExportDocument::class)->build($principal, $user, $account, $requestId, $at);
            $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if (strlen($json) > $limit) {
                throw new DataRightsException(413, 'export_too_large');
            }
            $token = bin2hex(random_bytes(32));
            $request = $this->open($account, 'export', $requestId, $at);
            $expires = $at->addMinutes($ttl);
            CustomerDataExport::create([
                'request_id' => $request->id, 'ciphertext' => Crypt::encryptString($json), 'content_hash' => hash('sha256', $json),
                'token_hash' => hash('sha256', $token), 'size_bytes' => strlen($json), 'expires_at' => $expires, 'created_at' => $at,
            ]);
            $this->event($request, 'export_ready', null, ['size_bytes' => strlen($json), 'expires_at' => $expires->toIso8601ZuluString(),
                'sections' => array_map('count', array_intersect_key($document, array_flip(['orders', 'licenseGrants', 'consent', 'inquiries'])))], $at);
            AuditEvent::recordAttributed('customer.data_export.ready', $request, ['request_id' => $requestId, 'size_bytes' => strlen($json)], $user->id);

            return ['requestId' => $requestId, 'downloadToken' => $token, 'expiresAt' => $expires->toIso8601ZuluString(), 'sizeBytes' => strlen($json)];
        }, 3);
    }

    /** Single use: the same signed-in customer and the one-time token; the ciphertext is purged in the same transaction. */
    public function download(CustomerPrincipal $principal, User $actor, string $requestId, #[SensitiveParameter] string $token): string
    {
        app(DataRightsPolicy::class)->requireEnabled();
        $this->outside();
        if (! $this->uuid($requestId) || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
            throw new DataRightsException(404, 'export_not_found');
        }
        $outcome = DB::transaction(function () use ($principal, $actor, $requestId, $token): array {
            [$user, $account] = $this->lockCustomer($principal, $actor);
            $request = CustomerDataRequest::where('public_id', $requestId)->where('customer_account_id', $account->id)
                ->where('kind', 'export')->lockForUpdate()->first();
            $export = $request === null ? null : CustomerDataExport::where('request_id', $request->id)->lockForUpdate()->first();
            if ($request === null || $export === null || ! hash_equals($request->public_id, $requestId)) {
                return ['status' => 404, 'reason' => 'export_not_found'];
            }
            if (! hash_equals($export->token_hash, hash('sha256', $token))) {
                return ['status' => 404, 'reason' => 'export_not_found'];
            }
            $at = $this->now();
            if ($request->state !== 'ready' || $export->purged_at !== null) {
                return ['status' => 410, 'reason' => 'export_unavailable'];
            }
            if ($at->greaterThanOrEqualTo($export->expires_at)) {
                $this->expire($request, $export, $at);

                return ['status' => 410, 'reason' => 'export_expired'];
            }
            try {
                $json = Crypt::decryptString($export->getRawOriginal('ciphertext'));
            } catch (Throwable) {
                return ['status' => 503, 'reason' => 'export_unreadable'];
            }
            if (strlen($json) !== $export->size_bytes || ! hash_equals($export->content_hash, hash('sha256', $json))) {
                return ['status' => 503, 'reason' => 'export_unreadable'];
            }
            $this->resolve($request, 'downloaded', $at);
            $export->forceFill(['ciphertext' => null, 'consumed_at' => $at, 'purged_at' => $at])->save();
            $this->event($request, 'export_downloaded', null, ['content_hash' => $export->content_hash], $at);
            AuditEvent::recordAttributed('customer.data_export.downloaded', $request, ['request_id' => $request->public_id], $user->id);

            return ['status' => 200, 'json' => $json];
        }, 3);
        if ($outcome['status'] !== 200) {
            throw new DataRightsException($outcome['status'], $outcome['reason']);
        }

        return $outcome['json'];
    }

    /** One open deletion request per account; a repeat returns the open request instead of creating another. */
    public function requestDeletion(CustomerPrincipal $principal, User $actor): array
    {
        app(DataRightsPolicy::class)->requireEnabled();
        $this->outside();

        return DB::transaction(function () use ($principal, $actor): array {
            [$user, $account] = $this->lockCustomer($principal, $actor);
            $open = CustomerDataRequest::where('customer_account_id', $account->id)->where('kind', 'deletion')->where('open_slot', 1)->lockForUpdate()->first();
            if ($open !== null) {
                return ['requestId' => $open->public_id, 'state' => $open->state, 'replayed' => true];
            }
            $at = $this->now();
            $request = $this->open($account, 'deletion', (string) Str::uuid(), $at);
            AuditEvent::recordAttributed('customer.data_deletion.requested', $request, ['request_id' => $request->public_id], $user->id);

            return ['requestId' => $request->public_id, 'state' => $request->state, 'replayed' => false];
        }, 3);
    }

    /** The requester's own requests, newest first; an unexpired-looking export past its time reads as expired. */
    public function list(CustomerPrincipal $principal, User $actor): array
    {
        app(DataRightsPolicy::class)->requireEnabled();
        $this->outside();

        return DB::transaction(function () use ($principal, $actor): array {
            [, $account] = $this->lockCustomer($principal, $actor);
            $at = $this->now();
            $rows = CustomerDataRequest::where('customer_account_id', $account->id)->orderByDesc('id')->limit(self::LIST_LIMIT)->get();
            $exports = CustomerDataExport::whereIn('request_id', $rows->pluck('id'))->get(['request_id', 'expires_at'])->keyBy('request_id');

            return ['dataRequestsSchema' => 1, 'limit' => self::LIST_LIMIT, 'requests' => $rows->map(function (CustomerDataRequest $row) use ($exports, $at): array {
                $expires = $exports->get($row->id)?->expires_at;
                $state = $row->state === 'ready' && $expires !== null && $at->greaterThanOrEqualTo($expires) ? 'expired' : $row->state;

                return ['requestId' => $row->public_id, 'kind' => $row->kind, 'state' => $state,
                    'createdAt' => $row->created_at->toIso8601ZuluString(), 'resolvedAt' => $row->resolved_at?->toIso8601ZuluString(),
                    'expiresAt' => $state === 'ready' ? $expires?->toIso8601ZuluString() : null];
            })->all()];
        });
    }

    /**
     * Staff approval of a deletion request. Refuses while any retention value is unset. Withdraws customer access, erases
     * the listening library and active sessions, pseudonymizes the customer's sign-in profile, and records every retained
     * record class with its retention reason. Immutable records are left exactly as they are.
     */
    public function approveDeletion(string $requestId, User $actor, int $expectedVersion): array
    {
        $policy = app(DataRightsPolicy::class);
        $policy->requireEnabled();
        $retention = $policy->retention();
        $this->outside();

        return DB::transaction(function () use ($policy, $retention, $requestId, $actor, $expectedVersion): array {
            $staff = $policy->staff($actor);
            [$request, $user, $account] = $this->lockForStaff($requestId, $expectedVersion);
            if ($user->is_admin) {
                // A staff account is never erased through the customer workflow.
                throw new DataRightsException(409, 'staff_account');
            }
            $at = $this->now();
            $actions = ['customer_access' => 'already_withdrawn', 'listening_library' => 'none', 'sessions' => 'none', 'sign_in_profile' => 'pseudonymized'];
            if ($account->active) {
                $account->forceFill(['active' => false, 'access_version' => $account->access_version + 1, 'updated_at' => $at])->save();
                $actions['customer_access'] = 'withdrawn';
            }
            if (DB::table('customer_saved_tracks')->where('customer_account_id', $account->id)->delete() > 0) {
                $actions['listening_library'] = 'erased';
            }
            if (Schema::hasColumn('sessions', 'user_id') && DB::table('sessions')->where('user_id', $user->id)->delete() > 0) {
                $actions['sessions'] = 'ended';
            }
            DB::table('users')->where('id', $user->id)->update([
                'name' => 'Erased customer', 'email' => 'erased+'.$account->public_id.'@erased.invalid',
                'password' => Hash::make(bin2hex(random_bytes(32))), 'remember_token' => null, 'email_verified_at' => null,
                'updated_at' => $at,
            ]);
            $detail = ['schema' => 1, 'request_id' => $request->public_id, 'account_id' => $account->public_id,
                'retention' => $retention, 'actions' => $actions, 'retained' => $this->retained($account)];
            $hash = CanonicalJson::hash($detail);
            $this->resolve($request, 'completed', $at, $staff, $hash);
            $this->event($request, 'deletion_completed', $staff, $detail, $at);
            AuditEvent::recordAttributed('customer.data_deletion.completed', $request, ['request_id' => $request->public_id,
                'resolution_hash' => $hash, 'retention_policy_reference' => $retention['policy_reference']], $staff->id);

            return ['requestId' => $request->public_id, 'state' => 'completed', 'resolutionHash' => $hash, 'actions' => $actions];
        }, 3);
    }

    /** Staff rejection with a short plain-text reason. No customer data changes. */
    public function rejectDeletion(string $requestId, User $actor, int $expectedVersion, string $reason): array
    {
        $policy = app(DataRightsPolicy::class);
        $policy->requireEnabled();
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500 || ! mb_check_encoding($reason, 'UTF-8') || preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $reason) === 1) {
            throw ValidationException::withMessages(['reason' => 'Give a plain-text reason of at most 500 characters.']);
        }
        $this->outside();

        return DB::transaction(function () use ($policy, $requestId, $actor, $expectedVersion, $reason): array {
            $staff = $policy->staff($actor);
            [$request] = $this->lockForStaff($requestId, $expectedVersion);
            $at = $this->now();
            $detail = ['schema' => 1, 'request_id' => $request->public_id, 'reason' => $reason];
            $hash = CanonicalJson::hash($detail);
            $this->resolve($request, 'rejected', $at, $staff, $hash);
            $this->event($request, 'deletion_rejected', $staff, $detail, $at);
            AuditEvent::recordAttributed('customer.data_deletion.rejected', $request, ['request_id' => $request->public_id, 'resolution_hash' => $hash], $staff->id);

            return ['requestId' => $request->public_id, 'state' => 'rejected', 'resolutionHash' => $hash];
        }, 3);
    }

    /** Purges every expired, still-open export. Operator command; also applied lazily before an account's next export. */
    public function pruneExpired(int $limit = 100): int
    {
        $this->outside();
        $count = 0;
        $ids = CustomerDataExport::whereNull('purged_at')->where('expires_at', '<=', $this->now())->orderBy('id')->limit(max(1, min($limit, 500)))->pluck('request_id');
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id): int {
                $request = CustomerDataRequest::whereKey($id)->lockForUpdate()->first();
                $export = CustomerDataExport::where('request_id', $id)->lockForUpdate()->first();
                $at = $this->now();
                if ($request === null || $export === null || $export->purged_at !== null || $at->lessThan($export->expires_at)) {
                    return 0;
                }
                $this->expire($request, $export, $at);

                return 1;
            });
        }

        return $count;
    }

    private function expireDue(int $accountId, CarbonImmutable $at): void
    {
        $requests = CustomerDataRequest::where('customer_account_id', $accountId)->where('kind', 'export')->where('open_slot', 1)->lockForUpdate()->get();
        foreach ($requests as $request) {
            $export = CustomerDataExport::where('request_id', $request->id)->lockForUpdate()->first();
            if ($export !== null && $export->purged_at === null && $at->greaterThanOrEqualTo($export->expires_at)) {
                $this->expire($request, $export, $at);
            }
        }
    }

    private function expire(CustomerDataRequest $request, CustomerDataExport $export, CarbonImmutable $at): void
    {
        if ($request->state === 'ready') {
            $this->resolve($request, 'expired', $at);
        }
        $export->forceFill(['ciphertext' => null, 'purged_at' => $at])->save();
        $this->event($request, 'export_expired', null, ['expires_at' => $export->expires_at->toIso8601ZuluString()], $at);
    }

    private function open(CustomerAccount $account, string $kind, string $publicId, CarbonImmutable $at): CustomerDataRequest
    {
        $request = CustomerDataRequest::create(['public_id' => $publicId, 'customer_account_id' => $account->id, 'kind' => $kind,
            'state' => $kind === 'export' ? 'ready' : 'received', 'open_slot' => 1, 'version' => 0, 'created_at' => $at, 'updated_at' => $at]);
        $this->event($request, 'requested', null, ['kind' => $kind], $at);

        return $request;
    }

    private function resolve(CustomerDataRequest $request, string $state, CarbonImmutable $at, ?User $staff = null, ?string $hash = null): void
    {
        $request->forceFill(['state' => $state, 'open_slot' => null, 'version' => $request->version + 1, 'updated_at' => $at,
            'resolved_at' => $at, 'resolved_by' => $staff?->id, 'resolution_hash' => $hash])->save();
    }

    private function event(CustomerDataRequest $request, string $event, ?User $actor, array $detail, CarbonImmutable $at): void
    {
        $sequence = (int) CustomerDataRequestEvent::where('request_id', $request->id)->max('sequence') + 1;
        $encoded = CanonicalJson::encode($detail);
        CustomerDataRequestEvent::create(['request_id' => $request->id, 'sequence' => $sequence, 'event' => $event,
            'actor_user_id' => $actor?->id, 'detail' => $encoded, 'detail_hash' => hash('sha256', $encoded), 'created_at' => $at]);
    }

    /** @return array{0: User, 1: CustomerAccount} user then account, the order every customer command uses */
    private function lockCustomer(CustomerPrincipal $principal, User $actor): array
    {
        app(CustomerAccess::class)->lock($principal, $principal->ownerKey, $actor);
        $user = User::whereKey($principal->userId)->lockForUpdate()->firstOrFail();
        $account = CustomerAccount::whereKey($principal->accountId)->lockForUpdate()->firstOrFail();

        return [$user, $account];
    }

    /** @return array{0: CustomerDataRequest, 1: User, 2: CustomerAccount} */
    private function lockForStaff(string $requestId, int $expectedVersion): array
    {
        if (! $this->uuid($requestId)) {
            throw new DataRightsException(404, 'request_not_found');
        }
        $hint = CustomerDataRequest::where('public_id', $requestId)->first(['id', 'customer_account_id']);
        $hintAccount = $hint === null ? null : CustomerAccount::find($hint->customer_account_id, ['id', 'user_id']);
        if ($hint === null || $hintAccount === null) {
            throw new DataRightsException(404, 'request_not_found');
        }
        // Customer user -> account -> request: the same order as the customer's own commands.
        $user = User::whereKey($hintAccount->user_id)->lockForUpdate()->first();
        $account = CustomerAccount::whereKey($hintAccount->id)->lockForUpdate()->first();
        $request = CustomerDataRequest::whereKey($hint->id)->lockForUpdate()->first();
        if ($user === null || $account === null || $request === null || $account->user_id !== $user->id
            || $request->customer_account_id !== $account->id || $request->kind !== 'deletion') {
            throw new DataRightsException(404, 'request_not_found');
        }
        if ($request->state !== 'received' || $request->open_slot !== 1 || $request->version !== $expectedVersion) {
            throw new DataRightsException(409, 'request_changed');
        }

        return [$request, $user, $account];
    }

    private function retained(CustomerAccount $account): array
    {
        $orders = DB::table('orders')->where('owner_key', $account->owner_key)
            ->orWhereIn('id', CustomerPurchaseClaim::where('account_id', $account->id)->select('order_id'))->pluck('id')->all();
        $counts = [
            'orders' => count($orders),
            'license_grants' => $orders === [] ? 0 : DB::table('license_grants as g')->join('order_finalizations as f', 'f.id', '=', 'g.order_finalization_id')->whereIn('f.order_id', $orders)->count(),
            'customer_purchase_claims' => DB::table('customer_purchase_claims')->where('account_id', $account->id)->count(),
            'customer_consent_events' => DB::table('customer_consent_events')->where('customer_account_id', $account->id)->count(),
            'inquiries' => $orders === [] ? 0 : DB::table('inquiry_order_contexts')->whereIn('order_id', $orders)->count(),
        ];
        $retained = [];
        foreach (self::RETAINED as $record => $class) {
            $retained[] = ['record' => $record, 'retention' => $class, 'reason' => 'immutable_record_retained'] + (array_key_exists($record, $counts) ? ['count' => $counts[$record]] : []);
        }

        return $retained;
    }

    private function now(): CarbonImmutable
    {
        return now()->toImmutable()->utc()->startOfSecond();
    }

    private function uuid(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $value) === 1;
    }

    private function outside(): void
    {
        foreach (DB::getConnections() as $connection) {
            if ($connection->transactionLevel() !== 0) {
                throw new DataRightsException(503, 'outer_transaction');
            }
        }
    }
}
