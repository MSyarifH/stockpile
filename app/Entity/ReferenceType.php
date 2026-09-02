<?php

declare(strict_types=1);

namespace App\Entity;

enum ReferenceType: string
{
    case PurchaseOrder = 'PurchaseOrder';
    case SalesOrder = 'SalesOrder';
    case Manual = 'Manual';
}
