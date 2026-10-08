#!/usr/bin/env python3
"""Reviewer mutations for lane 2. Usage: python3 -I mutate.py <worktree> <outdir> [ids...]
Each mutation edits one app file, runs its selected test classes on SQLite, records PHPUnit's rc, restores with git checkout and
verifies the file hash equals HEAD's. A mutation is KILLED when any selected run exits non-zero."""
import hashlib, json, os, subprocess, sys
W, O = sys.argv[1], sys.argv[2]
B = 'app/Domain/Memberships/Billing/'
T = 'tests/Feature/ProductionMembershipBilling/'
MUTS = [
 ('R1-no-pin-drop', 'app/Domain/Memberships/Production/MembershipRows.php', "        $this->pin = null;\n\n        throw new MembershipException('clone_refused');", "        throw new MembershipException('clone_refused');", ['tests/Feature/ProductionMembership/MembershipRowsFunctionClosureTest.php']),
 ('R1-no-clone-guard', 'app/Domain/Memberships/Production/MembershipRows.php', "        $this->pin = null;\n\n        throw new MembershipException('clone_refused');", "", ['tests/Feature/ProductionMembership/MembershipRowsFunctionClosureTest.php']),
 ('R6-no-app-check', B+'BillingLedger.php', "                    BillingException::require($last === null || $last['retrieval_started_at'] <= $started, 'superseded_retrieval');\n", "", [T+'BillingOverlappingRetrievalTest.php', T+'BillingRetrievalJobTest.php']),
 ('R6-strict-equal', B+'BillingLedger.php', "$last['retrieval_started_at'] <= $started", "$last['retrieval_started_at'] < $started", [T+'BillingOverlappingRetrievalTest.php', T+'BillingRetrievalJobTest.php', T+'BillingObservationLedgerTest.php', T+'BillingUnknownOutcomeTest.php']),
 ('R6-no-trigger-clause', B+'BillingSchema.php', "                .' AND o.retrieval_started_at <= NEW.retrieval_started_at))';", "                .'))';", [T+'BillingSchemaPreparationTest.php']),
 ('R6-no-mysql-lock', B+'BillingLedger.php', "                        $this->lockIdentity($invoice['id']);", "                        // lock removed", [T+'BillingOverlappingRetrievalTest.php']),
 ('R6-start-after-io', B+'BillingReconciliation.php', "            $snapshots = $this->snapshots($invoiceRef, $provenance);\n", "            $snapshots = $this->snapshots($invoiceRef, $provenance);\n            $startedAt = CarbonImmutable::now('UTC');\n", [T+'BillingOverlappingRetrievalTest.php']),
 ('A11-no-validated', B+'BillingReconciliation.php', "(in_array($verdict->reason, self::BINDING_REFUSALS, true) || ! $validated)", "in_array($verdict->reason, self::BINDING_REFUSALS, true)", [T+'BillingObservationLedgerTest.php']),
 ('A11-validated-ignores-invoice-id', B+'BillingSettlement.php', "&& ($invoice['object'] ?? null) === 'invoice' && ($invoice['id'] ?? null) === $s->requestedInvoiceRef\n", "\n", [T+'BillingObservationLedgerTest.php', T+'BillingSettlementTest.php']),
 ('A12-no-release', 'app/Jobs/RetrieveMembershipInvoice.php', "            $this->release($backoff[$attempt - 1] ?? $backoff[array_key_last($backoff)]);", "            // release removed", [T+'BillingRetrievalJobTest.php']),
 ('A12-tries-1', 'app/Jobs/RetrieveMembershipInvoice.php', "    public int $tries = 3;", "    public int $tries = 1;", [T+'BillingRetrievalJobTest.php']),
 ('A12-superseded-rethrown', 'app/Jobs/RetrieveMembershipInvoice.php', "            if ($error->reason === 'superseded_retrieval') {\n                return;\n            }\n", "", [T+'BillingRetrievalJobTest.php']),
 ('A12-sweep-no-dedupe', B+'BillingHintSweep.php', "            if (isset($seen[$hint['invoice_hash']])) {\n                continue;\n            }\n            $seen[$hint['invoice_hash']] = true;", "            $seen[] = true;", [T+'BillingSweepHintsCommandTest.php']),
 ('A12-sweep-no-policy', 'app/Console/Commands/SweepMembershipBillingHints.php', "            $configuration = $policy->current();", "            $configuration = ['account_ref' => 'acct_SYNTHETICREHEARSAL', 'mode' => 'test'];", [T+'BillingSweepHintsCommandTest.php']),
 ('A13-created-at', B+'BillingHintRecovery.php', "o.retrieval_started_at >= ?", "o.created_at >= ?", [T+'BillingOverlappingRetrievalTest.php', T+'BillingWebhookRedeliveryTest.php']),
 ('A13-same-second-covers', B+'BillingHintRecovery.php', "BillingValues::utcMicro($received->addSecond())", "BillingValues::utcMicro($received)", [T+'BillingOverlappingRetrievalTest.php', T+'BillingWebhookRedeliveryTest.php']),
 ('A14-plaintext-ref', 'app/Jobs/RetrieveMembershipInvoice.php', "        $this->sealedInvoiceRef = BillingValues::encrypt(['schema_version' => 1, 'purpose' => 'production_membership_billing_retrieval_job', 'invoice_ref' => $invoiceRef]);", "        $this->sealedInvoiceRef = $invoiceRef;", [T+'BillingRetrievalJobTest.php']),
 ('A14-generic-message', B+'BillingException.php', "parent::__construct('Production membership billing unavailable ('.(preg_match('/\\A[a-z0-9_]{1,80}\\z/D', $reason) === 1 ? $reason : 'unavailable').').');", "parent::__construct('Production membership billing unavailable.');", [T+'BillingRetrievalJobTest.php']),
 ('A14-unfiltered-reason', B+'BillingException.php', "(preg_match('/\\A[a-z0-9_]{1,80}\\z/D', $reason) === 1 ? $reason : 'unavailable')", "$reason", [T+'BillingRetrievalJobTest.php']),
]
sel = set(sys.argv[3:])
env = dict(os.environ, APP_KEY='base64:U1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1M=')
summary = []
def sha(p): return hashlib.sha256(open(os.path.join(W, p), 'rb').read()).hexdigest()
def head_sha(p): return hashlib.sha256(subprocess.run(['git', '-C', W, 'show', 'HEAD:'+p], capture_output=True, check=True).stdout).hexdigest()
for mid, path, old, new, tests in MUTS:
    if sel and mid not in sel: continue
    src = open(os.path.join(W, path)).read()
    assert src.count(old) == 1, (mid, src.count(old))
    open(os.path.join(W, path), 'w').write(src.replace(old, new))
    diff = subprocess.run(['git', '-C', W, 'diff', '--', path], capture_output=True, text=True).stdout
    open(f'{O}/{mid}.diff', 'w').write(diff)
    rcs = []
    with open(f'{O}/{mid}.txt', 'w') as log:
        for t in tests:
            log.write(f'### {t}\n'); log.flush()
            r = subprocess.run(['php', '-r', '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";', '--', '--colors=never', t],
                               cwd=W, env=env, stdout=log, stderr=subprocess.STDOUT)
            log.write(f'rc={r.returncode}\n'); log.flush()
            rcs.append(r.returncode)
    subprocess.run(['git', '-C', W, 'checkout', '-q', '--', path], check=True)
    restored = sha(path) == head_sha(path)
    status = subprocess.run(['git', '-C', W, 'status', '--porcelain', '--', 'app', 'tests', 'database'], capture_output=True, text=True).stdout.strip()
    verdict = 'KILLED' if any(rcs) else 'SURVIVED'
    summary.append({'id': mid, 'file': path, 'rcs': rcs, 'verdict': verdict, 'restored_hash_matches_head': restored, 'clean_status': status == ''})
    print(json.dumps(summary[-1]), flush=True)
with open(f'{O}/summary.jsonl', 'a') as f:
    for s in summary: f.write(json.dumps(s) + '\n')
