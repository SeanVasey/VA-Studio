<?php

namespace App\Domain\Grants\Member;

/** Pure comparison with the installed member plan. Quoted literal bytes are never whitespace/case normalized. */
final class MemberGrantSqlDefinition
{
    private const WORDS = ['create', 'table', 'trigger', 'before', 'insert', 'update', 'delete', 'on', 'for', 'each', 'row',
        'begin', 'end', 'if', 'not', 'coalesce', 'then', 'signal', 'sqlstate', 'set', 'message_text', 'when', 'select',
        'raise', 'abort', 'exists', 'from', 'where', 'and', 'or', 'is', 'null', 'join', 'new', 'count', 'length', 'in',
        'between', 'primary', 'key', 'constraint', 'check', 'unique', 'foreign', 'references', 'restrict', 'character',
        'collate', 'varchar', 'longtext', 'text', 'int', 'integer', 'bigint', 'unsigned', 'engine', 'default', 'charset', 'definer'];

    public static function same(string $actual, string $expected): bool
    {
        return self::tokens($actual) === self::tokens($expected);
    }

    public static function mysqlTable(string $shown, array $table, string $prefix): void
    {
        $tokens = self::tokens($shown);
        $header = self::tokens('CREATE TABLE `'.$table['name'].'` (');
        MemberGrantException::require(array_slice($tokens, 0, count($header)) === $header, 'schema');
        $offset = count($header);
        $parts = [];
        $part = [];
        $depth = 1;
        while (isset($tokens[$offset])) {
            $token = $tokens[$offset++];
            if ($token === ['symbol', '(']) {
                $depth++;
            } elseif ($token === ['symbol', ')']) {
                if (--$depth === 0) {
                    $parts[] = $part;
                    break;
                }
            }
            if ($token === ['symbol', ','] && $depth === 1) {
                MemberGrantException::require($part !== [], 'schema');
                $parts[] = $part;
                $part = [];
            } else {
                $part[] = $token;
            }
        }
        // These UUID tables own no allocation counter or optional table feature.
        MemberGrantException::require($depth === 0
            && array_slice($tokens, $offset) === self::tokens('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin'), 'schema');
        $definition = $table['definition'];
        foreach ($definition['columns'] as $column => $type) {
            $expected = self::tokens('`'.$column.'` '.$type);
            $actual = array_shift($parts);
            // SHOW can omit the explicit table-default character set; retain its exact binary collation.
            if (str_contains($type, 'CHARACTER SET utf8mb4 COLLATE utf8mb4_bin')) {
                $expected = self::without($expected, self::tokens('CHARACTER SET utf8mb4'));
                $actual = is_array($actual) ? self::without($actual, self::tokens('CHARACTER SET utf8mb4')) : null;
            }
            MemberGrantException::require($actual === $expected, 'schema');
        }
        $columns = static fn (array $names): string => '(`'.implode('`,`', $names).'`)';
        $wanted = [self::tokens('PRIMARY KEY '.$columns($definition['primary']))];
        $keys = [$definition['primary']];
        foreach ($definition['unique'] as $index => $names) {
            $wanted[] = self::tokens('UNIQUE KEY `'.$table['name'].'_u'.$index.'` '.$columns($names));
            $keys[] = $names;
        }
        foreach ($definition['foreign'] as $column => $logical) {
            $index = array_search($column, array_keys($definition['foreign']), true);
            $name = $table['name'].'_f'.$index;
            if (! array_any($keys, static fn (array $names): bool => $names[0] === $column)) {
                $wanted[] = self::tokens('KEY `'.$name.'` '.$columns([$column]));
            }
            $target = in_array($logical, ['users', 'customer_accounts'], true) ? $logical : $prefix.$logical;
            $wanted[] = self::tokens('CONSTRAINT `'.$name.'` FOREIGN KEY '.$columns([$column]).' REFERENCES `'.$target.'` (`id`)');
        }
        $checkHeader = self::tokens('CONSTRAINT `'.$table['name'].'_bounds` CHECK');
        $found = false;
        $other = [];
        foreach ($parts as $part) {
            if (array_slice($part, 0, count($checkHeader)) === $checkHeader) {
                MemberGrantException::require(! $found, 'schema');
                $actual = array_slice($part, count($checkHeader));
                MemberGrantException::require(self::expression($actual) === self::expression(self::tokens($definition['check'])), 'schema');
                $found = true;
            } else {
                // Only the exact default RESTRICT actions may disappear from native FK printing.
                if (array_slice($part, 0, 1) === self::tokens('CONSTRAINT')) {
                    $part = self::without(self::without($part, self::tokens('ON DELETE RESTRICT')), self::tokens('ON UPDATE RESTRICT'));
                }
                $other[] = $part;
            }
        }
        $encode = static fn (array $value): string => json_encode($value, JSON_THROW_ON_ERROR);
        $wanted = array_map($encode, $wanted);
        $other = array_map($encode, $other);
        sort($wanted);
        sort($other);
        MemberGrantException::require($found && $other === $wanted, 'schema');
    }

