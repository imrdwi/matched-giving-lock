<?php

namespace App\Support;

use Closure;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Throwable;

/**
 * A MySQL named lock (GET_LOCK / RELEASE_LOCK) around a callback.
 *
 * A named lock serializes work on something that is not a single row. Here
 * that is a matching pool's remaining balance, which is derived from many
 * donation rows, so no one row lock covers it.
 *
 * Two rules keep it correct:
 *
 * 1. Take the lock before the transaction starts. Under REPEATABLE READ a
 *    transaction reads from a snapshot taken at its first read. If that read
 *    happened before the lock was acquired, the code inside the lock would
 *    still see the balance as it was before the previous holder committed.
 *
 * 2. Release the lock after the transaction commits. Releasing first lets
 *    the next holder read the balance before this write is visible.
 *
 * The lock belongs to the database session, so it is released on the same
 * connection that took it, and MySQL drops it if that session dies.
 *
 * A failed release is reported, never thrown. If the callback threw, throwing
 * from `finally` would replace its exception with the release error and hide
 * the real failure. If the callback succeeded, the transaction has already
 * committed, and throwing would tell the caller a recorded donation failed,
 * inviting a retry that records it twice. Either way the lock is not leaked
 * for long: MySQL frees it when the session ends.
 */
final class NamedLock
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws LockTimeoutException when the lock is still held by another session after $timeoutSeconds
     */
    public function run(string $name, int $timeoutSeconds, Closure $callback): mixed
    {
        if ($this->connection->transactionLevel() > 0) {
            throw new LogicException("Take the named lock [{$name}] before starting a transaction, not inside one.");
        }

        // GET_LOCK returns 1 on success, 0 on timeout, and NULL on error.
        $acquired = $this->connection->selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$name, $timeoutSeconds])->acquired;

        if ((int) $acquired !== 1) {
            throw new LockTimeoutException($name, $timeoutSeconds);
        }

        try {
            return $callback();
        } finally {
            $this->release($name);
        }
    }

    private function release(string $name): void
    {
        try {
            $this->connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
