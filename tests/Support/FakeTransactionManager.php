<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\TransactionManager;

/**
 * Unit-test stand-in for a database transaction.
 *
 * It simply runs the work. That is the point of injecting the boundary as a
 * behaviour: the service can state that its operations are atomic without a
 * database being present to make them so.
 *
 * It also records how deeply transactional() was nested, so a test can assert
 * that a service really did wrap its work rather than calling repositories
 * directly.
 */
final class FakeTransactionManager implements TransactionManager
{
    public int $started = 0;
    public int $committed = 0;
    public int $rolledBack = 0;

    public function transactional(callable $work): mixed
    {
        $this->started++;

        try {
            $result = $work();
        } catch (\Throwable $e) {
            $this->rolledBack++;
            throw $e;
        }

        $this->committed++;

        return $result;
    }
}
