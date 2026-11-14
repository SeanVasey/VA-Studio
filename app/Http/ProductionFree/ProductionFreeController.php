<?php

namespace App\Http\ProductionFree;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantInput;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRequestIdentity;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Customer HTTP for family 256: JSON for the library, detail, review, assent and authorization, and one POST
 * redemption that streams the file. Every business rule stays in the domain services; this class only maps the
 * request-bound identity capsule and the bounded body onto them. The capsule is re-proved inside each command's
 * commit (through the domain's authority hook) and again before any JSON or file byte is released.
 */
final class ProductionFreeController
{
    public function index(Request $request): Response
    {
        return $this->json($request, false, fn (ProductionFreeGrantRequestIdentity $identity): array => (new ProductionFreeGrantLibrary)
            ->index($identity->principal, $identity->actor, $identity->authority()));
    }

    public function show(string $origin, Request $request): Response
    {
        return $this->json($request, false, fn (ProductionFreeGrantRequestIdentity $identity): array => ['origin' => (new ProductionFreeGrantLibrary)
            ->show($origin, $identity->principal, $identity->actor, $identity->authority())]);
    }

    public function review(string $definition, Request $request): Response
    {
        return $this->json($request, false, function (ProductionFreeGrantRequestIdentity $identity) use ($request, $definition): array {
            $body = $this->body($request);
            ProductionFreeGrantInput::keys($body, ['declaredName']);
            ProductionFreeGrantException::require(is_string($body['declaredName']), 'invalid_input');

            return ['review' => (new ProductionFreeGrants)->review($definition, $body['declaredName'], $identity->principal, $identity->actor, $identity->authority())];
        });
    }

    public function accept(string $definition, Request $request): Response
    {
        return $this->json($request, true, fn (ProductionFreeGrantRequestIdentity $identity): array => ['origin' => (new ProductionFreeGrants)
            ->accept($definition, $this->body($request), $identity->principal, $identity->actor, $identity->authority())]);
    }

    public function authorize(string $origin, Request $request): Response
    {
        return $this->json($request, true, fn (ProductionFreeGrantRequestIdentity $identity): array => ['authorization' => (new ProductionFreeGrantDownloads)
            ->authorize($origin, $this->body($request), $identity->principal, $identity->actor, $identity->authority())]);
    }

    /** Native form or JSON with exactly the bearer token; the token is never in the URL and is retained only as a hash. */
    public function redeem(string $authorization, Request $request): Response
    {
        try {
            ProductionFreeGrantException::require(ProductionFreeHttpMount::admitted(), 'not_mounted');
            $identity = ProductionFreeGrantRequestIdentity::from($request);
            $body = $this->body($request);
            ProductionFreeGrantInput::keys($body, ['token']);
            ProductionFreeGrantException::require(is_string($body['token']), 'token_refused');
            $transfer = (new ProductionFreeGrantDownloads)->redeem($authorization, $body['token'], $identity->principal, $identity->actor, $identity->authority());
            $download = new ProductionFreeDownload($transfer, $identity);
            $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $transfer->filename);

            return new ProductionFreeDownloadResponse($download, ['Content-Type' => $transfer->mimeType,
                'Content-Length' => (string) $transfer->stream->sizeBytes, 'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'X-Production-Free-Sha256' => $transfer->stream->sha256]);
        } catch (Throwable $error) {
            return ProductionFreePrivacy::refusal($error);
        }
    }

    /** @param  Closure(ProductionFreeGrantRequestIdentity):array  $operation */
    private function json(Request $request, bool $writes, Closure $operation): Response
    {
        try {
            // A stale cached route table never overrides the literal runtime mount.
            ProductionFreeGrantException::require(ProductionFreeHttpMount::admitted(), 'not_mounted');
            $identity = ProductionFreeGrantRequestIdentity::from($request);
            $data = $operation($identity);
            try {
                $identity->proveCurrent();
            } catch (ProductionFreeGrantException) {
                // After a write has committed, a withdrawn capsule cannot be answered as a refusal of the write itself.
                throw new ProductionFreeGrantException($writes ? 'outcome_unknown' : 'identity_refused');
            }

            return ProductionFreePrivacy::json($data);
        } catch (Throwable $error) {
            return ProductionFreePrivacy::refusal($error);
        }
    }

    private function body(Request $request): array
    {
        $body = $request->attributes->get(ProductionFreePrivacy::BODY);
        ProductionFreeGrantException::require(is_array($body), 'invalid_input');

        return $body;
    }
}
