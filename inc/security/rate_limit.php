<?php

declare(strict_types=1);

function fc_rate_limit_bucket_hash(string $namespace, string $subject): string
{
    $namespace = strtolower(trim($namespace));
    if (!preg_match('/\A[a-z0-9][a-z0-9_.:-]{0,63}\z/', $namespace)) {
        throw new InvalidArgumentException('Rate-limit namespace is invalid.');
    }
    if ($subject === '' || strlen($subject) > 2048) {
        throw new InvalidArgumentException('Rate-limit subject is invalid.');
    }

    $key = fc_auth_transaction_secret_key();
    try {
        return hash_hmac('sha256', $namespace . "\0" . $subject, $key);
    } finally {
        sodium_memzero($key);
    }
}

/**
 * Generic, database-backed fixed-window limiter. Callers own policy and must
 * compose an appropriate subject (for example, an actor ID, route plus network
 * evidence, or invitation public ID). Only keyed evidence is persisted.
 *
 * The first $maxAttempts calls in a window are allowed. Later calls are denied
 * until the window resets, or until the optional longer block expires.
 *
 * @return array{allowed:bool,remaining:int,retry_after_seconds:int}
 */
function fc_rate_limit_consume(
    PDO $pdo,
    string $namespace,
    string $subject,
    int $maxAttempts,
    int $windowSeconds,
    int $blockSeconds = 0,
    ?DateTimeImmutable $now = null
): array {
    if ($maxAttempts < 1 || $maxAttempts > 10000) {
        throw new InvalidArgumentException('Rate-limit maximum attempts must be between 1 and 10000.');
    }
    if ($windowSeconds < 1 || $windowSeconds > 604800) {
        throw new InvalidArgumentException('Rate-limit window must be between 1 second and 7 days.');
    }
    if ($blockSeconds < 0 || $blockSeconds > 2592000) {
        throw new InvalidArgumentException('Rate-limit block must be between 0 seconds and 30 days.');
    }

    $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('UTC'));
    $nowSql = $now->format('Y-m-d H:i:s.u');
    $bucketHash = fc_rate_limit_bucket_hash($namespace, $subject);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $seed = $pdo->prepare(
            'INSERT INTO security_rate_limit_buckets ' .
            '    (bucket_key_hash, window_started_at, attempt_count) ' .
            'VALUES (:bucket_hash, :window_started_at, 0) ' .
            'ON DUPLICATE KEY UPDATE bucket_key_hash = VALUES(bucket_key_hash)'
        );
        $seed->execute([
            ':bucket_hash' => $bucketHash,
            ':window_started_at' => $nowSql,
        ]);

        $select = $pdo->prepare(
            'SELECT window_started_at, attempt_count, blocked_until ' .
            'FROM security_rate_limit_buckets WHERE bucket_key_hash = :bucket_hash FOR UPDATE'
        );
        $select->execute([':bucket_hash' => $bucketHash]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Rate-limit bucket could not be loaded.');
        }

        $windowStarted = new DateTimeImmutable((string) $row['window_started_at'], new DateTimeZone('UTC'));
        $windowEnds = $windowStarted->modify('+' . $windowSeconds . ' seconds');
        $blockedUntil = $row['blocked_until'] !== null
            ? new DateTimeImmutable((string) $row['blocked_until'], new DateTimeZone('UTC'))
            : null;

        if ($blockedUntil !== null && $blockedUntil > $now) {
            $result = [
                'allowed' => false,
                'remaining' => 0,
                'retry_after_seconds' => max(1, $blockedUntil->getTimestamp() - $now->getTimestamp()),
            ];
        } else {
            $attemptCount = (int) $row['attempt_count'];
            if ($now >= $windowEnds) {
                $windowStarted = $now;
                $windowEnds = $now->modify('+' . $windowSeconds . ' seconds');
                $attemptCount = 0;
                $blockedUntil = null;
            }

            if ($attemptCount >= $maxAttempts) {
                $denialEnds = $blockSeconds > 0
                    ? $now->modify('+' . $blockSeconds . ' seconds')
                    : $windowEnds;
                $update = $pdo->prepare(
                    'UPDATE security_rate_limit_buckets ' .
                    'SET blocked_until = :blocked_until, updated_at = :updated_at ' .
                    'WHERE bucket_key_hash = :bucket_hash'
                );
                $update->execute([
                    ':blocked_until' => $denialEnds->format('Y-m-d H:i:s.u'),
                    ':updated_at' => $nowSql,
                    ':bucket_hash' => $bucketHash,
                ]);
                $result = [
                    'allowed' => false,
                    'remaining' => 0,
                    'retry_after_seconds' => max(1, $denialEnds->getTimestamp() - $now->getTimestamp()),
                ];
            } else {
                $nextCount = $attemptCount + 1;
                $update = $pdo->prepare(
                    'UPDATE security_rate_limit_buckets ' .
                    'SET window_started_at = :window_started_at, attempt_count = :attempt_count, ' .
                    '    blocked_until = NULL, updated_at = :updated_at ' .
                    'WHERE bucket_key_hash = :bucket_hash'
                );
                $update->execute([
                    ':window_started_at' => $windowStarted->format('Y-m-d H:i:s.u'),
                    ':attempt_count' => $nextCount,
                    ':updated_at' => $nowSql,
                    ':bucket_hash' => $bucketHash,
                ]);
                $result = [
                    'allowed' => true,
                    'remaining' => max(0, $maxAttempts - $nextCount),
                    'retry_after_seconds' => 0,
                ];
            }
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function fc_rate_limit_clear(PDO $pdo, string $namespace, string $subject): bool
{
    $statement = $pdo->prepare(
        'DELETE FROM security_rate_limit_buckets WHERE bucket_key_hash = :bucket_hash'
    );
    $statement->execute([
        ':bucket_hash' => fc_rate_limit_bucket_hash($namespace, $subject),
    ]);

    return $statement->rowCount() === 1;
}

function fc_rate_limit_cleanup(PDO $pdo, int $retentionSeconds = 86400): int
{
    if ($retentionSeconds < 3600 || $retentionSeconds > 2592000) {
        throw new InvalidArgumentException('Rate-limit retention must be between 1 hour and 30 days.');
    }

    $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('-' . $retentionSeconds . ' seconds')
        ->format('Y-m-d H:i:s.u');
    $statement = $pdo->prepare(
        'DELETE FROM security_rate_limit_buckets ' .
        'WHERE updated_at < :cutoff AND (blocked_until IS NULL OR blocked_until < CURRENT_TIMESTAMP(6))'
    );
    $statement->execute([':cutoff' => $cutoff]);

    return $statement->rowCount();
}
