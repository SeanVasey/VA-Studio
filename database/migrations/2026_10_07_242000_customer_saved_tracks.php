<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Saved listening storage requires a supported database.');
        }
        $this->refuseShadow();
        if (Schema::hasTable('customer_saved_tracks')) {
            throw new LogicException('Existing saved listening storage requires inspection.');
        }
        Schema::create('customer_saved_tracks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_account_id')->unique()->constrained('customer_accounts')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->text('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $this->refuseShadow();
        if (! Schema::hasTable('customer_saved_tracks')) {
            return;
        }
        if (DB::table('customer_saved_tracks')->exists()
            || Schema::getColumnListing('customer_saved_tracks') !== ['id', 'customer_account_id', 'version', 'payload', 'created_at', 'updated_at']) {
            throw new LogicException('Retained or unexpected saved listening storage prevents rollback.');
        }
        Schema::drop('customer_saved_tracks');
    }

    private function refuseShadow(): void
    {
        if (DB::getDriverName() === 'sqlite'
            && DB::table('sqlite_temp_master')->whereRaw('lower(name) = ?', ['customer_saved_tracks'])->exists()) {
            throw new LogicException('Temporary saved listening storage requires inspection.');
        }
        if (DB::getDriverName() === 'mysql') {
            // SHOW CREATE TABLE resolves a temporary table before its permanent namesake.
            try {
                $row = (array) DB::selectOne('SHOW CREATE TABLE `customer_saved_tracks`');
                if (str_contains(strtoupper((string) ($row['Create Table'] ?? '')), 'CREATE TEMPORARY TABLE')) {
                    throw new LogicException('Temporary saved listening storage requires inspection.');
                }
            } catch (QueryException $error) {
                if (($error->errorInfo[0] ?? null) !== '42S02' || ($error->errorInfo[1] ?? null) !== 1146) {
                    throw $error;
                }
            }
        }
    }
};
