<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Fixed by §1.3: Draft / Ordered / PartiallyReceived / Received / Cancelled.
 *
 * The legal transitions live on the enum rather than being re-checked in each
 * controller, so there is exactly one description of the lifecycle.
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'Draft';
    case Ordered = 'Ordered';
    case PartiallyReceived = 'PartiallyReceived';
    case Received = 'Received';
    case Cancelled = 'Cancelled';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Ordered, self::Cancelled],
            self::Ordered => [self::PartiallyReceived, self::Received, self::Cancelled],
            // A partly received order may still be closed early; what already
            // arrived stays in the ledger (decision D4).
            self::PartiallyReceived => [self::Received, self::Cancelled],
            self::Received, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }

    /** Goods may only be received against an order that has been placed. */
    public function acceptsGoodsReceipt(): bool
    {
        return $this === self::Ordered || $this === self::PartiallyReceived;
    }

    public function isFinal(): bool
    {
        return $this->allowedNext() === [];
    }

    public function label(): string
    {
        return match ($this) {
            self::PartiallyReceived => 'Partially received',
            default => $this->value,
        };
    }

    public function badgeModifier(): string
    {
        return match ($this) {
            self::Draft => 'badge--draft',
            self::Ordered => 'badge--info',
            self::PartiallyReceived => 'badge--warn',
            self::Received => 'badge--ok',
            self::Cancelled => 'badge--off',
        };
    }
}
