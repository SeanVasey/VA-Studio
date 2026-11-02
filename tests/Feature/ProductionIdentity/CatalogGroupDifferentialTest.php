<?php

namespace Tests\Feature\ProductionIdentity;

use App\Domain\Customers\ProductionIdentity\AccountAdmissionGuards;
use App\Domain\Customers\ProductionIdentity\AccountAdmissionUpgrade;
use App\Domain\Customers\ProductionIdentity\CatalogGroup;
use App\Domain\Customers\ProductionIdentity\CatalogGroupPlan;
use App\Domain\Customers\ProductionIdentity\CatalogLease;
use App\Domain\Customers\ProductionIdentity\CatalogRead;
use App\Domain\Customers\ProductionIdentity\CurrentCatalog;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use Pdo\Mysql;
use PHPUnit\Framework\ExpectationFailedException;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Support\ExplainFailureObserver;
use Tests\Support\ExplainNoteSnapshot;
use Tests\Support\ExplainPrepareSnapshot;
use Tests\Support\ExplainWindowIds;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

/** Native proof source only until a separate exact-source review and disposable DB lease. */
final class CatalogGroupDifferentialTest extends TestCase
{
    use ProductionIdentityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native MySQL staged catalog differential and warning EOF controls.');
        }
        $this->identitySetup();
    }

    public function test_native_every_fixed_group_matches_original_typed_rows_in_v1_v2_and_ordered_overlap(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $upgrade = new AccountAdmissionUpgrade;
        $this->assertCanonicalEntries(AccountAdmissionGuards::V2);
        $this->compareRawFamilies(AccountAdmissionGuards::V2);
        $upgrade->down($connection);
        try {
            $this->assertCanonicalEntries(AccountAdmissionGuards::V1);
            $this->compareRawFamilies(AccountAdmissionGuards::V1);
            $guard = AccountAdmissionGuards::definitions('mysql', AccountAdmissionGuards::OVERLAP)[AccountAdmissionGuards::NEW_INSERT];
            $sql = str_replace('FOR EACH ROW ', 'FOR EACH ROW FOLLOWS `'.AccountAdmissionGuards::OLD_INSERT.'` ', $guard['sql']);
            $pdo->exec($sql);
            $this->assertCanonicalEntries(AccountAdmissionGuards::OVERLAP);
            $this->compareRawFamilies(AccountAdmissionGuards::OVERLAP);
        } finally {
            $upgrade->up($connection);
        }
        $this->assertCanonicalEntries(AccountAdmissionGuards::V2);
    }

    public function test_native_view_functional_unordered_indexes_unicode_defaults_aliases_and_global_definitions_match_original(): void
    {
        $pdo = DB::connection()->getPdo();
        self::assertSame(0, (int) $pdo->query('SELECT @@lower_case_table_names')->fetchColumn(), 'This literal-name native profile requires exact case-distinct dictionary objects.');
        $this->assertCanonicalEntries(AccountAdmissionGuards::V2);
        $created = [];
        try {
            foreach ([
                ['CREATE VIEW `CUSTOMER_ACCOUNTS` AS SELECT 1 AS id', 'DROP VIEW `CUSTOMER_ACCOUNTS`'],
                ["ALTER TABLE users ADD catalog_diff_text varchar(255) NULL DEFAULT '001É é ', ADD catalog_diff_empty varchar(255) NOT NULL DEFAULT ''", 'ALTER TABLE users DROP COLUMN catalog_diff_empty, DROP COLUMN catalog_diff_text'],
                ['CREATE INDEX catalog_diff_functional ON users ((lower(email)))', 'DROP INDEX catalog_diff_functional ON users'],
                ['CREATE FULLTEXT INDEX catalog_diff_fulltext ON users (email)', 'DROP INDEX catalog_diff_fulltext ON users'],
                ["CREATE PROCEDURE catalog_diff_text() SELECT '001É é \\\\ literal'", 'DROP PROCEDURE catalog_diff_text'],
                ['CREATE EVENT customer_accounts_update ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 1 DAY DO SET @catalog_diff=1', 'DROP EVENT customer_accounts_update'],
            ] as [$sql,$undo]) {
                $pdo->exec($sql);
                $created[] = $undo;
            }
            $this->compareRawFamilies(AccountAdmissionGuards::V2);
            $lease = CatalogLease::open($pdo);
            try {
                $shape = CatalogLease::group($pdo, CatalogGroup::P1_PARENT_SHAPE);
                $indexes = $shape[CatalogRead::Q54EFEC87F80D->value]['users'];
                self::assertNotEmpty(array_filter($indexes, fn (array $r): bool => $r['INDEX_NAME'] === 'catalog_diff_functional' && $r['COLUMN_NAME'] === null));
                self::assertNotEmpty(array_filter($indexes, fn (array $r): bool => $r['INDEX_NAME'] === 'catalog_diff_fulltext' && $r['COLLATION'] === null));
                $namespace = CatalogLease::group($pdo, CatalogGroup::I0_IDENTITY_NAMESPACE);
                self::assertNotEmpty(array_filter($namespace[CatalogRead::QEDBF704DA857->value]['customer_accounts'], fn (array $r): bool => $r['TABLE_NAME'] === 'CUSTOMER_ACCOUNTS' && $r['ENGINE'] === null && $r['TABLE_COLLATION'] === null));
                // Facts remain representable; accepting these altered ownership schemas is never authorized.
                foreach (['stable', 'identityParentStable', 'migrationState', 'identityParentMigrationState'] as $entry) {
                    try {
                        AccountAdmissionGuards::$entry($pdo, 'mysql');
                        self::fail('Reserved foreign event admitted.');
                    } catch (LogicException) {
                        self::assertTrue(true);
                    }
                }
            } finally {
                $lease->close();
            }
        } finally {
            foreach (array_reverse($created) as $undo) {
                $pdo->exec($undo);
            }
        }
        $this->assertCanonicalEntries(AccountAdmissionGuards::V2);
    }

    public function test_native_warning_count_observes_prepare_execute_full_eof_without_sql_and_rejects_warned_transport(): void
    {
        $pdo = DB::connection()->getPdo();
        $lease = CatalogLease::open($pdo);
        try {
            $catalog = (new ReflectionProperty($lease, 'catalog'))->getValue($lease);
            $native = (new ReflectionProperty($catalog, 'catalog'))->getValue($catalog);
            self::assertSame(Mysql::class, $native::class);
            self::assertTrue($native->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, false));
            $guard = new ReflectionMethod(CurrentCatalog::class, 'requireNoWarnings');
            foreach ([
                ['clean-nonempty', "SELECT JSON_ARRAY(JSON_ARRAY('S','001É'),JSON_ARRAY('N',NULL)) AS payload", false],
                ['warning-envelope', "SELECT JSON_ARRAY(JSON_ARRAY('I',CAST(CAST('catalog-not-integer' AS UNSIGNED) AS CHAR CHARACTER SET utf8mb4))) AS payload", true],
                ['clean-empty', "SELECT JSON_ARRAY(JSON_ARRAY('S','')) AS payload WHERE 1=0", false],
                ['clean-after-warning', "SELECT JSON_ARRAY(JSON_ARRAY('S','')) AS payload", false],
            ] as [$name,$sql,$warning]) {
                $before = (int) $native->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
                $statement = $native->prepare($sql);
                $prepared = $native->getWarningCount();
                self::assertSame(0, $prepared);
                $statement->execute();
                $executed = $native->getWarningCount();
                $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
                $eof = $native->getWarningCount();
                if ($warning) {
                    self::assertGreaterThan(0, $eof);
                    try {
                        $guard->invoke(null, $native);
                        self::fail('Native warned JSON transport admitted.');
                    } catch (LogicException) {
                        self::assertTrue(true);
                    }
                } else {
                    self::assertSame(0, $eof);
                    $guard->invoke(null, $native);
                }
                self::assertTrue($statement->closeCursor());
                $closed = $native->getWarningCount();
                $after = (int) $native->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
                self::assertSame(2, $after - $before, 'All warning getter snapshots and the shared rejection primitive issue zero SQL.');
                self::assertSame(0, $native->getWarningCount(), 'Next clean Questions statement demonstrates warning-area replacement.');
                fwrite(STDERR, json_encode(['control' => $name, 'prepare' => $prepared, 'execute' => $executed, 'eof' => $eof, 'close' => $closed, 'rows' => count($rows), 'sql_delta' => $after - $before], JSON_THROW_ON_ERROR)."\n");
            }
            self::assertTrue($native->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, true));
            unset($native,$catalog);
        } finally {
            $lease->close();
        }
    }

    public function test_native_window_relations_are_materialized_and_shared_between_headers_and_rows(): void
    {
        $pdo = DB::connection()->getPdo();
        $lease = CatalogLease::open($pdo);
        try {
            $catalog = (new ReflectionProperty($lease, 'catalog'))->getValue($lease);
            $native = (new ReflectionProperty($catalog, 'catalog'))->getValue($catalog);
            foreach (CatalogGroup::cases() as $group) {
                $plan = CatalogGroupPlan::build($group, AccountAdmissionGuards::V2);
                $streams = $group === CatalogGroup::I2_RESERVED_NAMES ? $plan['i2_split'] : [$plan];
                $covered = [];
                foreach ($streams as $streamIndex => $stream) {
                    foreach (array_keys($stream['families']) as $familyIndex) {
                        self::assertNotContains($familyIndex, $covered);
                        $covered[] = $familyIndex;
                    }
                    $statement = $native->prepare('EXPLAIN FORMAT=JSON '.$stream['sql']);
                    $prepareWarnings = $native->getWarningCount();
                    $prepareProof = ExplainPrepareSnapshot::capture($native, $statement, $prepareWarnings);
                    self::assertSame($prepareWarnings, $prepareProof['count']);
                    $beforeExecute = $native->getWarningCount();
                    self::assertSame(0, $beforeExecute);
                    foreach ($stream['bindings'] as $position => $value) {
                        self::assertTrue($statement->bindValue($position + 1, $value, PDO::PARAM_STR));
                    }
                    if ($group === CatalogGroup::I2_RESERVED_NAMES && $streamIndex === 1) {
                        // EXPLAIN-only representative legal residuals; production derives ACTUAL first-stream charge.
                        self::assertTrue($statement->bindValue(57, 131044, PDO::PARAM_INT));
                        self::assertTrue($statement->bindValue(58, 16777216 - 4096 - 7336, PDO::PARAM_INT));
                    }
                    self::assertTrue($statement->execute());
                    $executeWarnings = $native->getWarningCount();
                    $json = $statement->fetchColumn();
                    $fetchWarnings = $native->getWarningCount();
                    $snapshot = ExplainNoteSnapshot::capture($native, $statement, $beforeExecute, $executeWarnings, $fetchWarnings);
                    self::assertTrue($statement->closeCursor());
                    fwrite(STDERR, 'EXPLAIN diagnostics: '.json_encode(['group' => $group->value, 'stream' => $streamIndex, 'counts' => $snapshot['counts'], 'rows' => $snapshot['rows']], JSON_THROW_ON_ERROR)."\n");
                    ExplainNoteSnapshot::requireOnlyExplainNote($snapshot);
                    self::assertTrue(true, 'Complete matched EXPLAIN-only Note 1003 provenance.');
                    $explain = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                    $materialized = 0;
                    $shared = 0;
                    $windowIds = ExplainWindowIds::collect($explain);
                    $sharedIds = [];
                    $walk = function (array $node) use (&$walk, &$materialized, &$shared, &$sharedIds): void {
                        foreach ($node as $key => $value) {
                            if ($key === 'materialized_from_subquery') {
                                $materialized++;
                            }
                            if ($key === 'sharing_temporary_table_with') {
                                $shared++;
                                self::assertIsArray($value);
                                self::assertArrayHasKey('select_id', $value);
                                $sharedIds[] = $value['select_id'];
                            }
                            if (is_array($value)) {
                                $walk($value);
                            }
                        }
                    };
                    $walk($explain);
                    $windowIds = array_values(array_unique($windowIds));
                    try {
                        self::assertCount(count($stream['families']), $windowIds, $group->value.' exact window-bearing family materializations');
                        self::assertSame([], array_values(array_diff($windowIds, $sharedIds)), $group->value.' every family window must be shared by header and row consumers');
                        self::assertGreaterThanOrEqual(count($stream['families']), $materialized, $group->value);
                        self::assertGreaterThanOrEqual(count($stream['families']), $shared, 'Every family must have a shared materialization, not separate header/row evaluation: '.$group->value);
                    } catch (ExpectationFailedException $original) {
                        ExplainFailureObserver::rethrow($original, $explain, count($stream['families']));
                    }
                }
                try {
                    self::assertSame(array_keys($plan['families']), $covered, $group->value.' complete stream family coverage');
                } catch (ExpectationFailedException $original) {
                    ExplainFailureObserver::rethrow($original, $explain, count($stream['families']));
                }
            }
            unset($native,$catalog);
        } finally {
            $lease->close();
        }
    }

    public function test_native_explain_note_policy_rejects_real_conversion_warning_and_preserves_production_zero_guard(): void
    {
        $primary = DB::connection()->getPdo();
        $lease = CatalogLease::open($primary);
        $statement = null;
        try {
            $catalog = (new ReflectionProperty($lease, 'catalog'))->getValue($lease);
            $native = (new ReflectionProperty($catalog, 'catalog'))->getValue($catalog);
            // Both clean A0 and genuine positive A1 prepare stages, SAME statements retained.
            foreach ([CatalogGroup::A0_ACCOUNT_TABLE, CatalogGroup::A1_ACCOUNT_AFTER_SHOW] as $positiveGroup) {
                $plan = CatalogGroupPlan::build($positiveGroup, AccountAdmissionGuards::V2);
                $statement = $native->prepare('EXPLAIN FORMAT=JSON '.$plan['sql']);
                $prepareWarnings = $native->getWarningCount();
                if ($positiveGroup === CatalogGroup::A0_ACCOUNT_TABLE) {
                    self::assertSame(0, $prepareWarnings);
                } else {
                    self::assertGreaterThan(0, $prepareWarnings);
                    try {
                        (new ReflectionMethod(CurrentCatalog::class, 'requireNoWarnings'))->invoke(null, $native);
                        self::fail('Production zero guard accepted prepare notes.');
                    } catch (LogicException) {
                        self::assertTrue(true);
                    }
                }
                $prepareProof = ExplainPrepareSnapshot::capture($native, $statement, $prepareWarnings);
                self::assertSame($prepareWarnings, $prepareProof['count']);
                $beforeExecute = $native->getWarningCount();
                self::assertSame(0, $beforeExecute);
                foreach ($plan['bindings'] as $position => $value) {
                    self::assertTrue($statement->bindValue($position + 1, $value, PDO::PARAM_STR));
                }
                self::assertTrue($statement->execute());
                $executeWarnings = $native->getWarningCount();
                $positivePayload = $statement->fetchColumn();
                self::assertIsString($positivePayload);
                unset($positivePayload);
                $positive = ExplainNoteSnapshot::capture($native, $statement, $beforeExecute, $executeWarnings, $native->getWarningCount());
                self::assertTrue($statement->closeCursor());
                $statement = null;
                ExplainNoteSnapshot::requireOnlyExplainNote($positive);
                self::assertTrue(true, 'Same prepared statement completed strict execute Note1003 proof.');
            }
            // EXACT existing warning-envelope expression: a counterfactual Warning, not EXPLAIN.
            $statement = $native->prepare("SELECT JSON_ARRAY(JSON_ARRAY('I',CAST(CAST('catalog-not-integer' AS UNSIGNED) AS CHAR CHARACTER SET utf8mb4))) AS payload");
            $prepareWarnings = $native->getWarningCount();
            self::assertSame(0, $prepareWarnings);
            $beforeExecute = $native->getWarningCount();
            self::assertSame(0, $beforeExecute);
            self::assertTrue($statement->execute());
            $executeWarnings = $native->getWarningCount();
            $negativePayload = $statement->fetchColumn();
            self::assertIsString($negativePayload);
            unset($negativePayload);
            $fetchWarnings = $native->getWarningCount();
            self::assertGreaterThan(0, $fetchWarnings);
            // BEFORE SHOW can replace diagnostic area: ordinary production guard still refuses ANY warning.
            try {
                (new ReflectionMethod(CurrentCatalog::class, 'requireNoWarnings'))->invoke(null, $native);
                self::fail('Production zero-warning guard accepted a real conversion diagnostic.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            $negative = ExplainNoteSnapshot::capture($native, $statement, $beforeExecute, $executeWarnings, $fetchWarnings);
            self::assertTrue($statement->closeCursor());
            $statement = null;
            self::assertNotEmpty(array_filter($negative['rows'], fn (array $row): bool => $row['level'] === 'Warning'));
            fwrite(STDERR, 'Conversion diagnostic negative: '.json_encode($negative, JSON_THROW_ON_ERROR)."\n");
            try {
                ExplainNoteSnapshot::requireOnlyExplainNote($negative);
                self::fail('EXPLAIN-only note policy accepted the real conversion Warning.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            try {
                ExplainPrepareSnapshot::requireOnlyResolutionRows($negative['rows']);
                self::fail('Prepare row-family classifier accepted actual EXECUTED CAST Warning.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            self::assertFalse($native->getAttribute(PDO::ATTR_EMULATE_PREPARES));
            self::assertTrue($native->getAttribute(Mysql::ATTR_USE_BUFFERED_QUERY));
            unset($native, $catalog);
        } finally {
            try {
                if ($statement !== null) {
                    $statement->closeCursor();
                }
            } finally {
                unset($statement, $native, $catalog);
                $lease->close();
            }
        }
    }

    /** Fixed reflected second-gate primitive, NOT naturally exhausted public-stage capacity. */
    public function test_native_i2_second_gate_preserves_positive_facts_and_emits_all_reserved_invalid_headers(): void
    {
        $primary = DB::connection()->getPdo();
        $lease = CatalogLease::open($primary);
        try {
            $catalog = (new ReflectionProperty($lease, 'catalog'))->getValue($lease);
            $native = (new ReflectionProperty($catalog, 'catalog'))->getValue($catalog);
            $plan = CatalogGroupPlan::build(CatalogGroup::I2_RESERVED_NAMES, AccountAdmissionGuards::V2);
            $stream = $plan['i2_split'][1];
            $original = CatalogLease::identity($primary, CatalogRead::Q6546FD85F02A, $plan['families'][1]['names']);
            self::assertNotEmpty($original[$plan['families'][1]['names'][0]], 'Canonical original target positive required.');
            foreach ([[131044, 16773120], [28, 7336]] as $control => [$recordBudget, $byteBudget]) {
                self::assertTrue($native->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, false));
                $statement = $native->prepare($stream['sql']);
                self::assertSame(0, $native->getWarningCount());
                foreach ($stream['bindings'] as $position => $value) {
                    self::assertTrue($statement->bindValue($position + 1, $value, PDO::PARAM_STR));
                }
                self::assertTrue($statement->bindValue(57, $recordBudget, PDO::PARAM_INT));
                self::assertTrue($statement->bindValue(58, $byteBudget, PDO::PARAM_INT));
                self::assertTrue($statement->execute());
                self::assertSame(0, $native->getWarningCount());
                $wire = [];
                $charge = 0;
                while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                    self::assertLessThan(131072, count($wire));
                    self::assertIsString($row['payload']);
                    self::assertLessThanOrEqual(8388608, strlen($row['payload']));
                    $charge += 256 + strlen($row['payload']);
                    self::assertLessThanOrEqual($byteBudget, $charge);
                    self::assertLessThan($recordBudget, count($wire));
                    $wire[] = $row;
                }
                self::assertSame(0, $native->getWarningCount());
                self::assertTrue($statement->closeCursor());
                unset($statement);
                self::assertTrue($native->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, true));
                self::assertTrue($native->getAttribute(Mysql::ATTR_USE_BUFFERED_QUERY));
                if ($control === 0) {
                    $decoded = (new ReflectionMethod(CurrentCatalog::class, 'decodeGroup'))->invoke(null, CatalogGroup::I2_RESERVED_NAMES, $stream['families'], $wire);
                    foreach ($original as $name => $rows) {
                        $left = array_map(serialize(...), $rows);
                        $right = array_map(serialize(...), $decoded[CatalogRead::Q6546FD85F02A->value][$name]);
                        sort($left, SORT_STRING);
                        sort($right, SORT_STRING);
                        self::assertSame($left, $right, 'FULL second-family positive native typed bags.');
                    }
                } else {
                    self::assertCount(28, $wire);
                    self::assertSame(7336, $charge);
                    foreach ($wire as $slot => $record) {
                        self::assertSame(['group_id' => CatalogGroup::I2_RESERVED_NAMES->value, 'family' => 1, 'slot' => $slot, 'kind' => 0, 'ordinal' => '0', 'payload' => '[null]'], $record);
                    }
                    try {
                        (new ReflectionMethod(CurrentCatalog::class, 'decodeGroup'))->invoke(null, CatalogGroup::I2_RESERVED_NAMES, $stream['families'], $wire);
                        self::fail('Invalid reserved headers admitted as absent facts.');
                    } catch (LogicException) {
                        self::assertTrue(true);
                    }
                }
            }
            unset($native, $catalog);
        } finally {
            if (isset($native)) {
                self::assertTrue($native->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, true));
                self::assertTrue($native->getAttribute(Mysql::ATTR_USE_BUFFERED_QUERY));
            }
            unset($statement, $native, $catalog);
            $lease->close();
        }
    }

    /** Fixed test-only reflected fault descriptors; canonical public positives run BEFORE each fault. */
    public function test_native_i2_first_and_second_prepare_failures_invalidate_without_partial_result_or_reuse(): void
    {
        $connection = DB::connection();
        $primary = $connection->getPdo();
        foreach ([0, 1] as $faultStream) {
            $lease = null;
            $connection->beginTransaction();
            $primary->exec('SAVEPOINT catalog_i2_fault');
            try {
                $lease = CatalogLease::open($primary);
                $catalog = (new ReflectionProperty($lease, 'catalog'))->getValue($lease);
                $identity = (new ReflectionProperty($catalog, 'catalogIdentity'))->getValue($catalog);
                $companionId = $identity['connection_id'];
                self::assertIsInt($companionId);
                $visible = $primary->prepare('SELECT COUNT(*) FROM performance_schema.threads WHERE PROCESSLIST_ID=?');
                self::assertTrue($visible->execute([$companionId]));
                self::assertSame(1, (int) $visible->fetchColumn(), 'Owned live companion positive visibility.');
                self::assertTrue($visible->closeCursor());
                unset($visible, $identity); // Never retain a private PDO reference across failure.
                $plan = CatalogGroupPlan::build(CatalogGroup::I2_RESERVED_NAMES, AccountAdmissionGuards::V2);
                $positive = CatalogLease::group($primary, CatalogGroup::I2_RESERVED_NAMES, AccountAdmissionGuards::V2);
                foreach ($plan['families'] as $family) {
                    $query = constant(CatalogRead::class.'::'.$family['enum']);
                    $original = CatalogLease::identity($primary, $query, $family['names']);
                    self::assertNotEmpty($original[$family['names'][0]], 'Both canonical original family targets required.');
                    self::assertSame(array_keys($original), array_keys($positive[$query->value]));
                    foreach ($original as $name => $rows) {
                        $left = array_map(serialize(...), $rows);
                        $right = array_map(serialize(...), $positive[$query->value][$name]);
                        sort($left, SORT_STRING);
                        sort($right, SORT_STRING);
                        self::assertSame($left, $right, 'Genuine public split path positive before reflected fault.');
                    }
                }
                unset($positive, $original, $rows, $left, $right);
                // Fixed syntax error only: no user SQL, production seam, callback, custom PDO, or schema mutation.
                $plan['i2_split'][$faultStream]['sql'] = 'SELECT FROM';
                $before = CurrentCatalog::accounting();
                $published = null;
                try {
                    $published = (new ReflectionMethod(CurrentCatalog::class, 'i2'))->invoke($catalog, $plan);
                    self::fail('Faulted stage returned partial metadata.');
                } catch (LogicException) {
                    self::assertNull($published);
                }
                self::assertSame($before['companion_sql'] + $faultStream + 1, CurrentCatalog::accounting()['companion_sql'],
                    $faultStream === 1 ? 'Second prepare reached only AFTER completed first decode/close/restore.' : 'First refusal issued no second prepare.');
                self::assertNull((new ReflectionProperty($catalog, 'catalog'))->getValue($catalog));
                self::assertTrue((new ReflectionProperty($catalog, 'failed'))->getValue($catalog));
                $failed = CurrentCatalog::accounting();
                foreach ([fn () => $catalog->group(CatalogGroup::I2_RESERVED_NAMES, AccountAdmissionGuards::V2),
                    fn () => CatalogLease::group($primary, CatalogGroup::I2_RESERVED_NAMES, AccountAdmissionGuards::V2)] as $retry) {
                    try {
                        $retry();
                        self::fail('Failed capability or registry transparently reused/replaced.');
                    } catch (LogicException) {
                        self::assertTrue(true);
                    }
                }
                self::assertSame($failed, CurrentCatalog::accounting(), 'No further statement/constructor/handshake attempt.');
                $release = $primary->prepare('SELECT COUNT(*) FROM performance_schema.threads WHERE PROCESSLIST_ID=?');
                $deadline = hrtime(true) + 1000000000;
                $samples = [];
                do {
                    self::assertTrue($release->execute([$companionId]));
                    $remaining = (int) $release->fetchColumn();
                    self::assertTrue($release->closeCursor());
                    $samples[] = $remaining;
                    if ($remaining === 0) {
                        break;
                    }
                    usleep(150000);
                } while (hrtime(true) < $deadline);
                fwrite(STDERR, 'I2 fault release samples: '.json_encode(['fault_stream' => $faultStream, 'samples' => $samples], JSON_THROW_ON_ERROR)."\n");
                self::assertSame(0, $remaining, 'Owned failed companion session actually released.');
                self::assertTrue($primary->inTransaction());
                $primary->exec('ROLLBACK TO SAVEPOINT catalog_i2_fault');
            } finally {
                try {
                    $lease?->close();
                } catch (LogicException) { /* Refusal remains permanent; external audit still required. */
                }
                if ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
                unset($retry, $catalog, $lease, $plan, $release);
            }
        }
    }

    public function test_native_oversized_complete_catalog_refuses_and_releases_failed_companion_preserving_primary_savepoint(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $pdo->exec("CREATE VIEW catalog_diff_oversize AS SELECT '".str_repeat('x', 8388609)."' AS value");
        $lease = null;
        try {
            $original = CatalogLease::read($pdo, CatalogRead::GlobalViews);
            self::assertNotEmpty(array_filter($original, fn (array $r): bool => is_string($r['VIEW_DEFINITION']) && strlen($r['VIEW_DEFINITION']) > 8388608));
            unset($original);
            $connection->beginTransaction();
            $pdo->exec('SAVEPOINT catalog_group_overflow');
            $lease = CatalogLease::open($pdo);
            $catalog = (new ReflectionProperty($lease, 'catalog'))->getValue($lease);
            $native = (new ReflectionProperty($catalog, 'catalog'))->getValue($catalog);
            $companionId = (int) $native->query('SELECT CONNECTION_ID()')->fetchColumn();
            $visible = $pdo->prepare('SELECT COUNT(*) FROM performance_schema.threads WHERE PROCESSLIST_ID=?');
            $visible->execute([$companionId]);
            self::assertSame(1, (int) $visible->fetchColumn(), 'Positive owned companion visibility before teardown.');
            $visible->closeCursor();
            $plan = CatalogGroupPlan::build(CatalogGroup::D0_FULL_DEPENDENTS, AccountAdmissionGuards::V2);
            self::assertTrue($native->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, false));
            $statement = $native->prepare($plan['sql']);
            self::assertSame(0, $native->getWarningCount());
            $statement->execute($plan['bindings']);
            self::assertSame(0, $native->getWarningCount());
            $wireBytes = 4096;
            $headers = 0;
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                self::assertSame(0, $row['kind']);
                self::assertSame('[null]', $row['payload']);
                $wireBytes += 256 + strlen($row['payload']);
                $headers++;
            }
            self::assertSame(0, $native->getWarningCount());
            self::assertTrue($statement->closeCursor());
            self::assertTrue($native->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, true));
            self::assertSame(array_sum(array_map(fn (array $f): int => count($f['names']), $plan['families'])), $headers);
            self::assertLessThanOrEqual(16777216, $wireBytes);
            unset($statement,$native,$catalog);
            $beforeAttempts = CurrentCatalog::accounting()['connection_attempts'];
            try {
                CatalogLease::group($pdo, CatalogGroup::D0_FULL_DEPENDENTS);
                self::fail('Oversized catalog partially admitted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            $failed = (new ReflectionProperty($lease, 'catalog'))->getValue($lease);
            self::assertNull((new ReflectionProperty($failed, 'catalog'))->getValue($failed), 'No reflected native PDO remains to prevent release.');
            foreach ([
                fn () => $failed->group(CatalogGroup::D0_FULL_DEPENDENTS, AccountAdmissionGuards::V2),
                fn () => CatalogLease::read($pdo, CatalogRead::GlobalViews),
            ] as $retry) {
                try {
                    $retry();
                    self::fail('Failed capability reused or transparently replaced.');
                } catch (LogicException) {
                    self::assertTrue(true);
                }
            }
            self::assertSame($beforeAttempts, CurrentCatalog::accounting()['connection_attempts']);
            $release = $pdo->prepare('SELECT COUNT(*) FROM performance_schema.threads WHERE PROCESSLIST_ID=?');
            $releaseDeadline = hrtime(true) + 1000000000;
            $releaseSamples = [];
            do {
                $release->execute([$companionId]);
                $remaining = (int) $release->fetchColumn();
                $release->closeCursor();
                $releaseSamples[] = $remaining;
                if ($remaining === 0) {
                    break;
                }
                usleep(150000);
            } while (hrtime(true) < $releaseDeadline);
            fwrite(STDERR, 'overflow companion release samples: '.json_encode($releaseSamples, JSON_THROW_ON_ERROR)."\n");
            self::assertSame(0, $remaining, 'Failed companion must actually disappear from server sessions.');
            self::assertTrue($pdo->inTransaction());
            $pdo->exec('ROLLBACK TO SAVEPOINT catalog_group_overflow');
        } finally {
            try {
                $lease?->close();
            } catch (LogicException) { /* Permanently failed companion cannot regain authority. */
            }
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            $pdo->exec('DROP VIEW catalog_diff_oversize');
        }
    }

    public function test_native_grouped_and_original_parent_entries_both_refuse_partial_and_foreign_guard_sets(): void
    {
        $pdo = DB::connection()->getPdo();
        $this->assertCanonicalEntries(AccountAdmissionGuards::V2);
        $guard = AccountAdmissionGuards::definitions('mysql', AccountAdmissionGuards::V2)['customer_accounts_update'];
        $pdo->exec('DROP TRIGGER customer_accounts_update');
        try {
            foreach (['stable', 'identityParentStable', 'migrationState', 'identityParentMigrationState'] as $entry) {
                try {
                    AccountAdmissionGuards::$entry($pdo, 'mysql');
                    self::fail('Partial guard set admitted.');
                } catch (LogicException) {
                    self::assertTrue(true);
                }
            }
        } finally {
            $pdo->exec($guard['sql']);
        }
        $this->assertCanonicalEntries(AccountAdmissionGuards::V2);
        $pdo->exec('CREATE TRIGGER catalog_diff_foreign BEFORE INSERT ON customer_accounts FOR EACH ROW SET @catalog_diff_foreign=1');
        try {
            foreach (['stable', 'identityParentStable', 'migrationState', 'identityParentMigrationState'] as $entry) {
                try {
                    AccountAdmissionGuards::$entry($pdo, 'mysql');
                    self::fail('Foreign guard admitted.');
                } catch (LogicException) {
                    self::assertTrue(true);
                }
            }
        } finally {
            $pdo->exec('DROP TRIGGER catalog_diff_foreign');
        }
        $this->assertCanonicalEntries(AccountAdmissionGuards::V2);
    }

    private function assertCanonicalEntries(string $version): void
    {
        $primary = DB::connection()->getPdo();
        $expected = AccountAdmissionGuards::migrationState($primary, 'mysql');
        foreach (['migrationState', 'identityParentMigrationState', ...($version === AccountAdmissionGuards::OVERLAP ? [] : ['stable', 'identityParentStable'])] as $entry) {
            $actual = AccountAdmissionGuards::$entry($primary, 'mysql');
            self::assertSame($version, $actual['version']);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $actual['fingerprint']);
            self::assertSame($expected, $actual, $entry.' complete positive version/fingerprint equivalence');
        }
        if ($version !== AccountAdmissionGuards::OVERLAP) {
            self::assertSame(AccountAdmissionGuards::stable($primary, 'mysql'), AccountAdmissionGuards::identityParentStable($primary, 'mysql'));
        } else {
            foreach (['stable', 'identityParentStable'] as $entry) {
                try {
                    AccountAdmissionGuards::$entry($primary, 'mysql');
                    self::fail('Runtime ordered overlap admitted.');
                } catch (LogicException) {
                    self::assertTrue(true);
                }
            }
        }
    }

    private function compareRawFamilies(string $version): void
    {
        $pdo = DB::connection()->getPdo();
        $lease = CatalogLease::open($pdo);
        try {
            foreach (CatalogGroup::cases() as $group) {
                $plan = CatalogGroupPlan::build($group, $version);
                $grouped = CatalogLease::group($pdo, $group, $version);
                foreach ($plan['families'] as $family) {
                    $query = constant(CatalogRead::class.'::'.$family['enum']);
                    [$names,$direct] = (new ReflectionMethod(CatalogGroupPlan::class, 'recipe'))->invoke(null, $group, $query, $version);
                    $original = $direct === null ? CatalogLease::identity($pdo, $query, $names) : ['' => CatalogLease::read($pdo, $query, $direct)];
                    self::assertSame(array_keys($original), array_keys($grouped[$query->value]));
                    foreach ($original as $name => $rows) {
                        $actual = $grouped[$query->value][$name];
                        if ($family['order'] !== null) {
                            self::assertSame($rows, $actual, $group->value.'/'.$family['enum'].'/'.$name);
                        } else {
                            // No original ORDER BY: compare strict typed-row multisets, preserving every duplicate/key order.
                            $left = array_map(serialize(...), $rows);
                            $right = array_map(serialize(...), $actual);
                            sort($left, SORT_STRING);
                            sort($right, SORT_STRING);
                            self::assertSame($left, $right, $group->value.'/'.$family['enum'].'/'.$name);
                        }
                    }
                }
            }
        } finally {
            $lease->close();
        }
    }
}
