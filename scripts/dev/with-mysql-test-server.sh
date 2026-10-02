#!/usr/bin/env bash
# Run one command and its child workers against a disposable, loopback-only MySQL 8.4.
set -euo pipefail

: "${MYSQL_TEST_BASEDIR:?Set MYSQL_TEST_BASEDIR to an extracted official MySQL 8.4 distribution}"
if [[ $# -eq 0 ]]; then
    echo 'Usage: with-mysql-test-server.sh <test command> [arguments...]' >&2
    exit 2
fi
mysql_server="$MYSQL_TEST_BASEDIR/bin/mysqld"
mysql_library_path="${MYSQL_TEST_LIBRARY_PATH:-}"
mysql_version=$(LD_LIBRARY_PATH="$mysql_library_path${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}" "$mysql_server" --version)
[[ "$mysql_version" == *'Ver 8.4.'* ]] || { echo 'MySQL 8.4 is required.' >&2; exit 2; }
command -v php >/dev/null
mysql_test_directory=$(mktemp -d "${TMPDIR:-/tmp}/vasey-mysql-test.XXXXXXXX")
mysql_test_pid=''
cleanup() {
    if [[ -n "$mysql_test_pid" ]]; then
        kill "$mysql_test_pid" 2>/dev/null || true
        wait "$mysql_test_pid" 2>/dev/null || true
    fi
    # Only the unique directory created by this invocation is eligible for removal.
    rm -rf -- "$mysql_test_directory"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
chmod 700 "$mysql_test_directory"
mkdir "$mysql_test_directory/data"
export APP_ENV=testing APP_DEBUG=false DB_CONNECTION=mysql DB_HOST=127.0.0.1
export DB_PORT="${MYSQL_TEST_PORT:-33067}" DB_SOCKET='' DB_URL='' DB_DATABASE=vaseyaudio_test DB_USERNAME=root
export DB_PASSWORD
DB_PASSWORD=$(php -r 'echo bin2hex(random_bytes(24));')
export APP_KEY
APP_KEY=$(php -r 'echo "base64:".base64_encode(random_bytes(32));')
export CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array
php -r 'file_put_contents($argv[1], "ALTER USER '\''root'\''@'\''localhost'\'' IDENTIFIED BY '\''".getenv("DB_PASSWORD")."'\'';\n");' "$mysql_test_directory/init.sql"
chmod 600 "$mysql_test_directory/init.sql"
LD_LIBRARY_PATH="$mysql_library_path${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}" "$mysql_server" --no-defaults \
    --initialize-insecure --user="$(id -un)" --basedir="$MYSQL_TEST_BASEDIR" \
    --datadir="$mysql_test_directory/data" --log-error="$mysql_test_directory/initialize.log"
LD_LIBRARY_PATH="$mysql_library_path${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}" "$mysql_server" --no-defaults \
    --user="$(id -un)" --basedir="$MYSQL_TEST_BASEDIR" --datadir="$mysql_test_directory/data" \
    --socket= --bind-address=127.0.0.1 --port="$DB_PORT" --mysqlx=OFF \
    --performance-schema=ON --innodb-buffer-pool-size=64M --max-connections=20 \
    --pid-file="$mysql_test_directory/mysql.pid" --log-error="$mysql_test_directory/server.log" \
    --init-file="$mysql_test_directory/init.sql" &
mysql_test_pid=$!
php -r '
    $deadline = microtime(true) + 30;
    do {
        try {
            $p = new PDO("mysql:host=127.0.0.1;port=".getenv("DB_PORT"), "root", getenv("DB_PASSWORD"));
            $p->exec("CREATE DATABASE vaseyaudio_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            echo json_encode($p->query("SELECT VERSION() AS version, @@performance_schema AS performance_schema, @@socket AS socket, @@bind_address AS bind_address, @@transaction_isolation AS isolation")->fetch(PDO::FETCH_ASSOC)), PHP_EOL;
            exit(0);
        } catch (PDOException $error) { usleep(100000); }
    } while (microtime(true) < $deadline);
    fwrite(STDERR, "Disposable MySQL startup did not become ready.\n"); exit(1);
' || { cat "$mysql_test_directory/server.log" >&2; exit 1; }
rm -f -- "$mysql_test_directory/init.sql"
"$@"
