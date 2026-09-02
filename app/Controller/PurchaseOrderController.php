<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuthenticatedUser;
use App\Service\BusinessPartnerService;
use App\Entity\PartnerType;
use App\Service\ProductService;
use App\Service\PurchaseOrderService;
use App\Service\WarehouseService;
use App\Support\Csrf;
use App\Support\Exception\HttpException;
use App\Support\Exception\ValidationException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * PO-01. Purchase orders and goods receipt.
 */
final class PurchaseOrderController
{
    public function __construct(
        private readonly PurchaseOrderService $orders,
        private readonly ProductService $products,
        private readonly BusinessPartnerService $partners,
        private readonly WarehouseService $warehouses,
        private readonly Session $session,
        private readonly View $view,
        private readonly Csrf $csrf,
    ) {
    }

    public function index(Request $request): Response
    {
        $actor = $this->requireUser();

        return Response::html($this->view->renderInLayout('purchase.index', [
            'title' => 'Purchase orders',
            'orders' => $this->orders->list($actor),
        ]));
    }

    public function show(Request $request, string $id): Response
    {
        $actor = $this->requireUser();

        return Response::html($this->view->renderInLayout('purchase.show', [
            'title' => 'Purchase order',
            'order' => $this->orders->find($actor, (int) $id),
        ]));
    }

    public function create(Request $request): Response
    {
        $actor = $this->requireUser();

        return Response::html($this->view->renderInLayout('purchase.form', [
            'title' => 'New purchase order',
            'csrfToken' => $this->csrf->token(),
            'suppliers' => $this->partners->list(PartnerType::Supplier, true),
            'warehouses' => $this->warehouses->list(true),
            'products' => $this->products->list($actor, true),
            'values' => ['supplier_id' => '', 'warehouse_id' => '', 'order_date' => date('Y-m-d')],
            'errors' => [],
        ]));
    }

    public function store(Request $request): Response
    {
        $actor = $this->requireUser();
        $this->csrf->assertValid($request);

        try {
            $id = $this->orders->create(
                $actor,
                $request->integer('supplier_id'),
                $request->integer('warehouse_id'),
                $request->string('order_date'),
                $this->linesFrom($request),
            );
        } catch (ValidationException $e) {
            return Response::html($this->view->renderInLayout('purchase.form', [
                'title' => 'New purchase order',
                'csrfToken' => $this->csrf->token(),
                'suppliers' => $this->partners->list(PartnerType::Supplier, true),
                'warehouses' => $this->warehouses->list(true),
                'products' => $this->products->list($actor, true),
                'values' => [
                    'supplier_id' => $request->string('supplier_id'),
                    'warehouse_id' => $request->string('warehouse_id'),
                    'order_date' => $request->string('order_date'),
                ],
                'errors' => $e->errors(),
            ]), 422);
        }

        $this->session->flash('success', 'Purchase order created as a draft.');
        return Response::redirect('/purchase-orders/' . $id);
    }

    public function place(Request $request, string $id): Response
    {
        $actor = $this->requireUser();
        $this->csrf->assertValid($request);

        $this->orders->place($actor, (int) $id);
        $this->session->flash('success', 'Purchase order placed with the supplier.');

        return Response::redirect('/purchase-orders/' . (int) $id);
    }

    public function cancel(Request $request, string $id): Response
    {
        $actor = $this->requireUser();
        $this->csrf->assertValid($request);

        $this->orders->cancel($actor, (int) $id);
        $this->session->flash('success', 'Purchase order cancelled.');

        return Response::redirect('/purchase-orders/' . (int) $id);
    }

    public function receive(Request $request, string $id): Response
    {
        $actor = $this->requireUser();
        $this->csrf->assertValid($request);

        /** @var array<int,int> $quantities */
        $quantities = [];
        $submitted = $request->input('received', []);
        if (is_array($submitted)) {
            foreach ($submitted as $itemId => $quantity) {
                if (is_numeric($quantity) && (int) $quantity > 0) {
                    $quantities[(int) $itemId] = (int) $quantity;
                }
            }
        }

        try {
            $this->orders->receiveGoods($actor, (int) $id, $quantities);
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors()));
            return Response::redirect('/purchase-orders/' . (int) $id);
        }

        $this->session->flash('success', 'Goods receipt recorded and stock updated.');
        return Response::redirect('/purchase-orders/' . (int) $id);
    }

    /**
     * Line arrays arrive as parallel inputs (items[product_id][], items[quantity][]).
     * Rows where no product was chosen are dropped rather than rejected, because
     * the form always renders one blank row.
     *
     * @return list<array{product_id:int,quantity:int,purchase_price:float}>
     */
    private function linesFrom(Request $request): array
    {
        $raw = $request->input('items', []);
        if (!is_array($raw)) {
            return [];
        }

        $productIds = is_array($raw['product_id'] ?? null) ? $raw['product_id'] : [];
        $quantities = is_array($raw['quantity'] ?? null) ? $raw['quantity'] : [];
        $prices = is_array($raw['purchase_price'] ?? null) ? $raw['purchase_price'] : [];

        $lines = [];
        foreach ($productIds as $index => $productId) {
            if (!is_numeric($productId) || (int) $productId <= 0) {
                continue;
            }
            $lines[] = [
                'product_id' => (int) $productId,
                'quantity' => (int) ($quantities[$index] ?? 0),
                'purchase_price' => (float) ($prices[$index] ?? 0),
            ];
        }

        return $lines;
    }

    private function requireUser(): AuthenticatedUser
    {
        $user = $this->session->user();
        if ($user === null) {
            throw HttpException::unauthorised();
        }

        return $user;
    }
}
