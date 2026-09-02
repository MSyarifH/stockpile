<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Fixed by §1.3 of the brief. A backed enum rather than string constants so an
 * invalid role cannot exist in memory, and PHPStan can check role comparisons.
 */
enum Role: string
{
    case Admin = 'Admin';
    case Sales = 'Sales';
    case WarehouseStaff = 'WarehouseStaff';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Sales => 'Sales',
            self::WarehouseStaff => 'Warehouse Staff',
        };
    }

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Admin, self::Sales, self::WarehouseStaff];
    }
}
