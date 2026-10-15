<?php

namespace App\Domain\Grants\PaidTax;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use PDO;
use PDOException;
use ReflectionProperty;

/** Captured raw model storage only. Final proof invokes no Eloquent getter, offset or cast. */
final readonly class PaidTaxActor
{
    private function __construct(private User $actor, public int $id, private array $original, private array $attributes) {}

    public static function capture(User $actor): self
    {
        [$original, $attributes, $exists] = self::raw($actor);
        PaidTaxException::require($exists === true && is_int($original['id'] ?? null) && $original['id'] >= 1
            && ($attributes['id'] ?? null) === $original['id'], 403);

        return new self($actor, $original['id'], $original, $attributes);
    }

    public function prove(): void
    {
        [$original, $attributes, $exists] = self::raw($this->actor);
        PaidTaxException::require($exists === true && $original === $this->original && $attributes === $this->attributes, 403);
    }

    /** Serialize this buyer BEFORE dictionary reads/delegates can establish a repeatable-read snapshot. */
    public function lockOriginal(PaidTaxRuntime $runtime, PaidTaxOwnedFrame $frame): void
    {
        $runtime->prove(1);
        $frame->proveHeld();
        $this->prove();
        if ($runtime->driver !== 'mysql') {
            return;
        }
        PaidTaxException::require(preg_match('/\A[a-zA-Z0-9_]+\z/D', $runtime->database) === 1);
        $pdo = $runtime->primary;
        [$metadataWait, $rowWait] = $pdo->query('SELECT @@session.lock_wait_timeout, @@session.innodb_lock_wait_timeout')->fetch(PDO::FETCH_NUM);
        PaidTaxException::require(is_int($metadataWait) && $metadataWait >= 1 && is_int($rowWait) && $rowWait >= 1);
        try {
            $pdo->exec('SET SESSION lock_wait_timeout = '.min($metadataWait, 5));
            $pdo->exec('SET SESSION innodb_lock_wait_timeout = '.min($rowWait, 5));
            $table = '`'.$runtime->database.'`.`users`';
            // SHOW CREATE is current and opens no InnoDB read view; information_schema would do so.
            $shown = $pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM);
            PaidTaxException::require(is_array($shown) && is_string($shown[1] ?? null)
                && str_starts_with($shown[1], 'CREATE TABLE `users` (')
                && preg_match('/\n\) ENGINE=InnoDB(?: |$)/', $shown[1]) === 1);
            $query = $pdo->prepare('SELECT id FROM '.$table.' WHERE id = ? ORDER BY id LIMIT 2 FOR UPDATE');
            $query->execute([$this->id]);
            PaidTaxException::require($query->fetchAll(PDO::FETCH_COLUMN) === [$this->id], 403);
        } catch (PDOException $error) {
            if (in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213], true)) {
                throw new PaidTaxException;
            }
            throw $error;
        } finally {
            $pdo->exec('SET SESSION innodb_lock_wait_timeout = '.$rowWait);
            $pdo->exec('SET SESSION lock_wait_timeout = '.$metadataWait);
        }
        $runtime->prove(1);
        $frame->proveHeld();
        $this->prove();
        // This is serialization only. Full current buyer/schema authority is independently admitted afterward.
    }

    private static function raw(User $actor): array
    {
        PaidTaxException::require($actor::class === User::class, 403);
        $original = (new ReflectionProperty(Model::class, 'original'))->getValue($actor);
        $attributes = (new ReflectionProperty(Model::class, 'attributes'))->getValue($actor);
        $public = get_object_vars($actor);
        PaidTaxException::require(is_array($original) && is_array($attributes) && is_bool($public['exists'] ?? null), 403);
        foreach ([$original, $attributes] as $values) {
            foreach ($values as $value) {
                PaidTaxException::require($value === null || is_string($value) || is_int($value) || is_bool($value), 403);
            }
        }

        return [$original, $attributes, $public['exists']];
    }

    private function __clone() {}

    public function __serialize(): never
    {
        throw new \LogicException('Tax actor admission cannot be serialized.');
    }

    public function __unserialize(array $data): never
    {
        throw new \LogicException('Tax actor admission cannot be deserialized.');
    }
}
