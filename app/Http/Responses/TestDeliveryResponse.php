<?php

namespace App\Http\Responses;

use App\Domain\Delivery\DeliveryException;
use App\Domain\Delivery\PreparedDeliveryStream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** Explicit public projections only: never serialize retained delivery evidence or a domain DTO. */
final class TestDeliveryResponse
{
    public static function matches(Request $request): bool
    {
        return $request->is('orders/*/delivery', 'orders/*/delivery/*');
    }

    public static function headers(): array
    {
        return ['Cache-Control' => 'private, no-store', 'Vary' => 'Cookie', 'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow'];
    }

    public static function protect(Response $response): Response
    {
        $response->headers->add(self::headers());
        foreach (['ETag', 'Last-Modified', 'Accept-Ranges'] as $header) { $response->headers->remove($header); }
        return $response;
    }

    public static function error(int $status, ?string $code = null, array $headers = []): JsonResponse
    {
        $status = $status >= 500 ? 503 : $status;
        $code ??= match ($status) {
            404 => 'DELIVERY_NOT_FOUND', 419 => 'SESSION_EXPIRED', 429 => 'DELIVERY_RATE_LIMITED',
            400, 405, 413, 415, 416, 422 => 'INVALID_DELIVERY_REQUEST', default => 'DELIVERY_UNAVAILABLE',
        };
        return response()->json(['code' => $code], $status, self::headers() + $headers);
    }

    public static function domainError(DeliveryException $error): JsonResponse
    {
        [$status, $code] = match ($error->reason) {
            'not_found' => [404, 'DELIVERY_NOT_FOUND'],
            'already_issued' => [409, 'DELIVERY_ALREADY_ISSUED'],
            'conflict' => [409, 'DELIVERY_CONFLICT'],
            'expired' => [410, 'DELIVERY_EXPIRED'],
            'redeemed' => [409, 'DELIVERY_ATTEMPTED'],
            'budget_exhausted' => [429, 'DELIVERY_RATE_LIMITED'],
            default => [503, 'DELIVERY_UNAVAILABLE'],
        };
        return self::error($status, $code);
    }

    /** Ownership, immutable metadata and redemption are already verified before this adapter is called. */
    public static function attachment(PreparedDeliveryStream $prepared, string $filename, string $mime): StreamedResponse
    {
        try {
            $response = new StreamedResponse(function () use ($prepared): void {
                $previous = ignore_user_abort(true);
                try {
                    $prepared->writeTo(function (string $chunk): void {
                        if (connection_aborted()) { throw new \RuntimeException('Delivery transport interrupted.'); }
                        echo $chunk;
                    });
                } catch (Throwable $error) {
                    // Headers may already be sent. Never append exception/debug/JSON text to private file bytes.
                    try { Log::warning('Test delivery stream interrupted.', ['exception_class' => $error::class]); }
                    catch (Throwable) { /* Closing the descriptor must survive a failed logger. */ }
                } finally {
                    $prepared->close();
                    ignore_user_abort((bool) $previous);
                }
            }, 200, self::headers() + [
                'Content-Type' => $mime,
                'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $filename),
                'Content-Length' => (string) $prepared->sizeBytes,
            ]);
            return $response;
        } catch (Throwable $error) {
            $prepared->close();
            throw $error;
        }
    }
}
