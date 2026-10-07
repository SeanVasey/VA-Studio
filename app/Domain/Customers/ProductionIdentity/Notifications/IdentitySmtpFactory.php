<?php

namespace App\Domain\Customers\ProductionIdentity\Notifications;

use App\Domain\Customers\ProductionIdentity\IdentityException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/** Server configuration only. Construction opens no connection and each resolution owns one client. */
final class IdentitySmtpFactory
{
    public static function make(Container $container, Repository $config): IdentityNoticeTransport
    {
        $settings = $config->get('production-identity-smtp.settings');
        if (! is_array($settings)) {
            throw new IdentityException;
        }
        $mode = $settings['mode'] ?? null;
        $keys = $mode === 'production'
            ? ['mode', 'host', 'port', 'security', 'sender', 'hello', 'username', 'password', 'revision']
            : ['mode', 'port', 'security', 'ca_file', 'username', 'password', 'revision'];
        $actual = array_keys($settings);
        sort($keys);
        sort($actual);
        if ($actual !== $keys || ! is_int($settings['port'])) {
            throw new IdentityException;
        }
        foreach ($settings as $key => $value) {
            if ($key !== 'port' && ! is_string($value)) {
                throw new IdentityException;
            }
        }
        $endpoint = match ($mode) {
            'production' => SmtpIdentitySettings::production($settings['host'], $settings['port'], $settings['security'],
                $settings['sender'], $settings['hello'], $settings['username'], $settings['password'], $settings['revision']),
            'rehearsal' => SmtpIdentitySettings::loopback($settings['port'], $settings['security'], $settings['ca_file'],
                $settings['username'], $settings['password'], $settings['revision']),
            default => throw new IdentityException,
        };

        return new ConfigurationBoundIdentityTransport($container, $config, $settings, $config->get('app.key'),
            new ConfiguredSmtpIdentityTransport($endpoint));
    }
}
