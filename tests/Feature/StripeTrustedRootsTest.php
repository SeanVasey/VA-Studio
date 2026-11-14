<?php

namespace Tests\Feature;

use Composer\InstalledVersions;
use Stripe\Stripe;
use Tests\TestCase;

/**
 * Every Stripe request trusts only Stripe's current roots, not the 144-root bundle stripe-php 21.3.2 ships, which includes
 * emSign ECC Root CA - G3 (compromised intermediates). The bundle is a byte-identical copy of the one stripe-php 21.3.3
 * and 22.0.1 ship (https://docs.stripe.com/tls-certificates).
 */
class StripeTrustedRootsTest extends TestCase
{
    /** SHA-256 of stripe-php v21.3.3 `data/ca-certificates.crt` (reference 3d52008ffeab02763b02462802393f3d4da2aa51). */
    private const BUNDLE_SHA256 = '2215c6efc154f6c674046fe996a7aa4067e84667a4fd3c2045f991c59a88320d';

    public function test_every_stripe_request_uses_the_vendored_stripe_roots(): void
    {
        $path = resource_path('stripe/ca-certificates.crt');
        $this->assertSame($path, Stripe::getCABundlePath());
        $this->assertSame(self::BUNDLE_SHA256, hash_file('sha256', $path));
        $this->assertNotSame(realpath(InstalledVersions::getInstallPath('stripe/stripe-php').'/data/ca-certificates.crt'), realpath($path));
    }

    public function test_the_bundle_holds_only_stripe_roots_and_no_emsign_root(): void
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', (string) file_get_contents(resource_path('stripe/ca-certificates.crt')), $pem);
        $subjects = [];
        foreach ($pem[0] as $certificate) {
            $parsed = openssl_x509_parse($certificate);
            $this->assertIsArray($parsed);
            $subjects[] = $parsed['subject']['CN'];
        }
        sort($subjects);
        $this->assertSame([
            'DigiCert Assured ID Root G2', 'DigiCert Global Root G2', 'DigiCert Global Root G3', 'DigiCert High Assurance EV Root CA',
            'DigiCert TLS ECC P384 Root G5', 'DigiCert TLS RSA4096 Root G5', 'GlobalSign Root E46', 'GlobalSign Root R46', 'ISRG Root X1',
            'ISRG Root X2', 'Sectigo Public Server Authentication Root E46', 'Sectigo Public Server Authentication Root R46',
        ], $subjects);
    }

    public function test_the_shipped_sdk_bundle_still_contains_emsign_so_the_override_is_needed(): void
    {
        // Fails once the pinned SDK ships the trimmed bundle itself; then drop the override and this test.
        $bundle = (string) file_get_contents(InstalledVersions::getInstallPath('stripe/stripe-php').'/data/ca-certificates.crt');
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $bundle, $pem);
        $names = array_map(fn (string $certificate) => (string) (openssl_x509_parse($certificate)['subject']['CN'] ?? ''), $pem[0]);
        $this->assertContains('emSign ECC Root CA - G3', $names);
    }
}
