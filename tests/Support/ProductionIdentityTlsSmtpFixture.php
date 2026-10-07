<?php

namespace Tests\Support;

use App\Domain\Customers\ProductionIdentity\Notifications\ConfiguredSmtpIdentityTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\SmtpIdentitySettings;
use Symfony\Component\Process\Process;

trait ProductionIdentityTlsSmtpFixture
{
    /** Ephemeral certificate/private key and literal loopback only; never repository or external mail data. */
    protected function withTlsIdentitySink(string $security, string $mode, callable $operation, bool $trusted = true): array
    {
        $directory = sys_get_temp_dir().'/va-identity-tls-'.bin2hex(random_bytes(12));
        mkdir($directory, 0700);
        $certificate = $directory.'/certificate.pem';
        $key = $directory.'/key.pem';
        $capture = $directory.'/capture.json';
        $certificateProcess = new Process(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
            '-keyout', $key, '-out', $certificate, '-subj', '/CN=127.0.0.1', '-addext', 'subjectAltName=IP:127.0.0.1',
            '-addext', 'basicConstraints=critical,CA:TRUE', '-addext', 'keyUsage=critical,keyCertSign,digitalSignature,keyEncipherment'], timeout: 15);
        $process = null;
        try {
            $certificateProcess->mustRun();
            chmod($key, 0600);
            $process = new Process(['python3', base_path('tests/Support/production_identity_tls_smtp_sink.py'), $security, $mode, $capture, $certificate, $key], timeout: 20);
            $process->start();
            $process->waitUntil(fn (): bool => preg_match('/\A[0-9]+\n\z/D', $process->getOutput()) === 1);
            $port = (int) trim($process->getOutput());
            $settings = SmtpIdentitySettings::loopback($port, $security, $trusted ? $certificate : '/etc/ssl/certs/ca-certificates.crt',
                'synthetic-account', 'SyntheticSmtpPassword123', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
            $result = $operation(new ConfiguredSmtpIdentityTransport($settings), $settings);
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $facts = json_decode(file_get_contents($capture), true, 8, JSON_THROW_ON_ERROR);

            return ['facts' => $facts, 'result' => $result, 'capability' => $settings->capabilityVersion()];
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
