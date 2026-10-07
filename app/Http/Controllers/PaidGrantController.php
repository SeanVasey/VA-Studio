<?php

namespace App\Http\Controllers;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Grants\Paid\PaidGrantDocuments;
use App\Domain\Grants\Paid\PaidGrantDownloads;
use App\Domain\Grants\Paid\PaidGrantException;
use App\Domain\Grants\Paid\PaidGrantInput;
use App\Domain\Grants\Paid\PaidGrantJson;
use App\Domain\Grants\Paid\PaidGrantPolicy;
use App\Domain\Grants\Paid\PaidGrantReads;
use App\Domain\Grants\Paid\PaidGrants;
use App\Http\Middleware\PaidGrantPrivacy;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class PaidGrantController
{
    public function page(Request $request): Response
    {
        return $this->run(function () use ($request): Response {
            [$principal, $actor] = $this->identity($request);
            (new PaidGrantReads)->index($principal, $actor);

            // Private declarations, terms and capabilities never enter Inertia page/history props.
            return Inertia::render('PaidGrants')->toResponse($request);
        });
    }

    public function index(Request $request): Response
    {
        return $this->run(function () use ($request): Response {
            [$principal, $actor] = $this->identity($request);

            return response()->json((new PaidGrantReads)->index($principal, $actor));
        });
    }

    public function finalize(string $order, Request $request): Response
    {
        return $this->run(function () use ($order, $request): Response {
            [$principal, $actor] = $this->identity($request);
            PaidGrantInput::keys($this->body($request), []);

            return response()->json(['origin' => (new PaidGrants)->finalize($principal, $actor, $order)]);
        });
    }

    public function show(string $batch, Request $request): Response
    {
        return $this->run(function () use ($batch, $request): Response {
            [$principal, $actor] = $this->identity($request);

            return response()->json(['origin' => (new PaidGrantReads)->show($batch, $principal, $actor)]);
        });
    }

    public function document(string $batch, Request $request): Response
    {
        return $this->run(function () use ($batch, $request): Response {
            [$principal, $actor] = $this->identity($request);
            PaidGrantInput::keys($this->body($request), []);

            return response()->json(['origin' => (new PaidGrantDocuments)->prepare($batch, $principal, $actor)]);
        });
    }

    public function downloads(string $batch, Request $request): Response
    {
        return $this->run(function () use ($batch, $request): Response {
            [$principal, $actor] = $this->identity($request);

            return response()->json(['status' => (new PaidGrantDownloads)->status($batch, $principal, $actor)]);
        });
    }

    public function authorize(string $batch, string $line, Request $request): Response
    {
        return $this->run(function () use ($batch, $line, $request): Response {
            [$principal, $actor] = $this->identity($request);

            return response()->json(['authorization' => (new PaidGrantDownloads)->authorize($batch, $line, $this->body($request), $principal, $actor)]);
        });
    }

    public function redeem(string $authorization, Request $request): Response
    {
        return $this->run(function () use ($authorization, $request): Response {
            [$principal, $actor] = $this->identity($request);
            $input = $this->body($request);
            PaidGrantInput::keys($input, ['token']);
            PaidGrantException::require(is_string($input['token']), 403);
            $transfer = (new PaidGrantDownloads)->redeem($authorization, $input['token'], $principal, $actor);

            return response()->stream(function () use ($transfer): void {
                $transfer->writeTo(static function (string $bytes): void {
                    echo $bytes;
                });
            }, 200, ['Content-Type' => $transfer->mimeType, 'Content-Length' => (string) $transfer->sizeBytes,
                'Content-Disposition' => 'attachment; filename="'.$transfer->filename.'"', 'X-Paid-Grant-Sha256' => $transfer->sha256]);
        });
    }

    private function identity(Request $request): array
    {
        app(PaidGrantPolicy::class)->capture();
        $principal = app(ProductionCustomerSessions::class)->principal($request);
        $actor = Auth::guard('customer')->user();
        PaidGrantException::require($actor instanceof User && $actor->exists, 403);

        return [$principal, $actor];
    }

    private function body(Request $request): array
    {
        return PaidGrantJson::body($request->attributes->get('_paid_grant_body'));
    }

    private function run(callable $operation): Response
    {
        try {
            return PaidGrantPrivacy::protect($operation());
        } catch (PaidGrantException $error) {
            return PaidGrantPrivacy::error($error->status);
        } catch (IdentityException|AuthorizationException) {
            return PaidGrantPrivacy::error(403);
        } catch (ValidationException) {
            return PaidGrantPrivacy::error(422);
        } catch (ContractIssuanceException|DeliveryException) {
            return PaidGrantPrivacy::error(503);
        } catch (Throwable $error) {
            PaidGrantPrivacy::report($error);

            return PaidGrantPrivacy::error(503);
        }
    }
}
