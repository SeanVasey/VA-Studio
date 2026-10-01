<?php

// Disposable CI evidence only. Invoke from the exact application checkout root.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

if (! $app->environment('testing') || DB::getDriverName() !== 'mysql'
    || ! in_array(DB::getDatabaseName(), ['vaseyaudio_test', 'vaseyaudio_diagnostic'], true)) {
    throw new RuntimeException('This probe only runs in the disposable MySQL testing database.');
}

function emitDiagnostic(string $kind, array $data): void
{
    echo json_encode(['kind' => $kind] + $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
}

function warnings(): array
{
    return array_map(fn ($row) => (array) $row, DB::select('SHOW WARNINGS'));
}

function parameterEvidence(string $value): array
{
    return ['php_bytes' => strlen($value), 'php_hex' => bin2hex($value), 'bound' => (array) DB::selectOne(
        'SELECT HEX(?) AS bytes_hex, OCTET_LENGTH(?) AS byte_length, CHAR_LENGTH(?) AS character_length', [$value, $value, $value])];
}

$pdo = DB::connection()->getPdo();
$attributes = [];
foreach (['client_version' => PDO::ATTR_CLIENT_VERSION, 'server_version' => PDO::ATTR_SERVER_VERSION,
    'emulate_prepares' => PDO::ATTR_EMULATE_PREPARES] as $label => $attribute) {
    try { $attributes[$label] = $pdo->getAttribute($attribute); }
    catch (Throwable $error) { $attributes[$label] = ['unsupported' => get_class($error)]; }
}
emitDiagnostic('environment', ['connection' => (array) DB::selectOne(
    'SELECT VERSION() AS server_version, @@session.sql_mode AS session_sql_mode, @@global.sql_mode AS global_sql_mode,
    @@character_set_connection AS connection_charset, @@collation_connection AS connection_collation'),
    'pdo' => $attributes, 'hash_migration_sha256' => hash_file('sha256', getcwd().'/database/migrations/2026_10_01_000029_byte_exact_hash_guards.php')]);
emitDiagnostic('columns', ['rows' => array_map(fn ($row) => (array) $row, DB::select(
    "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME, IS_NULLABLE
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
    AND ((TABLE_NAME IN ('site_images', 'site_image_variants') AND COLUMN_NAME IN ('source_sha256', 'sha256', 'manifest_sha256', 'profile_fingerprint', 'claim_token'))
    OR (TABLE_NAME = 'test_delivery_authorizations' AND COLUMN_NAME IN ('public_id', 'token_hash', 'evidence_hash'))
    OR (TABLE_NAME = 'contract_render_requests' AND COLUMN_NAME IN ('public_id', 'document_public_id')))
    ORDER BY TABLE_NAME, ORDINAL_POSITION"))]);
emitDiagnostic('triggers', ['rows' => array_map(fn ($row) => (array) $row, DB::select(
    "SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, SQL_MODE, ACTION_STATEMENT
    FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()
    AND EVENT_OBJECT_TABLE IN ('site_images', 'site_image_variants', 'test_delivery_authorizations')
    ORDER BY EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_ORDER"))]);

$migration = require getcwd().'/database/migrations/2026_10_01_000029_byte_exact_hash_guards.php';
$specifications = [
    'char64_no_trigger' => ['CHAR(64)', null, 'utf8mb4', 'utf8mb4_unicode_ci', false],
    'varchar64_no_trigger' => ['VARCHAR(64)', null, 'utf8mb4', 'utf8mb4_unicode_ci', false],
    'varchar64_ascii_no_trigger' => ['VARCHAR(64)', null, 'ascii', 'ascii_bin', false],
    'char36_no_trigger' => ['CHAR(36)', null, 'utf8mb4', 'utf8mb4_unicode_ci', false],
    'varchar36_ascii_no_trigger' => ['VARCHAR(36)', null, 'ascii', 'ascii_bin', false],
    'char64_plain' => ['CHAR(64)', null, 'utf8mb4', 'utf8mb4_unicode_ci'],
    'char64_length' => ['CHAR(64)', $migration->hashBytes('NEW.candidate', false), 'utf8mb4', 'utf8mb4_unicode_ci'],
    'char64_hex' => ['CHAR(64)', $migration->hashBytes('NEW.candidate', true), 'utf8mb4', 'utf8mb4_unicode_ci'],
    'varchar64_plain' => ['VARCHAR(64)', null, 'utf8mb4', 'utf8mb4_unicode_ci'],
    'varchar64_ascii_hex' => ['VARCHAR(64)', $migration->hashBytes('NEW.candidate', true), 'ascii', 'ascii_bin'],
    'varchar128_length' => ['VARCHAR(128)', $migration->hashBytes('NEW.candidate', false), 'utf8mb4', 'utf8mb4_unicode_ci'],
    'varchar128_hex' => ['VARCHAR(128)', $migration->hashBytes('NEW.candidate', true), 'utf8mb4', 'utf8mb4_unicode_ci'],
    'char36_plain' => ['CHAR(36)', null, 'utf8mb4', 'utf8mb4_unicode_ci'],
    'varchar36_plain' => ['VARCHAR(36)', null, 'utf8mb4', 'utf8mb4_unicode_ci'],
    'varchar36_ascii' => ['VARCHAR(36)', null, 'ascii', 'ascii_bin'],
    'varchar72_uuid' => ['VARCHAR(72)', "OCTET_LENGTH(NEW.candidate) = 36 AND REGEXP_LIKE(NEW.candidate, '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$', 'c')", 'ascii', 'ascii_bin'],
];
$hashes = ['valid' => str_repeat('a', 64), 'NUL suffix' => str_repeat('a', 64)."\0suffix",
    'NUL terminator' => str_repeat('a', 64)."\0", 'newline suffix' => str_repeat('a', 64)."\n",
    'CRLF suffix' => str_repeat('a', 64)."\r\n", 'space suffix' => str_repeat('a', 64).' ',
    'ordinary overflow' => str_repeat('a', 65), 'multibyte' => str_repeat('é', 64),
    'short' => str_repeat('a', 63), 'embedded NUL' => str_repeat('a', 31)."\0".str_repeat('a', 32)];
$uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
$uuids = ['valid' => $uuid, 'NUL suffix' => $uuid."\0suffix", 'NUL terminator' => $uuid."\0",
    'newline suffix' => $uuid."\n", 'space suffix' => $uuid.' ', 'ordinary overflow' => $uuid.'a',
    'embedded NUL' => substr($uuid, 0, 20)."\0".substr($uuid, 21), 'short' => substr($uuid, 0, 35)];

$created = [];
try {
    foreach ($specifications as $label => $specification) {
        [$type, $predicate, $charset, $collation] = $specification;
        $observerInstalled = $specification[4] ?? true;
        $table = 'diagnostic_bytes_'.$label;
        DB::unprepared("CREATE TABLE {$table} (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, candidate {$type} CHARACTER SET {$charset} COLLATE {$collation} NOT NULL) ENGINE=InnoDB");
        $created[] = $table;
        $guard = $predicate === null ? '' : "IF NOT COALESCE(({$predicate}), 0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Diagnostic byte guard'; END IF;";
        if ($observerInstalled) {
            DB::unprepared("CREATE TRIGGER {$table}_observe BEFORE INSERT ON {$table} FOR EACH ROW BEGIN
                SET @diagnostic_new_hex = HEX(NEW.candidate); SET @diagnostic_new_length = OCTET_LENGTH(NEW.candidate); {$guard} END");
        }
        foreach (str_contains($label, '36') || str_contains($label, '72') ? $uuids : $hashes as $case => $value) {
            foreach (['bound', 'hex_literal'] as $transport) {
                $evidence = parameterEvidence($value);
                DB::statement('SET @diagnostic_new_hex = NULL, @diagnostic_new_length = NULL');
                $error = null;
                try {
                    if ($transport === 'bound') { DB::insert("INSERT INTO {$table} (candidate) VALUES (?)", [$value]); }
                    else { DB::insert("INSERT INTO {$table} (candidate) VALUES (CONVERT(UNHEX('".bin2hex($value)."') USING utf8mb4))"); }
                } catch (Throwable $caught) {
                    $error = ['class' => get_class($caught), 'code' => $caught->getCode(), 'message' => $caught->getMessage()];
                }
                // Must precede any reads that would clear the insert's diagnostics area.
                $warningRows = warnings();
                $observed = (array) DB::selectOne('SELECT @diagnostic_new_hex AS bytes_hex, @diagnostic_new_length AS byte_length');
                $stored = $error === null ? (array) DB::selectOne("SELECT HEX(candidate) AS bytes_hex, OCTET_LENGTH(candidate) AS byte_length, CHAR_LENGTH(candidate) AS character_length FROM {$table} ORDER BY id DESC LIMIT 1") : null;
                emitDiagnostic('scratch_insert', ['table' => $label, 'observer_installed' => $observerInstalled, 'case' => $case, 'transport' => $transport,
                    'input' => $evidence, 'error' => $error, 'warnings' => $warningRows, 'before_trigger' => $observed, 'stored' => $stored]);
            }
        }
    }

    // Exercise the actual application guards without retaining even synthetic image rows.
    DB::beginTransaction();
    try {
        $uploader = App\Models\User::factory()->create()->id;
        foreach ($hashes as $case => $value) {
            $evidence = parameterEvidence($value);
            $attributes = ['slot' => 'share', 'original_name' => 'diagnostic.jpg',
                'source_path' => 'site-images/quarantine/'.Illuminate\Support\Str::uuid().'/source.upload', 'source_sha256' => $value,
                'size_bytes' => 10, 'mime_type' => 'image/jpeg', 'width' => 1200, 'height' => 630,
                'credit' => 'Synthetic CI diagnostic', 'rights_confirmed_at' => now(), 'uploaded_by' => $uploader,
                'created_at' => now(), 'updated_at' => now()];
            $error = null; $id = null;
            try { $id = DB::table('site_images')->insertGetId($attributes); }
            catch (Throwable $caught) { $error = ['class' => get_class($caught), 'code' => $caught->getCode(), 'message' => $caught->getMessage()]; }
            $warningRows = warnings();
            $stored = $id !== null ? (array) DB::selectOne('SELECT HEX(source_sha256) AS bytes_hex, OCTET_LENGTH(source_sha256) AS byte_length, CHAR_LENGTH(source_sha256) AS character_length FROM site_images WHERE id = ?', [$id]) : null;
            emitDiagnostic('application_image_insert', ['case' => $case, 'input' => $evidence, 'error' => $error, 'warnings' => $warningRows, 'stored' => $stored]);
        }
    } finally { DB::rollBack(); }
} finally {
    foreach (array_reverse($created) as $table) { DB::unprepared("DROP TABLE {$table}"); }
}
emitDiagnostic('complete', ['scratch_tables_removed' => count($created), 'application_rows_rolled_back' => true]);
