<?php

namespace App\Domain\Services\Invoicing;

use App\Domain\Services\Invoicing\Models\ServiceInvoice;
use App\Domain\Services\Projects\ServiceProjectException;
use App\Domain\Services\Projects\ServiceProjectInput;
use App\Domain\Services\Projects\ServiceProjects;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Staff issue a Stripe test-mode invoice for a service project's authored deposit or balance (BL-70 phase 1).
 *
 * The amount is derived here from the retained, buyer-accepted quote (integer minor units, USD only); nothing numeric comes from the
 * caller. The command is three steps with no provider request inside a transaction: reserve a local row (idempotent on project,
 * kind, quote and revision), ask Stripe with idempotency keys derived from that identity, then record the provider ids. A crash or
 * provider failure leaves the row `creating`; repeating the command resumes it and cannot create a second invoice.
 * Issuing grants nothing: payment state moves only on a verified payment (ServiceInvoiceSettlement).
 */
final class ServiceInvoices
{
    public const KINDS = ['deposit', 'balance'];

    /** A deposit follows acceptance; the balance follows every milestone's approval (the staff's choice of timing, not a payment term). */
    public const ELIGIBLE = [
        'deposit' => ['accepted', 'in_progress', 'awaiting_customer_review', 'scope_reviewed'],
        'balance' => ['scope_reviewed'],
    ];

    public function issue(string $projectId, string $kind, int $daysUntilDue, ?User $actor): array
    {
        $policy = app(ServiceInvoicePolicy::class);
        $policy->requireEnabled();
        $actor = app(ServiceProjects::class)->staffActor($actor);
        try {
            ServiceProjectInput::key($projectId);
        } catch (ValidationException) {
            throw new ServiceInvoiceException(404);
        }
        if (! in_array($kind, self::KINDS, true) || $daysUntilDue < 1 || $daysUntilDue > 90) {
            throw new ServiceInvoiceException(422, 'Choose deposit or balance and a due period of 1 to 90 days.');
        }
        try {
            $reserved = app(ServiceProjects::class)->withStaffProject($projectId, $actor, fn (array $project, array $row, \Closure $read): array => $this->reserve($project, $row, $read, $kind, $daysUntilDue, $actor));
        } catch (ServiceProjectException $error) {
            throw new ServiceInvoiceException($error->status);
        }
        $invoice = $reserved['invoice'];
        if ($invoice->state !== 'creating') {
            return ['invoice' => $this->view($invoice), 'replayed' => true];
        }
        try {
            $result = app(StripeInvoiceGateway::class)->issue($reserved['spec']);
        } catch (RuntimeException) {
            throw new ServiceInvoiceException(503, 'Stripe did not confirm the invoice. Repeat the same command: it resumes without creating a second invoice.');
        }

        return ['invoice' => $this->view($this->record($invoice, $result)), 'replayed' => $reserved['replayed']];
    }

    /** Staff projection of a project's invoices, oldest first. The hosted URL is the page the buyer pays on. */
    public function forProject(string $projectPublicId, ?User $actor): array
    {
        app(ServiceProjects::class)->staffActor($actor);
        $project = DB::table('service_projects')->where('public_id', $projectPublicId)->value('id');

        return $project === null ? [] : ServiceInvoice::where('project_id', $project)->orderBy('id')->get()->map(fn (ServiceInvoice $invoice): array => $this->view($invoice))->all();
    }

    public function view(ServiceInvoice $invoice): array
    {
        return ['id' => $invoice->public_id, 'kind' => $invoice->kind, 'revision' => $invoice->revision, 'state' => $invoice->state,
            'amountMinor' => $invoice->amount_minor, 'currency' => $invoice->currency, 'daysUntilDue' => $invoice->days_until_due,
            'hostedInvoiceUrl' => $invoice->hosted_invoice_url, 'paymentFailures' => $invoice->payment_failures, 'testOnly' => true];
    }

