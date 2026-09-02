<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

final class PdoTransactionManager implements TransactionManager
{
    /**
     * Nesting depth. Order services open a transaction and then call StockService,
     * which opens one too. MySQL has no true nested transactions, so inner calls
     * join the outer one and only the outermost commit is real. Without this the
     * inner commit would end the transaction early and the outer rollback would
     * have nothing left to undo.
     */
    private int $depth = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function transactional(callable $work): mixed
    {
        if ($this->depth === 0) {
            $this->pdo->beginTransaction();
        }
        $this->depth++;

        try {
            $result = $work();
        } catch (Throwable $e) {
            $this->depth--;
            if ($this->depth === 0 && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->depth--;
        if ($this->depth === 0) {
            $this->pdo->commit();
        }

        return $result;
    }
}
