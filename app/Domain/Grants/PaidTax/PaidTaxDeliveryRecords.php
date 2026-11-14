<?php

namespace App\Domain\Grants\PaidTax;

/** Inserts only the separately owned delivery schema, using the original admitted consumer rows. */
final class PaidTaxDeliveryRecords
{
    public static function insert(string $table, array $values, PaidTaxRows $rows): array
    {
        $rows->deliveryInputs();
        $specs = PaidTaxDeliverySchema::specs();
        PaidTaxException::require(isset($specs[$table]) && array_keys($values) === array_keys($specs[$table]['columns']));
        $columns = array_keys($values);
        $id = $rows->execute('INSERT INTO '.$rows->table($table).' ('.implode(', ', $columns).') VALUES ('
            .implode(', ', array_fill(0, count($values), '?')).')', array_values($values));
        PaidTaxException::require($id >= 1);

        return $rows->one($table, 'id = ?', [$id]);
    }
}
