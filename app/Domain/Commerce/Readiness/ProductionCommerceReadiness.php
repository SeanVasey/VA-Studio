<?php

namespace App\Domain\Commerce\Readiness;

use Illuminate\Encryption\Encrypter;

/** A redacted preparation inventory, never a payment, entitlement or deployment authority. */
final class ProductionCommerceReadiness
{
    public const VERSION = 'production-track-commerce-readiness-v1';

    /** Current source boundaries. Enabling a test flag cannot implement their production successor. */
    private const IMPLEMENTATION = [
        'production_pricing_and_tax' => [['app/Domain/Commerce/PricingPolicy.php', 'app/Domain/Commerce/Checkout/CheckoutEvidence.php'], 'Implement approved production currency/tax calculation and exact settlement verification; current checkout requires fixed zero test tax.'],
        'production_inventory_and_exclusives' => [['app/Domain/Commerce/Inventory/InventoryPolicy.php', 'app/Domain/Commerce/Inventory/ExclusiveSelectionPolicy.php'], 'Implement the approved production reservation, cutoff and late-payment contract while preserving existing grants.'],
        'production_order_and_assent' => [['app/Domain/Commerce/Orders/OrderPolicy.php'], 'Implement versioned real seller/buyer/assent policy; current orders use a local/testing unverified-guest contract.'],
        'production_checkout_and_provider' => [['app/Domain/Commerce/Checkout/CheckoutPolicy.php', 'app/Domain/Commerce/Payments/StripeSdkCheckoutGateway.php', 'config/payments.php'], 'Implement a separately reviewed own-account production checkout/provider configuration; current adapter accepts only test credentials and test responses.'],
        'production_webhook_and_verification' => [['app/Domain/Commerce/Payments/VerifyStripeWebhook.php', 'app/Domain/Commerce/Payments/PaymentProcessingPolicy.php', 'app/Domain/Commerce/Payments/PaymentEvidence.php'], 'Implement signed live receipt processing and authoritative account/amount verification; current paths reject live evidence.'],
        'production_paid_effects' => [['app/Domain/Commerce/Finalization/FinalizationPolicy.php', 'database/migrations/2026_09_26_000019_test_payment_evidence.php', 'database/migrations/2026_09_26_000020_test_order_finalization.php'], 'Add production evidence/state contracts with historical compatibility; retained payment/finalization guards explicitly require test mode.'],
        'production_original_contracts' => [['app/Domain/Contracts/ContractIssuancePolicy.php', 'database/migrations/2026_09_26_000021_test_contract_issuance.php'], 'Implement approved production original issuance/profile and archival policy; preserve every retained test and purchased original.'],
        'production_entitlements_and_delivery' => [['app/Domain/Delivery/ActivationPolicy.php', 'app/Domain/Delivery/TestAccessPolicy.php', 'database/migrations/2026_09_26_000023_test_owner_delivery.php'], 'Implement production entitlement/access policy and compatible persistence; current activation/access and SQL parent guards are test-only.'],
        'production_customer_identity' => [['app/Domain/Customers/CustomerAccessPolicy.php', 'app/Domain/Customers/CustomerIdentityPolicy.php', 'app/Domain/Customers/CustomerPurchaseClaimPolicy.php'], 'Implement production enrollment/recovery and original-guest purchase claims with a verified transactional transport; private_capture is local test mail.'],
        'production_refunds_and_disputes' => [['app/Domain/Commerce/RefundResolution/RefundResolutionPolicy.php', 'app/Domain/Commerce/UnpaidRelease/UnpaidReleasePolicy.php', 'app/Domain/Commerce/Operations/TestPaymentExceptionOperations.php'], 'Implement approved live exception/refund/dispute/resource and rights dispositions; test refund/release operations do not provide production resolution.'],
    ];

    private const TEST_FLAGS = [
        'payments.stripe.webhook_enabled', 'payments.stripe.checkout_enabled', 'payments.stripe.processing_enabled',
        'payments.stripe.finalization_enabled', 'contracts.test_issuance_enabled', 'delivery.test_activation_enabled',
        'delivery.test_access_enabled', 'customer.test_accounts_enabled', 'customer.test_identity_enabled',
        'customer.test_purchase_claims_enabled', 'inquiries.test_order_inquiries_enabled', 'unpaid-release.enabled', 'refund-resolution.enabled',
    ];

