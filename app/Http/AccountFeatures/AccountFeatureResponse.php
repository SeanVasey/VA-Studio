<?php

namespace App\Http\AccountFeatures;

use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use App\Domain\Customers\ProductionFeatures\ProductionFeaturePreparation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Throwable;

/** Private bytes become observable only during one atomic post-kernel native send. */
final class AccountFeatureResponse extends Response
{
    private ?ProductionFeaturePreparation $preparation = null;

    private bool $attempted = false;

    private bool $emitting = false;

    private array $nativeHeaders = [];

    private ?array $candidateHeaders = null;

    private readonly ResponseHeaderBag $failureHeaders;

    private readonly array $failureNative;

    public function __construct(private readonly Request $request)
    {
        parent::__construct('', 200, AccountFeaturePrivacy::headers());
        $failure = AccountFeaturePrivacy::error(503);
        $this->failureHeaders = $failure->headers;
        $this->failureNative = self::materialize($failure);
    }

    public function attach(ProductionFeaturePreparation $preparation): void
    {
        if ($this->preparation !== null || $this->attempted) {
            throw new ProductionFeatureException;
        }
        $this->preparation = $preparation;
    }

    public function forRequest(Request $request): bool
    {
        return $request === $this->request;
    }

    public function __toString(): string
    {
        return '';
    }

    public function requireUnchanged(): void
    {
        if ($this->content !== '' || $this->statusCode !== 200 || $this->version !== '1.1'
            || $this->headers::class !== ResponseHeaderBag::class
            || ($this->candidateHeaders !== null && self::materialize($this) !== $this->candidateHeaders)) {
            throw new ProductionFeatureException;
        }
    }

    public function send(bool $flush = true): static
    {
        if ($this->attempted || $this->emitting || headers_sent()
            || ! AccountFeatureHttpKernel::completed($this->request, $this)) {
            return $this;
        }
        $this->attempted = true;
        try {
            if ($this->preparation === null || $this->content !== '' || $this->statusCode !== 200
                || $this->headers::class !== ResponseHeaderBag::class || $this->version !== '1.1') {
                throw new ProductionFeatureException;
            }
            $body = $this->preparation->bytes();
            $native = self::materialize($this);
            $this->candidateHeaders = $native;
            // Every callback-producing preparation finishes before the original final proof.
            $this->preparation->finish();
            $this->nativeHeaders = $native;
            $this->content = $body;
        } catch (Throwable) {
            $this->statusCode = 503;
            $this->statusText = 'Service Unavailable';
            $this->headers = $this->failureHeaders;
            $this->nativeHeaders = $this->failureNative;
            $this->content = '{"code":"ACCOUNT_FEATURE_UNAVAILABLE"}';
        }
        $this->emitting = true;
        try {
            $this->sendHeaders();
            $this->sendContent();
        } finally {
            $this->emitting = false;
        }

        return $this;
    }

    public function sendHeaders(?int $statusCode = null): static
    {
        if ($this->emitting && $statusCode === null) {
            foreach ($this->nativeHeaders as $header) {
                header($header, false, $this->statusCode);
            }
            http_response_code($this->statusCode);
        }

        return $this;
    }

    public function sendContent(): static
    {
        if ($this->emitting) {
            echo $this->content;
        }

        return $this;
    }

    private static function materialize(Response $response): array
    {
        if ($response->headers::class !== ResponseHeaderBag::class) {
            throw new ProductionFeatureException;
        }
        $allowed = ['content-type', 'cache-control', 'x-robots-tag', 'referrer-policy', 'x-content-type-options',
            'vary', 'date', 'x-ratelimit-limit', 'x-ratelimit-remaining', 'retry-after', 'expires'];
        $result = [];
        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            if (! in_array(strtolower($name), $allowed, true) || ! is_array($values) || count($values) > 8) {
                throw new ProductionFeatureException;
            }
            foreach ($values as $value) {
                if (! is_string($value) || strlen($value) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                    throw new ProductionFeatureException;
                }
                $result[] = $name.': '.$value;
            }
        }
        foreach (AccountFeaturePrivacy::headers() as $name => $expected) {
            if ($response->headers->get($name) !== $expected && $name !== 'Cache-Control') {
                throw new ProductionFeatureException;
            }
        }
        if ($response->headers->get('Cache-Control') !== 'no-store, private') {
            throw new ProductionFeatureException;
        }
        $cookies = $response->headers->getCookies();
        if (count($cookies) > 8) {
            throw new ProductionFeatureException;
        }
        foreach ($cookies as $cookie) {
            if ($cookie::class !== Cookie::class) {
                throw new ProductionFeatureException;
            }
            $value = (string) $cookie;
            if (strlen($value) > 8192 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new ProductionFeatureException;
            }
            $result[] = 'Set-Cookie: '.$value;
        }

        return $result;
    }
}
