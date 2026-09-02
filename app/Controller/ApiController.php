<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\StockLevel;
use App\Service\ProductService;
use App\Support\Exception\HttpException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

/**
 * API-01. A JSON contract, separate from the HTML pages.
 *
 * Note what is NOT here: no SQL, no business rule, no second copy of the
 * authorization logic. The endpoint calls the same ProductService the HTML
 * controller uses and differs only in how it renders the answer. That is the
 * practical payoff of the layering ARCH-01 requires — adding a second
 * presentation could not make the two drift apart, because there is only one
 * source of the rules.
 *
 * Authentication is checked exactly as for a page (API-01), but a failure
 * returns 401 JSON rather than a redirect to the login form: a redirect to HTML
 * is useless to a program.
 */
final class ApiController
{
    public function __construct(
        private readonly ProductService $products,
        private readonly Session $session,
    ) {
    }

    /**
     * GET /api/products/{sku}/availability
     *
     * 200 — the product exists: total and per-warehouse balances
     * 401 — no session
     * 404 — no product with that SKU
     */
    public function productAvailability(Request $request, string $sku): Response
    {
        // Same session check as any page. Throwing here reaches the central
        // handler, which sees an /api/ path and answers in JSON.
        if (!$this->session->isAuthenticated()) {
            throw HttpException::unauthorised('Authentication required.');
        }

        $product = $this->products->findBySku(rawurldecode($sku));

        if ($product === null) {
            // 404 with a JSON body, never an HTML error page.
            throw HttpException::notFound(sprintf('No product with SKU "%s".', $sku));
        }

        $levels = $this->products->stockLevels($product->id ?? 0);

        return Response::json([
            'sku' => $product->sku,
            'name' => $product->name,
            'unit' => $product->unit,
            'is_active' => $product->isActive,
            'reorder_point' => $product->reorderPoint,
            'total_available' => $product->totalStock,
            'is_low_stock' => $product->isLowStock(),
            'warehouses' => array_map(
                static fn (StockLevel $level): array => [
                    'warehouse_id' => $level->warehouseId,
                    'warehouse' => $level->warehouseName,
                    'available' => $level->quantity,
                ],
                $levels,
            ),
        ]);
    }
}
