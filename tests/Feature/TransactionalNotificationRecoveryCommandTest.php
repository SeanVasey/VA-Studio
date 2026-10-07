<?php

namespace Tests\Feature;

use App\Domain\Notifications\Models\TransactionalNoticeAttempt;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\TransactionalNotificationFixtures as F;
use Tests\TestCase;

class TransactionalNotificationRecoveryCommandTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
    }

    public function test_registered_artisan_command_recovers_and_replays_with_sanitized_cursor_output(): void
    {
        $f = F::ready();
        $this->assertSame(0, Artisan::call('vasey:recover-test-notifications', ['--limit' => '1']));
        $first = Artisan::output();
        $result = json_decode(trim($first), true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('accepted', $result['results'][0]['state']);
        $this->assertSame($f['notice']['notificationId'], $result['results'][0]['notificationId']);
        $this->assertStringNotContainsString($f['user']->email, $first);
        $this->assertStringNotContainsString('receipt', $first);
        $this->assertStringNotContainsString('capture', $first);
        $this->assertSame(0, Artisan::call('vasey:recover-test-notifications', ['--limit' => '1']));
        $this->assertSame($first, Artisan::output());
        $this->assertSame(1, TransactionalNoticeAttempt::count());
        $this->assertSame(0, Artisan::call('vasey:recover-test-notifications', ['--after' => (string) $result['nextCursor']]));
        $this->assertSame([], json_decode(trim(Artisan::output()), true, 8, JSON_THROW_ON_ERROR)['results']);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    #[DataProvider('invalidOptions')]
    public function test_invalid_decimal_options_fail_with_constant_safe_output(string $option, string $value): void
    {
        F::configure();
        $this->assertSame(1, Artisan::call('vasey:recover-test-notifications', [$option => $value]));
        $this->assertSame(['recoverySchema' => 1, 'testOnly' => true, 'state' => 'unavailable'],
            json_decode(trim(Artisan::output()), true, 8, JSON_THROW_ON_ERROR));
        $this->assertSame(0, TransactionalNoticeAttempt::count());
    }

    public static function invalidOptions(): array
    {
        return [['--after', '-1'], ['--after', '01'], ['--after', '1e2'], ['--after', ' 1'],
            ['--after', '9223372036854775808'], ['--limit', '0'], ['--limit', '26'], ['--limit', '+1']];
    }

    public function test_default_off_and_production_are_refused_even_without_any_notice(): void
    {
        $this->assertSame(1, Artisan::call('vasey:recover-test-notifications'));
        $this->assertSame('unavailable', json_decode(trim(Artisan::output()), true)['state']);
        F::configure();
        app()->instance('env', 'production');
        try {
            $this->assertSame(1, Artisan::call('vasey:recover-test-notifications'));
            $this->assertSame('unavailable', json_decode(trim(Artisan::output()), true)['state']);
        } finally {
            app()->instance('env', 'testing');
        }
    }

    public function test_per_notice_refusal_returns_nonzero_and_retains_safe_page_cursor(): void
    {
        $f = F::ready();
        CustomerFixtures::withdraw($f);
        $this->assertSame(1, Artisan::call('vasey:recover-test-notifications'));
        $result = json_decode(trim(Artisan::output()), true, 8, JSON_THROW_ON_ERROR);
        $this->assertGreaterThan(0, $result['nextCursor']);
        $this->assertSame('unavailable', $result['results'][0]['state']);
        $this->assertSame(0, TransactionalNoticeAttempt::count());
        $this->assertStringNotContainsString($f['user']->email, Artisan::output());
    }
}
