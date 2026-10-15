<?php

namespace App\Http\Tax;

use App\Domain\Grants\PaidTax\PaidTaxException;
use App\Http\Middleware\PaidTaxPrivacy;
use Closure;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** The response owns cleanup even if never sent. Its callback never captures the response. */
final class PaidTaxStreamResponse extends StreamedResponse
{
    public function __construct(private readonly PaidTaxResponseOwner $owner, Closure $callback, array $headers)
    {
        try {
            parent::__construct($callback, 200, $headers);
            PaidTaxPrivacy::protect($this);
        } catch (Throwable $error) {
            $owner->close();
            throw $error;
        }
    }

    public static function projection(array $data, PaidTaxResponseOwner $owner): self
    {
        try {
            $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            PaidTaxException::require(strlen($encoded) <= 4 * 1024 * 1024);

            return new self($owner, static function () use ($owner, $encoded): void {
                try {
                    $owner->writeJson($encoded);
                } catch (Throwable $error) {
                    if (! $error instanceof PaidTaxException) {
                        PaidTaxPrivacy::report($error);
                    }
                    $status = $error instanceof PaidTaxException ? $error->status : 503;
                    echo '{"error":"Paid Tax request unavailable.","status":'.$status.'}';
                } finally {
                    $owner->close();
                }
            }, ['Content-Type' => 'application/json']);
        } catch (Throwable $error) {
            $owner->close();
            throw $error;
        }
    }

    public function close(): void
    {
        $this->owner->close();
    }

    public function __destruct()
    {
        $this->close();
    }

    private function __clone() {}
}
