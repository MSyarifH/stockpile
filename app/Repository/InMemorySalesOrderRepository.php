<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;
use App\Entity\SalesOrderStatus;
use App\Support\Page;

final class InMemorySalesOrderRepository implements SalesOrderRepository
{
    /** @var array<int,SalesOrder> */
    private array $orders = [];

    private int $nextId = 1;
    private int $nextItemId = 1;

    /** @param list<SalesOrder> $orders */
    public function __construct(array $orders = [])
    {
        foreach ($orders as $order) {
            $this->create($order);
        }
    }

    public function all(?int $createdBy = null): array
    {
        $orders = array_values($this->orders);
        if ($createdBy === null) {
            return $orders;
        }

        return array_values(array_filter(
            $orders,
            static fn (SalesOrder $order): bool => $order->createdBy === $createdBy,
        ));
    }

    public function between(string $from, string $to, ?int $createdBy = null): array
    {
        return array_values(array_filter(
            $this->all($createdBy),
            static fn (SalesOrder $order): bool => $order->orderDate >= $from && $order->orderDate <= $to,
        ));
    }

    public function paginate(OrderFilter $filter, int $page, ?int $createdBy = null): Page
    {
        $matching = array_values(array_filter(
            $this->all($createdBy),
            static function (SalesOrder $order) use ($filter): bool {
                if ($filter->status !== null && $order->status->value !== $filter->status) {
                    return false;
                }
                if ($filter->search === '') {
                    return true;
                }

                $haystack = mb_strtolower($order->soNumber . ' ' . ($order->customerName ?? ''));

                return str_contains($haystack, mb_strtolower($filter->search));
            },
        ));

        usort($matching, static function (SalesOrder $a, SalesOrder $b) use ($filter): int {
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

    public function findById(int $id): ?SalesOrder
    {
        return $this->orders[$id] ?? null;
    }

    public function nextNumber(): string
    {
        return sprintf('SO-%s-%04d', date('Y'), $this->nextId);
    }

    public function create(SalesOrder $order): int
    {
        $id = $order->id ?? $this->nextId;
        $this->nextId = max($this->nextId, $id + 1);

        $items = [];
        foreach ($order->items as $item) {
            $items[] = new SalesOrderItem(
                $item->id ?? $this->nextItemId++,
                $item->productId,
                $item->quantity,
                $item->sellingPrice,
                $item->productName,
                $item->productSku,
                $item->availableInWarehouse,
            );
        }

        $this->orders[$id] = new SalesOrder(
            $id,
            $order->soNumber,
            $order->customerId,
            $order->warehouseId,
            $order->status,
            $order->orderDate,
            $order->createdBy,
            $order->approvedBy,
            $order->approvedAt,
            $items,
        );

        return $id;
    }

    private function replace(int $id, SalesOrderStatus $status, ?int $approvedBy, ?string $approvedAt): void
    {
        $order = $this->orders[$id] ?? null;
        if ($order === null) {
            return;
        }

        $this->orders[$id] = new SalesOrder(
            $id,
            $order->soNumber,
            $order->customerId,
            $order->warehouseId,
            $status,
            $order->orderDate,
            $order->createdBy,
            $approvedBy,
            $approvedAt,
            $order->items,
        );
    }

    public function updateStatus(int $id, SalesOrderStatus $status): void
    {
        $order = $this->orders[$id] ?? null;
        $this->replace($id, $status, $order?->approvedBy, $order?->approvedAt);
    }

    public function markApproved(int $id, int $approvedBy): void
    {
        $this->replace($id, SalesOrderStatus::Approved, $approvedBy, date('Y-m-d H:i:s'));
    }
}
