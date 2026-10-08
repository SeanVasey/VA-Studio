<?php
// Reviewer check (addendum 2): what the 855610be expression (unchanged regex, no delimiter guard)
// returns for the unit test's bodies and for the bodies MySQL actually stored (storage/delimiter-storage.out).
// 1 = qualifies (the guard treats the object as a dependency and refuses); 0 = not a qualifier (admitted).
$m = static function (string $sql, string $db): int {
    $name = preg_quote($db, '/');

    return preg_match('/(?:`'.$name.'`|"'.$name.'"|(?<![A-Za-z0-9_$\x{80}-\x{10FFFF}])'.$name.')(?:\s|\/\*.*?\*\/|(?:--\s|#)[^\n]*)*\./isu', $sql);
};
foreach (['a`b', 'a"b', 'a``b', '"a'] as $db) {
    $sql = 'SELECT COUNT(*) FROM `'.str_replace('`', '``', $db).'`.`owned`';
    printf("unit-test body  db=%-6s %d  %s\n", $db, $m($sql, $db), $sql);
}
foreach ([['rv`bt', 'SELECT COUNT(*) AS q_backtick FROM `rrv`btt`.`owned`'], ['rv"q', 'SELECT COUNT(*) AS q_dquote FROM `rv"q`.`owned`'],
    ['rv"q', 'SELECT COUNT(*) AS ansi_dq FROM "rrv"qq"."owned"']] as [$db, $sql]) {
    printf("stored body     db=%-6s %d  %s\n", $db, $m($sql, $db), $sql);
}
