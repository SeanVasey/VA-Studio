<?php

namespace Tests\Support;

use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\CompleteIdentity;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\IdentityRequests;
use App\Domain\Customers\ProductionIdentity\IdentitySchema;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

trait ProductionIdentityFixture
{
    protected function identitySetup(): void
    {
        static $nativePrepared = false;
        $isolatedNative = getenv('VA_IDENTITY_ISOLATED_NATIVE') === '1' && app()->environment('testing')
            && DB::getDriverName() === 'mysql' && DB::connection()->getDatabaseName() === 'vaseyaudio_production_identity';
        if (! $isolatedNative || ! $nativePrepared) {
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
            $nativePrepared = true;
        } else {
            $pdo = DB::connection()->getPdo();
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            try {
                foreach ([...array_reverse(array_keys(IdentitySchema::definitions())), 'audit_events', 'customer_accounts', 'quote_owners', 'users'] as $table) {
                    $pdo->exec('TRUNCATE TABLE `'.$table.'`');
                }
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }
        }
        config(['production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY]);
        Queue::fake();
    }

    /** Operative shared fixture: actual SMTP DATA -> mailbox proof -> real writer -> typed server authority. */
    protected function enrollThroughLocalSmtp(string $email = 'buyer@example.test', string $password = 'MailboxPassword123'): array
    {
        $this->requestIdentity('enroll', $email);
        $id = (int) DB::table('production_identity_notices')->orderByDesc('id')->value('id');
        $received = $this->smtp('accept', $id);
        if (! preg_match('~http://localhost/customer/access#enroll\.([a-f0-9-]{36})\.([a-f0-9]{64})~', $received['data'] ?? '', $match)) {
            throw new \LogicException('Synthetic SMTP message missing proof.');
        }
        $result = (new CompleteIdentity)->complete($match[1], $match[2], $password, 'Declared buyer', str_repeat('b', 64));
        $sessions = new ProductionCustomerSessions;
        $verified = $sessions->authenticate($email, $password);
        if ($verified === null) {
            throw new \LogicException('Operative synthetic identity was not authenticated.');
        }

        return ['user' => $verified['user'], 'principal' => $verified['principal'],
            'binding' => (new ProductionCustomerAccess)->durableBinding($verified['principal'])];
    }

    protected function requestIdentity(string $purpose = 'enroll', string $email = 'buyer@example.test', ?string $key = null): array
    {
        (new IdentityRequests)->request($purpose, $email, $key ?? bin2hex(random_bytes(32)));

        return (array) DB::table('production_identity_challenges')->orderByDesc('id')->first();
    }

    protected function proofFor(array $challenge): array
    {
        $payload = json_decode(Crypt::decryptString($challenge['payload_ciphertext']), true, 8, JSON_THROW_ON_ERROR);
        [$purpose, $id, $proof] = explode('.', parse_url($payload['url'], PHP_URL_FRAGMENT));

        return [$id, $proof];
    }

    protected function completeIdentity(array $challenge, string $password = 'MailboxPassword123', ?string $key = null): array
    {
        [$id, $proof] = $this->proofFor($challenge);

        return (new CompleteIdentity)->complete($id, $proof, $password, 'Declared buyer', $key ?? str_repeat('b', 64));
    }

    protected function smtp(string $mode, int $noticeId): array
    {
        $capture = tempnam(sys_get_temp_dir(), 'va-identity-synthetic-');
        $process = new Process(['python3', base_path('tests/Support/production_identity_smtp_sink.py'), $mode, $capture], timeout: 20);
        $process->start();
        try {
            $process->waitUntil(fn (): bool => preg_match('/\A[0-9]+\n/', $process->getOutput()) === 1);
            $port = (int) trim($process->getOutput());
            app()->instance(IdentityNoticeTransport::class, new LoopbackSmtp($port));
            (new WorkIdentityNotice)->process($noticeId);
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

            return filesize($capture) > 0 ? json_decode(file_get_contents($capture), true, 8, JSON_THROW_ON_ERROR) : [];
        } finally {
            $process->stop(0);
            unlink($capture);
        }
    }
}
