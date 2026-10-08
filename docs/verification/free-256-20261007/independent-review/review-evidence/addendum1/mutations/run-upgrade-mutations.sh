#!/usr/bin/env bash
# U1-U3 against the fixed code, in a separate detached worktree at 68c4e03c; each mutation is reverted and the tree proven clean.
M=/home/user/VA-Studio-review-free256b-mut
A=/home/user/VA-Studio-review-free256b/docs/verification/free-256-20261007/independent-review/review-evidence/addendum1
PROBE=/home/user/VA-Studio-review-free256b/docs/verification/free-256-20261007/independent-review/review-evidence/probes/UpgradeProbeTest.php
W=/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-free256b-upgrade
mkdir -p $W; : > $W/db.sqlite
cd "$M" || exit 9
R() { php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never "$@"; }
export DB_CONNECTION=sqlite DB_DATABASE=$W/db.sqlite VA_REVIEW_STATE=$W/state.json
R --filter test_prepare $PROBE > $A/mutations/prepare.txt 2>&1; echo "rc=$?" >> $A/mutations/prepare.txt
cp $W/db.sqlite $W/db-prepared.sqlite
for U in control U1 U2 U3; do
  out=$A/mutations/$U.txt
  cp $W/db-prepared.sqlite $W/db.sqlite
  { echo "== $U at $(git rev-parse HEAD)"; [ $U != control ] && python3 $A/mutations/mutate.py $U; git diff --stat; git diff; } > $out 2>&1
  VA_REVIEW_LABEL=$U R --filter test_observe $PROBE >> $out 2>&1; echo "rc=$?" >> $out
  git checkout -- app database resources; git diff --quiet -- app database resources; echo "after revert: git diff --quiet -- app database resources rc=$?" >> $out
  grep PROBE $out
done
git status --short
