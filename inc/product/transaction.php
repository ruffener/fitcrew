<?php

declare(strict_types=1);

/** A write transaction must use current reads, not an earlier REPEATABLE READ snapshot. */
function fc_product_current_read(PDO $pdo): string
{
    return $pdo->inTransaction() ? ' FOR UPDATE' : '';
}

/** Preserve atomicity inside caller-owned transactions as well as standalone operations. */
function fc_product_atomic(PDO $pdo, callable $operation): mixed
{
    static $savepointSequence = 0;
    $ownsTransaction = !$pdo->inTransaction();
    $savepoint = 'fc_product_' . (++$savepointSequence);
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    } else {
        $pdo->exec('SAVEPOINT ' . $savepoint);
    }
    try {
        $result = $operation();
        if ($ownsTransaction) {
            $pdo->commit();
        } else {
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
        }
        throw $error;
    }
}
