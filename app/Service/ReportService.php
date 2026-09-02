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
 * REPORT-01. CSV export of stock movements and order status for a date range.
 *
 * The rows come from the SAME repositories the dashboards read, so an export can
 * never disagree with what the screen showed.
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
        [$from, $to] = $this->validateRange($from, $to);

        // A Sales user's export is restricted at the QUERY, exactly as their
        // list page is — the report is not a way around ownership.
        $createdBy = $actor->role === Role::Sales ? $actor->id : null;

        $rows = [[
            'Order number', 'Date', 'Customer', 'Warehouse', 'Status', 'Raised by', 'Approved by', 'Total',
        ]];

        foreach ($this->salesOrders->all($createdBy) as $order) {
            if ($order->orderDate < $from || $order->orderDate > $to) {
                continue;
            }

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

    /**
     * Renders rows as CSV text.
     *
     * Built through fputcsv rather than by joining with commas: a product name
     * containing a comma, a quote or a newline would otherwise silently shift
     * every following column.
     *
     * @param list<list<string>> $rows
     */
    public function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Could not open a buffer for the CSV export.');
        }

        // BOM so Excel opens UTF-8 correctly; without it, accented names appear
        // mangled and the file looks broken to whoever asked for the report.
        fwrite($handle, "\xEF\xBB\xBF");

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
