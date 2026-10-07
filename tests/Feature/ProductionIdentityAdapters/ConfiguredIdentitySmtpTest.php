<?php

namespace Tests\Feature\ProductionIdentityAdapters;

use App\Domain\Customers\ProductionIdentity\CompleteIdentity;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\ConfiguredSmtpIdentityTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\DefinitelyNotSubmitted;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityMail;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\SmtpIdentitySettings;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionIdentityFixture;
use Tests\Support\ProductionIdentityTlsSmtpFixture;
use Tests\TestCase;

final class ConfiguredIdentitySmtpTest extends TestCase
{
    use ProductionIdentityFixture, ProductionIdentityTlsSmtpFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identitySetup();
    }

    public static function tlsModes(): array
    {
        return [['implicit_tls'], ['starttls']];
    }

    #[DataProvider('tlsModes')]
    public function test_actual_tls_auth_notice_creates_identity_only_after_received_mailbox_proof(string $security): void
    {
        $this->requestIdentity();
        $noticeId = (int) DB::table('production_identity_notices')->value('id');
        $received = $this->withTlsIdentitySink($security, 'accept', function (ConfiguredSmtpIdentityTransport $transport, SmtpIdentitySettings $settings) use ($noticeId): void {
            config(['production-customer-identity.transport_capability' => $settings->capabilityVersion()]);
            app()->instance(IdentityNoticeTransport::class, $transport);
            (new WorkIdentityNotice)->process($noticeId);
        });
        $this->assertTrue($received['facts']['tls']);
        $this->assertTrue($received['facts']['authenticated']);
        $this->assertSame('accepted', DB::table('production_identity_outcomes')->value('status'));
        $this->assertSame(0, DB::table('production_identity_origins')->count());
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(1, preg_match('~http://localhost/customer/access#enroll\.([a-f0-9-]{36})\.([a-f0-9]{64})~', $received['facts']['body'], $proof));
        (new CompleteIdentity)->complete($proof[1], $proof[2], 'MailboxPassword123', 'Declared buyer', str_repeat('b', 64));
        $authenticated = (new ProductionCustomerSessions)->authenticate('buyer@example.test', 'MailboxPassword123');
        $this->assertNotNull($authenticated);
        $this->assertSame(IdentityPolicy::REHEARSAL, $authenticated['principal']->provenance);
    }

    public static function smtpFailures(): array
    {
        return [['reject_rcpt', true, 'definitely_not_submitted'], ['lost_ack', true, 'unknown'],
            ['no_tls', true, 'definitely_not_submitted'], ['no_auth', true, 'definitely_not_submitted'], ['accept', false, 'definitely_not_submitted']];
    }

    #[DataProvider('smtpFailures')]
    public function test_tls_auth_and_data_failure_classification_is_retained_without_private_diagnostics(string $mode, bool $trusted, string $expected): void
    {
        $this->requestIdentity();
        $noticeId = (int) DB::table('production_identity_notices')->value('id');
        $received = $this->withTlsIdentitySink('starttls', $mode, function (ConfiguredSmtpIdentityTransport $transport, SmtpIdentitySettings $settings) use ($noticeId): void {
            config(['production-customer-identity.transport_capability' => $settings->capabilityVersion()]);
            app()->instance(IdentityNoticeTransport::class, $transport);
            (new WorkIdentityNotice)->process($noticeId);
        }, $trusted);
        $this->assertSame($expected, DB::table('production_identity_outcomes')->value('status'));
        $this->assertSame(0, DB::table('production_identity_origins')->count());
        if ($mode === 'lost_ack') {
            $this->assertNotSame('', $received['facts']['data']);
            (new WorkIdentityNotice)->process($noticeId);
            $this->assertSame(1, DB::table('production_identity_attempts')->count());
        } else {
            $this->assertSame('', $received['facts']['data']);
        }
        $outcome = (array) DB::table('production_identity_outcomes')->first();
        $this->assertStringNotContainsString('SyntheticSmtpPassword123', json_encode($outcome));
        $this->assertStringNotContainsString('buyer@example.test', json_encode($outcome));
    }

    public function test_settings_changes_get_new_capability_and_live_settings_never_execute_as_rehearsal(): void
    {
        $original = SmtpIdentitySettings::production('smtp.example.com', 587, 'starttls', 'identity@example.com', 'vaseyaudio.com',
            'private-account', 'SyntheticSmtpPassword123', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $changed = SmtpIdentitySettings::production('smtp.example.com', 587, 'starttls', 'identity@example.com', 'vaseyaudio.com',
            'private-account', 'ChangedSyntheticPassword123', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $this->assertNotSame($original->capabilityVersion(), $changed->capabilityVersion());
        $this->assertSame(IdentityPolicy::PRODUCTION, $original->provenance);
        $this->assertFalse($original->accepts(new IdentityMail('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'enroll', IdentityPolicy::REHEARSAL,
            'buyer@example.test', 'http://localhost/customer/access')));
        $this->assertFalse($original->accepts(new IdentityMail('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'enroll', IdentityPolicy::PRODUCTION,
            'buyer@example.com', 'https://vaseyaudio.com/customer/access')));
        try {
            (new ConfiguredSmtpIdentityTransport($original))->submit(new IdentityMail('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'enroll', IdentityPolicy::PRODUCTION,
                'buyer@example.com', 'https://vaseyaudio.com/customer/access'));
            $this->fail('Testing must never connect to configured external mail.');
        } catch (DefinitelyNotSubmitted) {
            $this->assertTrue(true);
        }
        $this->assertSame(false, config('production-account-features.enabled', false));
    }

    public function test_cleartext_external_alias_and_header_injection_settings_are_refused(): void
    {
        foreach ([['smtp.example.com', 'plain', 'identity@example.com'], ['127.0.0.1', 'implicit_tls', 'identity@example.com'],
            ['smtp.example.com', 'starttls', "identity@example.com\r\nBcc: foreign@example.com"]] as [$host, $security, $sender]) {
            try {
                SmtpIdentitySettings::production($host, 587, $security, $sender, 'vaseyaudio.com',
                    'private-account', 'SyntheticSmtpPassword123', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
                $this->fail('Unsafe SMTP setup must be refused.');
            } catch (IdentityException) {
                $this->assertTrue(true);
            }
        }
    }
}
