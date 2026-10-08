<?php
// Profiles one IdentityMigrationOwnership::inspect() on the configured MySQL schema: time per statement.
chdir($argv[1]);
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
class TimedStatement extends PDOStatement {
    public static array $log = [];
    protected function __construct() {}
    public function execute(?array $params = null): bool { $t = hrtime(true); $r = parent::execute($params); self::$log[] = [(hrtime(true) - $t) / 1e6, substr(preg_replace('/\s+/', ' ', $this->queryString), 0, 150)]; return $r; }
}
class TimedPdo extends PDO {
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false { $t = hrtime(true); $r = parent::query($query); TimedStatement::$log[] = [(hrtime(true) - $t) / 1e6, substr($query, 0, 150)]; return $r; }
}
$c = Illuminate\Support\Facades\DB::connection()->getConfig();
$pdo = new TimedPdo('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['database'].';charset=utf8mb4', $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STATEMENT_CLASS => ['TimedStatement', []]]);
$pdo->exec("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
TimedStatement::$log = [];
$t = hrtime(true);
(new App\Domain\Customers\ProductionIdentity\IdentityMigrationOwnership)->inspect($pdo, 'mysql');
printf("total %.1f ms, %d timed statements\n", (hrtime(true) - $t) / 1e6, count(TimedStatement::$log));
$log = TimedStatement::$log; usort($log, fn ($a, $b) => $b[0] <=> $a[0]);
foreach (array_slice($log, 0, (int) (getenv("TOP") ?: 25)) as [$ms, $sql]) printf("%8.1f  %s\n", $ms, $sql);
