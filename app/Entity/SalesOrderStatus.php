<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Fixed by §1.3: Draft -> PendingApproval -> Approved -> Fulfilled, or
 * Cancelled at any stage before Fulfilled.
 */
enum SalesOrderStatus: string
{
    case Draft = 'Draft';
    case PendingApproval = 'PendingApproval';
    case Approved = 'Approved';
    case Fulfilled = 'Fulfilled';
    case Cancelled = 'Cancelled';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::PendingApproval, self::Cancelled],
            // Rejection sends the order back to Draft so the Sales user can fix
            // and resubmit it; outright cancellation is also available.
            self::PendingApproval => [self::Approved, self::Draft, self::Cancelled],
            self::Approved => [self::Fulfilled, self::Cancelled],
            self::Fulfilled, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }

    /** SO-01: goods issue is legal only from Approved. */
    public function acceptsGoodsIssue(): bool
    {
        return $this === self::Approved;
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isFinal(): bool
    {
        return $this->allowedNext() === [];
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingApproval => 'Pending approval',
            default => $this->value,
        };
    }

    public function badgeModifier(): string
    {
        return match ($this) {
            self::Draft => 'badge--draft',
            self::PendingApproval => 'badge--warn',
            self::Approved => 'badge--info',
            self::Fulfilled => 'badge--ok',
            self::Cancelled => 'badge--off',
        };
    }
}
