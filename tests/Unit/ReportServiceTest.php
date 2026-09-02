<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\AuthenticatedUser;
use App\Entity\LedgerEntry;
use App\Entity\MovementType;
use App\Entity\ReferenceType;
use App\Entity\Role;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;
use App\Entity\SalesOrderStatus;
use App\Repository\InMemorySalesOrderRepository;
use App\Repository\InMemoryStockLedgerRepository;
use App\Service\Exception\AuthorizationException;
use App\Service\ReportService;
use App\Support\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Logic area 8 — CSV export rules and formatting (REPORT-01).
 */
final class ReportServiceTest extends TestCase
{
    private InMemoryStockLedgerRepository $ledger;
    private InMemorySalesOrderRepository $orders;
    private ReportService $service;

    protected function setUp(): void
    {
        $this->ledger = new InMemoryStockLedgerRepository();
        $this->orders = new InMemorySalesOrderRepository();
        $this->service = new ReportService($this->ledger, $this->orders);
    }

    private function admin(): AuthenticatedUser
    {
        return new AuthenticatedUser(1, 'Admin', Role::Admin);
    }

    private function sales(): AuthenticatedUser
    {
        return new AuthenticatedUser(2, 'Seller', Role::Sales);
    }

    private function warehouse(): AuthenticatedUser
    {
        return new AuthenticatedUser(4, 'Storeman', Role::WarehouseStaff);
    }

    private function givenOrder(int $id, int $createdBy, string $date, string $customer = 'A Customer'): void
    {
        $this->orders->create(new SalesOrder(
            $id,
            'SO-2026-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT),
            1,
            1,
            SalesOrderStatus::Fulfilled,
            $date,
            $createdBy,
            1,
            null,
            [new SalesOrderItem(null, 1, 2, 1500.0)],
            $customer,
        ));
    }

    // --- authorization -----------------------------------------------------

    /** §1.2: Sales may download their own orders, but not stock reports. */
    public function testSalesCannotExportStockMovements(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service->stockMovements($this->sales(), '2026-01-01', '2026-12-31');
    }

    public function testWarehouseStaffCanExportStockMovements(): void
    {
        [$filename, $rows] = $this->service->stockMovements($this->warehouse(), '2026-01-01', '2026-12-31');

        self::assertStringContainsString('stock-movements', $filename);
        self::assertSame('Date', $rows[0][0], 'The first row is the header.');
    }

    /**
     * The export must obey the same ownership rule as the list page, or it
     * becomes a way to read other sellers' orders.
     */
    public function testASalesExportContainsOnlyTheirOwnOrders(): void
    {
        $this->givenOrder(1, 2, '2026-06-10');   // theirs
        $this->givenOrder(2, 3, '2026-06-11');   // another seller's

        [, $rows] = $this->service->orderStatus($this->sales(), '2026-01-01', '2026-12-31');
        [, $adminRows] = $this->service->orderStatus($this->admin(), '2026-01-01', '2026-12-31');

        self::assertCount(2, $rows, 'Header plus one order.');
        self::assertCount(3, $adminRows, 'An Admin sees both.');
    }

    // --- date range --------------------------------------------------------

    public function testAReversedDateRangeIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->orderStatus($this->admin(), '2026-12-31', '2026-01-01');
    }

    public function testAnUnparseableDateIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->orderStatus($this->admin(), 'not-a-date', '2026-01-01');
    }

    public function testTheDateRangeIsInclusiveOfBothEnds(): void
    {
        $this->givenOrder(1, 2, '2026-06-01');
        $this->givenOrder(2, 2, '2026-06-30');
        $this->givenOrder(3, 2, '2026-07-01');

        [, $rows] = $this->service->orderStatus($this->admin(), '2026-06-01', '2026-06-30');

        self::assertCount(3, $rows, 'Header plus the two orders on the boundary dates.');
    }

    public function testTheFilenameCarriesTheRangeSoExportsDoNotOverwriteEachOther(): void
    {
        [$june] = $this->service->orderStatus($this->admin(), '2026-06-01', '2026-06-30');
        [$july] = $this->service->orderStatus($this->admin(), '2026-07-01', '2026-07-31');

        self::assertNotSame($june, $july);
        self::assertStringContainsString('2026-06-01', $june);
    }

    // --- row assembly ------------------------------------------------------

    public function testMovementRowsRecordDirectionActorAndReference(): void
    {
        $this->ledger->append(new LedgerEntry(
            null,
            1,
            1,
            MovementType::Issue,
            -4,
            ReferenceType::SalesOrder,
            17,
            4,
            '2026-06-10 09:00:00',
            'Keyboard',
            'SKU-1',
            'Gudang Jakarta',
            'Wawan Gudang',
        ));

        [, $rows] = $this->service->stockMovements($this->admin(), '2026-06-01', '2026-06-30');

        self::assertSame('Issue', $rows[1][4]);
        self::assertSame('-4', $rows[1][5], 'The sign shows the direction.');
        self::assertSame('SalesOrder #17', $rows[1][6], 'Every movement names its source document.');
        self::assertSame('Wawan Gudang', $rows[1][7]);
    }
}
