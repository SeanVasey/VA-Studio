<?php

// Pure classification only: no database or native capability claim.
$checks = 0;
$check = function (bool $condition) use (&$checks): void {
    $checks++;
    if (! $condition) {
        throw new RuntimeException('Pure capability classification failed at '.$checks);
    }
};
foreach (['share' => 'LOCK IN SHARE MODE', 'update' => 'FOR UPDATE'] as $family => $clause) {
    $error = ['state' => 'HY000', 'code' => 3550, 'message' => "SQLSTATE[HY000]: General error: 3550 Information schema queries do not support the '$clause' clause."];
    foreach (['tables', 'triggers', 'routines', 'events', 'indexes', 'constraints', 'peer_scope', 'union'] as $kind) {
        $check(capability($family.'.'.$kind, $error));
    }
    foreach (['plain.tables', 'show.tables', $family.'.unknown', $family.'.tables.extra'] as $id) {
        $check(! capability($id, $error));
    }
    foreach (['state' => '42000', 'code' => 1295, 'message' => $error['message'].' '] as $key => $value) {
        $bad = $error;
        $bad[$key] = $value;
        $check(! capability($family.'.tables', $bad));
    }
    $other = $error;
    $other['message'] = "SQLSTATE[HY000]: General error: 3550 Information schema queries do not support the '".($family === 'share' ? 'FOR UPDATE' : 'LOCK IN SHARE MODE')."' clause.";
    $check(! capability($family.'.tables', $other));
    $warning = ['Level' => 'Error', 'Code' => 3550, 'Message' => substr($error['message'], strlen('SQLSTATE[HY000]: General error: 3550 '))];
    $check(exactCapabilityWarning($error, [$warning]));
    $check(! exactCapabilityWarning($error, []));
    $check(! exactCapabilityWarning($error, [$warning, $warning]));
    $warning['Code'] = 1295;
    $check(! exactCapabilityWarning($error, [$warning]));
}
emit(['pure_capability' => 'PASS', 'assertions' => $checks, 'database_connections' => 0, 'native_update_execution' => false]);
