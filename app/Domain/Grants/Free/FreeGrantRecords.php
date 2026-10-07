<?php

namespace App\Domain\Grants\Free;

use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;

final class FreeGrantRecords
{
    public static function encode(array $payload): array
    {
        $plain = CanonicalJson::encode($payload);
        FreeGrantException::require(strlen($plain) <= 4194304, 422);

        return ['payload' => Crypt::encryptString($plain), 'payload_hash' => hash('sha256', $plain)];
    }

    public static function decode(array $row): array
    {
        FreeGrantException::require(is_string($row['payload'] ?? null) && strlen($row['payload']) <= 8388608);
        $plain = Crypt::decryptString($row['payload']);
        FreeGrantException::require(strlen($plain) <= 4194304 && hash_equals((string) $row['payload_hash'], hash('sha256', $plain)));
        $payload = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
        FreeGrantException::require(is_array($payload) && ! array_is_list($payload) && CanonicalJson::encode($payload) === $plain);

        return $payload;
    }

    public static function insert(string $table, array $values, FreeGrantRows $rows): array
    {
        $columns = array_keys($values);
        $rows->execute('INSERT INTO '.$rows->table($table).' ('.implode(', ', $columns).') VALUES ('.implode(', ', array_fill(0, count($values), '?')).')', array_values($values));

        return $rows->one($table, 'id = ?', [(int) $rows->identity()->lastInsertId()]);
    }
}
