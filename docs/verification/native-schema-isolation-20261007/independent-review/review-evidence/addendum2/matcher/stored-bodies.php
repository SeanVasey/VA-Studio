<?php
// Reviewer harness (addendum 2): reads the bodies MySQL 8.4.11 actually stored (storage/delimiter-storage.sql)
// from the private instance and runs the three private qualifier matchers by reflection, plus a
// hypothetical "nearest regex fix" (identifier-character lookbehind before the opening delimiter too).
// Run from the worktree: DB_PORT=3709 DB_PASSWORD=... php <this file>
require getcwd().'/vendor/autoload.php';

use App\Domain\Commerce\ProductionPolicy\CapabilityMigrationOwnership;
use App\Domain\Customers\ProductionIdentity\IdentityMigrationOwnership;

$pdo = new PDO('mysql:host=127.0.0.1;port='.getenv('DB_PORT').';charset=utf8mb4', 'root', getenv('DB_PASSWORD'));
$bodies = [];
foreach ($pdo->query("SELECT ROUTINE_SCHEMA s, ROUTINE_NAME n, ROUTINE_DEFINITION b FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA IN ('rvpeer','a`b','aa`bb') UNION ALL SELECT TRIGGER_SCHEMA, TRIGGER_NAME, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA IN ('rvpeer','a`b')") as $r) {
    $bodies[$r['s'].'.'.$r['n']] = $r['b'];
}
$capability = new CapabilityMigrationOwnership;
$identity = new IdentityMigrationOwnership;
$inquiry = require getcwd().'/database/migrations/2026_10_07_243000_inquiry_notification_intents.php';
$impls = [
    'capability' => fn ($sql, $db) => (new ReflectionMethod($capability, 'qualifies'))->invoke($capability, $sql, $db),
    'identity' => fn ($sql, $db) => (new ReflectionMethod($identity, 'qualifies'))->invoke($identity, $sql, $db),
    'inquiry' => fn ($sql, $db) => (new ReflectionMethod($inquiry, 'qualifiesDatabase'))->invoke($inquiry, $sql, $db),
];
$variant = static function (string $sql, string $db): int {
    $n = preg_quote($db, '/');
    $id = '(?<![A-Za-z0-9_$\x{80}-\x{10FFFF}])';

    return preg_match('/'.$id.'(?:`'.$n.'`|"'.$n.'"|'.$n.')(?:\s|\/\*.*?\*\/|(?:--\s|#)[^\n]*)*\./isu', $sql);
};
// [object, selected database, expected from all three guards (1 qualifies, 0 not, 'refuse'), what MySQL resolves (storage .out), note]
$cases = [
    ['rvpeer.q_dot', 'rv.dot', 1, 'rv.dot.owned', 'dot inside the name'],
    ['rvpeer.q_block', 'rv/*c', 1, 'rv/*c.owned', 'block-comment opener inside the name'],
    ['rvpeer.q_dash', 'rv--c', 1, 'rv--c.owned', 'dash-dash inside the name'],
    ['rvpeer.q_hash', 'rv#c', 1, 'rv#c.owned', 'hash inside the name'],
    ['rvpeer.q_space', 'rv sp', 1, 'rv sp.owned', 'inner space; spaces around the dot'],
    ['rvpeer.q_backslash', 'rv\\bs', 1, 'rv\\bs.owned', 'backslash inside the name'],
    ['rvpeer.q_dquote', 'rv"q', 'refuse', 'rv"q.owned', 'double quote: fail-closed guard'],
    ['rvpeer.ansi_dq', 'rv"q', 'refuse', 'rv"q.owned (stored mangled "rrv"qq")', 'ANSI_QUOTES doubled quote: fail-closed guard'],
    ['rvpeer.q_backtick', 'rv`bt', 'refuse', 'rv`bt.owned (stored mangled `rrv`btt`)', 'backtick: fail-closed guard'],
    ['rvpeer.q_dot', 'rv', 1, 'rv.dot.owned (peer)', 'selected rv, peer rv.dot: over-refusal (safe)'],
    ['rvpeer.q_hash', 'rv', 1, 'rv#c.owned (peer)', 'selected rv, peer rv#c: # consumes to the later dot, over-refusal (safe)'],
    ['rvpeer.q_block', 'rv', 0, 'rv/*c.owned (peer)', 'selected rv, peer rv/*c: unterminated comment, no match'],
    ['rvpeer.q_dash', 'rv', 0, 'rv--c.owned (peer)', 'selected rv, peer rv--c: -- needs whitespace, no match'],
    ['rvpeer.q_space', 'rv', 0, 'rv sp.owned (peer)', 'selected rv, peer "rv sp": no match'],
    ['a`b.own', 'bb', 1, 'a`b.owned (peer itself, 7 rows)', 'Codex P2-2: peer a`b stored `aa`bb`: over-refusal for selected bb'],
    ['a`b.t_own', 'bb', 1, 'a`b.owned (peer itself)', 'Codex P2-2, trigger form'],
    ['aa`bb.own', 'bbb', 1, 'aa`bb.owned (peer itself, 5 rows)', 'same mangling one level longer (`aaa`bbb`)'],
    ['a`b.own', 'b', 0, 'a`b.owned (peer)', 'selected b: `bb` ≠ `b`, bare b preceded by b'],
    ['rvpeer.nosep', 'bb', 1, 'bb.owned (2 rows)', 'FROM`bb`.`owned`: real dependency on bb'],
    ['rvpeer.nosep2', 'bb', 1, 'bb.owned (2 rows)', 'FROM`bb` inside a derived table'],
    ['rvpeer.t_nosep', 'bb', 1, 'bb.owned (2 rows)', 'trigger form'],
    ['rvpeer.ansi_bb', 'bb', 1, 'bb.owned (2 rows)', 'ANSI_QUOTES FROM"bb"."owned"'],
];
$bad = 0;
foreach ($cases as [$object, $db, $expected, $resolves, $note]) {
    $sql = $bodies[$object] ?? throw new RuntimeException("missing $object");
    $got = [];
    foreach ($impls as $name => $impl) {
        try {
            $got[$name] = $impl($sql, $db) ? 1 : 0;
        } catch (LogicException $e) {
            $got[$name] = 'refuse';
        }
    }
    $ok = count(array_unique($got)) === 1 && reset($got) === $expected;
    $bad += $ok ? 0 : 1;
    printf("%-4s %-18s db=%-8s guards=%-7s variant=%d resolves=%s | %s\n     stored: %s\n", $ok ? 'ok' : 'BAD', $object, $db,
        implode('/', array_unique(array_map('strval', $got))), $variant($sql, $db), $resolves, $note, $sql);
}
printf("cases=%d mismatches=%d\n", count($cases), $bad);
exit($bad === 0 ? 0 : 1);
