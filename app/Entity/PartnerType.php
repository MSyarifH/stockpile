<?php

declare(strict_types=1);

namespace App\Entity;

enum PartnerType: string
{
    case Supplier = 'supplier';
    case Customer = 'customer';

    /** The table this partner type lives in. Never built from user input. */
    public function table(): string
    {
        return match ($this) {
            self::Supplier => 'suppliers',
            self::Customer => 'customers',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Supplier => 'Supplier',
            self::Customer => 'Customer',
        };
    }

    public function pluralLabel(): string
    {
        return $this->label() . 's';
    }

    public function urlSegment(): string
    {
        return match ($this) {
            self::Supplier => 'suppliers',
            self::Customer => 'customers',
        };
    }
}
