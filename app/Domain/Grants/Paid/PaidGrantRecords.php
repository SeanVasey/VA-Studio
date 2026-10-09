<?php

namespace App\Domain\Grants\Paid;

use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;

final class PaidGrantRecords
{
    public static function encode(array $payload): array
    {
        $plain = CanonicalJson::encode($payload);
        PaidGrantException::require(strlen($plain) <= 4194304, 422);
        $ciphertext = Crypt::encryptString($plain);
        PaidGrantException::require(strlen($ciphertext) <= 4194304, 422);

        return ['payload' => $ciphertext, 'payload_hash' => hash('sha256', $plain)];
    }

    public static function decode(array $row): array
    {
        PaidGrantException::require(is_string($row['payload'] ?? null) && strlen($row['payload']) <= 4194304);
        $plain = Crypt::decryptString($row['payload']);
        PaidGrantException::require(strlen($plain) <= 4194304 && hash_equals((string) $row['payload_hash'], hash('sha256', $plain)));
        $payload = json_decode($plain, true, 64, JSON_THROW_ON_ERROR);
        PaidGrantException::require(is_array($payload) && ! array_is_list($payload) && CanonicalJson::encode($payload) === $plain);

        return $payload;
    }

    public static function insert(string $table, array $values, PaidGrantRows $rows): array
    {
        PaidGrantException::require(isset(PaidGrantSchema::specs()[$table]) && array_keys($values) === array_keys(PaidGrantSchema::specs()[$table]['columns']));
        $columns = array_keys($values);
        $rows->execute('INSERT INTO '.$rows->table($table).' ('.implode(', ', $columns).') VALUES ('.implode(', ', array_fill(0, count($values), '?')).')', array_values($values));

        return $rows->one($table, 'id = ?', [(int) $rows->identity()->lastInsertId()]);
    }
}
