<?php
// Reviewer matcher matrix for b47de6b6: calls the three private qualifier matchers by reflection
// with adversarial definitions and database names (no database connection). Run from the worktree:
//   php <this file>
require getcwd().'/vendor/autoload.php';

use App\Domain\Commerce\ProductionPolicy\CapabilityMigrationOwnership;
use App\Domain\Customers\ProductionIdentity\IdentityMigrationOwnership;

$capability = new CapabilityMigrationOwnership;
$identity = new IdentityMigrationOwnership;
$inquiry = require getcwd().'/database/migrations/2026_10_07_243000_inquiry_notification_intents.php';
$impls = [
    'capability' => fn ($sql, $db) => (new ReflectionMethod($capability, 'qualifies'))->invoke($capability, $sql, $db),
    'identity' => fn ($sql, $db) => (new ReflectionMethod($identity, 'qualifies'))->invoke($identity, $sql, $db),
    'inquiry' => fn ($sql, $db) => (new ReflectionMethod($inquiry, 'qualifiesDatabase'))->invoke($inquiry, $sql, $db),
];
// The three expressions must be identical.
$patterns = [];
foreach (['app/Domain/Commerce/ProductionPolicy/CapabilityMigrationOwnership.php', 'app/Domain/Customers/ProductionIdentity/IdentityMigrationOwnership.php',
    'database/migrations/2026_10_07_243000_inquiry_notification_intents.php'] as $file) {
    preg_match_all("/preg_match\\('\\/\\(\\?:`'\\.[^\\n]*/", file_get_contents($file), $m);
    $patterns[$file] = $m[0];
}
$flat = array_merge(...array_values($patterns));
printf("pattern lines found: %d, distinct: %d\n%s\n", count($flat), count(array_unique($flat)), $flat[0] ?? '(none)');

$t = 'production_identity_origins';
$cases = static function (string $d) use ($t): array {
    $u = mb_strtoupper($d);

    return [
        // [label, sql, expected: 1 qualifies, 0 does not, 'refuse' = matcher throws (fail closed)]
        ['backtick', "SELECT COUNT(*) FROM `$d`.`$t`", 1],
        ['bare', "SELECT COUNT(*) FROM $d.$t", 1],
        ['spaces around dot', "SELECT COUNT(*) FROM $d . $t", 1],
        ['tabs/newlines around dot', "SELECT COUNT(*) FROM $d\n\t.\n$t", 1],
        ['block comment before dot', "SELECT COUNT(*) FROM $d/* c */.$t", 1],
        ['multi-line block comment before dot', "SELECT COUNT(*) FROM `$d` /* a\n b */ . `$t`", 1],
        ['-- comment before dot', "SELECT COUNT(*) FROM $d -- c\n . $t", 1],
        ['# comment before dot', "SELECT COUNT(*) FROM $d # c\n.$t", 1],
        ['ANSI double quotes', "SELECT COUNT(*) FROM \"$d\".\"$t\"", 1],
        ['double-quoted qualifier (ANSI_QUOTES off: a string)', "SELECT \"$d\".x", 1],
        ['upper case', "SELECT COUNT(*) FROM `$u`.`$t`", 1],
        ['upper case bare', "SELECT COUNT(*) FROM $u.$t", 1],
        ['inside a string literal', "SELECT '$d.$t'", 1],
        ['no dot', "SELECT '$d' AS $t", 0],
        ['dot only inside a comment', "SELECT COUNT(*) FROM $d/*.*/$t", 0],
        ['dot inside a versioned comment (raw text; MySQL stores it expanded)', "SELECT COUNT(*) FROM `$d` /*!.*/ `$t`", 0],
        ['-- without space is not a comment', "SELECT $d--x\n.$t", 0],
        ['suffix -2 quoted', "SELECT COUNT(*) FROM `$d-2`.`$t`", 0],
        ['suffix $x quoted', "SELECT COUNT(*) FROM `{$d}\$x`.`$t`", 0],
        ['suffix $x bare', "SELECT COUNT(*) FROM {$d}\$x.$t", 0],
        ['suffix é quoted', "SELECT COUNT(*) FROM `{$d}é`.`$t`", 0],
        ['suffix é bare', "SELECT COUNT(*) FROM {$d}é.$t", 0],
        ['suffix _x', "SELECT COUNT(*) FROM {$d}_x.$t", 0],
        ['prefix x quoted', "SELECT COUNT(*) FROM `x$d`.`$t`", 0],
        ['prefix x bare', "SELECT COUNT(*) FROM x$d.$t", 0],
        ['prefix $ bare', "SELECT COUNT(*) FROM \$$d.$t", 0],
        ['prefix é bare', "SELECT COUNT(*) FROM é$d.$t", 0],
        ['prefix - quoted', "SELECT COUNT(*) FROM `x-$d`.`$t`", 0],
        ['backtick-escaped dot (identifier "<db>`.t")', "SELECT COUNT(*) FROM `$d``.$t`", 0],
        ['qualifier inside another quoted identifier', "SELECT COUNT(*) FROM `a $d`.`$t`", 0],
        ['table named like the db, after another schema', "SELECT other.$d.id FROM other.$d", 1],
        ['ReDoS attempt: name then 40 # and no dot/newline', "SELECT $d".str_repeat('#', 40).'x', 'any'],
        ['ReDoS attempt: name then 2000 "# \n" lines and no dot', "SELECT $d".str_repeat("# \n", 2000).' x', 'any'],
        ['invalid UTF-8 body', "SELECT `$d`.`$t` \xC3\x28", 'refuse'],
    ];
};
$names = ['vaseyaudio_review_trigscan', 'va+sey(1)', 'va*sey?', 'va$sey', 'va#sey', 'va/sey', 'va|sey', 'va-sey', 'va.sey', 'vasé', 'va[s]ey', 'va\\sey', 'va^sey{2}'];
$bad = 0;
$total = 0;
foreach ($names as $d) {
    foreach ($cases($d) as [$label, $sql, $expected]) {
        foreach ($impls as $impl => $call) {
            $total++;
            $started = hrtime(true);
            try {
                $got = $call($sql, $d) ? 1 : 0;
            } catch (Throwable $e) {
                $got = 'refuse';
            }
            $ms = (hrtime(true) - $started) / 1e6;
            $ok = $expected === 'any' ? true : $got === $expected;
            if (! $ok) {
                $bad++;
            }
            if (! $ok || $impl === 'capability' || $expected === 'any') {
                printf("%s db=%-28s %-10s %-66s expected=%-6s got=%-6s %.1fms\n", $ok ? 'ok ' : 'BAD', $d, $impl, $label, (string) $expected, (string) $got, $ms);
            }
        }
    }
}
printf("total checks=%d mismatches=%d\n", $total, $bad);
exit($bad === 0 ? 0 : 1);
