<?php

namespace App\Domain\Customers\ProductionIdentity;

use App\Domain\Catalog\Discovery\DiscoveryEpoch;
use LogicException;

/** Internal finite plans. All schema, cells, names and SQL come from this source. */
final class CatalogGroupPlan
{
    private const REGISTRY = [
        'A0_ACCOUNT_TABLE' => [
            [
                'enum' => 'Q3C5185ED551F',
                'columns' => [
                    ['key' => 'TABLE_NAME', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'TABLE_TYPE', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'ENGINE', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'TABLE_COLLATION', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'TABLE_COMMENT', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'CREATE_OPTIONS', 'php_kind' => 'string', 'nullable' => true],
                ],
                'order' => null,
            ],
        ],
        'A1_ACCOUNT_AFTER_SHOW' => [
            [
                'enum' => 'AccountTables',
                'columns' => [
                    ['key' => 'TABLE_NAME', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => null,
            ],
            [
                'enum' => 'AccountRoutineNames',
                'columns' => [
                    ['key' => 'ROUTINE_NAME', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => null,
            ],
            [
                'enum' => 'AccountEventNames',
                'columns' => [
                    ['key' => 'EVENT_NAME', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => null,
            ],
            [
                'enum' => 'AccountIndexNames',
                'columns' => [
                    ['key' => 'INDEX_NAME', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => null,
            ],
            [
                'enum' => 'AccountConstraintNames',
                'columns' => [
                    ['key' => 'CONSTRAINT_NAME', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => null,
            ],
            [
                'enum' => 'Q01CED6344452',
                'columns' => [
                    ['key' => 'TRIGGER_NAME', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'EVENT_OBJECT_TABLE', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'EVENT_MANIPULATION', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'ACTION_TIMING', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'ACTION_STATEMENT', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'ACTION_ORDER', 'php_kind' => 'int', 'nullable' => false],
                    ['key' => 'SQL_MODE', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'CHARACTER_SET_CLIENT', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'COLLATION_CONNECTION', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'DATABASE_COLLATION', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'DEFINER', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => 'TRIGGER_NAME',
            ],
            [
                'enum' => 'Q54EFEC87F80D',
                'columns' => [
                    ['key' => 'INDEX_NAME', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'COLUMN_NAME', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'NON_UNIQUE', 'php_kind' => 'int', 'nullable' => false],
                    ['key' => 'SEQ_IN_INDEX', 'php_kind' => 'int', 'nullable' => false],
                    ['key' => 'INDEX_TYPE', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'SUB_PART', 'php_kind' => 'int', 'nullable' => true],
                    ['key' => 'COLLATION', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'EXPRESSION', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'IS_VISIBLE', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'INDEX_COMMENT', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => 'INDEX_NAME,SEQ_IN_INDEX',
            ],
        ],
        'A2_ACCOUNT_FLOOR' => [
            [
                'enum' => 'QD9E74E9B8F9D',
                'columns' => [
                    ['key' => 'COLUMN_NAME', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'COLUMN_TYPE', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'IS_NULLABLE', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'COLUMN_DEFAULT', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'EXTRA', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'CHARACTER_SET_NAME', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'COLLATION_NAME', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'COLUMN_COMMENT', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'GENERATION_EXPRESSION', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => 'ORDINAL_POSITION',
            ],
            [
                'enum' => 'Q3A0D56156905',
                'columns' => [
                    ['key' => 'CONSTRAINT_NAME', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'COLUMN_NAME', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'REFERENCED_TABLE_SCHEMA', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'REFERENCED_TABLE_NAME', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'REFERENCED_COLUMN_NAME', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'UPDATE_RULE', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'DELETE_RULE', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => 'k.COLUMN_NAME',
            ],
            [
                'enum' => 'QB894B1A4D034',
                'columns' => [
                    ['key' => 'count', 'php_kind' => 'int', 'nullable' => false],
                ],
                'order' => null,
            ],
        ],
        'P0_PARENT_NAMESPACE' => [
            [
                'enum' => 'QA8762690E619',
                'columns' => [
                    ['key' => 'name', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'type', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'tbl_name', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => null,
            ],
            [
                'enum' => 'Q38A05D78DB1E',
                'columns' => [
                    ['key' => 'name', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'type', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'tbl_name', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => null,
            ],
            [
                'enum' => 'RoutineName',
                'columns' => [
                    ['key' => 'ROUTINE_NAME', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => null,
            ],
            [
                'enum' => 'EventName',
                'columns' => [
                    ['key' => 'EVENT_NAME', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => null,
            ],
            [
                'enum' => 'IndexName',
                'columns' => [
                    ['key' => 'INDEX_NAME', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => null,
            ],
            [
                'enum' => 'ConstraintName',
                'columns' => [
                    ['key' => 'CONSTRAINT_NAME', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => null,
            ],
        ],
        'P1_PARENT_SHAPE' => [
            [
                'enum' => 'Q9D29C0ADA7EE',
                'columns' => [
                    ['key' => 'ENGINE', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'TABLE_TYPE', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'TABLE_COMMENT', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'TABLE_COLLATION', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'CREATE_OPTIONS', 'php_kind' => 'string', 'nullable' => true],
                ],
                'order' => null,
            ],
            [
                'enum' => 'QD9E74E9B8F9D',
                'columns' => [
                    ['key' => 'COLUMN_NAME', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'COLUMN_TYPE', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'IS_NULLABLE', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'COLUMN_DEFAULT', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'EXTRA', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'CHARACTER_SET_NAME', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'COLLATION_NAME', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'COLUMN_COMMENT', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'GENERATION_EXPRESSION', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => 'ORDINAL_POSITION',
            ],
            [
                'enum' => 'Q54EFEC87F80D',
                'columns' => [
                    ['key' => 'INDEX_NAME', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'COLUMN_NAME', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'NON_UNIQUE', 'php_kind' => 'int', 'nullable' => false],
                    ['key' => 'SEQ_IN_INDEX', 'php_kind' => 'int', 'nullable' => false],
                    ['key' => 'INDEX_TYPE', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'SUB_PART', 'php_kind' => 'int', 'nullable' => true],
                    ['key' => 'COLLATION', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'EXPRESSION', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'IS_VISIBLE', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'INDEX_COMMENT', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => 'INDEX_NAME,SEQ_IN_INDEX',
            ],
            [
                'enum' => 'QD29EA5375536',
                'columns' => [
                    ['key' => 'CONSTRAINT_NAME', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'COLUMN_NAME', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'REFERENCED_TABLE_SCHEMA', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'REFERENCED_TABLE_NAME', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'REFERENCED_COLUMN_NAME', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'UPDATE_RULE', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'DELETE_RULE', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => 'k.COLUMN_NAME',
            ],
            [
                'enum' => 'Q357FD211BAA0',
                'columns' => [
                    ['key' => 'CONSTRAINT_NAME', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => null,
            ],
        ],
        'P2_PARENT_GUARDS' => [
            [
                'enum' => 'Q95556A0350AB',
                'columns' => [
                    ['key' => 'EVENT_OBJECT_TABLE', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'EVENT_MANIPULATION', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'ACTION_TIMING', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'ACTION_STATEMENT', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => null,
            ],
            [
                'enum' => 'QB2684ECFE856',
                'columns' => [
                    ['key' => 'name', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => 'TRIGGER_NAME',
            ],
        ],
        'I0_IDENTITY_NAMESPACE' => [
            [
                'enum' => 'QEDBF704DA857',
                'columns' => [
                    ['key' => 'TABLE_NAME', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'TABLE_TYPE', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'ENGINE', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'CREATE_OPTIONS', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'TABLE_COMMENT', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'TABLE_COLLATION', 'php_kind' => 'string', 'nullable' => true],
                ],
                'order' => null,
            ],
            [
                'enum' => 'QDE6EB7150A9B',
                'columns' => [
                    ['key' => 'TRIGGER_NAME', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'EVENT_OBJECT_TABLE', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'EVENT_MANIPULATION', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'ACTION_TIMING', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'ACTION_STATEMENT', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => null,
            ],
        ],
        'I1_IDENTITY_SHAPE' => [
            [
                'enum' => 'Q88ED11D61B97',
                'columns' => [
                    ['key' => 'COLUMN_NAME', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'COLUMN_TYPE', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'IS_NULLABLE', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'COLUMN_DEFAULT', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'COLUMN_COMMENT', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'GENERATION_EXPRESSION', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'EXTRA', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'CHARACTER_SET_NAME', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'COLLATION_NAME', 'php_kind' => 'string', 'nullable' => true],
                ],
                'order' => 'ORDINAL_POSITION',
            ],
            [
                'enum' => 'Q603824C0D074',
                'columns' => [
                    ['key' => 'INDEX_NAME', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'COLUMN_NAME', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'NON_UNIQUE', 'php_kind' => 'int', 'nullable' => false],
                    ['key' => 'SUB_PART', 'php_kind' => 'int', 'nullable' => true],
                    ['key' => 'EXPRESSION', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'INDEX_TYPE', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'COLLATION', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'IS_VISIBLE', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'INDEX_COMMENT', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => 'INDEX_NAME,SEQ_IN_INDEX',
            ],
            [
                'enum' => 'Q26706FA791A1',
                'columns' => [
                    ['key' => 'CONSTRAINT_NAME', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'COLUMN_NAME', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'REFERENCED_TABLE_SCHEMA', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'REFERENCED_TABLE_NAME', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'REFERENCED_COLUMN_NAME', 'php_kind' => 'string', 'nullable' => true],
                    ['key' => 'ORDINAL_POSITION', 'php_kind' => 'int', 'nullable' => false],
                    ['key' => 'UPDATE_RULE', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'DELETE_RULE', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => null,
            ],
            [
                'enum' => 'QD3A32C45A22D',
                'columns' => [
                    ['key' => 'CONSTRAINT_NAME', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => null,
            ],
        ],
        'I2_RESERVED_NAMES' => [
            [
                'enum' => 'QECFE2D2F4FCD',
                'columns' => [
                    ['key' => 'TABLE_NAME', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'INDEX_NAME', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => null,
            ],
            [
                'enum' => 'Q6546FD85F02A',
                'columns' => [
                    ['key' => 'TABLE_NAME', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'CONSTRAINT_NAME', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => null,
            ],
        ],
        'D0_FULL_DEPENDENTS' => [
            [
                'enum' => 'Q7BC108FBFFA5',
                'columns' => [
                    ['key' => 'TRIGGER_SCHEMA', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'TRIGGER_NAME', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'EVENT_OBJECT_TABLE', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'ACTION_STATEMENT', 'php_kind' => 'string', 'nullable' => false],
                ],
                'order' => null,
            ],
            [
                'enum' => 'Q037BE6D6C820',
                'columns' => [
                    ['key' => 'TABLE_SCHEMA', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'TABLE_NAME', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'REFERENCED_TABLE_NAME', 'php_kind' => 'string', 'nullable' => true],
                ],
                'order' => null,
            ],
            [
                'enum' => 'GlobalViews',
                'columns' => [
                    ['key' => 'TABLE_SCHEMA', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'VIEW_DEFINITION', 'php_kind' => 'string', 'nullable' => true],
                ],
                'order' => null,
            ],
            [
                'enum' => 'GlobalRoutines',
                'columns' => [
                    ['key' => 'ROUTINE_SCHEMA', 'php_kind' => 'string', 'nullable' => false],
                    ['key' => 'ROUTINE_DEFINITION', 'php_kind' => 'string', 'nullable' => true],
                ],
                'order' => null,
            ],
        ],
    ];

    public static function build(CatalogGroup $group, string $version): array
    {
        if (! in_array($version, [AccountAdmissionGuards::V1, AccountAdmissionGuards::V2, AccountAdmissionGuards::OVERLAP], true)) {
            throw new LogicException('Closed catalog group required.');
        }
        $families = self::REGISTRY[$group->value];
        $ctes = [];
        $branches = [];
        $bindings = [];
        foreach ($families as $index => &$family) {
            $query = constant(CatalogRead::class.'::'.$family['enum']);
            [$names, $direct] = self::recipe($group, $query, $version);
            if ($direct !== null) {
                $plan = ['sql' => $query->value, 'bindings' => $direct, 'names' => ['']];
                $slot = '0';
                $orders = $family['order'] === null ? [] : explode(',', $family['order']);
                $hidden = implode('', array_map(fn (int $n, string $key): string => ', '.$key.' AS catalog_order_'.$n, array_keys($orders), $orders));
                if ($hidden !== '') {
                    $plan['sql'] = preg_replace('/ FROM /', $hidden.' FROM ', $plan['sql'], 1);
                }
                $order = implode(',', array_map(fn (int $n): string => 's.catalog_order_'.$n, array_keys($orders)));
            } else {
                $plan = self::identityPlan($query, $names);
                $slot = 's.identity_lookup';
                $orders = $family['order'] === null ? [] : explode(',', $family['order']);
                $order = implode(',', array_map(fn (int $n): string => 's.identity_order_'.$n, array_keys($orders)));
            }
            if ($plan['sql'] === null || $plan['names'] === []) {
                throw new LogicException('Empty catalog recipe.');
            }
            $family['names'] = $plan['names'];
            $bindings = [...$bindings, ...$plan['bindings']];
            $name = 'catalog_family_'.$index;
            // A window-bearing CTE is nonmergeable. Its single materialization is shared by header and rows.
            $window = ($direct === null ? 'PARTITION BY '.$slot : '').($order === '' ? '' : ($direct === null ? ' ' : '').'ORDER BY '.$order);
            $ctes[] = $name.' AS (SELECT s.*, '.$slot.' AS catalog_slot, ROW_NUMBER() OVER ('.$window.') AS catalog_ordinal FROM ('.$plan['sql'].') s)';
            $slots = implode(' UNION ALL ', array_map(fn (int $n): string => 'SELECT '.$n.' AS catalog_slot', array_keys($plan['names'])));
            $branches[] = 'SELECT '.$index.' AS family, expected.catalog_slot AS slot, 0 AS kind, CAST(0 AS CHAR CHARACTER SET utf8mb4) AS ordinal, CAST(JSON_ARRAY(CAST(COALESCE(actual.total,0) AS CHAR CHARACTER SET utf8mb4)) AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_bin AS payload FROM ('.$slots.') expected LEFT JOIN (SELECT catalog_slot, COUNT(*) AS total FROM '.$name.' GROUP BY catalog_slot) actual ON actual.catalog_slot=expected.catalog_slot';
            $cells = [];
            foreach ($family['columns'] as $column) {
                $cell = 'r.`'.$column['key'].'`';
                $value = $column['php_kind'] === 'int' ? 'CAST('.$cell.' AS CHAR CHARACTER SET utf8mb4)' : $cell;
                $cells[] = 'JSON_ARRAY(IF('.$cell." IS NULL,'N','".($column['php_kind'] === 'int' ? 'I' : 'S')."'),".$value.')';
            }
            $branches[] = 'SELECT '.$index.' AS family, encoded.catalog_slot AS slot, 1 AS kind, CAST(encoded.catalog_ordinal AS CHAR CHARACTER SET utf8mb4) AS ordinal, CASE WHEN OCTET_LENGTH(encoded.payload)>8388608 THEN NULL ELSE encoded.payload END AS payload FROM (SELECT r.catalog_slot,r.catalog_ordinal,CAST(JSON_ARRAY('.implode(',', $cells).') AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_bin AS payload FROM '.$name.' r) encoded';
        }
        unset($family);
        if (count($bindings) > 1024 || ! array_all($bindings, fn ($v): bool => is_string($v) && strlen($v) <= 4096)) {
            throw new LogicException('Closed catalog bind limit.');
        }

        // UNION ALL prevents merging; the complete wire (ALL families/names/rows) is materialized once.
        $ctes[] = 'catalog_wire AS ('.implode(' UNION ALL ', $branches).')';
        // Counts/bytes include headers and tags. 256 bytes/record plus 4096 fixed framing reserve
        // bounds the native binary-row/protocol overhead, not server work/materialized pages.
        $ctes[] = "catalog_gate AS (SELECT (COUNT(*)<=131072 AND SUM(payload IS NULL)=0 AND SUM(OCTET_LENGTH(payload)+256)+4096<=16777216 AND MAX(CASE WHEN kind=0 THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$[0]')) AS UNSIGNED) ELSE 0 END)<=65536) AS valid FROM catalog_wire)";
        // Overflow emits every expected header with decisively INVALID counts, NEVER partial facts.
        $sql = 'WITH '.implode(', ', $ctes)." SELECT '".$group->value."' AS group_id,w.family,w.slot,w.kind,w.ordinal,CASE WHEN g.valid THEN w.payload ELSE CAST('[null]' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_bin END AS payload FROM catalog_wire w CROSS JOIN catalog_gate g WHERE g.valid OR w.kind=0 ORDER BY family,slot,kind,CAST(ordinal AS UNSIGNED)";

        return ['sql' => $sql, 'bindings' => $bindings, 'families' => $families];
    }

    private static function recipe(CatalogGroup $group, CatalogRead $query, string $version): array
    {
        $parents = ['users', 'customer_accounts', 'quote_owners'];
        $accountNames = ['customer_accounts_insert', 'customer_accounts_insert_v2', 'customer_accounts_update', 'customer_accounts_delete'];
        $guards = array_keys(AccountAdmissionGuards::definitions('mysql', $version));
        $guards = [...$guards, 'quote_owners_immutable_update', 'quote_owners_immutable_delete'];
        if (class_exists(DiscoveryEpoch::class)) {
            foreach (DiscoveryEpoch::guards('mysql') as $name => $guard) {
                if ($guard['table'] === 'users') {
                    $guards[] = $name;
                }
            }
        }
        $definitions = IdentitySchema::definitions();
        $tables = array_keys($definitions);
        $identityGuards = array_keys(IdentitySchema::guards('mysql'));
        $reserved = [];
        foreach ($definitions as $definition) {
            $reserved = [...$reserved, ...array_keys($definition['unique']), ...array_keys($definition['foreign'])];
        }

        return match ($group) {
            CatalogGroup::A0_ACCOUNT_TABLE, CatalogGroup::A2_ACCOUNT_FLOOR => [[], ['customer_accounts']],
            CatalogGroup::A1_ACCOUNT_AFTER_SHOW => [[], match ($query) {
                CatalogRead::AccountTables => $accountNames,
                CatalogRead::Q01CED6344452 => ['customer_accounts', ...$accountNames],
                CatalogRead::Q54EFEC87F80D => ['customer_accounts'],
                default => ['customer_accounts', ...$accountNames],
            }],
            CatalogGroup::P0_PARENT_NAMESPACE => [[...$parents, ...$guards], null],
            CatalogGroup::P1_PARENT_SHAPE => [$parents, null],
            CatalogGroup::P2_PARENT_GUARDS => [$query === CatalogRead::Q95556A0350AB ? $guards : $parents, null],
            CatalogGroup::I0_IDENTITY_NAMESPACE => [$query === CatalogRead::QEDBF704DA857 ? [...$parents, ...$tables, ...$identityGuards, ...$reserved] : [...$reserved, ...$identityGuards, ...$tables], null],
            CatalogGroup::I1_IDENTITY_SHAPE => [$tables, null],
            CatalogGroup::I2_RESERVED_NAMES => [$reserved, null],
            CatalogGroup::D0_FULL_DEPENDENTS => [[], []],
        };
    }

    private static function identityPlan(CatalogRead $query, array $names): array
    {
        $sql = $query->value;
        if (! \array_is_list($names) || \count($names) > 128 || ! \array_all($names, fn ($name) => \is_string($name) && \strlen($name) <= 256)) {
            throw new LogicException('Closed catalog plan required.');
        }
        if (\preg_match('/\ASELECT (.+?) FROM (information_schema\..+?) WHERE (.+) AND (LOWER\()?((?:[a-z]\.)?[A-Z_]+)(\)?)=\?(?: ORDER BY ([a-zA-Z_.,]+))?\z/', $sql, $part) !== 1
            || $part[4] !== ($part[6] === '' ? '' : 'LOWER(')) {
            throw new LogicException('Unsupported identity dictionary lookup.');
        }
        $names = \array_values(\array_unique($names));
        if ($names === []) {
            return ['sql' => null, 'bindings' => [], 'names' => []];
        }
        $order = isset($part[7]) && $part[7] !== '' ? \explode(',', $part[7]) : [];
        $ordering = \implode('', \array_map(fn (int $index, string $column): string => ', '.$column.' AS identity_order_'.$index, \array_keys($order), $order));
        $predicate = $part[4].'identity_key'.$part[6].'=?';
        $positions = \implode(' UNION ALL ', \array_map(fn (int $position): string => 'SELECT '.$position.' AS identity_lookup', \array_keys($names)));
        $cases = \implode(' ', \array_map(fn (int $position): string => 'WHEN '.$position.' THEN '.$predicate, \array_keys($names)));
        // IN is the disjunction of the branch equalities (same operands, same comparison collation),
        // so the prefilter keeps exactly the rows some branch would match, including LOWER() aliases.
        $prefilter = ' AND '.$part[4].$part[5].$part[6].' IN ('.\implode(',', \array_fill(0, \count($names), '?')).')';
        $statement = 'WITH lookup AS (SELECT '.$part[1].', '.$part[5].' AS identity_key'.$ordering.' FROM '.$part[2].' WHERE '.$part[3].$prefilter.') SELECT /*+ NO_MERGE(lookup) */ positions.identity_lookup, lookup.* FROM lookup JOIN ('.$positions.') positions ON CASE positions.identity_lookup '.$cases.' ELSE FALSE END'
            .($order === [] ? '' : ' ORDER BY identity_lookup'.\implode('', \array_map(fn (int $index): string => ', identity_order_'.$index, \array_keys($order))));

        return ['sql' => $statement, 'bindings' => [...$names, ...$names], 'names' => $names];
    }
}
