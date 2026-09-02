<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Fixed by §1.3. The sign convention lives here so that nothing else has to
 * remember it: a Receipt adds, an Issue subtracts, an Adjustment is signed by
 * the caller (used for opening balances and corrections).
 */
enum MovementType: string
{
    case Receipt = 'Receipt';
    case Issue = 'Issue';
    case Adjustment = 'Adjustment';

    /** Converts a positive quantity into the signed delta stored in the ledger. */
    public function signedDelta(int $quantity): int
    {
        return match ($this) {
            self::Receipt, self::Adjustment => $quantity,
            self::Issue => -$quantity,
        };
    }

    public function reducesStock(): bool
    {
        return $this === self::Issue;
    }
}
