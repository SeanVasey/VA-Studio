<?php

namespace Tests\Support;

use LogicException;
use PDO;
use Pdo\Mysql;
use PDOStatement;
use ReflectionClass;
use ReflectionMethod;

final class NativeExplainNoteTransport implements ExplainNoteTransport
{
    private ?PDOStatement $diagnostic = null;

    public function __construct(private readonly Mysql $pdo, private readonly PDOStatement $offender)
    {
        if ($pdo::class !== Mysql::class || $offender::class !== PDOStatement::class
            || ! (new ReflectionClass($pdo))->isInternal() || ! (new ReflectionClass($offender))->isInternal()
            || ! (new ReflectionMethod(Mysql::class, 'getWarningCount'))->isInternal()
            || $pdo->getAttribute(Mysql::ATTR_USE_BUFFERED_QUERY) !== true || $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) !== false
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $pdo->getAttribute(PDO::ATTR_CASE) !== PDO::CASE_NATURAL
            || $pdo->getAttribute(PDO::ATTR_ORACLE_NULLS) !== PDO::NULL_NATURAL || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || $pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES) !== false || $pdo->getAttribute(PDO::ATTR_PERSISTENT) !== false
            || $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS) !== [PDOStatement::class]) {
            throw new LogicException('Native buffered EXPLAIN transport required.');
        }
    }

    public function emulated(): mixed
    {
        return $this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
    }

    public function setEmulated(bool $value): bool
    {
        return $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $value);
    }

    public function finishOffender(): bool
    {
        return $this->offender->fetch(PDO::FETCH_NUM) === false;
    }

    public function warningCount(): int
    {
        return $this->pdo->getWarningCount();
    }

    public function warningRows(): iterable
    {
        $this->diagnostic = $this->pdo->query('SHOW WARNINGS LIMIT 8');
        while (($row = $this->diagnostic->fetch(PDO::FETCH_ASSOC)) !== false) {
            yield $row;
        }
    }

    public function closeDiagnostic(): bool
    {
        if ($this->diagnostic === null) {
            return true;
        }
        $closed = $this->diagnostic->closeCursor();
        $this->diagnostic = null;

        return $closed;
    }

    public function closeOffender(): bool
    {
        return $this->offender->closeCursor();
    }
}
