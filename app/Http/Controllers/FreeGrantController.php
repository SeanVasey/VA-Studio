<?php

namespace App\Http\Controllers;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Grants\Free\FreeGrantDocuments;
use App\Domain\Grants\Free\FreeGrantDownloads;
use App\Domain\Grants\Free\FreeGrantException;
use App\Domain\Grants\Free\FreeGrantHttpIdentity;
use App\Domain\Grants\Free\FreeGrantInput;
use App\Domain\Grants\Free\FreeGrantJson;
use App\Domain\Grants\Free\FreeGrantPolicy;
use App\Domain\Grants\Free\FreeGrantReads;
use App\Domain\Grants\Free\FreeGrantRows;
use App\Domain\Grants\Free\FreeGrants;
use App\Http\Middleware\FreeGrantPrivacy;
use App\Support\CommerceRequestIdentity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class FreeGrantController
{
    public function page(Request $request): Response
    {
        return $this->run(function () use ($request): Response {
            // Page props contain no private graph or entered buyer declaration in Inertia history.
            [$principal, $actor] = $this->identity($request);
            DB::transaction(function () use ($principal, $actor): void {
                $rows = new FreeGrantRows;
                $identity = (new FreeGrantPolicy)->identity();
                $raw = $identity->lock($principal, $actor, $rows);
                $identity->proveCurrent($principal, $actor, $rows, $raw);
                (new FreeGrantPolicy)->requireEnabled();
                $identity->provePrimary($principal, $actor, $rows, $raw);
            });

            return Inertia::render('FreeGrants')->toResponse($request);
        });
    }

    public function index(Request $request): Response
    {
        return $this->run(fn (): Response => response()->json($this->indexData($request)));
    }

    public function review(string $definition, Request $request): Response
    {
        return $this->run(function () use ($definition, $request): Response {
            [$principal, $actor] = $this->identity($request);
            $body = $this->body($request);
            FreeGrantInput::keys($body, ['declaredName']);

            return response()->json((new FreeGrantReads)->review($definition, FreeGrantInput::text($body['declaredName'], 120), $principal, $actor));
        });
    }

    public function accept(string $definition, Request $request): Response
    {
        return $this->run(function () use ($definition, $request): Response {
            [$principal, $actor] = $this->identity($request);

            return response()->json(['origin' => (new FreeGrants)->accept($definition, $this->body($request), $principal, $actor)]);
        });
    }

    public function show(string $origin, Request $request): Response
    {
        return $this->run(function () use ($origin, $request): Response {
            [$principal, $actor] = $this->identity($request);

            return response()->json(['origin' => (new FreeGrants)->readOrigin($origin, $principal, $actor)]);
        });
    }

    public function document(string $origin, Request $request): Response
    {
        return $this->run(function () use ($origin, $request): Response {
            [$principal, $actor] = $this->identity($request);
            $body = $this->body($request);
            FreeGrantInput::keys($body, ['originHash']);

            return response()->json(['origin' => (new FreeGrantDocuments)->issue($origin, FreeGrantInput::hash($body['originHash']), $principal, $actor)]);
        });
    }

    public function authorize(string $origin, Request $request): Response
    {
        return $this->run(function () use ($origin, $request): Response {
            [$principal, $actor] = $this->identity($request);

            return response()->json(['authorization' => (new FreeGrantDownloads)->authorize($origin, $this->body($request), $principal, $actor)]);
        });
    }

    public function redeem(string $authorization, Request $request): Response
    {
        return $this->run(function () use ($authorization, $request): Response {
            [$principal, $actor] = $this->identity($request);
            $body = $this->body($request);
            FreeGrantInput::keys($body, ['token']);
            FreeGrantException::require(is_string($body['token']), 403);
            $transfer = (new FreeGrantDownloads)->redeem($authorization, $body['token'], $principal, $actor);

            $stream = $transfer->stream;

            // Stream the already held exact unlinked descriptor. Never reopen a source pathname.
            return response()->stream(function () use ($stream): void {
                $stream->writeTo(static function (string $bytes): void {
                    echo $bytes;
                });
            }, 200, ['Content-Type' => $transfer->mimeType, 'Content-Length' => (string) $stream->sizeBytes,
                'Content-Disposition' => 'attachment; filename="'.$transfer->filename.'"', 'X-Free-Grant-Sha256' => $stream->sha256]);
        });
    }

    private function indexData(Request $request): array
    {
        [$principal, $actor] = $this->identity($request);

        return (new FreeGrantReads)->customer($principal, $actor);
    }

    private function identity(Request $request): array
    {
        (new FreeGrantPolicy)->requireEnabled();
        if (app()->bound(FreeGrantHttpIdentity::class)) {
            return app(FreeGrantHttpIdentity::class)->forRequest($request);
        }
        // The test path verifies the original credential-stamped session marker.
        FreeGrantException::require(app()->environment('local', 'testing'), 403);
        $identity = app(CommerceRequestIdentity::class);
        $principal = $identity->principal($request);
        $actor = $identity->actor($request);
        FreeGrantException::require($principal !== null && $actor !== null, 403);

        return [$principal, $actor];
    }

    private function body(Request $request): array
    {
        return FreeGrantJson::body($request->attributes->get('_free_grant_body'));
    }

    private function run(callable $operation): Response
    {
        try {
            return FreeGrantPrivacy::protect($operation());
        } catch (FreeGrantException $error) {
            return FreeGrantPrivacy::error($error->status);
        } catch (CustomerAccessException|AuthorizationException) {
            return FreeGrantPrivacy::error(403);
        } catch (ValidationException) {
            return FreeGrantPrivacy::error(422);
        } catch (Throwable $error) {
            FreeGrantPrivacy::report($error);

            return FreeGrantPrivacy::error(503);
        }
    }
}
