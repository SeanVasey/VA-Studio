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
use App\Domain\Grants\Paid\PaidGrantProjectionRead;
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

            $read = PaidGrantProjectionRead::begin();

            return $this->json((new PaidGrantReads)->index($principal, $actor, $read), $read);
        });
    }

    public function finalize(string $order, Request $request): Response
    {
        return $this->run(function () use ($order, $request): Response {
            [$principal, $actor] = $this->identity($request);
            PaidGrantInput::keys($this->body($request), []);

            $read = PaidGrantProjectionRead::begin();

            return $this->json(['origin' => (new PaidGrants)->finalize($principal, $actor, $order, $read)], $read);
        });
    }

    public function show(string $batch, Request $request): Response
    {
        return $this->run(function () use ($batch, $request): Response {
            [$principal, $actor] = $this->identity($request);

            $read = PaidGrantProjectionRead::begin();

            return $this->json(['origin' => (new PaidGrantReads)->show($batch, $principal, $actor, $read)], $read);
        });
    }

    public function document(string $batch, Request $request): Response
    {
        return $this->run(function () use ($batch, $request): Response {
            [$principal, $actor] = $this->identity($request);
            PaidGrantInput::keys($this->body($request), []);

            $read = PaidGrantProjectionRead::begin();
            $busy = false;
            $origin = (new PaidGrantDocuments)->prepare($batch, $principal, $actor, $read, $busy);

            // `busy` (a boolean, no token) tells the page that other work holds this buyer's preparation, so it waits and
            // polls instead of stopping (condition C13). Progress is visible in the projection itself.
            return $this->json(['origin' => $origin, 'busy' => $busy], $read);
        });
    }

    public function downloads(string $batch, Request $request): Response
    {
        return $this->run(function () use ($batch, $request): Response {
            [$principal, $actor] = $this->identity($request);

            $read = PaidGrantProjectionRead::begin();

            return $this->json(['status' => (new PaidGrantDownloads)->status($batch, $principal, $actor, $read)], $read);
        });
    }

    public function authorize(string $batch, string $line, Request $request): Response
    {
        return $this->run(function () use ($batch, $line, $request): Response {
            [$principal, $actor] = $this->identity($request);

            $read = PaidGrantProjectionRead::begin();

            return $this->json(['authorization' => (new PaidGrantDownloads)->authorize($batch, $line, $this->body($request), $principal, $actor, $read)], $read);
        });
    }

    public function redeem(string $authorization, Request $request): Response
    {
        return $this->run(function () use ($authorization, $request): Response {
            // The server's own request-start time, taken before the identity proof: admission judges the token as it was
            // when the request arrived (Codex 4224514947). Never a client-supplied header.
            $receivedAt = PaidGrantDownloads::receivedAt($request->server('REQUEST_TIME_FLOAT'));
            [$principal, $actor] = $this->identity($request);
            $input = $this->body($request);
            PaidGrantInput::keys($input, ['token']);
            PaidGrantException::require(is_string($input['token']), 403);
            $transfer = (new PaidGrantDownloads)->redeem($authorization, $input['token'], $principal, $actor, $receivedAt);

            return response()->stream(function () use ($transfer): void {
                try {
                    $transfer->writeTo(static function (string $bytes): void {
                        echo $bytes;
                    });
                } catch (Throwable $error) {
                    // Headers may already be sent. Emit no private/error bytes into the attachment stream.
                    if (! $error instanceof PaidGrantException) {
                        PaidGrantPrivacy::report($error);
                    }
                }
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

    private function json(array $data, PaidGrantProjectionRead $read): Response
    {
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        PaidGrantException::require(strlen($encoded) <= 4 * 1024 * 1024);

        return response()->stream(static function () use ($read, $encoded): void {
            try {
                $read->proveBeforeBytes();
                echo $encoded;
            } catch (Throwable $error) {
                $status = $error instanceof PaidGrantException ? $error->status : 503;
                if (! $error instanceof PaidGrantException) {
                    PaidGrantPrivacy::report($error);
                }
                // A late denial carries no original projection; headers can already be on the wire.
                echo json_encode(['error' => 'Paid grant request unavailable.', 'status' => $status], JSON_THROW_ON_ERROR);
            }
        }, 200, ['Content-Type' => 'application/json']);
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
