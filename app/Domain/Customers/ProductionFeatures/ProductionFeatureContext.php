<?php

namespace App\Domain\Customers\ProductionFeatures;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionFeatures\Models\ProductionFeatureBinding;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureAccess;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureIdentity;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Support\CanonicalJson;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Throwable;

/** Fixed captured-primary scopes; retained origin and current owner are separate proofs. */
final class ProductionFeatureContext
{
    private Connection $connection;

    private PDO $primary;

    private string $driver;

    private string $database;

    private string $sentinel;

    private array $scopes = [];

    private array $fences = [];

    private array $guards = [];

    private ?array $binding = null;

    private ?array $original = null;

    private ?IdentityCommittedFrame $committedFrame = null;

    private function __construct(
        public readonly ProductionAccountFeatureIdentity $identity,
        private readonly ProductionAccountFeatureAccess $access,
        private readonly CurrentRows $currentReader,
        private readonly array $currentAuthority,
        private readonly int $deadline,
        private readonly ProductionFeatureConfiguration $configuration,
        private readonly ProductionFeatureTransaction $transaction,
    ) {
        $this->connection = DB::connection();
        $this->primary = $currentReader->identityPrimary();
        $this->driver = $currentReader->identityDriver();
        $this->database = $this->connection->getDatabaseName();
        $this->sentinel = 'va_feature_'.bin2hex(random_bytes(16));
        $this->assertSource(false);
        $this->primary->exec('SAVEPOINT '.$this->sentinel);
        $this->schema();
        foreach ($identity->feature === 'listening_library' ? ['customer_saved_tracks'] : ['customer_consent_events', 'customer_consent_states'] as $legacy) {
            if ($this->observe($legacy, ['customer_account_id' => $this->accountId()]) !== []) {
                throw new ProductionFeatureException(409);
            }
        }
    }

    /** Only this factory can mint the held module context, after the exact typed identity floor. */
    public static function locked(ProductionAccountFeatureIdentity $identity, ProductionAccountFeatureAccess $access,
        CurrentRows $reader, ProductionFeatureTransaction $frame, int $deadline, ProductionFeatureConfiguration $configuration): self
    {
        $configuration->admit();
        ProductionFeatureConfiguration::plainPrimary($reader->identityPrimary());
        $authority = $access->lock($identity, $reader);
        $frame->admit();
        $configuration->admit();

        return new self($identity, $access, $reader, $authority, $deadline, $configuration, $frame);
    }

    /** Releasing an outer SQLite savepoint removes later child markers. Re-arm only after
     * the exact original outer marker proves that this is still the original physical transaction.
     */
    public function admitOuter(): void
    {
        $this->transaction->admit();
        $this->assertSource(false);
        $this->primary->exec('SAVEPOINT '.$this->sentinel);
    }

    public function admitConfiguration(): void
    {
        $this->configuration->admit();
        ProductionFeatureConfiguration::plainPrimary($this->primary);
    }

    /** Sealed entry proof plus the still-owned ordinary marker or committed read-only frame. */
    public function heldStorage(): array
    {
        if ($this->committedFrame !== null) {
            $this->committedFrame->assertActive();
            $this->assertSource(true);
        } else {
            $this->assertSource(false);
            $this->primary->exec('RELEASE SAVEPOINT '.$this->sentinel);
            $this->primary->exec('SAVEPOINT '.$this->sentinel);
        }

        return [$this->primary, $this->driver, $this->database];
    }

    public function configuration(): ProductionFeatureConfiguration
    {
        return $this->configuration;
    }

    public function accountId(): int
    {
        return $this->identity->principal()->accountId;
    }

    public function reader(): CurrentRows
    {
        return $this->currentReader;
    }

    public function authority(): array
    {
        return $this->currentAuthority;
    }