    public static function mysqlTrigger(string $shown, string $expected): void
    {
        $tokens = self::tokens($shown);
        // SHOW inserts the installation account. It changes no owned timing, table, event, body or literal.
        if (array_slice($tokens, 0, 3) === self::tokens('CREATE DEFINER=')) {
            MemberGrantException::require(in_array($tokens[3][0] ?? null, ['identifier', 'literal'], true)
                && ($tokens[4] ?? null) === ['symbol', '@']
                && in_array($tokens[5][0] ?? null, ['identifier', 'literal'], true)
                && ($tokens[6] ?? null) === ['word', 'trigger'], 'schema');
            $tokens = [$tokens[0], ...array_slice($tokens, 6)];
        }
        MemberGrantException::require($tokens === self::tokens($expected), 'schema');
    }

    private static function without(array $tokens, array $needle): array
    {
        for ($index = 0; $index <= count($tokens) - count($needle); $index++) {
            if (array_slice($tokens, $index, count($needle)) === $needle) {
                array_splice($tokens, $index, count($needle));
                $index--;
            }
        }

        return $tokens;
    }

    /** The owned CHECK grammar preserves predicates and boolean grouping, admitting server-added parentheses only. */
    private static function expression(array $tokens): array
    {
        $offset = 0;
        $result = self::boolean($tokens, $offset, 'or');
        MemberGrantException::require($offset === count($tokens), 'schema');

        return $result;
    }

    private static function boolean(array $tokens, int &$offset, string $operator): array
    {
        $read = static function () use ($tokens, &$offset, $operator): array {
            return $operator === 'or' ? self::boolean($tokens, $offset, 'and') : self::comparison($tokens, $offset);
        };
        $nodes = [$read()];
        while (($tokens[$offset] ?? null) === ['word', $operator]) {
            $offset++;
            $nodes[] = $read();
        }
        if (count($nodes) === 1) {
            return $nodes[0];
        }
        $flat = [];
        foreach ($nodes as $node) {
            array_push($flat, ...($node[0] === $operator ? array_slice($node, 1) : [$node]));
        }

        return [$operator, ...$flat];
    }

    private static function comparison(array $tokens, int &$offset): array
    {
        $left = self::atom($tokens, $offset);
        $operator = $tokens[$offset] ?? null;
        if ($operator === ['word', 'between']) {
            $offset++;
            $low = self::atom($tokens, $offset);
            self::take($tokens, $offset, ['word', 'and']);

            return ['between', $left, $low, self::atom($tokens, $offset)];
        }
        if ($operator === ['word', 'in']) {
            $offset++;
            self::take($tokens, $offset, ['symbol', '(']);
            $values = [self::atom($tokens, $offset)];
            while (($tokens[$offset] ?? null) === ['symbol', ',']) {
                $offset++;
                $values[] = self::atom($tokens, $offset);
            }
            self::take($tokens, $offset, ['symbol', ')']);

            return ['in', $left, $values];
        }
        if (is_array($operator) && $operator[0] === 'symbol' && in_array($operator[1], ['=', '<', '>', '<=', '>=', '<>', '!='], true)) {
            $offset++;

            return [$operator[1], $left, self::atom($tokens, $offset)];
        }

        return $left;
    }

