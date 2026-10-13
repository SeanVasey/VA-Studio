#!/usr/bin/env bash
set -u
set -o pipefail
umask 077
evidence=/workspace/runtime/billing-retrieval-records-evidence/award-upgrade
phase=${1:?old or new phase required}
case "$phase" in
  old) root=/workspace/VA-Studio-billing-c3-baseline; probe=OldWriterAwardTest.php ;;
  new) root=/workspace/VA-Studio-billing-award-upgrade; probe=NewWriterAwardReplayTest.php ;;
  *) exit 2 ;;
esac
if test -e "$evidence/$phase-phpunit.exit"; then
  printf 'Preserve prior phase evidence before a further run.\n' >&2
  exit 2
fi
cd "$root"
source /workspace/.va-studio-toolchain/activate.sh
export APP_BASE_PATH="$root"
export APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE="$evidence/award.sqlite" DB_URL=
export XDEBUG_MODE=off
python3 "$evidence/source_receipt.py" "$phase" before || exit 2
php -r 'require "vendor/autoload.php"; echo "PHP ", PHP_VERSION, PHP_EOL; echo "SQLite ", (new PDO("sqlite::memory:"))->query("select sqlite_version()")->fetchColumn(), PHP_EOL; foreach ([App\Domain\Memberships\Billing\BillingLedger::class, App\Domain\Memberships\Production\MembershipAwards::class, Tests\Support\MembershipAwardFixtures::class, App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions::class] as $class) { echo $class, " ", (new ReflectionClass($class))->getFileName(), PHP_EOL; }' > "$evidence/$phase-runtime.txt"
sha256sum "$evidence/award.sqlite" > "$evidence/$phase-database-before.sha256"
php vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result --colors=never --log-junit "$evidence/$phase-phpunit.xml" "$evidence/$probe" > "$evidence/$phase-phpunit.log" 2>&1
phase_status=$?
printf '%s\n' "$phase_status" > "$evidence/$phase-phpunit.exit"
python3 "$evidence/source_receipt.py" "$phase" after || exit 2
sha256sum "$evidence/award.sqlite" > "$evidence/$phase-database-after.sha256"
cp "$evidence/award.sqlite" "$evidence/$phase-after.sqlite"
cat "$evidence/$phase-runtime.txt" "$evidence/$phase-phpunit.log"
exit "$phase_status"
