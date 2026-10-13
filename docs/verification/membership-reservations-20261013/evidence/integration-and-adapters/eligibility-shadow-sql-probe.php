<?php

// Standalone disposable MySQL fixture probe. No application bootstrap or shared-schema writes.
$pdo = new PDO('mysql:unix_socket=/workspace/runtime/mysql-policy-run/mysql.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
echo 'MySQL ', $pdo->query('SELECT VERSION()')->fetchColumn(), PHP_EOL;
$schema = 'vaseyaudio_membership_shadow_probe';
$created = false;
try {
    $pdo->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
    $created = true;
    $pdo->exec('USE `'.$schema.'`');
    echo 'schema ', $pdo->query('SELECT DATABASE()')->fetchColumn(), PHP_EOL;
    $pdo->exec('CREATE TABLE owners (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO owners VALUES (1)');
    $pdo->exec('CREATE TABLE tracks (id BIGINT UNSIGNED PRIMARY KEY, owner_id BIGINT UNSIGNED NOT NULL, title VARCHAR(64) NOT NULL, metadata JSON NULL, published_at TIMESTAMP NULL, CONSTRAINT shadow_probe_owner_fk FOREIGN KEY (owner_id) REFERENCES owners(id)) ENGINE=InnoDB');
    $insert = $pdo->prepare('INSERT INTO tracks VALUES (?, ?, ?, ?, ?)');
    $insert->execute([1, 1, 'SYNTHETIC exact-row probe', '{"kind":"synthetic","number":17}', '2026-10-13 12:00:00']);
    $insert->execute([2, 1, 'SYNTHETIC null-field probe', null, null]);
    $durableRows = $pdo->query('SELECT * FROM tracks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    try {
        $pdo->exec('CREATE TEMPORARY TABLE tracks LIKE tracks');
        throw new RuntimeException('Old SQL unexpectedly succeeded.');
    } catch (PDOException $error) {
        if (($error->errorInfo[1] ?? null) !== 1066) {
            throw $error;
        }
        echo 'original SQL: expected MySQL error 1066', PHP_EOL;
    }
    // Same definition-copy pattern already used by PersistentCatalogDraftImportTest.
    $definition = (string) $pdo->query('SHOW CREATE TABLE tracks')->fetch(PDO::FETCH_NUM)[1];
    $lines = array_filter(explode("\n", $definition), static fn (string $line): bool => ! str_contains($line, ' FOREIGN KEY '));
    $definition = (string) preg_replace('/,(\n\))/', '$1', implode("\n", $lines));
    $pdo->exec((string) preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $definition));
    $temporaryDefinition = (string) $pdo->query('SHOW CREATE TABLE tracks')->fetch(PDO::FETCH_NUM)[1];
    if (! str_starts_with($temporaryDefinition, 'CREATE TEMPORARY TABLE')) {
        throw new RuntimeException('Temporary definition was not created.');
    }
    foreach ($durableRows as $row) {
        $statement = $pdo->prepare('INSERT INTO tracks (`'.implode('`, `', array_keys($row)).'`) VALUES ('
            .implode(', ', array_fill(0, count($row), '?')).')');
        $statement->execute(array_values($row));
    }
    $shadowRows = $pdo->query('SELECT * FROM tracks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    if ($shadowRows !== $durableRows) {
        throw new RuntimeException('Shadow rows differ from durable rows.');
    }
    echo 'replacement SQL: temporary definition admitted; ', count($shadowRows), ' rows exactly equal including PDO types', PHP_EOL;
    $pdo->exec('DROP TEMPORARY TABLE tracks');
    if ($pdo->query('SELECT * FROM tracks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) !== $durableRows) {
        throw new RuntimeException('Durable source changed.');
    }
    echo 'durable source unchanged after shadow removal', PHP_EOL;
} finally {
    if ($created) {
        $pdo->exec('DROP DATABASE `'.$schema.'`');
        echo 'owned probe schema removed', PHP_EOL;
    }
}
