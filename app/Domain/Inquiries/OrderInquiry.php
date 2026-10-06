<?php

namespace App\Domain\Inquiries;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\QuoteException;
use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\CustomerPrincipal;
use App\Domain\Customers\PurchaseAccess;
use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\Inquiries\Models\InquiryOrderContext;
use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\SiteContent;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use LogicException;
use SensitiveParameter;

/** A private original-session inquiry can retain one explicitly selected test-order reference. */
final class OrderInquiry
{
    public function setup(string $id, #[SensitiveParameter] string $owner, ?CustomerPrincipal $principal = null, ?User $actor = null): array
    {
        $this->outside();

        return DB::transaction(function () use ($id, $owner, $principal, $actor): array {
            $setup = $this->intake();
            $order = $this->admitOrder($id, $owner, $principal, $actor);
            $this->intake();

            return ['orderInquirySchema' => 1, 'orderId' => $order->public_id, 'testOnly' => true,
                'privacyNotice' => $setup['privacyNotice'], 'noticeToken' => $setup['noticeToken']];
        });
    }

    public function submit(string $id, #[SensitiveParameter] string $owner, string $inquiryOwner, array $body,
        ?CustomerPrincipal $principal = null, ?User $actor = null): array
    {
        $this->outside();
        $body = InquiryInput::validate($body);
        $this->ownerHash($inquiryOwner);

        return DB::transaction(function () use ($id, $owner, $inquiryOwner, $body, $principal, $actor): array {
            // Existing intake uses publication -> responsible operator. Customer commands then
            // fence customer user -> account -> order. No participating customer writer needs publication.
            $this->intake();
            $order = $this->admitOrder($id, $owner, $principal, $actor);
            $existing = CustomerInquiry::where('request_key', $body['requestKey'])->lockForUpdate()->first();
            if ($existing !== null) {
                $context = InquiryOrderContext::where('inquiry_id', $existing->id)->lockForUpdate()->first();
                if (! $context || $existing->request_key !== $body['requestKey'] || $existing->owner_hash !== $inquiryOwner
                    || $existing->payload_hash !== CanonicalJson::hash(array_diff_key($body, ['requestKey' => true]))
                    || $context->order_id !== $order->id) {
                    throw new InquiryException(409);
                }
                $this->context($existing, $context);
                $result = ['state' => 'saved', 'receipt' => $existing->public_id, 'replayed' => true];
            } else {
                // Reuse the exact existing notice admission, encrypted payload, hash and request-key contract.
                $result = app(SubmitInquiry::class)->handle($body, $inquiryOwner);
                $inquiry = CustomerInquiry::where('public_id', $result['receipt'])->lockForUpdate()->sole();
                $context = InquiryOrderContext::create([
                    'inquiry_id' => $inquiry->id, 'order_id' => $order->id, 'schema_version' => 1,
                    'order_hash' => $order->payload_hash, 'inquiry_hash' => $inquiry->payload_hash,
                    'context_hash' => self::bindingHash($inquiry, $order), 'created_at' => $inquiry->created_at,
                ]);
                AuditEvent::recordAttributed('inquiry.test_order_linked', $inquiry,
                    ['order_public_id' => $order->public_id, 'context_hash' => $context->context_hash, 'test_only' => true], $principal?->userId);
                $this->context($inquiry, $context);
            }
            // Audit observers cannot bypass configuration, current credential or authority withdrawal.
            $this->intake();
            $this->admitOrder($id, $owner, $principal, $actor);

            return $result;
        });
    }

    public function ownerContext(string $receipt, string $owner): array
    {
        $this->readable();
        $this->ownerHash($owner);
        if (! OrderRequest::uuid($receipt)) {
            throw new InquiryException(404);
        }

        return DB::transaction(function () use ($receipt, $owner): array {
            $inquiry = CustomerInquiry::where('public_id', $receipt)->lockForUpdate()->first();
            if (! $inquiry || $inquiry->public_id !== $receipt || ! hash_equals($inquiry->owner_hash, $owner)) {
                throw new InquiryException(404);
            }

            return $this->context($inquiry);
        });
    }

    public function staffContext(int $inquiryId, User $actor): array
    {
        return DB::transaction(function () use ($inquiryId, $actor): array {
            $actor = app(InquiryAdministration::class)->actor($actor, lockForUpdate: true);
            $inquiry = CustomerInquiry::lockForUpdate()->findOrFail($inquiryId);
            $context = $this->context($inquiry);
            if ($context['order'] !== null) {
                AuditEvent::recordAttributed('inquiry.order_context_viewed', $inquiry,
                    ['order_public_id' => $context['order']['id']], $actor->id);
            }
            app(InquiryAdministration::class)->actor($actor, lockForUpdate: true);

            return $context;
        });
    }

    private function intake(): array
    {
        $this->readable();
        if (config('inquiries.test_order_inquiries_enabled') !== true) {
            throw new InquiryException(404);
        }
        SitePublication::lockForUpdate()->find(1);
        $setup = app(InquiryPolicy::class)->publicSetup(app(SiteContent::class)->current(), lockForUpdate: true);
        if ($setup === null) {
            throw new InquiryException(404);
        }

        return $setup;
    }

    private function admitOrder(string $id, string $owner, ?CustomerPrincipal $principal, ?User $actor): Order
    {
        try {
            if (! OrderRequest::uuid($id) || preg_match('/\A[a-f0-9]{64}\z/D', $owner) !== 1) {
                throw new InquiryException(404);
            }
            app(CustomerAccess::class)->lock($principal, $owner, $actor);
            $order = Order::where('public_id', $id)->lockForUpdate()->first();
            if (! $order || $order->public_id !== $id) {
                throw new InquiryException(404);
            }
            app(PurchaseAccess::class)->assertOrder($order, $owner, $principal);
            app(ReadOrder::class)->verify($order);
            app(CustomerAccess::class)->lock($principal, $owner, $actor);
            app(PurchaseAccess::class)->assertOrder($order, $owner, $principal);

            return $order;
        } catch (CustomerAccessException) {
            throw new InquiryException(404);
        } catch (QuoteException $error) {
            throw new InquiryException($error->status === 404 ? 404 : 503);
        }
    }

    private function context(CustomerInquiry $inquiry, ?InquiryOrderContext $context = null): array
    {
        $context ??= InquiryOrderContext::where('inquiry_id', $inquiry->id)->lockForUpdate()->first();
        if ($context === null) {
            return ['orderInquiryContextSchema' => 1, 'order' => null];
        }
        // The retained reference grants no current order access, status projection or recovery.
        $order = Order::find($context->order_id);
        if (! $order || $context->schema_version !== 1 || $context->inquiry_id !== $inquiry->id
            || $context->order_hash !== $order->payload_hash || $context->inquiry_hash !== $inquiry->payload_hash
            || ! hash_equals($context->context_hash, self::bindingHash($inquiry, $order))
            || ! $context->created_at->equalTo($inquiry->created_at)) {
            throw new InquiryException(503);
        }
        try {
            app(ReadOrder::class)->verify($order);
        } catch (QuoteException) {
            throw new InquiryException(503);
        }

        return ['orderInquiryContextSchema' => 1, 'order' => ['id' => $order->public_id, 'testOnly' => true]];
    }

    public static function bindingHash(CustomerInquiry $inquiry, Order $order): string
    {
        return CanonicalJson::hash(['schema_version' => 1, 'purpose' => 'test_order_inquiry',
            'inquiry_id' => $inquiry->id, 'receipt' => $inquiry->public_id, 'inquiry_hash' => $inquiry->payload_hash,
            'order_id' => $order->id, 'order_public_id' => $order->public_id, 'order_hash' => $order->payload_hash]);
    }

    private function ownerHash(string $owner): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $owner) !== 1) {
            throw new InquiryException(404);
        }
    }

    private function readable(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new InquiryException(404);
        }
    }

    private function outside(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Order inquiry admission requires its own current transaction.');
        }
    }
}
