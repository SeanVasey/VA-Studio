<?php

namespace App\Domain\Grants\ProductionFree;

use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;
use PDOException;
use Throwable;

/**
 * Every Free256 command runs in one of these. A deadlock (SQLSTATE 40001, MySQL 1213) or lock wait timeout (MySQL 1205)
 * rolls the whole transaction back, so nothing partial is written, and the caller gets the fixed reason `contention`
 * instead of a raw driver exception. There is deliberately no retry here: commands carry external effects (leased
 * claims, staged files, the one-use redemption row) and the customer-facing ones are idempotent by request key, so
 * the caller retrying the same command is the safe and observable path.
 */
final class ProductionFreeGrantTransactions
{
    public static function run(callable $work): mixed
    {
        try {
            return DB::transaction($work);
        } catch (ProductionFreeGrantException $error) {
            throw $error;
        } catch (Throwable $error) {
            if (self::contended($error)) {
                throw new ProductionFreeGrantException('contention');
            }
            throw $error;
        }
    }

    /** True for a deadlock or lock wait timeout anywhere in the exception chain. */
    public static function contended(Throwable $error): bool
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof DeadlockException) {
                return true;
            }
            if ($current instanceof PDOException) {
                $info = is_array($current->errorInfo) ? $current->errorInfo : [];
                if (($info[0] ?? null) === '40001' || in_array((int) ($info[1] ?? 0), [1213, 1205], true) || $current->getCode() === '40001') {
                    return true;
                }
            }
        }

        return false;
    }
}
