# Reviewer mutations for Tax255 at 9ec94d8c. Each: (id, file, old, new, test selection). Exactly one occurrence required.
D = 'app/Domain/Commerce/ProductionTaxCheckout/'
T = 'tests/Feature/ProductionTaxCheckout/'
PROBE = '/home/user/VA-Studio-review-tax255/docs/verification/tax-255-20261007/independent-review/review-evidence/probes/TaxReviewMoneyProbeTest.php'
SUITE = [T+'ProductionTaxCheckoutJourneyTest.php', T+'ProductionTaxSourceV2Test.php', T+'ProductionTaxPaidLineAdapterV2Test.php', T+'ProductionTaxCheckoutPolicyTest.php', T+'ProductionTaxCheckoutHttpBoundaryTest.php']
M = [
 ('R1-pi-amount-received', D+'TaxCheckoutEvidence.php', "CheckoutException::require($payment['amount_received'] === $total && $payment['amount_capturable'] === 0", "CheckoutException::require($payment['amount_capturable'] === 0", SUITE+[PROBE]),
 ('R2-pi-amount', D+'TaxCheckoutEvidence.php', "&& ($payment['amount'] ?? null) === $total && is_int", "&& is_int", SUITE+[PROBE]),
 ('R3-pi-currency', D+'TaxCheckoutEvidence.php', "=== ($context['funds_mode'] === 'live') && ($payment['currency'] ?? null) === 'usd'", "=== ($context['funds_mode'] === 'live')", SUITE+[PROBE]),
 ('R4-session-currency', D+'TaxCheckoutEvidence.php', "&& ($session['currency'] ?? null) === 'usd' && ($session['client_reference_id']", "&& ($session['client_reference_id']", SUITE+[PROBE]),
 ('R5-session-ceiling', D+'TaxCheckoutEvidence.php', "CheckoutException::require($net >= 0 && $tax * 10000 <= $net * $ceiling, 'tax_ceiling');", "", SUITE),
 ('R6-automatic-tax-complete-both', D+'TaxCheckoutEvidence.php', "&& $safe['status'] === 'complete' && $safe['payment_status'] === 'paid' && $safe['automatic_tax']['status'] === 'complete', 'provider_inconsistent');", "&& $safe['status'] === 'complete' && $safe['payment_status'] === 'paid', 'provider_inconsistent');", SUITE, ("CheckoutException::require($automatic['status'] === 'complete', 'provider_inconsistent');", "")),
 ('R7-line-tax-behavior', D+'TaxCheckoutEvidence.php', "&& ($line['price']['tax_behavior'] ?? null) === $behavior", "", SUITE),
 ('R8-preview-hash', D+'ProductionTaxCheckout.php', "CheckoutException::require(hash_equals(CanonicalJson::hash($commitment), $previewHash), 'changed');", "", SUITE),
 ('R9-transport-admission', D+'ProductionTaxCheckout.php', "        $transport = TaxCheckoutPolicy::transport($this->transport);\n        $context = $prepared['order']['context'];\n        $request = $prepared['intent']['body'];\n        try {\n            $sessionId = $prepared", "        $transport = $this->transport;\n        $context = $prepared['order']['context'];\n        $request = $prepared['intent']['body'];\n        try {\n            $sessionId = $prepared", SUITE),
 ('R10-adapter-ceiling', D+'ProductionTaxPaidLineAdapterV2.php', "CheckoutException::require($net >= 0 && $net * $context['tax']['maximum_rate_bps'] >= $tax['order_tax_minor'] * 10000, 'tax_ceiling');", "", SUITE),
 ('R11-adapter-source-hash', D+'ProductionTaxPaidLineAdapterV2.php', "CheckoutException::require(is_string($line['source_hash']) && hash_equals(CanonicalJson::hash($source), $line['source_hash']), 'source_hash');", "", SUITE),
 ('R12-policy-environment', D+'TaxCheckoutPolicy.php', "return app()->environment('local', 'testing') && config('production-tax-checkout.enabled') === true;", "return config('production-tax-checkout.enabled') === true;", SUITE),
 ('R13-session-client-reference', D+'TaxCheckoutEvidence.php', "&& ($session['client_reference_id'] ?? null) === $request['order_public_id']", "", SUITE+[PROBE]),
 ('R14-controller-exact-keys', 'app/Http/Controllers/ProductionTaxCheckoutController.php', "Evidence::keys($body, ['candidateId', 'items', 'previewHash', 'accepted', 'buyer', 'requestKey']);", "", SUITE),
]
