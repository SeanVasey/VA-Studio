<?php
require '/workspace/VA-Studio-member-original-facts/vendor/autoload.php';
$old = file_get_contents(__DIR__.'/MemberGrantSchema.before.php');
$old = str_replace('final class MemberGrantSchema', 'final class MemberGrantSchemaBefore', $old);
file_put_contents(__DIR__.'/MemberGrantSchemaBefore.php', $old);
require __DIR__.'/MemberGrantSchemaBefore.php';
use App\Domain\Grants\Member\MemberGrantSchema;
use App\Domain\Grants\Member\MemberGrantSchemaBefore;
use Illuminate\Support\Facades\DB;
foreach (['sqlite', 'mysql'] as $driver) {
    foreach (['', 'unit_'] as $prefix) {
        DB::swap(new class($driver, $prefix) {
            public function __construct(private string $driver, private string $prefix) {}
            public function connection(): self { return $this; }
            public function getDriverName(): string { return $this->driver; }
            public function getTablePrefix(): string { return $this->prefix; }
        });
        $old = new MemberGrantSchemaBefore;
        $tables = [];
        foreach (MemberGrantSchemaBefore::TABLES as $logical) {
            $name = $prefix.$logical;
            $installed = $old->table($logical);
            $definition = (new ReflectionMethod($old, 'definition'))->invoke($old, $logical, $driver);
            $tables[$logical] = ['name' => $name, 'table' => '`'.($driver === 'sqlite' ? 'main' : 'synthetic').'`.`'.$name.'`',
                'create' => 'CREATE TABLE '.$installed.' ('.$definition['sql'].')'.($driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : ''),
                'definition' => $definition, 'guards' => (new ReflectionMethod($old, 'guards'))->invoke($old, $logical, $driver, $name, $installed)];
        }
        $plan = ['driver' => $driver, 'schema' => $driver === 'sqlite' ? 'main' : 'synthetic', 'prefix' => $prefix, 'tables' => $tables];
        if ($plan !== MemberGrantSchema::expectedPlan($driver, $plan['schema'], $prefix)) { throw new RuntimeException('Changed installed plan'); }
        echo $driver, ' ', json_encode($prefix), ' ', hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR)), PHP_EOL;
    }
}
