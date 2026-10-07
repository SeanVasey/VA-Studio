<?php

namespace Tests\IndependentIdentityBinding;

use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\Notifications\DefinitelyNotSubmitted;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityMail;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use ArrayAccess;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class NestedConfigurationCallbackCanaryTest extends TestCase
{
    public function test_nested_raw_configuration_parent_cannot_run_a_callback_at_the_final_smtp_handoff(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('I', 32))]);
        $directory = sys_get_temp_dir().'/va-identity-binding-'.bin2hex(random_bytes(12));
        mkdir($directory, 0700);
        $certificate = $directory.'/certificate.pem';
        $key = $directory.'/key.pem';
        $capture = $directory.'/capture.json';
        $process = null;
        try {
            (new Process(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                '-keyout', $key, '-out', $certificate, '-subj', '/CN=127.0.0.1', '-addext', 'subjectAltName=IP:127.0.0.1',
                '-addext', 'basicConstraints=critical,CA:TRUE', '-addext', 'keyUsage=critical,keyCertSign,digitalSignature,keyEncipherment'], timeout: 15))->mustRun();
            chmod($key, 0600);
            $process = new Process(['python3', base_path('tests/Support/production_identity_tls_smtp_sink.py'),
                'starttls', 'accept', $capture, $certificate, $key], timeout: 20);
            $process->start();
            $process->waitUntil(fn (): bool => preg_match('/\A[0-9]+\n\z/D', $process->getOutput()) === 1);
            $settings = ['mode' => 'rehearsal', 'port' => (int) trim($process->getOutput()), 'security' => 'starttls', 'ca_file' => $certificate,
                'username' => 'synthetic-account', 'password' => 'SyntheticSmtpPassword123', 'revision' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'];
            config(['production-identity-smtp.settings' => $settings]);
            $transport = $this->app->make(IdentityNoticeTransport::class);
            $mail = new IdentityMail('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'enroll', $transport->provenance(), 'buyer@example.test',
                'http://localhost/customer/access#enroll.aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa.'.str_repeat('a', 64));
            // Public Laravel configuration permits ArrayAccess parents. The ordinary
            // configuration repository and captured endpoint were admitted before this hook.
            $parent = new class($settings) implements ArrayAccess
            {
                public int $callbacks = 0;

                public function __construct(private array $settings) {}

                public function offsetExists(mixed $offset): bool
                {
                    return $offset === 'settings';
                }

                public function offsetGet(mixed $offset): mixed
                {
                    $this->callbacks++;
                    $previous = $this->settings;
                    $this->settings['password'] = 'WithdrawnSyntheticCredential';

                    return $offset === 'settings' ? $previous : null;
                }

                public function offsetSet(mixed $offset, mixed $value): void {}

                public function offsetUnset(mixed $offset): void {}

                public function withdrawn(): bool
                {
                    return $this->settings['password'] === 'WithdrawnSyntheticCredential';
                }
            };
            config(['production-identity-smtp' => $parent]);
            $refused = false;
            try {
                $transport->submit($mail);
            } catch (DefinitelyNotSubmitted|IdentityException) {
                $refused = true;
            }
            if ($refused) {
                $process->stop(0);
            } else {
                $process->wait();
            }
            $facts = is_file($capture) ? json_decode(file_get_contents($capture), true, 8, JSON_THROW_ON_ERROR) : [];
            $dataReceived = ($facts['data'] ?? '') !== '';
            file_put_contents(base_path('docs/verification/cloud-identity-adapters-binding-independent-20261007/nested-configuration-snapshot.json'), json_encode([
                'source' => trim(shell_exec('git rev-parse HEAD')), 'callback_count' => $parent->callbacks,
                'raw_credentials_withdrawn' => $parent->withdrawn(), 'refused' => $refused,
                'smtp_acceptance_returned' => ! $refused, 'actual_tls' => $facts['tls'] ?? false,
                'actual_authenticated' => $facts['authenticated'] ?? false, 'actual_smtp_data_received' => $dataReceived,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $this->assertTrue($refused, 'A nested configuration callback withdrew credentials at the final SMTP guard but submission was accepted.');
            $this->assertSame(0, $parent->callbacks);
            $this->assertFalse($dataReceived);
        } finally {
            $process?->stop(0);
            foreach ([$capture, $certificate, $key] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }
}
