<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Entity\PurchaseOrderStatus;
use App\Support\Page;

final class InMemoryPurchaseOrderRepository implements PurchaseOrderRepository
{
    /** @var array<int,PurchaseOrder> */
    private array $orders = [];

    private int $nextId = 1;
    private int $nextItemId = 1;

    /** @param list<PurchaseOrder> $orders */
    public function __construct(array $orders = [])
    {
        foreach ($orders as $order) {
            $this->create($order);
        }
    }

    public function all(): array
    {
        return array_values($this->orders);
    }

    public function paginate(OrderFilter $filter, int $page): Page
    {
        $matching = array_values(array_filter(
            $this->all(),
            static function (PurchaseOrder $order) use ($filter): bool {
                if ($filter->status !== null && $order->status->value !== $filter->status) {
                    return false;
                }
                if ($filter->search === '') {
                    return true;
                }

                $haystack = mb_strtolower($order->poNumber . ' ' . ($order->supplierName ?? ''));

                return str_contains($haystack, mb_strtolower($filter->search));
            },
        ));

        usort($matching, static function (PurchaseOrder $a, PurchaseOrder $b) use ($filter): int {
            $comparison = strcmp($a->orderDate, $b->orderDate);

            return $filter->sortDirection === OrderFilter::SORT_ASC ? $comparison : -$comparison;
        });

        $current = Page::normalisePage($page);

        return new Page(
            array_values(array_slice($matching, Page::offsetFor($current), Page::PER_PAGE)),
            count($matching),
            $current,
        );
    }

    public function findById(int $id): ?PurchaseOrder
    {
        return $this->orders[$id] ?? null;
    }

    public function nextNumber(): string
    {
        return sprintf('PO-%s-%04d', date('Y'), $this->nextId);
    }

    public function create(PurchaseOrder $order): int
    {
        $id = $order->id ?? $this->nextId;
        $this->nextId = max($this->nextId, $id + 1);

        $items = [];
        foreach ($order->items as $item) {
            $items[] = new PurchaseOrderItem(
                $item->id ?? $this->nextItemId++,
                $item->productId,
                $item->quantity,
                $item->receivedQuantity,
                $item->purchasePrice,
                $item->productName,
                $item->productSku,
            );
        }

        $this->orders[$id] = new PurchaseOrder(
            $id,
            $order->poNumber,
            $order->supplierId,
            $order->warehouseId,
            $order->status,
            $order->orderDate,
            $order->createdBy,
            $items,
        );

        return $id;
    }

    public function updateStatus(int $id, PurchaseOrderStatus $status): void
    {
        $order = $this->orders[$id] ?? null;
        if ($order === null) {
            return;
        }

        $this->orders[$id] = new PurchaseOrder(
            $id,
            $order->poNumber,
            $order->supplierId,
            $order->warehouseId,
            $status,
            $order->orderDate,
            $order->createdBy,
            $order->items,
        );
    }

    public function addReceivedQuantity(int $itemId, int $quantity): void
    {
        foreach ($this->orders as $id => $order) {
            $changed = false;
            $items = [];
            foreach ($order->items as $item) {
                if ($item->id === $itemId) {
                    $items[] = new PurchaseOrderItem(
                        $item->id,
                        $item->productId,
                        $item->quantity,
                        $item->receivedQuantity + $quantity,
                        $item->purchasePrice,
                        $item->productName,
                        $item->productSku,
                    );
                    $changed = true;
                    continue;
                }
                $items[] = $item;
            }

            if ($changed) {
                $this->orders[$id] = new PurchaseOrder(
                    $order->id,
                    $order->poNumber,
                    $order->supplierId,
                    $order->warehouseId,
                    $order->status,
                    $order->orderDate,
                    $order->createdBy,
                    $items,
                );

                return;
            }
        }
    }
}
