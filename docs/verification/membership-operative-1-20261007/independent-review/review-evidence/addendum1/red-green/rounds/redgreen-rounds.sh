#!/usr/bin/env bash
# Completing reviewer: red/green per later round, SQLite, in the scratch red-green worktree.
# Usage: redgreen-rounds.sh <worktree> <outdir>. For each round: tests and app at HEAD (green); app files reverted to BASE (red).
W=$1; O=$2
cd "$W" || exit 90
export APP_KEY=base64:U1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1M=
P() { php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never "$@"; }
B=app/Domain/Memberships/Billing
round() { # label base head "appfiles" "classes"
  label=$1 base=$2 head=$3 files=$4 classes=$5
  git checkout -q --detach "$head" || exit 91
  echo "# $label: head $(git rev-parse HEAD) base $base status-lines=$(git status --porcelain -- app tests | wc -l)" >> $O/$label.txt
  for phase in red green; do
    if [ $phase = red ]; then for f in $files; do git show "$base:$f" > "$f"; done; fi
    echo "## $phase (app $( [ $phase = red ] && echo "$files at $base" || echo "at $head"))" >> $O/$label.txt
    git status --porcelain -- app tests >> $O/$label.txt
    for c in $classes; do
      echo "### $c" >> $O/$label.txt
      P tests/Feature/ProductionMembershipBilling/$c.php --log-junit $O/$label-$phase-$c.xml >> $O/$label.txt 2>&1
      rc=$?
      echo "rc=$rc" >> $O/$label.txt
    done
    if [ $phase = red ]; then git checkout -q -- app; echo "restored status-lines=$(git status --porcelain -- app tests | wc -l)" >> $O/$label.txt; fi
  done
}
round round2-af089d40 9c2f99289da36a17f405e68ab7396fa2d7d6a9d9 af089d401376205adbe9db36234a4f1e6b5a1ec4 "$B/BillingWebhookIntake.php" "BillingWebhookRedeliveryTest"
round round3-4716ae52 af089d401376205adbe9db36234a4f1e6b5a1ec4 4716ae5278607b87a31317b343e4550e8403e0dc "$B/BillingWebhookIntake.php" "BillingWebhookIntakeTest"
round round4-e26d4cb7 4716ae5278607b87a31317b343e4550e8403e0dc e26d4cb74d464943178201133612e73b16945cf1 "$B/BillingReconciliation.php $B/BillingWebhookIntake.php" "BillingUnknownOutcomeTest BillingWebhookRedeliveryTest StripeSdkBillingGatewayTest"
echo DONE >> $O/rounds-done.txt
