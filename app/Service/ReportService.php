<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuthenticatedUser;
use App\Entity\LedgerEntry;
use App\Entity\Role;
use App\Repository\SalesOrderRepository;
use App\Repository\StockLedgerRepository;
use App\Service\Exception\AuthorizationException;
use App\Support\Exception\ValidationException;

/**
 * REPORT-01. Assembles report data for a date range.
 *
 * The rows come from the SAME repositories the dashboards read, so an export can
 * never disagree with what the screen showed.
 *
 * This service produces plain tabular data and knows nothing about CSV. Turning
 * rows into a file is CsvWriter's job — see the SRP audit in
 * docs/quality/refactor-log.md.
 */
final class ReportService
{
    public function __construct(
        private readonly StockLedgerRepository $ledger,
        private readonly SalesOrderRepository $salesOrders,
    ) {
    }

    /**
     * @return array{0:string,1:list<list<string>>} filename and rows (header first)
     */
    public function stockMovements(AuthenticatedUser $actor, string $from, string $to): array
    {
        // §1.2: Sales may download their own orders, not stock reports.
        if (!$actor->is(Role::Admin, Role::WarehouseStaff)) {
            throw new AuthorizationException('Only Admin and Warehouse Staff can export stock movements.');
        }

        [$from, $to] = $this->validateRange($from, $to);

        $rows = [[
            'Date', 'SKU', 'Product', 'Warehouse', 'Movement', 'Quantity', 'Reference', 'Performed by',
        ]];

        foreach ($this->ledger->between($from, $to) as $entry) {
            $rows[] = $this->movementRow($entry);
        }

        return [sprintf('stock-movements_%s_to_%s.csv', $from, $to), $rows];
    }

    /**
     * @return array{0:string,1:list<list<string>>}
     */
    public function orderStatus(AuthenticatedUser $actor, string $from, string $to): array
    {
        // §1.2 grants the order report to Admin ("Boleh") and Sales ("Order
        // miliknya"). Warehouse Staff are granted "Laporan stok" only — the
        // order export carries customer names and order values, which is
        // commercial information their role has no claim on.
        if (!$actor->is(Role::Admin, Role::Sales)) {
            throw new AuthorizationException('Only Admin and Sales can export order status.');
        }

        [$from, $to] = $this->validateRange($from, $to);

        // A Sales user's export is restricted at the QUERY, exactly as their
        // list page is — the report is not a way around ownership.
        $createdBy = $actor->role === Role::Sales ? $actor->id : null;

        $rows = [[
            'Order number', 'Date', 'Customer', 'Warehouse', 'Status', 'Raised by', 'Approved by', 'Total',
        ]];

        foreach ($this->salesOrders->between($from, $to, $createdBy) as $order) {
            $rows[] = [
                $order->soNumber,
                $order->orderDate,
                $order->customerName ?? '',
                $order->warehouseName ?? '',
                $order->status->value,
                $order->createdByName ?? '',
                $order->approvedByName ?? '',
                number_format($order->total(), 2, '.', ''),
            ];
        }

        return [sprintf('order-status_%s_to_%s.csv', $from, $to), $rows];
    }

    /** @return list<string> */
    private function movementRow(LedgerEntry $entry): array
    {
        return [
            $entry->createdAt ?? '',
            $entry->productSku ?? '',
            $entry->productName ?? '',
            $entry->warehouseName ?? '',
            $entry->movementType->value,
            (string) $entry->quantity,
            $entry->referenceId === null
                ? $entry->referenceType->value
                : $entry->referenceType->value . ' #' . $entry->referenceId,
            $entry->performedByName ?? '',
        ];
    }

    /**
     * @return array{0:string,1:string} normalised from and to dates
     */
    private function validateRange(string $from, string $to): array
    {
        $start = date_create_immutable($from);
        $end = date_create_immutable($to);

        if ($start === false || $end === false) {
            throw new ValidationException(['range' => 'Enter both dates in YYYY-MM-DD format.']);
        }

        if ($start > $end) {
            throw new ValidationException(['range' => 'The start date must not be after the end date.']);
        }

        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }
}