    /** Runs inside the project transaction: eligibility, amount and the local row, all from retained facts. */
    private function reserve(array $project, array $row, \Closure $read, string $kind, int $daysUntilDue, User $actor): array
    {
        $quote = collect($project['quotes'])->firstWhere('id', $project['quoteId']);
        if (! $quote || $project['quoteHash'] === null || $quote['hash'] !== $project['quoteHash'] || ! in_array($project['status'], self::ELIGIBLE[$kind], true)) {
            throw new ServiceInvoiceException;
        }
        $amount = $kind === 'deposit' ? $quote['depositMinor'] : $quote['totalMinor'] - $quote['depositMinor'];
        if ($quote['currency'] !== 'USD' || ! is_int($amount) || $amount < 1) {
            throw new ServiceInvoiceException(422, 'This quote has no USD amount to invoice for that step.');
        }
        $existing = $read('service_invoices', ['project_id' => (int) $row['id'], 'kind' => $kind, 'quote_id' => $quote['id']]);
        $last = $existing === [] ? null : end($existing);
        if ($last !== null && $last['state'] !== 'void') {
            if ((int) $last['days_until_due'] !== $daysUntilDue || (int) $last['amount_minor'] !== $amount) {
                throw new ServiceInvoiceException;
            }
            $invoice = ServiceInvoice::findOrFail((int) $last['id']);
            $replayed = true;
        } else {
            $revision = $last === null ? 1 : (int) $last['revision'] + 1;
            $key = hash('sha256', implode('|', ['service-invoice-v1', $row['public_id'], $kind, $quote['id'], $revision]));
            $invoice = ServiceInvoice::create(['public_id' => (string) Str::uuid(), 'project_id' => (int) $row['id'], 'kind' => $kind,
                'quote_id' => $quote['id'], 'quote_hash' => $quote['hash'], 'revision' => $revision, 'amount_minor' => $amount, 'currency' => 'USD',
                'days_until_due' => $daysUntilDue, 'idempotency_key' => $key, 'state' => 'creating', 'created_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now()]);
            AuditEvent::recordAttributed('service_invoice.reserved', $invoice, ['kind' => $kind, 'revision' => $revision, 'amount_minor' => $amount, 'test_only' => true], $actor->id);
            $replayed = false;
        }
        $account = $read('customer_accounts', ['id' => (int) $row['customer_account_id']])[0] ?? null;
        $user = $account ? ($read('users', ['id' => (int) $account['user_id']])[0] ?? null) : null;
        if (! $user || ! is_string($user['email'])) {
            throw new ServiceInvoiceException(503);
        }
        $customer = null;
        foreach ($read('service_projects', ['customer_account_id' => (int) $row['customer_account_id']]) as $sibling) {
            foreach ($read('service_invoices', ['project_id' => (int) $sibling['id']]) as $prior) {
                $customer ??= $prior['stripe_customer_id'];
            }
        }

        return ['invoice' => $invoice, 'replayed' => $replayed, 'spec' => ['customer_id' => $customer, 'email' => $user['email'],
            'amount_minor' => $invoice->amount_minor, 'currency' => $invoice->currency, 'days_until_due' => $invoice->days_until_due,
            'description' => Str::limit(($kind === 'deposit' ? 'Deposit: ' : 'Balance: ').$quote['title'], 120, ''),
            'invoice_ref' => $invoice->public_id, 'project_ref' => $row['public_id'], 'key' => $invoice->idempotency_key]];
    }

    /** Records the provider ids once the returned invoice is proven to be this invoice, finalized and unpaid. */
    private function record(ServiceInvoice $invoice, array $result): ServiceInvoice
    {
        $provider = $result['invoice'] ?? null;
        $customerId = $result['customer_id'] ?? null;
        if (! is_array($provider) || ! is_string($customerId) || ! StripeSdkInvoiceGateway::customerId($customerId) || ! StripeSdkInvoiceGateway::invoiceId($provider['id'] ?? null)) {
            throw new ServiceInvoiceException(503);
        }

        return DB::transaction(function () use ($invoice, $provider, $customerId): ServiceInvoice {
            $current = ServiceInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($current->state !== 'creating') {
                // A concurrent repeat or the webhook adoption already recorded it; accept only the same provider invoice.
                if ($current->stripe_invoice_id !== $provider['id']) {
                    throw new ServiceInvoiceException;
                }

                return $current;
            }
            $bad = ServiceInvoiceEvidence::issuedMismatches($provider, $current, $customerId);
            if ($bad !== []) {
                throw new ServiceInvoiceException(503, 'Stripe returned an invoice that does not match the authored quote. Nothing was recorded; contact an administrator.');
            }
            $current->fill(['state' => 'open', 'stripe_customer_id' => $customerId, 'stripe_invoice_id' => $provider['id'],
                'hosted_invoice_url' => ServiceInvoiceEvidence::hostedUrl($provider), 'updated_at' => now()])->save();
            AuditEvent::recordAttributed('service_invoice.issued', $current, ['kind' => $current->kind, 'revision' => $current->revision, 'test_only' => true], null);

            return $current;
        }, 3);
    }
}
