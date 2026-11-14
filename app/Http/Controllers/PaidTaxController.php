<?php

namespace App\Http\Controllers;

use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Domain\Grants\PaidTax\PaidTaxDocuments;
use App\Domain\Grants\PaidTax\PaidTaxDownloads;
use App\Domain\Grants\PaidTax\PaidTaxException;
use App\Domain\Grants\PaidTax\PaidTaxGrants;
use App\Domain\Grants\PaidTax\PaidTaxInput;
use App\Domain\Grants\PaidTax\PaidTaxJson;
use App\Domain\Grants\PaidTax\PaidTaxPolicy;
use App\Domain\Grants\PaidTax\PaidTaxReads;
use App\Http\Middleware\PaidTaxPrivacy;
use App\Http\Tax\PaidTaxRequestContext;
use App\Http\Tax\PaidTaxResponseOwner;
use App\Http\Tax\PaidTaxStreamResponse;
use App\Http\Tax\TaxHttpMount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class PaidTaxController
{
    public function finalize(string $order, Request $request): Response
    {
        return $this->projection($request, function (array $identity, PaidTaxResponseOwner $owner) use ($request, $order): array {
            PaidTaxInput::keys($this->body($request), []);

            return ['origin' => (new PaidTaxGrants)->finalize(...[...$identity, $order, $owner->read()])];
        });
    }

    /**
     * Page shell only. Its path is deliberately outside the JSON-forced private prefix, so no PaidTaxPrivacy or root
     * render exception is needed: it carries no locator, declaration, term, capability or token in Inertia page/history
     * props, and the browser reads every private value from the JSON endpoints, which enforce their own closures.
     */
    public function page(Request $request): Response
    {
        try {
            // The root privacy seam does not cover this path, so a stale cached route table is refused here, before
            // policy, identity or SQL work.
            PaidTaxException::require(TaxHttpMount::admitted('paid-tax-grants.http_mounted'), 404);
            PaidTaxException::require(in_array($request->getRealMethod(), ['GET', 'HEAD'], true) && $request->query->count() === 0
                && $request->server->get('QUERY_STRING', '') === '' && $request->getContent() === '', 422);
            $this->identity($request);

            return PaidTaxPrivacy::protect(Inertia::render('PaidTaxLicenses')->toResponse($request));
        } catch (Throwable $error) {
            return PaidTaxPrivacy::refusal($error);
        }
    }

    public function index(Request $request): Response
    {
        return $this->projection($request, fn (array $identity, PaidTaxResponseOwner $owner): array => (new PaidTaxReads)->index(...[...$identity, $owner->read()]));
    }

    public function show(string $batch, Request $request): Response
    {
        return $this->projection($request, fn (array $identity, PaidTaxResponseOwner $owner): array => ['origin' => (new PaidTaxReads)->show($batch, ...[...$identity, $owner->read()])]);
    }

    public function document(string $batch, Request $request): Response
    {
        return $this->projection($request, function (array $identity, PaidTaxResponseOwner $owner) use ($request, $batch): array {
            PaidTaxInput::keys($this->body($request), []);
            $busy = false;
            $origin = (new PaidTaxDocuments)->prepare($batch, $identity[0], $identity[1], $owner->read(), $busy);

            return ['origin' => $origin, 'busy' => $busy];
        });
    }

    public function downloads(string $batch, Request $request): Response
    {
        return $this->projection($request, fn (array $identity, PaidTaxResponseOwner $owner): array => ['status' => (new PaidTaxDownloads)->status($batch, ...[...$identity, $owner->read()])]);
    }

    public function authorize(string $batch, string $line, Request $request): Response
    {
        return $this->projection($request, fn (array $identity, PaidTaxResponseOwner $owner): array => ['authorization' => (new PaidTaxDownloads)->authorize($batch, $line, $this->body($request), ...[...$identity, $owner->read()])]);
    }

    public function redeem(string $batch, string $authorization, Request $request): Response
    {
        $owner = new PaidTaxResponseOwner;
        try {
            $budget = PaidTaxRequestContext::claim($request);
            [$principal, $actor] = $this->identity($request);
            $budget->initialDeadline()->proveCurrent();
            $input = $this->body($request);
            PaidTaxInput::keys($input, ['token']);
            PaidTaxException::require(is_string($input['token']), 403);
            $transfer = (new PaidTaxDownloads)->redeem($batch, $authorization, $input['token'], $principal, $actor, $budget->receivedAt, $budget);
            $owner->own($transfer);

            return new PaidTaxStreamResponse($owner, static function () use ($owner): void {
                try {
                    $owner->writeTransfer();
                } catch (Throwable $error) {
                    if (! $error instanceof PaidTaxException) {
                        PaidTaxPrivacy::report($error);
                    }
                } finally {
                    $owner->close();
                }
            }, ['Content-Type' => $transfer->mimeType, 'Content-Length' => (string) $transfer->sizeBytes,
                'Content-Disposition' => 'attachment; filename="'.$transfer->filename.'"', 'X-Paid-Tax-Sha256' => $transfer->sha256]);
        } catch (Throwable $error) {
            $owner->close();

            return PaidTaxPrivacy::refusal($error);
        }
    }

    private function projection(Request $request, callable $operation): Response
    {
        $owner = new PaidTaxResponseOwner;
        try {
            return PaidTaxStreamResponse::projection($operation($this->identity($request), $owner), $owner);
        } catch (Throwable $error) {
            $owner->close();

            return PaidTaxPrivacy::refusal($error);
        }
    }

    private function identity(Request $request): array
    {
        app(PaidTaxPolicy::class)->capture();
        $principal = app(ProductionCustomerSessions::class)->principal($request);
        $actor = Auth::guard('customer')->user();
        PaidTaxException::require($actor instanceof User && $actor->exists, 403);

        return [$principal, $actor];
    }

    private function body(Request $request): array
    {
        return PaidTaxJson::body($request->attributes->get('_paid_tax_body'));
    }
}
