<?php

$assertions = 0;
$verify = function (bool $ok) use (&$assertions): void {
    $assertions++;
    if (! $ok) {
        throw new RuntimeException('Pure native relation assertion failed');
    }
};
$refuses = function (callable $operation) use ($verify): void {
    try {
        $operation();
    } catch (RuntimeException) {
        $verify(true);

        return;
    }
    $verify(false);
};
$inventory = [];
foreach (['customer_accounts_insert' => 'BASE TABLE', 'customer_accounts_delete' => 'VIEW', 'CUSTOMER_ACCOUNTS_UPDATE' => 'BASE TABLE', 'custómer_accounts_insert' => 'BASE TABLE'] as $name => $type) {
    $inventory[] = ['TABLE_NAME' => $name, 'TABLE_TYPE' => $type, 'reserved_match' => in_array($name, ['customer_accounts_insert', 'customer_accounts_delete'], true) ? 1 : 0, 'case_match' => 0, 'accent_match' => $name === 'customer_accounts_insert' ? 1 : 0, 'pad_match' => $name === 'customer_accounts_insert' ? 1 : 0];
}
$projection = nativeRelationProjection($inventory);
$verify(count($projection['reserved']) === 2 && $projection['case'] === []);
$verify($projection['accent'] === [['TABLE_NAME' => 'customer_accounts_insert']]);
$verify($projection['pad'] === [['TABLE_NAME' => 'customer_accounts_insert']]);
assertNativeRelation($projection['case'], []);
$verify(true);
// A native TRUE alias relation must be included; PHP does not fold identifiers.
$nativeTrue = $inventory;
$nativeTrue[2]['reserved_match'] = 1;
$nativeTrue[2]['case_match'] = 1;
$nativeTrue[3]['accent_match'] = 1;
$nativeTrue[0]['pad_match'] = 0;
$positive = nativeRelationProjection($nativeTrue);
$verify(count($positive['reserved']) === 3);
$verify($positive['case'] === [['TABLE_NAME' => 'CUSTOMER_ACCOUNTS_UPDATE']]);
$verify(count($positive['accent']) === 2 && $positive['pad'] === []);
$refuses(fn () => nativeRelationProjection(array_slice($inventory, 0, 3)));
$refuses(fn () => nativeRelationProjection(array_slice($inventory, 1)));
$wrongCanonical = $inventory;
$wrongCanonical[1]['TABLE_TYPE'] = 'BASE TABLE';
$refuses(fn () => nativeRelationProjection($wrongCanonical));
foreach ([['TABLE_NAME', 'customer_accounts_update'], ['TABLE_NAME', 'customer_accounts_insert'], ['TABLE_TYPE', 'VIEW'], ['reserved_match', null], ['case_match', '0'], ['accent_match', 2], ['pad_match', false]] as [$field, $value]) {
    $bad = $inventory;
    $bad[2][$field] = $value;
    $refuses(fn () => nativeRelationProjection($bad));
}
$bad = $inventory;
$bad[1]['reserved_match'] = 0;
$refuses(fn () => nativeRelationProjection($bad));
$bad = $inventory;
$bad[] = $inventory[2];
$refuses(fn () => nativeRelationProjection($bad));
$refuses(fn () => assertNativeRelation($inventory, $nativeTrue));
$refuses(fn () => assertNativeRelation($projection['reserved'], array_slice($projection['reserved'], 0, 1)));
$refuses(fn () => assertNativeRelation($projection['accent'], [['TABLE_NAME' => 'custómer_accounts_insert']]));
$refuses(fn () => assertNativeRelation($positive['pad'], $projection['pad']));
$verify(str_contains(specs(['token' => '012345abcdef'])['relation.inventory'], 'BINARY TABLE_NAME IN (BINARY'));
emit(['pure_native_relations' => 'PASS', 'assertions' => $assertions, 'database_connections' => 0, 'native_comparison_execution' => false]);
