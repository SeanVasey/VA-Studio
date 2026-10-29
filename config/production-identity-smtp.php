<?php

/*
 * Production customer identity SMTP, read by IdentitySmtpFactory. Activation is a reviewed code change, never an environment
 * switch: only the literal `$enabled` below composes `settings`. The environment supplies the values (the credential stays in
 * the host secret store) and never selects or enables a provider; while the literal is false `settings` is null and the
 * variables are not read. SmtpIdentitySettings::production() validates every value (a provider host that is not an IP or a
 * test domain, port, `implicit_tls` or `starttls` with TLS required, sender, HELO name, credentials, a UUID revision); anything
 * missing or malformed refuses. In production the preflight keeps every PRODUCTION_IDENTITY_SMTP_* value blank unless
 * VASEY_PRODUCTION_REAL_MAIL_ADMITTED is true, and the validator refuses composed settings without it. Rehearsal capsules are
 * set by tests only.
 */
$enabled = false;

$settings = static function (): array {
    // A real environment supplies the port as a string; SmtpIdentitySettings admits only an int and refuses anything else.
    $port = env('PRODUCTION_IDENTITY_SMTP_PORT');
    if (is_string($port) && preg_match('/\A[1-9][0-9]{0,4}\z/D', $port) === 1) {
        $port = (int) $port;
    }

    return [
        'mode' => 'production',
        'host' => env('PRODUCTION_IDENTITY_SMTP_HOST'),
        'port' => $port,
        'security' => env('PRODUCTION_IDENTITY_SMTP_SECURITY'),
        'sender' => env('PRODUCTION_IDENTITY_SMTP_SENDER'),
        'hello' => env('PRODUCTION_IDENTITY_SMTP_HELLO'),
        'username' => env('PRODUCTION_IDENTITY_SMTP_USERNAME'),
        'password' => env('PRODUCTION_IDENTITY_SMTP_PASSWORD'),
        'revision' => env('PRODUCTION_IDENTITY_SMTP_REVISION'),
    ];
};

return [
    'enabled' => $enabled,
    'settings' => $enabled === true ? $settings() : null,
];
