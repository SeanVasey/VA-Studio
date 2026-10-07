<?php

namespace App\Domain\Grants\Free;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use PDO;

final class FreeGrantReads
{
    public function review(string $id, string $declaredName, object $principal, User $actor): array
    {
        FreeGrantInput::uuid($id);
        FreeGrantInput::text($declaredName, 120);
        $locator = (new FreeGrantDefinitions)->locate($id);

        return DB::transaction(function () use ($id, $declaredName, $principal, $actor, $locator): array {
            $rows = new FreeGrantRows;
            $identity = (new FreeGrantPolicy)->identity();
            $authority = $identity->lock($principal, $actor, $rows);
            $rows->one('rights_scopes', 'id = ?', [(int) $locator['scope_id']]);
            $definitions = new FreeGrantDefinitions;
            $graph = $definitions->graph($id, $rows);
            FreeGrantException::require($graph['definition'] === $locator && $graph['review'] !== [] && $graph['open'], 409);
            $identity->proveCurrent($principal, $actor, $rows, $authority);
            FreeGrantException::require($definitions->graph($id, $rows) === $graph, 409);
            (new FreeGrantSources)->proveCurrent($graph['payload']['source'], $rows, true);
            (new FreeGrantPolicy)->requireDefinition($graph['payload']);
            $identity->provePrimary($principal, $actor, $rows, $authority);
            $rows->assertCurrent();
            $definition = $definitions->project($graph);

            return ['definition' => $definition, 'declaredName' => $declaredName, 'assentHash' => (new FreeGrants)->assentHash($definition, $declaredName)];
        });
    }

    public function customer(object $principal, User $actor): array
    {
        return DB::transaction(function () use ($principal, $actor): array {
            $rows = new FreeGrantRows;
            $identity = (new FreeGrantPolicy)->identity();
            $authority = $identity->lock($principal, $actor, $rows);
            $accountId = (int) $identity->durableBinding($principal)['account_id'];
            $definitions = new FreeGrantDefinitions;
            $sql = 'SELECT d.* FROM '.$rows->table('free_definitions').' d WHERE EXISTS (SELECT 1 FROM '.$rows->table('free_availability')
                .' a WHERE a.definition_id = d.id) ORDER BY d.id DESC LIMIT 50';
            $records = $rows->identity()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            $scopes = array_values(array_unique(array_column($records, 'scope_id')));
            sort($scopes, SORT_NUMERIC);
            foreach ($scopes as $scope) {
                $rows->one('rights_scopes', 'id = ?', [(int) $scope]);
            }
            $graphs = array_map(fn (array $row): array => $definitions->graph($row['public_id'], $rows), $records);
            $statement = $rows->identity()->prepare('SELECT * FROM '.$rows->table('free_origins').' WHERE account_id = ? ORDER BY id DESC LIMIT 20'
                .($rows->identity()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $statement->execute([$accountId]);
            $origins = array_map(fn (array $row): array => (new FreeGrants)->originGraph($row['public_id'], $accountId, $rows), $statement->fetchAll(PDO::FETCH_ASSOC));
            $identity->proveCurrent($principal, $actor, $rows, $authority);
            foreach ($graphs as $graph) {
                FreeGrantException::require($definitions->graph($graph['definition']['public_id'], $rows) === $graph, 409);
                (new FreeGrantSources)->proveCurrent($graph['payload']['source'], $rows, false);
            }
            foreach ($origins as $origin) {
                FreeGrantException::require((new FreeGrants)->originGraph($origin['origin']['public_id'], $accountId, $rows) === $origin, 409);
                (new FreeGrantSources)->proveCurrent($origin['payload']['definition']['source'], $rows, false);
            }
            (new FreeGrantPolicy)->requireEnabled();
            $identity->provePrimary($principal, $actor, $rows, $authority);
            $rows->assertCurrent();

            return ['schemaVersion' => 1, 'definitions' => array_map($definitions->project(...), $graphs),
                'origins' => array_map((new FreeGrants)->originProject(...), $origins), 'definitionLimit' => 50, 'originLimit' => 20];
        });
    }

    public function staff(User $actor): array
    {
        return DB::transaction(function () use ($actor): array {
            $rows = new FreeGrantRows;
            $staff = (new FreeGrantStaff)->lock($actor, $rows);
            $records = $rows->identity()->query('SELECT * FROM '.$rows->table('free_definitions').' ORDER BY id DESC LIMIT 50'
                .($rows->identity()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''))->fetchAll(PDO::FETCH_ASSOC);
            $definitions = new FreeGrantDefinitions;
            $graphs = array_map(fn (array $row): array => $definitions->graph($row['public_id'], $rows), $records);
            (new FreeGrantStaff)->proveCurrent($actor, $rows, $staff);
            foreach ($graphs as $graph) {
                FreeGrantException::require($definitions->graph($graph['definition']['public_id'], $rows) === $graph, 409);
            }
            (new FreeGrantPolicy)->requireEnabled();
            (new FreeGrantStaff)->provePrimary($actor, $rows, $staff);
            $rows->assertCurrent();

            return array_map($definitions->project(...), $graphs);
        });
    }
}
