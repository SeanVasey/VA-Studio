# Reviewer addendum-1 mutations at 30ec1c9a (code f8cc0322). (id, file, old, new, mode, selection). Exactly one occurrence.
D = 'app/Domain/Commerce/ProductionTaxCheckout/'
T = 'tests/Feature/ProductionTaxCheckout/'
R = '/home/user/VA-Studio-review-tax255b/docs/verification/tax-255-20261007/independent-review/review-evidence/'
OLDPROBE = R + 'probes/TaxReviewMoneyProbeTest.php::test_q1_provider_facts'
BIND = R + 'addendum1/probes/TaxReviewAddendumBindingProbeTest.php'
J = T + 'ProductionTaxCheckoutJourneyTest.php'
SV = T + 'ProductionTaxSourceV2Test.php'
NI = T + 'ProductionTaxCheckoutNativeInstallerTest.php::test_native_installer_refuses_before_ddl@'
I = D + 'TaxCheckoutSchemaInstaller.php'
M = [
 ('A-R1-pi-amount-received', D+'TaxCheckoutEvidence.php', "CheckoutException::require($payment['amount_received'] === $total && $payment['amount_capturable'] === 0", "CheckoutException::require($payment['amount_capturable'] === 0", 'sqlite', [J, OLDPROBE]),
 ('A-R2-pi-amount', D+'TaxCheckoutEvidence.php', "&& ($payment['amount'] ?? null) === $total && is_int", "&& is_int", 'sqlite', [J, OLDPROBE]),
 ('A-R3-pi-currency', D+'TaxCheckoutEvidence.php', "=== ($context['funds_mode'] === 'live') && ($payment['currency'] ?? null) === 'usd'", "=== ($context['funds_mode'] === 'live')", 'sqlite', [J, OLDPROBE]),
 ('A-R4-session-currency', D+'TaxCheckoutEvidence.php', "&& ($session['currency'] ?? null) === 'usd' && ($session['client_reference_id']", "&& ($session['client_reference_id']", 'sqlite', [J, OLDPROBE]),
 ('A-B1-drop-source-binding', D+'ProductionTaxPaidLineAdapterV2.php', "        CheckoutException::require(CanonicalJson::encode($source->line($position)) === CanonicalJson::encode($line), 'source_binding');\n", "", 'sqlite', [SV, BIND]),
 ('A-B2-drop-live-unsupported', D+'ProductionTaxPaidLineAdapterV2.php', "        CheckoutException::require($provenance === 'synthetic_rehearsal', 'live_unsupported');\n", "", 'sqlite', [SV, BIND]),
 ('A-S1-initiate-session-mismatch', D+'ProductionTaxCheckout.php', "            CheckoutException::require($safe['session_id'] === $sessionId, 'session_mismatch');\n        } catch", "        } catch", 'sqlite', [J]),
 ('A-S2-reconcile-session-mismatch', D+'ProductionTaxCheckout.php', "            CheckoutException::require($safe['session_id'] === $sessionId, 'session_mismatch');\n            $payment", "            $payment", 'sqlite', [J]),
 ('A-N1-drifted-guard-body', I, " || $object['ACTION_STATEMENT'] !== $expected['body']", "", 'native', [NI+'drifted guard body']),
 ('A-N2-interior-gap', I, "$this->reject('installation gap');", "", 'native', [NI+'interior gap']),
 ('A-N3-populated-incomplete', I, "$this->reject('populated incomplete installation');", "", 'native', [NI+'populated incomplete installation']),
 ('A-N4-temporary-shadow', I, "str_starts_with($shown['Create Table'] ?? '', 'CREATE TEMPORARY TABLE ') || ", "", 'native', [NI+'temporary shadow']),
 ('A-N5-foreign-trigger', I, "$this->reject('foreign guard reference');", "", 'native', [NI+'foreign trigger']),
 ('A-N6-foreign-routine', I, "$this->reject('foreign or opaque routine reference');", "", 'native', [NI+'foreign routine']),
 ('A-N7-additional-index', I, "$this->same($indexes, $actual, 'indexes');", "", 'native', [NI+'additional owned index']),
 ('A-N8-column-drift', I, "$this->same($expected, array_map(fn ($row): array => (array) $row, $columns), 'columns');", "", 'native', [NI+'column collation drift']),
 ('A-N9-definer', I, " || $object['DEFINER'] !== $identity->definer", "", 'native', [NI+'guard recreated under another definer']),
 ('A-N10-sql-mode', I, "\n                        || $object['SQL_MODE'] !== $identity->sql_mode", "", 'native', [NI+'guard recreated under another SQL mode']),
]
# Judgement call 6: checks whose reviewer cases were not ported. Lane suite (Journey) vs reviewer money probe.
M += [
 ('A-U1-pi-livemode', D+'TaxCheckoutEvidence.php', "&& ($payment['livemode'] ?? null) === ($context['funds_mode'] === 'live') && ($payment['currency']", "&& ($payment['currency']", 'sqlite', [J, OLDPROBE]),
 ('A-U2-pi-metadata', D+'TaxCheckoutEvidence.php', "            Evidence::same($request['params']['payment_intent_data']['metadata'], $payment['metadata'] ?? null);\n", "", 'sqlite', [J, OLDPROBE]),
 ('A-U3-pi-on-behalf-of', D+'TaxCheckoutEvidence.php', "'application', 'application_fee_amount', 'on_behalf_of', ", "'application', 'application_fee_amount', ", 'sqlite', [J, OLDPROBE]),
 ('A-U4-line-currency', D+'TaxCheckoutEvidence.php', "($line['currency'] ?? null) === 'usd' && ", "", 'sqlite', [J, OLDPROBE]),
 ('A-U5-pi-not-succeeded-while-paid', D+'TaxCheckoutEvidence.php', "                CheckoutException::require($safe['payment_status'] !== 'paid', 'provider_inconsistent');\n", "", 'sqlite', [J, OLDPROBE]),
]
