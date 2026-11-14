<?php

namespace Runtime\AwardUpgrade;

use App\Domain\Customers\ProductionIdentity\IdentitySchema;
use App\Domain\Memberships\Billing\BillingSchema;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PDO;

/** Runtime receipts only. No capability or retained record is manufactured here. */
trait AwardUpgradeRuntime
{
    private function requirePrivateDatabase(string $root): void
    {
        $this->assertSame($root, base_path());
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(__DIR__.'/award.sqlite', DB::connection()->getDatabaseName());
        $this->assertSame('sqlite', DB::connection()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    private function rows(string $table): array
    {
        if (! preg_match('/\A[a-z_]+\z/D', $table)) {
            throw new \LogicException('Unexpected runtime snapshot table.');
        }

        return DB::connection()->getPdo()->query('SELECT * FROM "'.$table.'" ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function billingRows(): array
    {
        $rows = [];
        foreach (BillingSchema::TABLES as $table) {
            $rows[$table] = $this->rows($table);
        }

        return $rows;
    }

    private function identityRows(): array
    {
        $rows = [];
        foreach ([...array_keys(IdentitySchema::definitions()), 'users', 'customer_accounts'] as $table) {
            $rows[$table] = $this->rows($table);
        }

        return $rows;
    }

    private function configurationHashes(): array
    {
        return ['app_key_sha256' => hash('sha256', config('app.key')),
            'encrypter_key_sha256' => hash('sha256', Crypt::getKey()),
            'membership_configuration_sha256' => CanonicalJson::hash(config('production-memberships')),
            'billing_configuration_sha256' => CanonicalJson::hash(config('production-membership-billing')),
            'identity_configuration_sha256' => CanonicalJson::hash(config('production-customer-identity'))];
    }

    private function writeReceipt(string $name, array $value): void
    {
        file_put_contents(__DIR__.'/'.$name.'.json', $this->bytes($value));
        chmod(__DIR__.'/'.$name.'.json', 0600);
    }

    private function readReceipt(string $name): array
    {
        return json_decode(file_get_contents(__DIR__.'/'.$name.'.json'), true, 64, JSON_THROW_ON_ERROR);
    }

    private function bytes(array $value): string
    {
        return CanonicalJson::encode($value)."\n";
    }

    private function assertOriginalAwardRows(): void
    {
        $this->assertSame(file_get_contents(__DIR__.'/old-periods.json'), $this->bytes($this->rows(MembershipPeriodRecords::PERIODS)), 'Every original period value, ciphertext and seal is byte-identical.');
        $this->assertSame(file_get_contents(__DIR__.'/old-events.json'), $this->bytes($this->rows(MembershipPeriodRecords::EVENTS)), 'Every original event value and seal is byte-identical.');
        $this->assertCount(1, $this->rows(MembershipPeriodRecords::PERIODS));
        $this->assertCount(1, $this->rows(MembershipPeriodRecords::EVENTS));
    }

    private function assertOriginalIdentityRows(): void
    {
        $this->assertSame(file_get_contents(__DIR__.'/old-identity.json'), $this->bytes($this->identityRows()), 'Original retained identity and synthetic buyer/staff bytes remain unchanged.');
    }

    private function assertOriginalBillingRows(): void
    {
        foreach ($this->readReceipt('old-billing') as $table => $originalRows) {
            $current = [];
            foreach ($this->rows($table) as $row) {
                $current[(string) $row['id']] = $row;
            }
            foreach ($originalRows as $original) {
                $this->assertArrayHasKey((string) $original['id'], $current);
                $retained = $current[(string) $original['id']];
                $this->assertSame($this->bytes($original), $this->bytes(array_intersect_key($retained, $original)), 'All pre-upgrade billing columns retain their original bytes.');
                foreach (array_diff_key($retained, $original) as $added) {
                    $this->assertNull($added, 'Migration adds only NULL allocation evidence to legacy rows.');
                }
            }
        }
    }
}