    private const TEST_POLICIES = [
        'commerce.test_pricing_policy', 'commerce.test_promotions', 'commerce.test_inventory_policy',
        'commerce.test_exclusive_selection_policy', 'commerce.test_order_policy', 'commerce.test_checkout_policy',
        'payments.stripe.finalization_policy', 'contracts.test_issuance_policy', 'delivery.test_activation_policy',
        'delivery.test_access_policy', 'customer.identity_transport', 'unpaid-release.policy', 'refund-resolution.policy',
    ];

    private const EVIDENCE = [
        'merchant_currency_tax_and_terms' => 'Record approved merchant identity, currency/tax treatment, exact terms/assent, exclusive timing and refund/dispute policy from actual records.',
        'provider_account_and_payment_drills' => 'Verify the intended account/credential/webhook destination and end-to-end successful, delayed, duplicated and uncertain payments after the production implementation exists.',
        'effective_web_and_worker_configuration' => 'Verify the exact release and effective web/worker configuration, including cached configuration and protected secret handling.',
        'mysql_schema_grants_and_races' => 'Verify MySQL 8.4, least-privilege runtime grants, production schema guards and independent-connection races on the candidate.',
        'private_storage_and_original_integrity' => 'Verify shared durable private storage, path/link isolation and exact purchased media/original bytes; selecting a local adapter is not filesystem evidence.',
        'supervised_workers_and_scheduler' => 'Observe supervised payment/contract/media workers and scheduler recovery after missed dispatch, crashes and expired claims.',
        'transactional_identity_and_receipts' => 'Verify sender identity, actual delivery, expiry, replay, bounce/failure and recovery for transactional customer messages.',
        'operator_authorization_and_mfa' => 'Verify production operator roles, MFA, revocation and audit recovery with the actual provisioned identities.',
        'original_document_review_and_restore' => 'Review approved original documents and supported scripts/fonts, then restore exact original bytes rather than rerendering retained agreements.',
        'download_device_and_interruption_acceptance' => 'Verify actual attachment bytes, native/device behavior, large downloads, interruption/retry and agreed range policy.',
        'backup_monitoring_and_rollback' => 'Observe paired database/private-file/key restore, monitored failures, reconciliation and rollback on the selected host.',
        'final_candidate_verification' => 'Complete independent sensitive review and consolidated manual final verification on the exact candidate commit; focused checks are not full acceptance.',
    ];

    public function collect(): array
    {
        $checks = [];
        foreach (self::IMPLEMENTATION as $id => [$sources, $message]) {
            $checks[] = ['id' => $id, 'category' => 'implementation', 'status' => 'blocked', 'message' => $message, 'sources' => $sources];
        }

        $configuration = [
            'production_environment' => [app()->environment('production'), 'Run the future production candidate in the production environment.'],
            'debug_disabled' => [config('app.debug') === false, 'Disable application debug output.'],
            'https_origin' => [self::httpsOrigin(config('app.url')), 'Configure a bounded HTTPS origin without credentials, path, query or fragment.'],
            'application_key' => [$this->encryptionKey(), 'Configure a supported retained encryption key; format does not prove backup or rotation readiness.'],
            'mysql_driver_selected' => [config('database.default') === 'mysql' && config('database.connections.mysql.driver') === 'mysql', 'Select the documented MySQL driver; no database connection, version or grants are checked.'],
            'session_controls' => [config('session.driver') === 'database' && config('session.encrypt') === true && config('session.secure') === true
                && config('session.http_only') === true && in_array(config('session.same_site'), ['lax', 'strict'], true), 'Select encrypted database sessions and secure HttpOnly SameSite cookies.'],
            'test_features_disabled' => [$this->disabledTestFlags(), 'Keep every test-commerce, customer-identity and test-order-inquiry flag strictly disabled in production preparation.'],
            'test_policies_absent' => [$this->absentTestPolicies(), 'Remove synthetic test commerce/identity policies from production preparation; they are not approved live policies.'],
            'private_local_adapter_selected' => [$this->localAdapter(), 'Select the current unserved private local adapter; storage identity, public exposure and byte integrity remain unverified.'],
            'async_database_queue_selected' => [config('queue.default') === 'database' && config('queue.connections.database.driver') === 'database'
                && is_int(config('queue.connections.database.retry_after')) && config('queue.connections.database.retry_after') > 960,
                'Select the documented asynchronous database queue with retry beyond the current media/claim timeout; liveness is unverified.'],
            'transactional_mail_selected' => [$this->smtpSelected(), 'Configure an SMTP transport and non-placeholder sender; delivery, TLS, sender authentication and production identity integration remain unverified.'],
        ];
        foreach ($configuration as $id => [$configured, $message]) {
            $checks[] = ['id' => $id, 'category' => 'configuration', 'status' => $configured ? 'configured' : 'blocked', 'message' => $message, 'sources' => []];
        }
        foreach (self::EVIDENCE as $id => $message) {
            $checks[] = ['id' => $id, 'category' => 'acceptance', 'status' => 'unverified', 'message' => $message, 'sources' => []];
        }

        return [
            'schema_version' => 1, 'contract_version' => self::VERSION, 'scope' => 'production_track_commerce_preparation',
            'production_commerce_ready' => false, 'deployment_authorized' => false,
            'configuration_complete' => ! in_array(false, array_map(fn (array $check): bool => $check[0], $configuration), true),
            'counts' => array_count_values(array_column($checks, 'status')), 'checks' => $checks,
        ];
    }

