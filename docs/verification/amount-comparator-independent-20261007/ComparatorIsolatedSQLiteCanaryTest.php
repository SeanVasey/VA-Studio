<?php
namespace Tests\Feature;
use App\Domain\Commerce\ProductionPreparation\AmountRequirementsV1;
use App\Domain\Commerce\ProductionPreparation\ProductionAmountRequirements;
use App\Domain\Commerce\ProductionPreparation\CompareProductionAmountInputs;
use App\Domain\Commerce\ProductionPreparation\ProductionBuyerAssentObservations as Reports;
use App\Domain\Commerce\ProductionPreparation\ProductionTrackPreparationPackets;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionTrackPreparationFixtures as Fixture;
use Tests\TestCase;

final class ComparatorIsolatedSQLiteCanaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    private string $selectedDefault;
    private array $selectedConnections;
    protected function beforeRefreshingDatabase(): void
    {
        $this->selectedDefault = DB::getDefaultConnection();
        $this->selectedConnections = config('database.connections');
        config(['database.connections.amount_independent_sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true, 'url' => null,
        ]]);
        DB::setDefaultConnection('amount_independent_sqlite');
    }
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        // Registered after the disposable wipe: never redirect that wipe to native DB.
        $this->beforeApplicationDestroyed(function (): void {
            DB::purge('amount_independent_sqlite');
            config(['database.connections' => $this->selectedConnections]);
            DB::setDefaultConnection($this->selectedDefault);
        });
        config(['app.key'=>'base64:'.base64_encode(str_repeat('k',32))]);
        $this->fakePrivateMediaStorage();
        Http::preventStrayRequests();
    }
    private function supplied(array $r): array
    {
        return (new \ReflectionMethod(ProductionAmountInputConsistencyAccessTest::class, 'supplied'))->invoke(new ProductionAmountInputConsistencyAccessTest('test_amount_comparison_does_not_depend_on_buyer_report_storage'), $r);
    }
    private function snapshot(\PDO $pdo): array
    {
        $schema=$pdo->query('SELECT type,name,tbl_name,sql FROM sqlite_master ORDER BY type,name')->fetchAll(\PDO::FETCH_ASSOC);
        $rows=[];
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $values=array_map(CanonicalJson::encode(...),$pdo->query('SELECT * FROM "'.str_replace('"','""',$table).'"')->fetchAll(\PDO::FETCH_ASSOC));
            sort($values,SORT_STRING);$rows[$table]=$values;
        }
        return ['schema'=>$schema,'rows'=>$rows];
    }
    public function test_unavailable_buyer_report_storage_cannot_change_amount_requirements_or_produce_effects(): void
    {
        $f=Fixture::prepared(true);
        $r=app(ProductionAmountRequirements::class)->forPacket($f['packet']->public_id,$f['actor']);
        $input=$this->supplied($r);
        $service=app(CompareProductionAmountInputs::class);
        $expected=$service->forPacket($f['packet']->public_id,$input,$f['actor']);
        $reports=app(Reports::class);
        $reports->retain($reports->review($f['packet']->public_id,$f['actor']),['buyer'=>['legal_name'=>'Private Report Marker','email'=>'private-marker@example.test'],'reported_accepted'=>true,'observation_reference'=>'private-reference'],'private-report',$f['actor']);
        // Dispose only this synthetic test table: any hidden raw/Laravel D27 read would now fail.
        DB::unprepared('DROP TABLE '.Reports::TABLE);
        $pdo=DB::connection()->getPdo();$before=$this->snapshot($pdo);
        $pdo->exec('PRAGMA query_only=ON');
        try { $actual=$service->forPacket($f['packet']->public_id,$input,$f['actor']); }
        finally { $pdo->exec('PRAGMA query_only=OFF'); }
        $this->assertSame($expected,$actual);
        $this->assertSame($before,$this->snapshot($pdo));
        $this->assertStringNotContainsString('Private Report Marker',CanonicalJson::encode($actual));
        $this->assertStringNotContainsString('private-marker@example.test',CanonicalJson::encode($actual));
        $this->assertNull($actual['tax_minor']);$this->assertNull($actual['total_minor']);
        Http::assertNothingSent();
    }
    public function test_late_primary_audit_drift_cannot_be_hidden_by_default_connection_swap(): void
    {
        $f=Fixture::prepared(true);$input=$this->supplied(app(ProductionAmountRequirements::class)->forPacket($f['packet']->public_id,$f['actor']));$name=DB::getDefaultConnection();$primary=DB::connection()->getPdo();
        config(['database.connections.amount_decoy'=>config('database.connections.'.$name)]);
        $decoy=DB::connection('amount_decoy');$decoyPdo=$decoy->getPdo();
        $decoyPdo->exec($primary->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn());
        foreach ($primary->query('SELECT * FROM users')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $columns=implode(',',array_map(fn($key)=>'"'.$key.'"',array_keys($row)));
            $statement=$decoyPdo->prepare('INSERT INTO users ('.$columns.') VALUES ('.implode(',',array_fill(0,count($row),'?')).')');
            $statement->execute(array_values($row));
        }
        // Mirror valid current authority and its transaction fence, so refusal must
        // come from captured-primary evidence rather than an empty decoy user table.
        $decoy->beginTransaction();
        $before=$this->snapshot($primary);$armed=false;$mutated=false;$original=Crypt::getFacadeRoot();
        $encrypter=Mockery::mock($original)->makePartial();
        $encrypter->shouldReceive('decryptString')->andReturnUsing(function(string $ciphertext) use($original,$f,&$armed):string { $value=$original->decryptString($ciphertext);if($ciphertext===$f['packet']->payload_ciphertext){$armed=true;}return $value; });
        Crypt::swap($encrypter);
        DB::listen(function($query) use($primary,&$armed,&$mutated):void {
            if(!$armed || !str_contains($query->sql,'from "users"')){return;}
            $armed=false;$mutated=true;
            $primary->exec("UPDATE audit_events SET action='private.decoy_audit' WHERE action='commerce.production_preparation.packet_retained'");
            DB::setDefaultConnection('amount_decoy');
        });
        try {
            app(CompareProductionAmountInputs::class)->forPacket($f['packet']->public_id,$input,$f['actor']);
            $this->fail('Late primary audit drift was accepted.');
        } catch(ValidationException) {
            $this->assertTrue($mutated);
        } finally {
            $armed=false;DB::setDefaultConnection($name);Crypt::swap($original);$decoy->rollBack();DB::purge('amount_decoy');
        }
        $this->assertSame($before,$this->snapshot($primary));
        $this->assertSame(0,DB::connection()->transactionLevel());
        Http::assertNothingSent();
    }
}
