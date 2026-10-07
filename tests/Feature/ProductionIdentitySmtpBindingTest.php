<?php

namespace Tests\Feature;

use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\Notifications\DefinitelyNotSubmitted;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityMail;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\SmtpIdentitySettings;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Support\ProductionIdentityFixture;
use Tests\Support\ProductionIdentityTlsSmtpFixture;
use Tests\TestCase;

final class ProductionIdentitySmtpBindingTest extends TestCase
{
    use ProductionIdentityFixture;
    use ProductionIdentityTlsSmtpFixture;

    public function test_default_binding_cannot_construct_an_endpoint_or_enable_notifications(): void
    {
        $this->assertTrue($this->app->bound(IdentityNoticeTransport::class));
        $this->assertNull(config('production-identity-smtp.settings'));
        $this->assertFalse(config('production-customer-identity.enabled'));
        $this->assertFalse(config('production-customer-identity.notifications_enabled'));
        $this->expectException(IdentityException::class);
        $this->app->make(IdentityNoticeTransport::class);
    }

    #[DataProvider('tlsModes')]
    public function test_registered_http_and_actual_tls_worker_use_transient_server_binding(string $security): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('I', 32))]);
        $this->identitySetup();
        $this->withoutVite();
        $result = $this->withTlsIdentitySink($security, 'accept', function ($unused, SmtpIdentitySettings $settings) use ($security): void {
            config(['production-identity-smtp.settings' => $this->rehearsal(
                (new ReflectionProperty(SmtpIdentitySettings::class, 'port'))->getValue($settings), $security,
                (new ReflectionProperty(SmtpIdentitySettings::class, 'caFile'))->getValue($settings))]);
            $first = $this->app->make(IdentityNoticeTransport::class);
            $second = $this->app->make(IdentityNoticeTransport::class);
            $this->assertNotSame($first, $second);
            $this->assertSame($settings->capabilityVersion(), $first->capabilityVersion());
            $this->assertSame($first->capabilityVersion(), $second->capabilityVersion());
            config(['production-customer-identity.transport_capability' => $first->capabilityVersion()]);
            $this->postJson('/customer/identity/request', ['purpose' => 'enroll', 'email' => 'buyer@example.test', 'requestKey' => str_repeat('a', 64)])
                ->assertStatus(202)->assertExactJson(['accepted' => true]);
            (new WorkIdentityNotice)->process((int) DB::table('production_identity_notices')->value('id'));
        });
        $this->assertTrue($result['facts']['tls']);
        $this->assertTrue($result['facts']['authenticated']);
        $this->assertMatchesRegularExpression('~http://localhost/customer/access#enroll\.([a-f0-9-]{36})\.([a-f0-9]{64})~', $result['facts']['body']);
        preg_match('~http://localhost/customer/access#enroll\.([a-f0-9-]{36})\.([a-f0-9]{64})~', $result['facts']['body'], $proof);
        $this->assertSame('accepted', DB::table('production_identity_outcomes')->value('status'));
        $this->postJson('/customer/identity/complete', ['id' => $proof[1], 'proof' => $proof[2], 'password' => 'MailboxPassword123',
            'name' => 'Declared synthetic customer', 'requestKey' => str_repeat('b', 64)])->assertOk();
        $this->postJson('/customer/sign-in', ['email' => 'buyer@example.test', 'password' => 'MailboxPassword123'])->assertOk();
        $this->get('/customer')->assertOk()->assertDontSee($proof[2], false)->assertDontSee('buyer@example.test', false);
        $this->assertDatabaseCount('production_identity_origins', 1);
        $this->assertDatabaseCount('production_identity_attempts', 1);
    }

    public static function tlsModes(): array
    {
        return [['implicit_tls'], ['starttls']];
    }

    #[DataProvider('configurationChanges')]
    public function test_retained_transport_refuses_changed_server_configuration_before_a_socket(string $change): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('I', 32)),
            'production-identity-smtp.settings' => $this->rehearsal(65530, 'implicit_tls', '/etc/ssl/certs/ca-certificates.crt')]);
        $transport = $this->app->make(IdentityNoticeTransport::class);
        $mail = new IdentityMail('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'enroll', $transport->provenance(), 'buyer@example.test',
            'http://localhost/customer/access#enroll.aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa.'.str_repeat('a', 64));
        match ($change) {
            'revision' => config(['production-identity-smtp.settings.revision' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']),
            'password' => config(['production-identity-smtp.settings.password' => 'ChangedSyntheticCredential']),
            'endpoint' => config(['production-identity-smtp.settings.port' => 65529]),
            'key' => config(['app.key' => 'base64:'.base64_encode(str_repeat('K', 32))]),
            'repository' => $this->app->instance('config', new Repository(config()->all())),
        };
        $refused = false;
        try {
            $transport->capabilityVersion();
        } catch (IdentityException) {
            $refused = true;
        }
        $this->assertTrue($refused);
        $this->expectException(DefinitelyNotSubmitted::class);
        $transport->submit($mail);
    }

    public static function configurationChanges(): array
    {
        return [['revision'], ['password'], ['endpoint'], ['key'], ['repository']];
    }

    public function test_terminal_capability_reads_do_not_invoke_a_configuration_getter_callback(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('I', 32)),
            'production-identity-smtp.settings' => $this->rehearsal(65530, 'implicit_tls', '/etc/ssl/certs/ca-certificates.crt')]);
        $repository = new class(config()->all()) extends Repository
        {
            public int $callbacks = 0;

            public function get($key, $default = null)
            {
                $this->callbacks++;

                return parent::get($key, $default);
            }
        };
        $this->app->instance('config', $repository);
        $transport = $this->app->make(IdentityNoticeTransport::class);
        $repository->callbacks = 0;
        $this->assertNotEmpty($transport->capabilityVersion());
        $this->assertNotEmpty($transport->provenance());
        $this->assertSame(0, $repository->callbacks);
    }

    #[DataProvider('invalidSettings')]
    public function test_server_capsule_rejects_malformed_fields_before_constructing_a_transport(string $change): void
    {
        $settings = $this->rehearsal(65530, 'implicit_tls', '/etc/ssl/certs/ca-certificates.crt');
        match ($change) {
            'extra' => $settings['recipient'] = 'arbitrary@example.test',
            'port' => $settings['port'] = '65530',
            'security' => $settings['security'] = 'plaintext',
        };
        config(['production-identity-smtp.settings' => $settings]);
        $this->expectException(IdentityException::class);
        $this->app->make(IdentityNoticeTransport::class);
    }

    public static function invalidSettings(): array
    {
        return [['extra'], ['port'], ['security']];
    }

    #[DataProvider('rawParents')]
    public function test_final_guard_refuses_callback_capable_raw_parents_before_reading_them(string $section): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('I', 32)),
            'production-identity-smtp.settings' => $this->rehearsal(65530, 'implicit_tls', '/etc/ssl/certs/ca-certificates.crt')]);
        $transport = $this->app->make(IdentityNoticeTransport::class);
        $mail = new IdentityMail('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'enroll', $transport->provenance(), 'buyer@example.test',
            'http://localhost/customer/access#enroll.aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa.'.str_repeat('a', 64));
        $original = config($section);
        $parent = new class($original) extends \ArrayObject
        {
            public int $callbacks = 0;

            public function offsetGet(mixed $key): mixed
            {
                $this->callbacks++;

                return parent::offsetGet($key);
            }
        };
        config([$section => $parent]);
        try {
            $refused = false;
            try {
                $transport->submit($mail);
            } catch (DefinitelyNotSubmitted) {
                $refused = true;
            }
            $this->assertTrue($refused);
            $this->assertSame(0, $parent->callbacks);
        } finally {
            config([$section => $original]);
        }
    }

    public static function rawParents(): array
    {
        return [['production-identity-smtp'], ['app']];
    }

    private function rehearsal(int $port, string $security, string $caFile): array
    {
        return ['mode' => 'rehearsal', 'port' => $port, 'security' => $security, 'ca_file' => $caFile,
            'username' => 'synthetic-account', 'password' => 'SyntheticSmtpPassword123', 'revision' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'];
    }
}
