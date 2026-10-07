<?php

namespace Tests\Feature\ReviewProbes;

use App\Domain\Commerce\ProductionPolicy\CapabilityMigrationOwnership;
use App\Domain\Commerce\ProductionPreparation\ProductionBuyerAssentObservations;
use App\Domain\Customers\ProductionIdentity\IdentityMigrationOwnership;
use App\Domain\Customers\ProductionIdentity\IdentitySchema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use PDOStatement;
use ReflectionMethod;
use Tests\TestCase;
use Throwable;

/**
 * Independent reviewer probes for PR #50 (native schema isolation). Not part of the suite.
 * Native MySQL only. Every peer schema is created by the probe after proving it absent,
 * and dropped in teardown. The selected schema's owned catalog is compared before/after.
 *
 * Expected outcomes encode the review's reading of MySQL name resolution:
 *  - an object in another schema whose stored text names the selected schema and an owned table: refused;
 *  - an object in another schema whose unqualified names resolve to its own schema: admitted;
 *  - over-refusals (safe direction) are recorded as characterizations.
 */
class ReviewerSchemaIsolationProbeTest extends TestCase
{
    private const TABLES = ['capability' => ProductionBuyerAssentObservations::TABLE, 'inquiry' => 'inquiry_notification_intents', 'identity' => 'production_identity_origins'];

    private static bool $migrated = false;

    private string $database;