    public function binding(): ?array
    {
        $rows = $this->observe('production_account_feature_bindings', ['account_id' => $this->accountId(), 'feature' => $this->identity->feature]);
        if ($rows === []) {
            return null;
        }
        if (count($rows) !== 1) {
            throw new ProductionFeatureException;
        }
        $row = $rows[0];
        try {
            $binding = json_decode(Crypt::decryptString($row['binding_ciphertext']), true, 16, JSON_THROW_ON_ERROR);
            $this->admitConfiguration();
            if (! is_array($binding) || strlen($row['binding_ciphertext']) > 8192
                || ! hash_equals($row['binding_hash'], CanonicalJson::hash($binding))
                || $row['origin_public_id'] !== ($binding['buyer_binding']['origin_id'] ?? null)
                || (int) $row['feature_schema_version'] !== 1 || $row['feature_policy_version'] !== $this->identity->featureVersion
                || ! Str::isUuid($row['public_id']) || ! ProductionFeatureShape::timestamp($row['created_at'])) {
                throw new ProductionFeatureException;
            }
            $this->original = $this->access->verifyOriginalBinding($this->identity, $binding, $this->currentReader);
            if ($row['created_at'] < $this->original['origin']['created_at']) {
                throw new ProductionFeatureException;
            }
        } catch (Throwable $error) {
            throw new ProductionFeatureException;
        }
        $this->binding = ['row' => $row, 'binding' => $binding];

        return $this->binding;
    }

    public function createBinding(string $at): array
    {
        if ($this->binding() !== null) {
            throw new ProductionFeatureException(409);
        }
        $binding = $this->access->durableBinding($this->identity);
        $attributes = ['public_id' => (string) Str::uuid(), 'account_id' => $this->accountId(),
            'origin_public_id' => $binding['buyer_binding']['origin_id'], 'feature' => $this->identity->feature,
            'feature_schema_version' => 1, 'feature_policy_version' => $this->identity->featureVersion,
            'binding_hash' => CanonicalJson::hash($binding), 'binding_ciphertext' => Crypt::encryptString(CanonicalJson::encode($binding)), 'created_at' => $at];
        if (strlen($attributes['binding_ciphertext']) > 8192) {
            throw new ProductionFeatureException;
        }
        $model = ProductionFeatureBinding::create($attributes);
        $this->expected($model->getRawOriginal(), $attributes);
        $result = $this->binding();
        if ($result === null || (int) $result['row']['id'] !== (int) $model->id) {
            throw new ProductionFeatureException;
        }

        return $result;
    }

    /** Snapshot a bounded module scope. Use again only after an intended, independently checked write. */
    public function observe(string $table, array $where, int $limit = 2, bool $descending = false): array
    {
        $rows = $this->rows($table, $where, $limit, $descending, false);
        $key = CanonicalJson::encode([$table, $where, $limit, $descending]);
        $this->scopes[$key] = [$table, $where, $limit, $descending, $rows];

        return $rows;
    }

    /** Execute callbackful catalog/storage/clock checks before the final raw row and identity closure. */
    public function fence(callable $check): void
    {
        $this->fences[] = $check;
    }

    /** Callback-free configuration/public closure after extensible source resolution. */
    public function guard(callable $check): void
    {
        $this->guards[] = $check;
    }

    public function expected(array $raw, array $attributes): void
    {
        // A Saved hook must not move later module writes into a foreign physical transaction.
        $this->assertSource(false);
        try {
            $this->primary->exec('RELEASE SAVEPOINT '.$this->sentinel);
            $this->primary->exec('SAVEPOINT '.$this->sentinel);
        } catch (Throwable) {
            throw new ProductionFeatureException;
        }
        foreach ($attributes as $key => $value) {
            if (! array_key_exists($key, $raw) || ($raw[$key] === null ? null : (string) $raw[$key]) !== ($value === null ? null : (string) $value)) {
                throw new ProductionFeatureException;
            }
        }
    }

    public function proveCurrent(): void
    {
        $this->checks(false);
        $this->admitConfiguration();
        if ($this->binding !== null) {
            $this->access->proveOriginalBindingCurrent($this->identity, $this->binding['binding'], $this->currentReader, $this->original);
        }
        $this->assertSource(false);
        $this->primary->exec('RELEASE SAVEPOINT '.$this->sentinel);
        $this->admitConfiguration();
        $this->access->proveCurrent($this->identity, $this->currentReader, $this->currentAuthority);
        $this->assertSource(false);
    }

