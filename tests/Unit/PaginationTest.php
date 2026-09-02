<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\Product;
use App\Repository\InMemoryProductRepository;
use App\Repository\ProductFilter;
use App\Support\Page;
use App\Support\QueryString;
use PHPUnit\Framework\TestCase;

/**
 * Logic area 7 — search, filter and pagination (FIND-01).
 */
final class PaginationTest extends TestCase
{
    private function repositoryWith(int $count): InMemoryProductRepository
    {
        $products = [];
        for ($i = 1; $i <= $count; $i++) {
            $products[] = new Product(
                $i,
                sprintf('SKU-%03d', $i),
                sprintf('Product %03d', $i),
                ($i % 2) + 1,
                'pcs',
                100.0,
                150.0,
                10,
                null,
                true,
            );
        }

        return new InMemoryProductRepository($products);
    }

    // --- Page arithmetic ---------------------------------------------------

    public function testPageSizeIsFixedAtTenByTheBrief(): void
    {
        self::assertSame(10, Page::PER_PAGE);
    }

    public function testTotalPagesRoundsUpForAPartialLastPage(): void
    {
        $page = new Page([], 34, 1);

        self::assertSame(4, $page->totalPages(), '34 rows at 10 per page needs 4 pages.');
    }

    public function testAnEmptyResultStillReportsOnePage(): void
    {
        $page = new Page([], 0, 1);

        self::assertSame(1, $page->totalPages());
        self::assertSame(0, $page->from(), 'No rows means no "showing 1–..".');
        self::assertFalse($page->hasNext());
        self::assertFalse($page->hasPrevious());
    }

    public function testShowingRangeIsCorrectOnTheLastPage(): void
    {
        $page = new Page(array_fill(0, 4, 'row'), 34, 4);

        self::assertSame(31, $page->from());
        self::assertSame(34, $page->to(), 'The range must not overrun the total.');
        self::assertFalse($page->hasNext());
        self::assertTrue($page->hasPrevious());
    }

    /**
     * A negative page number would produce a negative SQL OFFSET, which MySQL
     * rejects — a 500 from a hand-typed URL.
     */
    public function testAnOutOfRangePageNumberIsClamped(): void
    {
        self::assertSame(1, Page::normalisePage(-5));
        self::assertSame(1, Page::normalisePage(0));
        self::assertSame(0, Page::offsetFor(-5), 'Offset can never be negative.');
        self::assertSame(20, Page::offsetFor(3));
    }

    // --- filters keep working across pages ---------------------------------

    public function testExactlyTenRowsPerPageAndTheRemainderOnTheLast(): void
    {
        $repository = $this->repositoryWith(34);

        self::assertCount(10, $repository->paginate(new ProductFilter(), 1)->items);
        self::assertCount(10, $repository->paginate(new ProductFilter(), 2)->items);
        self::assertCount(4, $repository->paginate(new ProductFilter(), 4)->items);
        self::assertSame(34, $repository->paginate(new ProductFilter(), 1)->total);
    }

    public function testPagesDoNotOverlapOrSkipRows(): void
    {
        $repository = $this->repositoryWith(25);

        $first = array_map(static fn (Product $p): string => $p->sku, $repository->paginate(new ProductFilter(), 1)->items);
        $second = array_map(static fn (Product $p): string => $p->sku, $repository->paginate(new ProductFilter(), 2)->items);

        self::assertSame([], array_intersect($first, $second), 'No row may appear on two pages.');
        self::assertCount(20, array_unique(array_merge($first, $second)));
    }

    /**
     * FIND-01: "filter tetap aktif saat berpindah halaman". The count must be
     * the filtered count, not the total, or the pager offers pages that do not
     * exist.
     */
    public function testTheFilteredTotalDrivesThePagerNotTheOverallTotal(): void
    {
        $repository = $this->repositoryWith(34);

        // Category 1 holds the odd-numbered products: 17 of 34.
        $page = $repository->paginate(new ProductFilter('', 1), 1);

        self::assertSame(17, $page->total);
        self::assertSame(2, $page->totalPages(), 'Not 4 — the pager must reflect the filter.');
        self::assertCount(7, $repository->paginate(new ProductFilter('', 1), 2)->items);
    }

    public function testSearchMatchesBothNameAndSku(): void
    {
        $repository = $this->repositoryWith(34);

        self::assertSame(1, $repository->paginate(new ProductFilter('SKU-007'), 1)->total);
        self::assertSame(1, $repository->paginate(new ProductFilter('Product 007'), 1)->total);
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $repository = $this->repositoryWith(5);

        self::assertSame(
            $repository->paginate(new ProductFilter('PRODUCT'), 1)->total,
            $repository->paginate(new ProductFilter('product'), 1)->total,
        );
    }

    public function testLowAndNormalStockFiltersPartitionTheCatalogue(): void
    {
        $repository = $this->repositoryWith(10);
        $repository->setStock(1, 1, 3);    // below its reorder point of 10
        $repository->setStock(2, 1, 50);   // comfortably above

        $low = $repository->paginate(new ProductFilter('', null, ProductFilter::STOCK_LOW), 1)->total;
        $normal = $repository->paginate(new ProductFilter('', null, ProductFilter::STOCK_NORMAL), 1)->total;

        self::assertSame(10, $low + $normal, 'Every product is either low or normal, never both.');
        self::assertSame(1, $normal, 'Only the product with stock above its reorder point.');
    }

    // --- query string ------------------------------------------------------

    public function testPaginationLinksCarryTheActiveFiltersForward(): void
    {
        $query = new QueryString(['q' => 'keyboard', 'stock' => 'low', 'page' => '1']);

        $link = $query->with(['page' => 2]);

        self::assertStringContainsString('q=keyboard', $link);
        self::assertStringContainsString('stock=low', $link);
        self::assertStringContainsString('page=2', $link);
    }

    public function testBlankFiltersAreDroppedFromTheUrl(): void
    {
        $query = new QueryString(['q' => '', 'category' => '3']);

        $link = $query->with(['page' => 2]);

        self::assertStringNotContainsString('q=', $link, 'An empty filter should not clutter the URL.');
        self::assertStringContainsString('category=3', $link);
    }

    public function testAFilterCanBeRemovedByPassingNull(): void
    {
        $query = new QueryString(['q' => 'keyboard', 'page' => '3']);

        self::assertStringNotContainsString('q=', $query->with(['q' => null]));
    }
}