    /** @var list<string> peers this test created */
    private array $peers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native MySQL probe.');
        }
        $this->assertTrue($this->app->environment('testing'));
        $this->database = DB::connection()->getPdo()->query('SELECT DATABASE()')->fetchColumn();
        $this->beforeApplicationDestroyed(function (): void {
            foreach ($this->peers as $peer) {
                DB::connection()->getPdo()->exec('DROP DATABASE IF EXISTS `'.$peer.'`');
            }
        });
        if (! self::$migrated) {
            $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
            self::$migrated = true;
        }
        DB::connection()->getSchemaBuilder();
        foreach (array_keys(self::TABLES) as $guard) {
            $this->assertSame('admitted', $this->outcome($guard), "baseline {$guard} must admit");
        }
    }

    /** 2(a): a view created in a peer schema while the selected schema is the default stores a qualified name. */
    public function test_p1_peer_view_created_with_selected_default_is_stored_qualified_and_refused(): void
    {
        $pdo = DB::connection()->getPdo();
        $peer = $this->peer('_rv1');
        $before = $this->catalog();
        foreach (self::TABLES as $guard => $table) {
            $pdo->exec('CREATE VIEW `'.$peer.'`.`p1_'.$guard.'` AS SELECT id FROM '.$table); // unqualified, default db = selected
            $definition = $this->one('SELECT VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', [$peer, 'p1_'.$guard]);
            $this->log("P1 {$guard}: VIEW_DEFINITION=".$definition);
            $this->assertStringContainsString('`'.$this->database.'`.`'.$table.'`', $definition);
            foreach (array_keys(self::TABLES) as $other) {
                $result = $this->outcome($other);
                $this->log("P1 view on {$table} -> {$other}: {$result}");
                $this->assertSame($other === $guard, $result !== 'admitted', "P1 {$guard} view vs {$other}");
            }
            $pdo->exec('DROP VIEW `'.$peer.'`.`p1_'.$guard.'`');
        }
        $this->assertSame($before, $this->catalog());
    }

    /** 2(a): a routine or trigger defined from the selected default database still resolves unqualified names to its own schema. */
    public function test_p2_peer_routine_and_trigger_created_with_selected_default_resolve_to_their_own_schema(): void
    {
        $pdo = DB::connection()->getPdo();
        $peer = $this->peer('_rv2');
        $before = $this->catalog();
        $table = self::TABLES['identity'];
        $pdo->exec('CREATE PROCEDURE `'.$peer.'`.p2_routine() SELECT COUNT(*) FROM '.$table);
        $pdo->exec('CREATE TABLE `'.$peer.'`.p2_source (id BIGINT) ENGINE=InnoDB');
        $pdo->exec('CREATE TRIGGER `'.$peer.'`.p2_trigger BEFORE INSERT ON `'.$peer.'`.p2_source FOR EACH ROW SET @p2 = (SELECT COUNT(*) FROM '.$table.')');
        $this->log('P2 ROUTINE_DEFINITION='.$this->one('SELECT ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=? AND ROUTINE_NAME=?', [$peer, 'p2_routine']));
        foreach (['CALL `'.$peer.'`.p2_routine()', 'INSERT INTO `'.$peer.'`.p2_source VALUES (1)'] as $statement) {
            try {
                $pdo->query($statement)?->fetchAll();
                $this->fail('expected 1146 for '.$statement);
            } catch (PDOException $error) {
                $this->log('P2 '.$statement.' -> '.$error->errorInfo[1].' '.$error->errorInfo[2]);
                $this->assertSame(1146, $error->errorInfo[1]);
                $this->assertStringContainsString("'{$peer}.{$table}'", $error->errorInfo[2]);
            }
        }
        foreach (array_keys(self::TABLES) as $guard) {
            $result = $this->outcome($guard);
            $this->log("P2 -> {$guard}: {$result}");
            $this->assertSame('admitted', $result);
        }
        $this->assertSame($before, $this->catalog());
    }

    /** 2(a)/(b): qualifier spellings an author could use: backticks, comments, whitespace, ANSI quotes, upper case. */
    public function test_p3_quoted_commented_ansi_and_mixed_case_qualifiers_are_refused(): void
    {
        $pdo = DB::connection()->getPdo();
        $peer = $this->peer('_rv3');
        $before = $this->catalog();
        $pdo->exec('CREATE TABLE `'.$peer.'`.p3_source (id BIGINT) ENGINE=InnoDB');
        $db = $this->database;
        foreach (self::TABLES as $guard => $table) {
            $variants = [
                'backtick+comment' => ['trigger', "SET @p3 = (SELECT COUNT(*) FROM `{$db}` /* note */ . `{$table}`)"],
                'unquoted+newlines' => ['procedure', "SELECT COUNT(*) FROM {$db}\n.\n{$table}"],
                'ansi-quotes' => ['ansi', "SET @p3 = (SELECT COUNT(*) FROM \"{$db}\".\"{$table}\")"],
                'upper-case-qualifier' => ['trigger', 'SET @p3 = (SELECT COUNT(*) FROM `'.strtoupper($db).'`.`'.$table.'`)'],
                'line-comment-then-name' => ['procedure', "SELECT COUNT(*) FROM -- x\n `{$db}`.`{$table}`"],
            ];
            foreach ($variants as $label => [$kind, $body]) {
                if ($kind === 'procedure') {
                    $pdo->exec('CREATE PROCEDURE `'.$peer.'`.p3_dep() '.$body);
                } else {
                    if ($kind === 'ansi') {
                        $mode = $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
                        $pdo->exec("SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',ANSI_QUOTES')");
                    }
                    $pdo->exec('CREATE TRIGGER `'.$peer.'`.p3_dep BEFORE INSERT ON `'.$peer.'`.p3_source FOR EACH ROW '.$body);
                    if ($kind === 'ansi') {
                        $statement = $pdo->prepare('SET SESSION sql_mode = ?');
                        $statement->execute([$mode]);
                    }
                }
                $result = $this->outcome($guard);
                $this->log("P3 {$guard} {$label}: {$result}");
                $this->assertNotSame('admitted', $result, "P3 {$guard} {$label}");
                $pdo->exec($kind === 'procedure' ? 'DROP PROCEDURE `'.$peer.'`.p3_dep' : 'DROP TRIGGER `'.$peer.'`.p3_dep');
            }
        }
        $this->assertSame($before, $this->catalog());
    }

    /** 2(b): schema names that are prefixes of each other, or extend the selected name with a non-word character. */
    public function test_p4_prefix_and_extension_named_peers(): void
    {
        $pdo = DB::connection()->getPdo();
        $before = $this->catalog();
        $shorter = substr($this->database, 0, strrpos($this->database, '_'));
        $this->assertNotSame('', $shorter);
        $cases = ['longer-underscore' => $this->database.'_rv4', 'shorter-prefix' => $shorter, 'hyphen-extension' => $this->database.'-rv4', 'dollar-extension' => $this->database.'$rv4'];
        foreach ($cases as $label => $name) {
            $peer = $this->peer('', $name);
            foreach (self::TABLES as $guard => $table) {
                $pdo->exec('CREATE TABLE `'.$peer.'`.`'.$table.'` (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
                $pdo->exec('CREATE VIEW `'.$peer.'`.`p4_'.$guard.'_view` AS SELECT id FROM `'.$peer.'`.`'.$table.'`');
                $pdo->exec('CREATE PROCEDURE `'.$peer.'`.`p4_'.$guard.'_routine`() SELECT COUNT(*) FROM `'.$peer.'`.`'.$table.'`');
                $pdo->exec('CREATE TRIGGER `'.$peer.'`.`p4_'.$guard.'_trigger` BEFORE INSERT ON `'.$peer.'`.`'.$table.'` FOR EACH ROW SET @p4 = (SELECT COUNT(*) FROM '.$table.')');
            }
            foreach (array_keys(self::TABLES) as $guard) {
                $result = $this->outcome($guard);
                $this->log("P4 {$label} ({$peer}) -> {$guard}: {$result}");
                if (in_array($label, ['longer-underscore', 'shorter-prefix'], true)) {
                    $this->assertSame('admitted', $result, "P4 {$label} {$guard}");
                } else {
                    // Characterization: the peer's own qualified view/routine text contains the selected name followed by '-' or '$'.
                    $this->assertNotSame('admitted', $result, "P4 {$label} {$guard} (over-refusal characterization)");
                }
            }
            $pdo->exec('DROP DATABASE `'.$peer.'`');
        }
        $this->assertSame($before, $this->catalog());
    }

    /** 2(b): on lower_case_table_names=0 an upper-case peer is a different schema; the guards treat it as the selected one. */
    public function test_p5_case_variant_peer_schema_is_classified_as_selected(): void
    {
        $pdo = DB::connection()->getPdo();
        $this->assertSame(0, (int) $pdo->query('SELECT @@lower_case_table_names')->fetchColumn());
        $before = $this->catalog();
        $peer = $this->peer('', strtoupper($this->database));
        foreach (self::TABLES as $guard => $table) {
            $pdo->exec('CREATE TABLE `'.$peer.'`.`'.$table.'` (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
            $pdo->exec('CREATE TABLE `'.$peer.'`.`p5_'.$guard.'_source` (id BIGINT) ENGINE=InnoDB');
            $pdo->exec('CREATE TRIGGER `'.$peer.'`.`p5_'.$guard.'` BEFORE INSERT ON `'.$peer.'`.`p5_'.$guard.'_source` FOR EACH ROW SET @p5 = (SELECT COUNT(*) FROM '.$table.')');
        }
        foreach (array_keys(self::TABLES) as $guard) {
            $result = $this->outcome($guard);
            $this->log("P5 {$peer} unqualified same-named trigger -> {$guard}: {$result}");
            $this->assertNotSame('admitted', $result, 'P5 over-refusal characterization');
        }
        $this->assertSame($before, $this->catalog());
    }

    /** Pre-existing scope: EVENTS bodies are not part of any of the three server-wide scans. */
    public function test_p6_peer_event_naming_the_selected_schema_is_not_scanned(): void
    {
        $pdo = DB::connection()->getPdo();
        $peer = $this->peer('_rv6');
        $before = $this->catalog();
        foreach (self::TABLES as $guard => $table) {
            $pdo->exec('CREATE EVENT `'.$peer.'`.`p6_'.$guard.'` ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 1 DAY DISABLE DO DELETE FROM `'.$this->database.'`.`'.$table.'`');
        }
        foreach (array_keys(self::TABLES) as $guard) {
            $result = $this->outcome($guard);
            $this->log("P6 disabled peer EVENT deleting from {$this->database}.owned -> {$guard}: {$result}");
            $this->assertSame('admitted', $result, 'P6 characterization (events not scanned; unchanged from base)');
        }
        $this->assertSame($before, $this->catalog());
    }

    /** 2(c): every batched dictionary lookup returns exactly the per-name rows, including LOWER()/collation aliases. */
    public function test_p7_batched_lookups_equal_the_original_per_name_queries(): void
    {
        $pdo = DB::connection()->getPdo();
        $owner = new IdentityMigrationOwnership;
        $lookups = new ReflectionMethod($owner, 'lookups');
        $source = file_get_contents((new \ReflectionClass($owner))->getFileName());
        preg_match_all("/'(SELECT [^']*information_schema[^']*=\\?[^']*)'/", $source, $single);
        preg_match_all('/"(SELECT [^"]*information_schema[^"]*=\\?[^"]*)"/', $source, $double);
        $sqls = [];
        foreach (array_unique([...$single[1], ...$double[1]]) as $sql) {
            if (str_contains($sql, '$dictionary')) {
                foreach ([['ROUTINES', 'ROUTINE_SCHEMA', 'ROUTINE_NAME'], ['EVENTS', 'EVENT_SCHEMA', 'EVENT_NAME'], ['STATISTICS', 'TABLE_SCHEMA', 'INDEX_NAME'], ['TABLE_CONSTRAINTS', 'CONSTRAINT_SCHEMA', 'CONSTRAINT_NAME']] as [$d, $s, $c]) {
                    $sqls[] = str_replace(['$column', '$dictionary', '$schema'], [$c, $d, $s], $sql);
                }
            } else {
                $sqls[] = $sql;
            }
        }
        $this->log('P7 lookup statements found: '.count($sqls));
        foreach ($sqls as $sql) {
            $this->log('P7 sql: '.$sql);
        }
        $definitions = IdentitySchema::definitions();
        $names = ['users', 'customer_accounts', 'quote_owners', ...array_keys($definitions), ...array_keys(IdentitySchema::guards('mysql'))];
        foreach ($definitions as $definition) {
            $names = [...$names, ...array_keys($definition['unique']), ...array_keys($definition['foreign'])];
        }
        $parentGuards = (new ReflectionMethod($owner, 'parentGuards'))->invoke($owner, 'mysql');
        $names = array_values(array_unique([...$names, ...array_keys($parentGuards)]));
        $firstGuard = array_key_first(IdentitySchema::guards('mysql'));
        $firstReserved = null;
        foreach ($definitions as $definition) {
            $firstReserved ??= array_key_first($definition['unique']);
        }
        $this->assertIsString($firstReserved);
        // Adversarial aliases in the selected schema, on a non-owned table.
        $pdo->exec('CREATE TABLE `Customer_Accounts` (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB'); // case alias table (lctn=0)
        $pdo->exec('CREATE TABLE p7_alias_source (id BIGINT UNSIGNED PRIMARY KEY, v BIGINT) ENGINE=InnoDB');
        $pdo->exec('CREATE UNIQUE INDEX `'.strtoupper($firstReserved).'` ON p7_alias_source (v)');
        $accented = preg_replace('/e(?!.*e)/', 'é', $firstGuard);
        try {
            $pdo->exec('CREATE TRIGGER `'.$accented.'` BEFORE INSERT ON p7_alias_source FOR EACH ROW SET @p7 = 1');
            $this->log("P7 created accent alias trigger {$accented}");
        } catch (PDOException $error) {
            $this->log("P7 accent alias trigger {$accented} not creatable: ".$error->getMessage());
        }
        $reservedAccent = preg_replace('/[aeiou](?!.*[aeiou])/', 'é', $firstReserved);
        foreach (['TRIGGER `'.$reservedAccent.'` BEFORE INSERT ON p7_alias_source FOR EACH ROW SET @p7 = 2', 'INDEX `'.$reservedAccent.'` ON p7_alias_source (id, v)'] as $object) {
            try {
                $pdo->exec('CREATE '.$object);
                $this->log('P7 created accent alias of reserved name: '.$object);
            } catch (PDOException $error) {
                $this->log('P7 accent alias not creatable: '.$object.': '.$error->getMessage());
            }
        }
        foreach (['TRIGGERS' => 'TRIGGER_NAME', 'STATISTICS' => 'INDEX_NAME'] as $dictionary => $column) {
            $statement = $pdo->prepare("SELECT $column FROM information_schema.$dictionary WHERE ".($dictionary === 'TRIGGERS' ? 'TRIGGER_SCHEMA' : 'TABLE_SCHEMA')."=DATABASE() AND LOWER($column)=?");
            $statement->execute([$firstReserved]);
            $this->log("P7 per-name LOWER($column)='{$firstReserved}' returns: ".json_encode($statement->fetchAll(PDO::FETCH_COLUMN), JSON_UNESCAPED_UNICODE));
        }
        try {
            // v2: compare twice. Set 'requested' holds only names the inspection itself requests (plus one absent name),
            // so an alias row must survive the prefilter on its own; set 'superset' adds the alias spellings as names.
            // (v1 used only the superset; with the alias spelling in the IN list a binary prefilter still kept the row.)
            foreach (['requested' => [...$names, 'p7_absent_name'], 'superset' => [...$names, 'Customer_Accounts', strtoupper($firstReserved), $accented, $reservedAccent, 'p7_absent_name']] as $set => $probeNames) {
            $rows = 0;
            foreach ($sqls as $sql) {
                $batched = $lookups->invoke($owner, $pdo, $sql, $probeNames);
                foreach (array_unique($probeNames) as $name) {
                    $statement = $pdo->prepare($sql);
                    $statement->execute([$name]);
                    $original = $statement->fetchAll(PDO::FETCH_ASSOC);
                    $rows += count($original);
                    $mine = $batched[$name];
                    if (! str_contains($sql, 'ORDER BY')) {
                        $key = fn (array $row): string => json_encode($row);
                        usort($original, fn ($a, $b) => strcmp($key($a), $key($b)));
                        usort($mine, fn ($a, $b) => strcmp($key($a), $key($b)));
                    }
                    $this->assertSame($original, $mine, "P7 {$set} mismatch for {$name} in {$sql}");
                }
            }
            $this->log('P7 set='.$set.' statements='.count($sqls).' names='.count(array_unique($probeNames)).' per-name rows compared='.$rows.' mismatches=0');
            }
            $result = $this->outcome('identity');
            $this->log('P7 identity inspection with aliases present: '.$result);
            $this->assertNotSame('admitted', $result);
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS p7_alias_source');
            $pdo->exec('DROP TABLE IF EXISTS `Customer_Accounts`');
        }
        $this->assertSame('admitted', $this->outcome('identity'));
    }

    /** 2(d): the statement bound does not depend on how many schemas or objects the server holds. */
    public function test_p8_statement_count_is_independent_of_peer_schema_volume(): void
    {
        $pdo = DB::connection()->getPdo();
        $before = $this->countedInspection();
        foreach (['_rv8a', '_rv8b'] as $suffix) {
            $peer = $this->peer($suffix);
            foreach ($pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
                $pdo->exec('CREATE TABLE `'.$peer.'`.`'.$table.'` LIKE `'.$table.'`');
            }
            foreach ($pdo->query('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY EVENT_OBJECT_TABLE, ACTION_ORDER')->fetchAll(PDO::FETCH_ASSOC) as $guard) {
                $pdo->exec('CREATE TRIGGER `'.$peer.'`.`'.$guard['TRIGGER_NAME'].'` '.$guard['ACTION_TIMING'].' '.$guard['EVENT_MANIPULATION'].' ON `'.$peer.'`.`'.$guard['EVENT_OBJECT_TABLE'].'` FOR EACH ROW '.$guard['ACTION_STATEMENT']);
            }
            for ($i = 0; $i < 50; $i++) {
                $pdo->exec('CREATE VIEW `'.$peer.'`.`p8_view_'.$i.'` AS SELECT id FROM `'.$peer.'`.`'.self::TABLES['identity'].'`');
                $pdo->exec('CREATE PROCEDURE `'.$peer.'`.`p8_routine_'.$i.'`() SELECT COUNT(*) FROM `'.self::TABLES['identity'].'`');
            }
        }
        $objects = $pdo->query('SELECT (SELECT COUNT(*) FROM information_schema.TRIGGERS), (SELECT COUNT(*) FROM information_schema.VIEWS), (SELECT COUNT(*) FROM information_schema.ROUTINES), (SELECT COUNT(*) FROM information_schema.SCHEMATA)')->fetch(PDO::FETCH_NUM);
        $after = $this->countedInspection();
        $this->log(sprintf('P8 without peers: %d statements %.1f ms; with 2 peers (server triggers=%s views=%s routines=%s schemata=%s): %d statements %.1f ms',
            $before[0], $before[1], ...[...$objects, $after[0], $after[1]]));
        $this->assertSame($before[0], $after[0]);
        $this->assertLessThanOrEqual(110, $after[0]);
    }

    /** @return array{0:int,1:float} */
    private function countedInspection(): array
    {
        $config = DB::connection()->getConfig();
        $counted = new class('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]) extends PDO
        {
            public int $statements = 0;

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
            {
                $this->statements++;

                return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                $this->statements++;

                return parent::prepare($query, $options);
            }

            public function exec(string $statement): int|false
            {
                $this->statements++;

                return parent::exec($statement);
            }
        };
        $counted->exec("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
        $counted->statements = 0;
        $started = hrtime(true);
        $result = (new IdentityMigrationOwnership)->inspect($counted, 'mysql');
        $elapsed = (hrtime(true) - $started) / 1e6;
        $this->assertNotContains(false, $result[0]);

        return [$counted->statements, $elapsed];
    }

    private function outcome(string $guard): string
    {
        try {
            if ($guard === 'capability') {
                $migration = require database_path('migrations/2026_10_07_239000_production_buyer_assent_observations.php');
                (new CapabilityMigrationOwnership)->preflight((new ReflectionMethod($migration, 'definitions'))->invoke($migration), (new ReflectionMethod($migration, 'guards'))->invoke($migration));
            } elseif ($guard === 'inquiry') {
                $migration = require database_path('migrations/2026_10_07_243000_inquiry_notification_intents.php');
                (new ReflectionMethod($migration, 'preflightExternalDependencies'))->invoke($migration);
            } else {
                (new IdentityMigrationOwnership)->inspect(DB::connection()->getPdo(), 'mysql');
            }

            return 'admitted';
        } catch (Throwable $error) {
            return 'refused '.class_basename($error).': '.$error->getMessage();
        }
    }

    private function peer(string $suffix, ?string $name = null): string
    {
        $peer = $name ?? $this->database.$suffix;
        $this->assertLessThanOrEqual(64, strlen($peer));
        $this->assertSame('0', (string) $this->one('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$peer]), "peer {$peer} must not pre-exist");
        $this->peers[] = $peer;
        DB::connection()->getPdo()->exec('CREATE DATABASE `'.$peer.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        return $peer;
    }

    private function one(string $sql, array $bindings): mixed
    {
        $statement = DB::connection()->getPdo()->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchColumn();
    }

    private function catalog(): array
    {
        $pdo = DB::connection()->getPdo();
        $out = [];
        foreach (['SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME',
            'SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME',
            'SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() ORDER BY ROUTINE_NAME'] as $sql) {
            $out[] = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        }

        return $out;
    }

    private function log(string $line): void
    {
        fwrite(STDERR, $line."\n");
    }
}
