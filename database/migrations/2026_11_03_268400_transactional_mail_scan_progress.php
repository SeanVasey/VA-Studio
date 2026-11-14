<?php

use App\Domain\Notifications\Mail\TransactionalMailScanSchema as Progress;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $connection = DB::connection();
        if ($connection->getTablePrefix() !== '' || $connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
            throw new LogicException('Mail scan installation requires its idle unprefixed connection.');
        }
        $pdo = $connection->getPdo();
        $driver = $connection->getDriverName();
        Progress::assertAbsent($pdo, $driver);
        Progress::assertDependencies($pdo, $driver);
        $pdo->exec(Progress::definition($driver));
        foreach (Progress::guards($driver) as $sql) {
            $pdo->exec($sql);
        }
        $insert = $pdo->prepare('INSERT INTO '.Progress::TABLE.' (scan_key, cycle, after_id, high_water_id, version) VALUES (?, 0, 0, 0, 0)');
        foreach (Progress::PURPOSES as $purpose) {
            $insert->execute([$purpose]);
        }
        Progress::assertOwned($pdo, $driver);
    }

    public function down(): void
    {
        throw new LogicException('Retain durable mail scan progress; rollback is unavailable.');
    }
};
