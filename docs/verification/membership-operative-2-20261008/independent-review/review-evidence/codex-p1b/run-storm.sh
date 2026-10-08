#!/bin/bash
# Review storm runner: the original lane-2 review's cross-invoice probes (split + 4-worker storm), run alone on the private
# instance (InnoDB's lock_deadlocks metric is server-global), alternating the head tree and a scratch copy without lockIdentity().
E=/home/user/VA-Studio-review-m54/docs/verification/membership-operative-2-20261008/independent-review/review-evidence/codex-p1b
P=docs/verification/membership-operative-2-20261008/independent-review/review-evidence/probes/Lane2ReviewProbeTest.php
for n in 1 2; do
  for label in head nolock; do
    if [ $label = head ]; then dir=/home/user/VA-Studio-review-m54; db=r54b_storm; else dir=/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/r54p1b/mut59; db=r54b_storm_mut; fi
    out=$E/storm-$label-$n.txt
    ( cd $dir
      export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3794 DB_DATABASE=$db DB_USERNAME=root DB_PASSWORD= DB_URL= DB_SOCKET= APP_ENV=testing
      echo "# tree: $label ($dir); head 59e8f8e2$( [ $label = nolock ] && echo ' with BillingLedger.php:172 $this->lockIdentity(...) removed (scratch copy)'); database $db on 127.0.0.1:3794"
      echo "# cmd: php -r '...phpunit' -- --colors=never --filter 'test_native_cross_invoice_wait_split_between_claim_and_append|test_native_concurrent_retrievals_of_different_invoices' $P"
      echo "# start: $(date -u +%FT%TZ)"
      php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --filter 'test_native_cross_invoice_wait_split_between_claim_and_append|test_native_concurrent_retrievals_of_different_invoices' $P 2>&1
      echo "rc=$?"; echo "# end: $(date -u +%FT%TZ)" ) > $out 2>&1
  done
done
