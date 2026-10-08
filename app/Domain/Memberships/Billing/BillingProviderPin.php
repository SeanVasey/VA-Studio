<?php

namespace App\Domain\Memberships\Billing;

use Composer\InstalledVersions;
use Stripe\BaseStripeClient;
use Stripe\Util\ApiVersion;
use Throwable;

/**
 * Billing pins its own provider provenance. The Checkout gateway's API constant gives no Billing
 * semantics. Every client is built with API_VERSION; the generated SDK models that define the
 * retrieved shapes are pinned by SHA256 because no official 2026-08-26.dahlia OpenAPI artifact was
 * retrievable (docs/verification/membership-billing-259/provider-schema/manifest.json).
 */
final class BillingProviderPin
{
    public const SDK_PACKAGE = 'stripe/stripe-php';

    public const SDK_VERSION = 'v21.3.2';

    public const SDK_REFERENCE = '0d8b075e1a97d15c5324353a5277d0ea686ea525';

    public const API_VERSION = '2026-08-26.dahlia';

    /** Locked SDK files (relative to the package's lib/) whose generated docblocks define each retrieved object. */
    public const MODEL_SHA256 = [
        'Invoice.php' => '4f5b96742db0b48b130e0fa43fc4543525929d6a10fa94f341d0ebf4116532b2',
        'InvoiceLineItem.php' => 'd64565a42a098cfc4f10fb007432f764c90b21d115bf63867bcf6ca8e4fb2feb',
        'InvoicePayment.php' => '3d37844fe766918f9e876faaa83cf40b39f7634af7c623cc62d8c5a87a7dcc91',
        'Subscription.php' => '5f104f4adfb423437cddfe3ccb36a1ad54057939648b1363c862c956fd5deba2',
        'PaymentIntent.php' => 'bf92246bd619ddad206aa29c416ce4fa24963dd0a01eea627c2f23191f8fd790',
        'Charge.php' => 'f1f6b410a1fd8343b2e51c9da163f55e6960256284bb545d5f8a604ba889f542',
        'BalanceTransaction.php' => 'd91bea04d038b1d8666fc549a6d32d932f537407f4422b6e7f581747c26b9de9',
        'Webhook.php' => 'de68f1ad57fa480468851aca46f144ec0eb8ee92ac64244e7cfc5b47f8d9b6ec',
        'Util/ApiVersion.php' => '86f26d2242e689b6add01c81c91f12c654d08594f6721b1a72a90347785d0e74',
    ];

    public static function assertInstalled(): void
    {
        try {
            $installed = InstalledVersions::isInstalled(self::SDK_PACKAGE)
                && InstalledVersions::getPrettyVersion(self::SDK_PACKAGE) === self::SDK_VERSION
                && InstalledVersions::getReference(self::SDK_PACKAGE) === self::SDK_REFERENCE
                && ApiVersion::CURRENT === self::API_VERSION;
            $root = InstalledVersions::getInstallPath(self::SDK_PACKAGE);
            BillingException::require($installed && is_string($root), 'provider_pin');
            $lib = realpath($root.'/lib');
            BillingException::require(is_string($lib), 'provider_pin');
            foreach (self::MODEL_SHA256 as $file => $sha256) {
                $path = realpath($lib.'/'.$file);
                BillingException::require(is_string($path) && str_starts_with($path, $lib.DIRECTORY_SEPARATOR)
                    && hash_equals($sha256, (string) hash_file('sha256', $path)), 'provider_pin');
            }
        } catch (BillingException $error) {
            throw $error;
        } catch (Throwable) {
            throw new BillingException('provider_pin');
        }
    }

    /** The only client options Billing uses: own account, pinned wire version, no SDK retries. */
    public static function clientOptions(#[\SensitiveParameter] string $secret): array
    {
        return ['api_key' => $secret, 'api_base' => BaseStripeClient::DEFAULT_API_BASE, 'stripe_version' => self::API_VERSION,
            'stripe_account' => null, 'stripe_context' => null, 'max_network_retries' => 0];
    }
}