    public function proveCommitted(IdentityCommittedFrame $frame): void
    {
        $frame->assertActive();
        if ($frame->reader() !== $this->currentReader) {
            throw new ProductionFeatureException;
        }
        $this->committedFrame = $frame;
        $this->checks(true);
        $this->admitConfiguration();
        if ($this->binding !== null) {
            // This terminal seam verifies the original signed history and compares the captured
            // raw evidence itself. A preceding identical verification only repeats the same proof.
            $this->access->proveOriginalBindingCommitted($this->identity, $this->binding['binding'], $frame, $this->original);
        }
        $this->assertSource(true);
    }

    private function checks(bool $committed): void
    {
        $this->assertSource($committed);
        foreach ($this->fences as $fence) {
            $fence($committed);
        }
        $this->resolveSources();
        $this->admitConfiguration();
        foreach ($this->guards as $guard) {
            $guard($committed);
        }
        $this->schema();
        foreach ($this->scopes as [$table, $where, $limit, $descending, $expected]) {
            if ($this->rows($table, $where, $limit, $descending, $committed) !== $expected) {
                throw new ProductionFeatureException;
            }
        }
        $this->assertSource($committed);
    }

    private function schema(): void
    {
        (new ProductionFeatureSchema)->assertHeld($this);
    }

    private function resolveSources(): void
    {
        $previous = null;
        // A lazy secondary resolver may register/replace another connection. Resolve to stability
        // before any final pure module guard; never authorize a fresh source or transaction.
        for ($pass = 0; $pass < 8; $pass++) {
            $source = [];
            foreach (DB::getConnections() as $name => $connection) {
                $pdo = $connection->getPdo();
                ProductionFeatureConfiguration::plainPrimary($pdo);
                if ($connection !== $this->connection && ($connection->transactionLevel() !== 0 || $pdo->inTransaction())) {
                    throw new ProductionFeatureException;
                }
                $source[$name] = [spl_object_id($connection), spl_object_id($pdo)];
            }
            if ($source === $previous && array_keys($source) === array_keys(DB::getConnections())) {
                return;
            }
            $previous = $source;
        }
        throw new ProductionFeatureException;
    }

    private function rows(string $table, array $where, int $limit, bool $descending, bool $committed): array
    {
        $columns = match ($table) {
            'production_account_feature_bindings' => ['id', 'account_id', 'feature', 'origin_public_id'],
            'production_listening_libraries' => ['id', 'binding_id'],
            'production_consent_policies' => ['id', 'purpose', 'version'],
            'production_consent_events' => ['id', 'binding_id', 'purpose'],
            'production_consent_states' => ['id', 'binding_id', 'purpose'],
            'customer_saved_tracks', 'customer_consent_events', 'customer_consent_states' => ['customer_account_id'],
            default => throw new ProductionFeatureException,
        };
        if ($where === [] || $limit < 1 || $limit > 2 || array_diff(array_keys($where), $columns) !== []) {
            throw new ProductionFeatureException;
        }
        $qualified = $this->driver === 'sqlite' ? 'main."'.$table.'"' : '`'.str_replace('`', '``', $this->database).'`.`'.$table.'`';
        $condition = implode(' AND ', array_map(fn ($column) => '`'.$column.'`=?', array_keys($where)));
        $sql = 'SELECT * FROM '.$qualified.' WHERE '.$condition.' ORDER BY id '.($descending ? 'DESC' : 'ASC').' LIMIT '.($descending ? $limit : $limit + 1).($this->driver === 'mysql' && ! $committed ? ' FOR UPDATE' : '');
        $statement = $this->primary->prepare($sql);
        $statement->execute(array_values($where));
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > $limit) {
            throw new ProductionFeatureException;
        }

        return $rows;
    }

    private function assertSource(bool $committed): void
    {
        $this->admitConfiguration();
        if (DB::connection() !== $this->connection || $this->connection->getRawPdo() !== $this->primary
            || $this->connection->getTablePrefix() !== '' || $this->connection->getDatabaseName() !== $this->database
            || $this->connection->transactionLevel() !== ($committed ? 0 : 1) || ! $this->primary->inTransaction()
            || hrtime(true) >= $this->deadline || ($this->driver === 'mysql' && $this->primary->query('SELECT DATABASE()')->fetchColumn() !== $this->database)) {
            throw new ProductionFeatureException;
        }
    }
}
