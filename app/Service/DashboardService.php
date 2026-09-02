<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuthenticatedUser;
use App\Entity\Product;
use App\Entity\PurchaseOrderStatus;
use App\Entity\Role;
use App\Entity\SalesOrderStatus;
use App\Repository\DashboardRepository;
use App\Repository\ProductRepository;

/**
 * DASH-01. Assembles the figures each role is allowed to see.
 *
 * The role decides WHAT is fetched, not merely what is rendered: a Sales user's
 * summary is built from a query already restricted to their own orders, so the
 * numbers for other sellers are never loaded (§1.2).
 */
final class DashboardService
{
    public function __construct(
        private readonly DashboardRepository $dashboard,
        private readonly ProductRepository $products,
    ) {
    }

    /**
     * @return array{
     *     inventory_value:float,
     *     total_units:int,
     *     low_stock_count:int,
     *     active_products:int,
     *     purchase_orders:array<string,int>,
     *     sales_orders:array<string,int>,
     *     pending_approval:int,
     *     low_stock:list<Product>
     * }
     */
    public function forAdmin(): array
    {
        $salesByStatus = $this->dashboard->salesOrdersByStatus();

        return [
            'inventory_value' => $this->dashboard->inventoryValueAtCost(),
            'total_units' => $this->dashboard->totalUnitsInStock(),
            'low_stock_count' => $this->dashboard->productsBelowReorderPoint(),
            'active_products' => $this->dashboard->activeProductCount(),
            'purchase_orders' => $this->dashboard->purchaseOrdersByStatus(),
            'sales_orders' => $salesByStatus,
            'pending_approval' => $salesByStatus[SalesOrderStatus::PendingApproval->value] ?? 0,
            // Limited to the worst offenders: a dashboard is a summary, and the
            // full list already exists at /products?stock=low.
            'low_stock' => array_slice($this->products->lowStock(), 0, 5),
        ];
    }

    /**
     * @return array{sales_orders:array<string,int>,order_value:float,pending_approval:int,drafts:int}
     */
    public function forSales(AuthenticatedUser $actor): array
    {
        $byStatus = $this->dashboard->salesOrdersByStatus($actor->id);

        return [
            'sales_orders' => $byStatus,
            'order_value' => $this->dashboard->salesOrderValue($actor->id),
            'pending_approval' => $byStatus[SalesOrderStatus::PendingApproval->value] ?? 0,
            'drafts' => $byStatus[SalesOrderStatus::Draft->value] ?? 0,
        ];
    }

    /**
     * @return array{receipt_queue:int,issue_queue:int,low_stock_count:int,low_stock:list<Product>,total_units:int}
     */
    public function forWarehouse(): array
    {
        return [
            'receipt_queue' => $this->dashboard->goodsReceiptQueueCount(),
            'issue_queue' => $this->dashboard->goodsIssueQueueCount(),
            'low_stock_count' => $this->dashboard->productsBelowReorderPoint(),
            'low_stock' => array_slice($this->products->lowStock(), 0, 8),
            'total_units' => $this->dashboard->totalUnitsInStock(),
        ];
    }

    /** @return list<string> every purchase order status, so zero-count states still show */
    public function purchaseStatuses(): array
    {
        return array_map(
            static fn (PurchaseOrderStatus $status): string => $status->value,
            PurchaseOrderStatus::cases(),
        );
    }

    /** @return list<string> */
    public function salesStatuses(): array
    {
        return array_map(
            static fn (SalesOrderStatus $status): string => $status->value,
            SalesOrderStatus::cases(),
        );
    }

    public function isAdmin(AuthenticatedUser $actor): bool
    {
        return $actor->role === Role::Admin;
    }
}
