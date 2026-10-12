#!/usr/bin/env bash
set -eu
source /workspace/.va-studio-toolchain/activate.sh
cd /workspace/VA-Studio-membership-c2
billing_output=/workspace/runtime/membership-c2-evidence
case "${1:?Choose timeout, attempts or integration}" in
  timeout)
    billing_schema=vaseyaudio_membership_timeout
    billing_filter=test_first_timeout_is_retained_without_claiming_an_invoice_and_retry_can_succeed
    billing_files=(tests/Feature/ProductionMembershipBilling/BillingRetrievalAttemptsTest.php)
    ;;
  attempts)
    billing_schema=vaseyaudio_catalog_import
    billing_filter='/(test_repeated_wrong_binding_attempts|test_attempt_rows_are_immutable|test_configuration_withdrawn|test_existing_wrong_scope_or_late_hint|test_forward_migration_preserves)/'
    billing_files=(tests/Feature/ProductionMembershipBilling/BillingRetrievalAttemptsTest.php)
    ;;
  integration)
    billing_schema=vaseyaudio_membership_corrections
    billing_filter='/(test_bindings_invoices_observations_events_positions_and_attempts_are_immutable|test_contiguous_empty_tail_restarts_but_a_data_bearing_unguarded_prefix_is_not_adopted|test_another_invoice_returned_for_a_matching_binding_records_a_refusal_without_claiming_the_requested_invoice|BillingNativeDedupRaceTest|test_first_failed_job_retains_its_hint_link_and_redelivery_and_sweep_still_dispatch_until_success)/'
    billing_files=(tests/Feature/ProductionMembershipBilling/BillingSchemaPreparationTest.php tests/Feature/ProductionMembershipBilling/BillingObservationLedgerTest.php tests/Feature/ProductionMembershipBilling/BillingNativeDedupRaceTest.php tests/Feature/ProductionMembershipBilling/BillingWebhookRedeliveryTest.php)
    ;;
  *) exit 2 ;;
esac
exec env TMPDIR=/tmp/va-studio-importer-h1-20261012 APP_ENV=testing APP_DEBUG=false \
  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33396 DB_USERNAME=root DB_PASSWORD= \
  DB_DATABASE="$billing_schema" DB_URL= DB_SOCKET=/workspace/runtime/mysql-run/mysql.sock \
  CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
  php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- \
  --no-progress --stop-on-error --stop-on-failure "${billing_files[@]}" --filter "$billing_filter" \
  --log-junit "$billing_output/mysql-final-$1.xml" > "$billing_output/mysql-final-$1.txt" 2>&1
