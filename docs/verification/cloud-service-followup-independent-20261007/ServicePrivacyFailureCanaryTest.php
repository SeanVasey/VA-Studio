<?php

namespace Tests\IndependentServiceFollowup;

use App\Http\Middleware\ServiceProjectPrivacy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class ServicePrivacyFailureCanaryTest extends TestCase
{
    public function test_middleware_exception_reports_only_fixed_class_and_returns_private_503(): void
    {
        Log::spy();
        $response = (new ServiceProjectPrivacy)->handle(Request::create('/services/projects', 'GET'), function () {
            throw new RuntimeException('SYNTHETIC private middleware failure lyric@example.invalid');
        });
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        $this->assertStringNotContainsString('lyric@example.invalid', $response->getContent());
        Log::shouldHaveReceived('error')->once()->with('Service project request failed.', ['exception_class' => RuntimeException::class]);
    }

    public function test_logging_failure_preserves_the_same_private_response_without_exception_echo(): void
    {
        Log::shouldReceive('error')->once()->with('Service project request failed.', ['exception_class' => LogicException::class])
            ->andThrow(new RuntimeException('SYNTHETIC private logging transport failure lyric@example.invalid'));
        $response = (new ServiceProjectPrivacy)->handle(Request::create('/services/projects', 'GET'), function () {
            throw new LogicException('SYNTHETIC private middleware request failure');
        });
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        $this->assertStringNotContainsString('SYNTHETIC', $response->getContent());
    }
}
