<?php

// Pure fake PDOs: no parent connection constructor, SQL or application bootstrap.
final class RRNSFakeWarningStatement extends PDOStatement
{
    public function __construct(private RRNSFakeWarningPDO $owner) {}

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $this->owner->calls[] = 'fetch';
        if ($this->owner->failure === 'fetch') {
            throw new RuntimeException('synthetic fetch error');
        }

        return $this->owner->rows;
    }

    public function closeCursor(): bool
    {
        $this->owner->calls[] = 'close';
        if ($this->owner->failure === 'close') {
            throw new RuntimeException('synthetic close error');
        }

        return $this->owner->failure !== 'close_false';
    }
}

final class RRNSFakeWarningPDO extends PDO
{
    public array $calls = [];

    public bool $emulate = false;

    private int $gets = 0;

    public function __construct(public string $failure = '', public array $rows = []) {}

    public function getAttribute(int $attribute): mixed
    {
        $this->calls[] = 'get';
        $this->gets++;
        if ($attribute !== PDO::ATTR_EMULATE_PREPARES) {
            throw new RuntimeException('unexpected attribute');
        }
        if (($this->failure === 'get_before' && $this->gets === 1)
            || ($this->failure === 'get_during' && $this->gets === 2)
            || ($this->failure === 'get_after' && $this->gets === 3)) {
            throw new RuntimeException('synthetic get error');
        }
        if ($this->failure === 'wrong_during' && $this->gets === 2) {
            return false;
        }

        return $this->emulate;
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        $this->calls[] = $value ? 'set_true' : 'set_false';
        if ($attribute !== PDO::ATTR_EMULATE_PREPARES) {
            throw new RuntimeException('unexpected attribute');
        }
        if (($value && $this->failure === 'set_true') || (! $value && $this->failure === 'restore_throw')) {
            throw new RuntimeException('synthetic set error');
        }
        $this->emulate = $value;

        return ! (($value && $this->failure === 'set_true_false') || (! $value && $this->failure === 'restore_false'));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->calls[] = 'query:'.$query;
        if ($query !== 'SHOW WARNINGS' || $this->emulate !== true) {
            throw new RuntimeException('Diagnostic command/mode escaped');
        }
        if ($this->failure === 'pdo_query') {
            throw new PDOException('synthetic PDO query error');
        }
        if ($this->failure === 'query') {
            throw new RuntimeException('synthetic query error');
        }
        if ($this->failure === 'query_false') {
            return false;
        }

        return new RRNSFakeWarningStatement($this);
    }
}

$tests = 0;
$check = static function (bool $condition, string $label) use (&$tests): void {
    $tests++;
    if (! $condition) {
        throw new RuntimeException('Pure warning transport test failed: '.$label);
    }
};
$pdo = new RRNSFakeWarningPDO;
$trace = [];
$result = directWarnings($pdo, static function (array $mode) use (&$trace): void {
    $trace[] = $mode;
});
$check($result['rows'] === [] && $pdo->emulate === false, 'clean success and exact restoration');
$check($result['diagnostic_mode']['before'] === false && $result['diagnostic_mode']['during'] === true && $result['diagnostic_mode']['after'] === false && $result['diagnostic_mode']['restored'], 'captured mode sequence');
$check($pdo->calls === ['get', 'set_true', 'get', 'query:SHOW WARNINGS', 'fetch', 'close', 'set_false', 'get'], 'no intervening SQL or mode spill');
$check(count($trace) === 1, 'one final diagnostic trace');
$unexpected = [['Level' => 'Error', 'Code' => 1295, 'Message' => 'synthetic unknown warning']];
$check(directWarnings(new RRNSFakeWarningPDO(rows: $unexpected))['rows'] === $unexpected, 'never filter1295 or unknown rows');
foreach (['set_true', 'set_true_false', 'get_during', 'wrong_during', 'query', 'pdo_query', 'query_false', 'fetch', 'close', 'close_false'] as $failure) {
    $pdo = new RRNSFakeWarningPDO($failure);
    $trace = [];
    $thrown = false;
    try {
        directWarnings($pdo, static function (array $mode) use (&$trace): void {
            $trace[] = $mode;
        });
    } catch (RuntimeException) {
        $thrown = true;
    }
    $check($thrown && $pdo->emulate === false && count($trace) === 1 && $trace[0]['restored'] === true, $failure.' fails while restoring nativefalse');
}
foreach (['restore_throw', 'restore_false', 'get_after'] as $failure) {
    $pdo = new RRNSFakeWarningPDO($failure);
    $trace = [];
    $thrown = false;
    try {
        directWarnings($pdo, static function (array $mode) use (&$trace): void {
            $trace[] = $mode;
        });
    } catch (RuntimeException) {
        $thrown = true;
    }
    $check($thrown && count($trace) === 1 && $trace[0]['restored'] === false, $failure.' is failure-closed');
    $check(count(array_filter($pdo->calls, static fn (string $call): bool => $call === 'set_false')) === 1, $failure.' does not retry restoration');
}
$pdo = new RRNSFakeWarningPDO('get_before');
$thrown = false;
try {
    directWarnings($pdo);
} catch (RuntimeException) {
    $thrown = true;
}
$check($thrown && $pdo->calls === ['get'], 'unknown initial mode never queries or mutates');
$pdo = new RRNSFakeWarningPDO;
$pdo->emulate = true;
$thrown = false;
try {
    directWarnings($pdo);
} catch (RuntimeException) {
    $thrown = true;
}
$check($thrown && $pdo->calls === ['get'] && $pdo->emulate === true, 'nonnative input refuses rather than normalizes');
emit(['pure_warning_transport' => 'PASS', 'assertions' => $tests, 'database_connections' => 0, 'native_control_execution' => false]);
