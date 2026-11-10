<?php

namespace App\Domain\Albums;

/**
 * The only places a release may link to. Links are https-only, carry no credentials, port or fragment, and their host must
 * match the platform the staff member chose exactly (or, for Bandcamp, be a single-label subdomain of bandcamp.com).
 */
final class ListeningPlatforms
{
    public const MAX_URL_LENGTH = 500;

    /** @var array<string, array{label: string, hosts: list<string>, subdomain_of?: string}> */
    private const PLATFORMS = [
        'spotify' => ['label' => 'Spotify', 'hosts' => ['open.spotify.com']],
        'apple_music' => ['label' => 'Apple Music', 'hosts' => ['music.apple.com']],
        'youtube_music' => ['label' => 'YouTube Music', 'hosts' => ['music.youtube.com']],
        'youtube' => ['label' => 'YouTube', 'hosts' => ['www.youtube.com', 'youtu.be']],
        'bandcamp' => ['label' => 'Bandcamp', 'hosts' => ['bandcamp.com'], 'subdomain_of' => 'bandcamp.com'],
        'soundcloud' => ['label' => 'SoundCloud', 'hosts' => ['soundcloud.com']],
        'tidal' => ['label' => 'TIDAL', 'hosts' => ['tidal.com', 'listen.tidal.com']],
        'amazon_music' => ['label' => 'Amazon Music', 'hosts' => ['music.amazon.com']],
        'deezer' => ['label' => 'Deezer', 'hosts' => ['www.deezer.com']],
    ];

    /** @return array<string, string> platform key => label */
    public static function options(): array
    {
        return array_map(fn (array $platform): string => $platform['label'], self::PLATFORMS);
    }

    public static function exists(mixed $platform): bool
    {
        return is_string($platform) && array_key_exists($platform, self::PLATFORMS);
    }

    public static function label(string $platform): string
    {
        return self::PLATFORMS[$platform]['label'] ?? $platform;
    }

    /** A plain-language reason the link is refused, or null when it is acceptable for the platform. */
    public static function problem(mixed $platform, mixed $url): ?string
    {
        if (! self::exists($platform)) {
            return 'Choose a listening platform from the list.';
        }
        $definition = self::PLATFORMS[$platform];
        if (! is_string($url) || $url === '' || strlen($url) > self::MAX_URL_LENGTH || preg_match('/[\x00-\x20\x7F-\xFF<>"\'\\\\`]/', $url) === 1) {
            return 'Enter the link as plain text of up to '.self::MAX_URL_LENGTH.' characters, with no spaces.';
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])) {
            return 'Links must start with https://.';
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment']) || str_ends_with($url, '#')) {
            return 'Links cannot carry a login, a port or a #fragment.';
        }
        $host = $parts['host'];
        $allowed = in_array($host, $definition['hosts'], true)
            || (isset($definition['subdomain_of']) && preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.'.preg_quote($definition['subdomain_of'], '/').'\z/D', $host) === 1);

        return $allowed ? null : 'This link is not on '.$definition['label'].'. Use the address of the release on that platform.';
    }
}
