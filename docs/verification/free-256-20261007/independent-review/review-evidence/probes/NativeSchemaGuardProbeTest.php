<?php

namespace Tests\ReviewProbes\Free256;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDefinitions;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRecords;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use ReflectionClass;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * Independent review probe (not part of the suite), native MySQL only: guard red/green for malformed and out-of-order
 * rows, the cap trigger under a REPEATABLE READ snapshot, temporary shadowing of guard parents, then native
 * empty-prefix recovery for all 37 prefixes, temporary parent shadows and parent-floor drift.
 */
final class NativeSchemaGuardProbeTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    private array $log = [];

    public function test_native_guards_snapshot_cap_shadowing_prefix_recovery_and_parent_floor(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native MySQL required.');
        }
        $this->freeSetup();
        $pdo = DB::connection()->getPdo();
        $author = $this->staff();
        $reviewer = $this->staff();
        $definitions = new ProductionFreeGrantDefinitions;
        $grants = new ProductionFreeGrants;
        $a = $this->customer('a@example.test');
        $b = $this->customer('b@example.test');
        $c = $this->customer('c@example.test');
        $definition = $this->openDefinition($author, $reviewer);
        $origin = $grants->accept($definition['id'], $this->assentInput($grants->review($definition['id'], 'A Buyer', $a['principal'], $a['user'])), $a['principal'], $a['user']);
        (new ProductionFreeGrantDocuments)->render($origin['id']);
        $seal = (new ProductionFreeGrantLibrary)->show($origin['id'], $a['principal'], $a['user'])['originSeal'];
        $downloads = new ProductionFreeGrantDownloads;
        $auth = $downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'contract'], $a['principal'], $a['user']);
        $downloads->redeem($auth['id'], $auth['token'], $a['principal'], $a['user'])->close();
        $definitions->close($definition['id'], ['definitionHash' => $definition['definitionHash'], 'expectedOrdinal' => 1], $reviewer);
        $definitions->open($definition['id'], ['definitionHash' => $definition['definitionHash'], 'expectedOrdinal' => 2], $reviewer);
        $now = fn (int $offset = 0): string => now('UTC')->addSeconds($offset)->format('Y-m-d H:i:s');
        $row = fn (string $table, string $where = '1 = 1'): array => $pdo->query('SELECT * FROM '.$table.' WHERE '.$where.' LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $events = $pdo->query("SELECT id, ordinal, kind FROM production_free_availability WHERE definition_id = '".$definition['id']."' ORDER BY ordinal")->fetchAll(PDO::FETCH_ASSOC);
        $o = $row('production_free_origins');
        $acct = fn (array $customer): array => ['account_id' => $customer['principal']->accountId, 'user_id' => $customer['principal']->userId];
        $originB = [...$o, 'id' => (string) Str::uuid(), ...$acct($b), 'request_key_hash' => hash('sha256', 'b'), 'availability_id' => $events[2]['id'], 'created_at' => $now()];
        // (a) origins
        $this->case('origins: stale availability (ordinal 0 after close/reopen)', false, fn () => $this->insert('production_free_origins', [...$originB, 'id' => (string) Str::uuid(), ...$acct($c), 'request_key_hash' => hash('sha256', 'c0'), 'availability_id' => $events[0]['id']]));
        $this->case('origins: closed event as availability', false, fn () => $this->insert('production_free_origins', [...$originB, 'id' => (string) Str::uuid(), ...$acct($c), 'request_key_hash' => hash('sha256', 'c1'), 'availability_id' => $events[1]['id']]));
        $this->case('origins: staff user as customer', false, fn () => $this->insert('production_free_origins', [...$originB, 'id' => (string) Str::uuid(), 'account_id' => $c['principal']->accountId, 'user_id' => (int) $author->id, 'request_key_hash' => hash('sha256', 'c2')]));
        $this->case('origins: definition hash drift', false, fn () => $this->insert('production_free_origins', [...$originB, 'id' => (string) Str::uuid(), ...$acct($c), 'request_key_hash' => hash('sha256', 'c3'), 'definition_hash' => str_repeat('d', 64)]));
        $this->case('origins: terms hash drift', false, fn () => $this->insert('production_free_origins', [...$originB, 'id' => (string) Str::uuid(), ...$acct($c), 'request_key_hash' => hash('sha256', 'c4'), 'terms_hash' => str_repeat('d', 64)]));
        $this->case('origins: second origin same account/definition', false, fn () => $this->insert('production_free_origins', [...$originB, 'id' => (string) Str::uuid(), ...$acct($a), 'request_key_hash' => hash('sha256', 'a2')]));
        $this->case('origins: created before availability', false, fn () => $this->insert('production_free_origins', [...$originB, 'id' => (string) Str::uuid(), ...$acct($c), 'request_key_hash' => hash('sha256', 'c5'), 'created_at' => '2000-01-01 00:00:00']));
        $this->case('origins: positive control (B, latest open)', true, fn () => $this->insert('production_free_origins', $originB));
        // (b) document work for originB
        $work = ['id' => (string) Str::uuid(), 'origin_id' => $originB['id'], 'claim_id' => (string) Str::uuid(), 'ordinal' => 0, 'kind' => 'claimed',
            'lease_expires_at' => $now(300), 'payload_ciphertext' => 'x', 'seal' => str_repeat('a', 64), 'created_at' => $now()];
        $this->case('work: ordinal gap first', false, fn () => $this->insert('production_free_document_work', [...$work, 'id' => (string) Str::uuid(), 'ordinal' => 1]));
        $this->case('work: failed first', false, fn () => $this->insert('production_free_document_work', [...$work, 'id' => (string) Str::uuid(), 'kind' => 'failed']));
        $this->case('work: created_at >= lease (CHECK)', false, fn () => $this->insert('production_free_document_work', [...$work, 'id' => (string) Str::uuid(), 'lease_expires_at' => $now()]));
        $this->case('work: positive control claimed', true, fn () => $this->insert('production_free_document_work', $work));
        $this->case('work: second claim under live lease', false, fn () => $this->insert('production_free_document_work', [...$work, 'id' => (string) Str::uuid(), 'claim_id' => (string) Str::uuid(), 'ordinal' => 1]));
        $this->case('work: failure for a different claim', false, fn () => $this->insert('production_free_document_work', [...$work, 'id' => (string) Str::uuid(), 'claim_id' => (string) Str::uuid(), 'ordinal' => 1, 'kind' => 'failed']));
        $this->case('work: failure with different lease', false, fn () => $this->insert('production_free_document_work', [...$work, 'id' => (string) Str::uuid(), 'ordinal' => 1, 'kind' => 'failed', 'lease_expires_at' => $now(299)]));
        // (c) originals
        $original = $row('production_free_originals');
        $this->case('originals: second original for an origin', false, fn () => $this->insert('production_free_originals', [...$original, 'id' => (string) Str::uuid(), 'work_id' => $work['id']]));
        $this->case('originals: after lease expiry', false, fn () => $this->insert('production_free_originals', [...$original, 'id' => (string) Str::uuid(), 'origin_id' => $originB['id'], 'work_id' => $work['id'], 'claim_id' => $work['claim_id'], 'created_at' => $now(301)]));
        $this->case('originals: wrong claim', false, fn () => $this->insert('production_free_originals', [...$original, 'id' => (string) Str::uuid(), 'origin_id' => $originB['id'], 'work_id' => $work['id'], 'claim_id' => (string) Str::uuid(), 'created_at' => $now()]));
        $this->case('originals: oversize bytes (CHECK)', false, fn () => $this->insert('production_free_originals', [...$original, 'id' => (string) Str::uuid(), 'origin_id' => $originB['id'], 'work_id' => $work['id'], 'claim_id' => $work['claim_id'], 'bytes' => 16777217, 'created_at' => $now()]));
        // (d) revocations
        $revocation = ['id' => (string) Str::uuid(), 'origin_id' => $originB['id'], 'actor_user_id' => (int) $author->id, 'reason_hash' => str_repeat('f', 64),
            'payload_ciphertext' => 'x', 'seal' => str_repeat('a', 64), 'created_at' => $now()];
        $this->case('revocations: customer actor', false, fn () => $this->insert('production_free_revocations', [...$revocation, 'id' => (string) Str::uuid(), 'actor_user_id' => $c['principal']->userId]));
        $this->case('revocations: positive control', true, fn () => $this->insert('production_free_revocations', $revocation));
        $this->case('revocations: second revocation', false, fn () => $this->insert('production_free_revocations', [...$revocation, 'id' => (string) Str::uuid()]));
        // (e) authorizations
        $authorization = $row('production_free_authorizations');
        $fresh = (string) Str::uuid();
        $this->case('authorizations: revoked origin', false, fn () => $this->insert('production_free_authorizations', [...$authorization, 'id' => (string) Str::uuid(), 'origin_id' => $originB['id'], ...$acct($b), 'role' => 'master_wav', 'token_hash' => hash('sha256', 't1'), 'created_at' => $now(), 'expires_at' => $now(300)]));
        $this->case('authorizations: created_at >= expires_at (CHECK)', false, fn () => $this->insert('production_free_authorizations', [...$authorization, 'id' => (string) Str::uuid(), 'token_hash' => hash('sha256', 't2'), 'expires_at' => $authorization['created_at']]));
        $this->case('authorizations: foreign account for origin', false, fn () => $this->insert('production_free_authorizations', [...$authorization, 'id' => (string) Str::uuid(), ...$acct($b), 'token_hash' => hash('sha256', 't3')]));
        $this->case('authorizations: positive control', true, fn () => $this->insert('production_free_authorizations', [...$authorization, 'id' => $fresh, 'token_hash' => hash('sha256', 't4'), 'role' => 'stems_zip', 'artifact_sha256' => str_repeat('9', 64), 'created_at' => $now(), 'expires_at' => $now(300)]));
        // (f) redemptions
        $redemption = $row('production_free_redemptions');
        $this->case('redemptions: second for one authorization', false, fn () => $this->insert('production_free_redemptions', [...$redemption, 'id' => (string) Str::uuid()]));
        $this->case('redemptions: after expiry', false, fn () => $this->insert('production_free_redemptions', [...$redemption, 'id' => (string) Str::uuid(), 'authorization_id' => $fresh, 'role' => 'stems_zip', 'artifact_sha256' => str_repeat('9', 64), 'created_at' => $now(300)]));
        $this->case('redemptions: role/hash mismatch', false, fn () => $this->insert('production_free_redemptions', [...$redemption, 'id' => (string) Str::uuid(), 'authorization_id' => $fresh, 'created_at' => $now()]));
        $this->case('redemptions: positive control', true, fn () => $this->insert('production_free_redemptions', [...$redemption, 'id' => (string) Str::uuid(), 'authorization_id' => $fresh, 'role' => 'stems_zip', 'artifact_sha256' => str_repeat('9', 64), 'created_at' => $now()]));
        foreach (ProductionFreeGrantSchema::TABLES as $table) {
            $this->case($table.': UPDATE', false, fn () => $pdo->exec('UPDATE '.$table.' SET created_at = created_at'));
            $this->case($table.': DELETE', false, fn () => $pdo->exec('DELETE FROM '.$table));
        }

        // (g) The cap guard under a REPEATABLE READ snapshot taken before a concurrent commit.
        $capped = $this->openDefinition($author, $reviewer, ['maxOrigins' => 2, 'title' => 'Snapshot cap']);
        $d = $this->customer('d@example.test');
        $grants->accept($capped['id'], $this->assentInput($grants->review($capped['id'], 'D Buyer', $d['principal'], $d['user'])), $d['principal'], $d['user']);
        $cappedOrigin = $row('production_free_origins', "definition_id = '".$capped['id']."'");
        $config = config('database.connections.mysql');
        $other = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $other->beginTransaction();
        $snapshotCount = (int) $other->query("SELECT COUNT(*) FROM production_free_origins WHERE definition_id = '".$capped['id']."'")->fetchColumn();
        $this->insert('production_free_origins', [...$cappedOrigin, 'id' => (string) Str::uuid(), ...$acct($b), 'request_key_hash' => hash('sha256', 'cap-b'), 'created_at' => $now()]);
        $admitted = true;
        try {
            $statement = $other->prepare('INSERT INTO production_free_origins ('.implode(', ', array_keys($cappedOrigin)).') VALUES ('.implode(', ', array_fill(0, count($cappedOrigin), '?')).')');
            $statement->execute(array_values([...$cappedOrigin, 'id' => (string) Str::uuid(), ...$acct($c), 'request_key_hash' => hash('sha256', 'cap-c'), 'created_at' => $now()]));
            $other->commit();
        } catch (PDOException $error) {
            $admitted = false;
            $other->rollBack();
            $this->log[] = 'snapshot-cap third insert error: '.$error->getMessage();
        }
        $finalCount = (int) $pdo->query("SELECT COUNT(*) FROM production_free_origins WHERE definition_id = '".$capped['id']."'")->fetchColumn();
        $this->log[] = 'snapshot-cap: max_origins=2, snapshot count='.$snapshotCount.', concurrent commit reached 2, snapshot session third insert admitted='.json_encode($admitted).', final count='.$finalCount;
        $other = null;

        // (h) A session TEMPORARY table named like a guard parent shadows it inside the trigger.
        $proposed = $definitions->propose($this->definitionInput(['title' => 'Shadow target']), $author);
        $defRow = $row('production_free_definitions', "id = '".$proposed['id']."'");
        $review = ['id' => (string) Str::uuid(), 'definition_id' => $defRow['id'], 'definition_hash' => $defRow['definition_hash'], 'terms_hash' => $defRow['terms_hash'],
            'reviewer_user_id' => $c['principal']->userId, 'decision' => 'approved', 'provenance' => 'synthetic_rehearsal', 'payload_ciphertext' => 'x', 'seal' => str_repeat('a', 64), 'created_at' => $now()];
        $this->case('reviews: customer reviewer without shadow', false, fn () => $this->insert('production_free_reviews', $review));
        $pdo->exec('CREATE TEMPORARY TABLE probe_users_copy SELECT * FROM users');
        // MySQL refuses `CREATE TEMPORARY TABLE users LIKE users` (1066); build the shadow from the copy instead.
        $pdo->exec('CREATE TEMPORARY TABLE users SELECT * FROM probe_users_copy');
        $pdo->exec('UPDATE users SET is_admin = 1 WHERE id = '.$c['principal']->userId);
        $shadowed = true;
        try {
            $this->insert('production_free_reviews', [...$review, 'id' => (string) Str::uuid()]);
        } catch (PDOException) {
            $shadowed = false;
        }
        $this->log[] = 'temporary users shadow: customer-as-reviewer insert admitted under shadow='.json_encode($shadowed);
        // While the shadow exists every domain command refuses at the parent floor.
        try {
            $definitions->read($proposed['id'], $author);
            $this->log[] = 'domain read under temporary users shadow: ALLOWED';
        } catch (ProductionFreeGrantException $error) {
            $this->log[] = 'domain read under temporary users shadow: '.$error->reason;
        }
        $pdo->exec('DROP TEMPORARY TABLE users');
        $pdo->exec('DROP TEMPORARY TABLE probe_users_copy');
        try {
            $definitions->read($proposed['id'], $author);
            $this->log[] = 'domain read after shadow dropped: ALLOWED';
        } catch (ProductionFreeGrantException $error) {
            $this->log[] = 'domain read after shadow dropped: '.$error->reason;
        }

        // (i) Native empty-prefix recovery for every contiguous prefix of the installer's own order.
        $full = $this->objects();
        $this->assertCount(36, $full);
        $steps = $this->steps();
        $this->assertCount(36, $steps);
        $recovered = 0;
        foreach (range(0, 36) as $prefix) {
            $this->wipe();
            foreach (array_slice($steps, 0, $prefix) as $sql) {
                $pdo->exec($sql);
            }
            (new ProductionFreeGrantSchema)->up();
            (new ProductionFreeGrantSchema)->up();
            $this->assertSame($full, $this->objects(), 'prefix '.$prefix);
            $recovered++;
        }
        $this->log[] = 'native prefix recovery: '.$recovered.' of 37 prefixes resumed to the exact schema (SHOW CREATE TABLE + trigger statements)';
        // Earlier hole with later objects is refused natively.
        $pdo->exec('DROP TRIGGER production_free_reviews_update');
        $this->refusal('earlier guard hole', 'schema_prefix', fn () => (new ProductionFreeGrantSchema)->up());
        $this->wipe();
        (new ProductionFreeGrantSchema)->up();

        // (j) Temporary parent shadows natively.
        foreach (['users', 'customer_accounts'] as $parent) {
            $pdo->exec('CREATE TEMPORARY TABLE probe_copy_'.$parent.' SELECT * FROM '.$parent);
            $pdo->exec('CREATE TEMPORARY TABLE '.$parent.' SELECT * FROM probe_copy_'.$parent);
            $this->refusal('temporary '.$parent.' shadow (installer)', 'parent_floor', fn () => (new ProductionFreeGrantSchema)->up());
            $this->refusal('temporary '.$parent.' shadow (domain command)', 'parent_floor', fn () => $definitions->read($proposed['id'], $author));
            $pdo->exec('DROP TEMPORARY TABLE '.$parent);
            $pdo->exec('DROP TEMPORARY TABLE probe_copy_'.$parent);
        }
        // (k) Parent-floor drift natively, before the first owned DDL.
        $this->wipe();
        $pdo->exec('ALTER TABLE customer_accounts MODIFY active INT NOT NULL');
        $this->refusal('customer_accounts.active drift', 'parent_floor', fn () => (new ProductionFreeGrantSchema)->up());
        $this->assertSame([], $this->objects());
        $pdo->exec('ALTER TABLE customer_accounts MODIFY active TINYINT(1) NOT NULL');
        $pdo->exec('ALTER TABLE users MODIFY email_verified_at DATETIME NULL');
        $this->refusal('users.email_verified_at drift', 'parent_floor', fn () => (new ProductionFreeGrantSchema)->up());
        $this->assertSame([], $this->objects());
        fwrite(STDERR, "PROBE native:\n  ".implode("\n  ", $this->log)."\n");
    }

    private function case(string $label, bool $admit, callable $operation): void
    {
        try {
            $operation();
            $this->log[] = ($admit ? 'admitted  ' : 'ADMITTED! ').$label;
            $this->assertTrue($admit, $label.' was admitted');
        } catch (PDOException $error) {
            $this->log[] = ($admit ? 'REFUSED!  ' : 'refused   ').$label.' ['.$error->errorInfo[1].']';
            $this->assertFalse($admit, $label.' was refused: '.$error->getMessage());
        }
    }

    private function refusal(string $label, string $reason, callable $operation): void
    {
        try {
            $operation();
            $this->log[] = 'ALLOWED! '.$label;
            $this->fail($label);
        } catch (ProductionFreeGrantException $error) {
            $this->log[] = 'refused   '.$label.' ('.$error->reason.')';
            $this->assertSame($reason, $error->reason);
        }
    }

    private function insert(string $table, array $row): void
    {
        $statement = DB::connection()->getPdo()->prepare('INSERT INTO '.$table.' ('.implode(', ', array_map(fn ($c) => '`'.$c.'`', array_keys($row))).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')');
        $statement->execute(array_values($row));
    }

    private function steps(): array
    {
        $schema = new ProductionFreeGrantSchema;
        $reflection = new ReflectionClass($schema);
        $definition = $reflection->getMethod('definition');
        $guards = $reflection->getMethod('guards');
        $steps = [];
        foreach (ProductionFreeGrantSchema::TABLES as $logical) {
            $table = $schema->table($logical);
            $steps[] = 'CREATE TABLE '.$table.' ('.$definition->invoke($schema, $logical, 'mysql')['sql'].') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
            foreach ($guards->invoke($schema, $logical, 'mysql', $logical, $table) as $guard) {
                $steps[] = $guard['sql'];
            }
        }

        return $steps;
    }

    private function wipe(): void
    {
        foreach (array_reverse(ProductionFreeGrantSchema::TABLES) as $table) {
            DB::connection()->getPdo()->exec('DROP TABLE IF EXISTS `'.$table.'`');
        }
    }

    private function objects(): array
    {
        $pdo = DB::connection()->getPdo();
        $objects = [];
        foreach ($pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'production\\_free\\_%' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $objects['table:'.$table] = preg_replace('/ AUTO_INCREMENT=\d+/', '', $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1]);
        }
        foreach ($pdo->query("SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_ORDER, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'production\\_free\\_%' ORDER BY TRIGGER_NAME")->fetchAll(PDO::FETCH_ASSOC) as $trigger) {
            $objects['trigger:'.$trigger['TRIGGER_NAME']] = json_encode($trigger);
        }
        ksort($objects);

        return $objects;
    }
}
