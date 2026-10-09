<?php

namespace App\Domain\Customers\ProductionIdentity\Notifications;

use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Support\CanonicalJson;
use JsonSerializable;
use LogicException;
use SensitiveParameter;
use Symfony\Component\Mailer\Transport\Smtp\Auth\LoginAuthenticator;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/** Server-owned immutable endpoint. No DSN, recipient, credential or TLS option comes from HTTP. */
final readonly class SmtpIdentitySettings implements JsonSerializable
{
    public const CAPABILITY = 'configured-smtp-identity-v1';

    private function __construct(
        public string $provenance,
        private string $host,
        private int $port,
        private string $security,
        private string $sender,
        private string $hello,
        #[SensitiveParameter] private string $username,
        #[SensitiveParameter] private string $password,
        private string $capability,
        private ?string $caFile,
        private bool $environmentAdmitted,
    ) {}

    /** Explicit production setup; activation and this object's binding remain root-owned/default-off. */
    public static function production(string $host, int $port, string $security, string $sender, string $hello,
        #[SensitiveParameter] string $username, #[SensitiveParameter] string $password, string $revision): self
    {
        if (! self::domain($host) || ! self::domain($hello) || preg_match('/\.(?:test|invalid|localhost)\z/D', $host)
            || filter_var($host, FILTER_VALIDATE_IP) !== false || ! self::address($sender) || str_ends_with($sender, '.test')) {
            throw new IdentityException;
        }
        self::validate($port, $security, $username, $password, $revision);

        return self::make(IdentityPolicy::PRODUCTION, $host, $port, $security, $sender, $hello, $username, $password, $revision, null);
    }

    /** Same TLS/authentication code against a synthetic local sink, never a production origin. */
    public static function loopback(int $port, string $security, string $caFile,
        #[SensitiveParameter] string $username, #[SensitiveParameter] string $password, string $revision): self
    {
        if (! app()->environment('local', 'testing') || $port < 1024 || ! str_starts_with($caFile, '/')
            || ! is_file($caFile) || is_link($caFile) || ! is_readable($caFile)) {
            throw new IdentityException;
        }
        self::validate($port, $security, $username, $password, $revision);

        return self::make(IdentityPolicy::REHEARSAL, '127.0.0.1', $port, $security, 'identity@vaseyaudio.test', 'vaseyaudio.test', $username, $password, $revision, $caFile);
    }

    private static function make(string $provenance, string $host, int $port, string $security, string $sender, string $hello,
        #[SensitiveParameter] string $username, #[SensitiveParameter] string $password, string $revision, ?string $caFile): self
    {
        $capability = self::CAPABILITY.':'.CanonicalJson::hash(['provenance' => $provenance, 'host' => $host, 'port' => $port,
            'security' => $security, 'sender' => $sender, 'hello' => $hello, 'revision' => $revision,
            'username_commitment' => IdentityPolicy::digest('smtp-username', $username), 'credential_commitment' => IdentityPolicy::digest('smtp-credential', $password),
            'ca_hash' => $caFile === null ? null : hash_file('sha256', $caFile)]);

        $rehearsalEnvironment = app()->environment('local', 'testing');
        $environmentAdmitted = $rehearsalEnvironment ? $provenance === IdentityPolicy::REHEARSAL : $provenance === IdentityPolicy::PRODUCTION;

        return new self($provenance, $host, $port, $security, $sender, $hello, $username, $password, $capability, $caFile, $environmentAdmitted);
    }

    private static function validate(int $port, string $security, string $username, string $password, string $revision): void
    {
        if ($port < 1 || $port > 65535 || ! in_array($security, ['implicit_tls', 'starttls'], true)
            || $username === '' || strlen($username) > 512 || preg_match('/[\x00-\x1F\x7F]/', $username)
            || $password === '' || strlen($password) > 4096 || str_contains($password, "\0")
            || ! preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D', $revision)) {
            throw new IdentityException;
        }
    }

    private static function domain(string $value): bool
    {
        return strlen($value) <= 253 && preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $value) === 1;
    }

    private static function address(string $value): bool
    {
        return strlen($value) <= 254 && preg_match('/\A[a-z0-9._+-]+@([^@]+)\z/D', $value, $match) === 1 && self::domain($match[1]);
    }

    public function capabilityVersion(): string
    {
        // Endpoint/credential/security changes automatically produce a different terminal capability.
        return $this->capability;
    }

    public function sender(): string
    {
        return $this->sender;
    }

    public function accepts(IdentityMail $mail): bool
    {
        // All application/environment hooks were captured during construction, before the worker's proof.
        return $this->environmentAdmitted && $mail->provenance === $this->provenance && self::address($mail->recipient())
            && ($this->provenance === IdentityPolicy::PRODUCTION || str_ends_with($mail->recipient(), '.test'));
    }

    /** Built before worker terminal proof; no late config/container/crypto hook is needed by SMTP. */
    public function client(): IdentityEsmtpClient
    {
        $client = new IdentityEsmtpClient($this->host, $this->port, $this->security === 'implicit_tls', authenticators: [new LoginAuthenticator]);
        $client->setAutoTls($this->security === 'starttls')->setRequireTls(true);
        $client->setUsername($this->username)->setPassword($this->password)->setLocalDomain($this->hello);
        $stream = $client->getStream();
        if (! $stream instanceof SocketStream) {
            throw new IdentityException;
        }
        $tls = ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
            'peer_name' => $this->host, 'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT];
        if ($this->caFile !== null) {
            $tls['cafile'] = $this->caFile;
        }
        $stream->setTimeout(5)->setStreamOptions(['ssl' => $tls]);

        return $client;
    }

    public function __debugInfo(): array
    {
        return ['provenance' => $this->provenance, 'capability' => $this->capabilityVersion()];
    }

    public function __serialize(): never
    {
        throw new LogicException('SMTP identity configuration must not be serialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('SMTP identity configuration is private.');
    }
}