    private static function atom(array $tokens, int &$offset): array
    {
        $token = $tokens[$offset++] ?? null;
        MemberGrantException::require(is_array($token), 'schema');
        if ($token === ['symbol', '(']) {
            $node = self::boolean($tokens, $offset, 'or');
            self::take($tokens, $offset, ['symbol', ')']);

            return $node;
        }
        if ($token === ['word', 'length']) {
            self::take($tokens, $offset, ['symbol', '(']);
            $argument = self::atom($tokens, $offset);
            self::take($tokens, $offset, ['symbol', ')']);

            return ['length', $argument];
        }
        if (in_array($token, [['identifier', '_ascii'], ['identifier', '_utf8mb4']], true)) {
            $token = $tokens[$offset++] ?? null;
            MemberGrantException::require(is_array($token) && $token[0] === 'literal'
                && in_array($token[1], ['synthetic_rehearsal', 'verified_production', 'production-member-origin-v1', 'production-member-license-grant-v1'], true), 'schema');
        }
        MemberGrantException::require(in_array($token[0], ['identifier', 'literal', 'number'], true), 'schema');

        return $token;
    }

    private static function take(array $tokens, int &$offset, array $expected): void
    {
        MemberGrantException::require(($tokens[$offset++] ?? null) === $expected, 'schema');
    }

    /** Closed SQL tokens only: comments/escapes are not an owned printer normalization. */
    private static function tokens(string $sql): array
    {
        MemberGrantException::require(strlen($sql) <= 131072 && ! str_contains($sql, "\0"), 'schema');
        $tokens = [];
        for ($offset = 0, $length = strlen($sql); $offset < $length;) {
            $character = $sql[$offset];
            if (str_contains(" \t\r\n\f\v", $character)) {
                $offset++;

                continue;
            }
            if (in_array($character, ["'", '"', '`'], true)) {
                $offset++;
                $value = '';
                $closed = false;
                while ($offset < $length) {
                    $next = $sql[$offset++];
                    MemberGrantException::require($next !== '\\', 'schema');
                    if ($next === $character) {
                        if ($offset < $length && $sql[$offset] === $character) {
                            $value .= $character;
                            $offset++;
                        } else {
                            $closed = true;
                            break;
                        }
                    } else {
                        $value .= $next;
                    }
                }
                MemberGrantException::require($closed, 'schema');
                $tokens[] = [$character === "'" ? 'literal' : 'identifier', $value];
            } elseif (preg_match('/[a-zA-Z_][a-zA-Z0-9_$]*/A', $sql, $match, 0, $offset) === 1) {
                $offset += strlen($match[0]);
                $word = strtolower($match[0]);
                $tokens[] = in_array($word, self::WORDS, true) ? ['word', $word === 'integer' ? 'int' : $word] : ['identifier', $match[0]];
            } elseif (preg_match('/[0-9]+/A', $sql, $match, 0, $offset) === 1) {
                $offset += strlen($match[0]);
                $tokens[] = ['number', $match[0]];
            } else {
                $pair = substr($sql, $offset, 2);
                if (in_array($pair, ['<=', '>=', '<>', '!='], true)) {
                    $tokens[] = ['symbol', $pair];
                    $offset += 2;
                } else {
                    MemberGrantException::require(str_contains('(),.=<>;*@', $character), 'schema');
                    $tokens[] = ['symbol', $character];
                    $offset++;
                }
            }
        }

        return $tokens;
    }
}
