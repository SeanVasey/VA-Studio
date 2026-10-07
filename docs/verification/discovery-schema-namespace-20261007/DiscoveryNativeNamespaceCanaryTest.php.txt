<?php

namespace Tests\IndependentDiscovery;

use App\Domain\Catalog\DiscoverySitemap\SitemapSchema;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;
use Throwable;

final class DiscoveryNativeNamespaceCanaryTest extends TestCase
{
    public function test_foreign_reserved_check_name_is_refused_before_any_owned_ddl(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $prior = $connection->getTablePrefix();
        $prefix = 'dss_indep_';
        $foreign = $prefix.'foreign';
        $constraint = $prefix.SitemapSchema::TABLES[1].'_bounds';
        $connection->setTablePrefix($prefix);
        $schema = new SitemapSchema;
        $clear = function () use ($pdo, $schema, $foreign): void {
            foreach (array_reverse(SitemapSchema::TABLES) as $table) {
                $pdo->exec('DROP TABLE IF EXISTS '.$schema->table($table));
            }
            $pdo->exec('DROP TABLE IF EXISTS `'.$foreign.'`');
        };
        try {
            $clear();
            $pdo->exec('CREATE TABLE `'.$foreign.'` (id INTEGER PRIMARY KEY, CONSTRAINT `'.$constraint.'` CHECK (id >= 0)) ENGINE=InnoDB');
            $pdo->exec('INSERT INTO `'.$foreign.'` (id) VALUES (5)');
            $before = $this->catalog($pdo);
            $foreignBefore = $pdo->query('SHOW CREATE TABLE `'.$foreign.'`')->fetch(PDO::FETCH_ASSOC);
            $caught = null;
            try {
                $schema->up();
            } catch (Throwable $error) {
                $caught = ['class' => $error::class, 'code' => (string) $error->getCode(), 'mysql_code' => $error->errorInfo[1] ?? null];
            }
            $after = $this->catalog($pdo);
            $owned = array_values(array_filter($after['tables'], fn (array $row): bool => str_starts_with($row['TABLE_NAME'], $prefix.'discovery_sitemap_')));
            file_put_contents(base_path('docs/verification/cloud-discovery-independent-20261007/native-namespace-snapshot.json'), json_encode([
                'source' => trim(shell_exec('git rev-parse HEAD')), 'server_version' => $pdo->query('SELECT VERSION()')->fetchColumn(),
                'foreign_constraint' => $constraint, 'caught' => $caught, 'owned_tables_created' => $owned,
                'foreign_before' => $foreignBefore, 'foreign_after' => $pdo->query('SHOW CREATE TABLE `'.$foreign.'`')->fetch(PDO::FETCH_ASSOC),
                'foreign_rows' => $pdo->query('SELECT id FROM `'.$foreign.'`')->fetchAll(PDO::FETCH_ASSOC), 'before' => $before, 'after' => $after,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $this->assertSame($foreignBefore, $pdo->query('SHOW CREATE TABLE `'.$foreign.'`')->fetch(PDO::FETCH_ASSOC));
            $this->assertSame([['id' => 5]], $pdo->query('SELECT id FROM `'.$foreign.'`')->fetchAll(PDO::FETCH_ASSOC));
            $this->assertSame($before, $after, 'Foreign reserved CHECK identity must be refused before any earlier owned table or guard DDL.');
            $this->assertSame([], $owned);
        } finally {
            $clear();
            $connection->setTablePrefix($prior);
        }
    }

    private function catalog(PDO $pdo): array
    {
        return [
            'tables' => $pdo->query('SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC),
            'guards' => $pdo->query('SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME')->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
}
