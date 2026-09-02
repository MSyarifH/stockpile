<?php

declare(strict_types=1);

namespace App\Support;

/**
 * An atomic boundary, expressed as a behaviour rather than as a database handle.
 *
 * Services depend on this instead of PDO so that business logic never imports a
 * database driver (ARCH-01) and can be unit-tested with a no-op implementation.
 */
interface TransactionManager
{
    /**
     * Run $work atomically: commit on return, roll back on any throwable.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function transactional(callable $work): mixed;
}