    private function disabledTestFlags(): bool
    {
        foreach (self::TEST_FLAGS as $key) {
            if (config($key) !== false) {
                return false;
            }
        }

        return true;
    }

    private function absentTestPolicies(): bool
    {
        foreach (self::TEST_POLICIES as $key) {
            if (! in_array(config($key), [null, ''], true)) {
                return false;
            }
        }

        return true;
    }

    private function encryptionKey(): bool
    {
        $key = config('app.key');
        $cipher = config('app.cipher');
        if (! is_string($key) || strlen($key) > 256 || ! is_string($cipher)) {
            return false;
        }
        $decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        return is_string($decoded) && Encrypter::supported($decoded, $cipher);
    }

    /** Shared bounded HTTPS-origin shape check; reused by the Stripe capability preflight. */
    public static function httpsOrigin(mixed $value): bool
    {
        if (! is_string($value) || strlen($value) > 255 || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
            return false;
        }
        $parts = parse_url($value);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && is_string($parts['host'] ?? null) && $parts['host'] !== ''
            && array_diff(array_keys($parts), ['scheme', 'host', 'port']) === []
            && (! isset($parts['port']) || ($parts['port'] >= 1 && $parts['port'] <= 65535));
    }

    private function localAdapter(): bool
    {
        $disk = config('filesystems.disks.local');

        return is_array($disk) && ($disk['driver'] ?? null) === 'local' && ($disk['serve'] ?? null) === false
            && ($disk['visibility'] ?? 'private') === 'private' && ($disk['prefix'] ?? '') === ''
            && is_string($disk['root'] ?? null) && strlen($disk['root']) <= 4096
            && str_starts_with($disk['root'], '/') && ! preg_match('~[\x00-\x1f\x7f]|//|(?:^|/)\.{1,2}(?:/|$)~', $disk['root']);
    }

    private function smtpSelected(): bool
    {
        $name = config('mail.default');
        $mailer = is_string($name) && strlen($name) <= 80 ? config('mail.mailers.'.$name) : null;
        $sender = config('mail.from.address');

        return is_array($mailer) && ($mailer['transport'] ?? null) === 'smtp' && is_string($mailer['host'] ?? null)
            && $mailer['host'] !== '' && strlen($mailer['host']) <= 255 && ! preg_match('/[\x00-\x20\x7f]/', $mailer['host'])
            && (is_int($mailer['port'] ?? null) || (is_string($mailer['port'] ?? null) && ctype_digit($mailer['port'])))
            && (int) $mailer['port'] >= 1 && (int) $mailer['port'] <= 65535
            && in_array($mailer['url'] ?? null, [null, ''], true)
            && is_string($sender) && strlen($sender) <= 254 && filter_var($sender, FILTER_VALIDATE_EMAIL) !== false
            && ! preg_match('/@(?:example\.(?:com|org|net)|localhost)\z/i', $sender);
    }
}
