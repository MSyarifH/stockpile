<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuthenticatedUser;
use App\Entity\PurchaseOrderStatus;
use App\Repository\OrderFilter;
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
use App\Support\OrderLineInput;
use App\Support\QueryString;
use App\Support\Session;
use App\Support\View;

/**
 * PO-01. Purchase orders and goods receipt.
 */
final class PurchaseOrderController
{
    /** Every write action redirects back to the order it changed (POST/Redirect/GET). */
    private const SHOW = '/purchase-orders/';

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
        $actor = $this->session->requireUser();
        $filter = $this->filterFrom($request);

        return Response::html($this->view->renderInLayout('purchase.index', [
            'title' => 'Purchase orders',
            'page' => $this->orders->search($actor, $filter, $request->integer('page', 1)),
            'filter' => $filter,
            'statuses' => PurchaseOrderStatus::cases(),
            'query' => new QueryString($request->all()),
        ]));
    }

    /**
     * A status that is not one of the enum's values is discarded rather than
     * passed to the query — the filter can only ever hold a legal value.
     */
    private function filterFrom(Request $request): OrderFilter
    {
        $status = PurchaseOrderStatus::tryFrom($request->string('status'));

        return new OrderFilter(
            $request->string('q'),
            $status?->value,
            $request->string('sort') === OrderFilter::SORT_ASC ? OrderFilter::SORT_ASC : OrderFilter::SORT_DESC,
        );
    }

    public function show(Request $request, string $id): Response
    {
        $actor = $this->session->requireUser();

        return Response::html($this->view->renderInLayout('purchase.show', [
            'title' => 'Purchase order',
            'order' => $this->orders->find($actor, (int) $id),
        ]));
    }

    public function create(): Response
    {
        $actor = $this->session->requireUser();

        return Response::html($this->view->renderInLayout('purchase.form', [
            'title' => 'New purchase order',
            'csrfToken' => $this->csrf->token(),
            'suppliers' => $this->partners->list(PartnerType::Supplier, true),
            'warehouses' => $this->warehouses->list(true),
            'products' => $this->products->list(true),
            'values' => ['supplier_id' => '', 'warehouse_id' => '', 'order_date' => date('Y-m-d')],
            'errors' => [],
        ]));
    }

    public function store(Request $request): Response
    {
        $actor = $this->session->requireUser();
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
                'products' => $this->products->list(true),
                'values' => [
                    'supplier_id' => $request->string('supplier_id'),
                    'warehouse_id' => $request->string('warehouse_id'),
                    'order_date' => $request->string('order_date'),
                ],
                'errors' => $e->errors(),
            ]), 422);
        }

        $this->session->flash('success', 'Purchase order created as a draft.');
        return Response::redirect(self::SHOW . $id);
    }

    public function place(Request $request, string $id): Response
    {
        $actor = $this->session->requireUser();
        $this->csrf->assertValid($request);

        $this->orders->place($actor, (int) $id);
        $this->session->flash('success', 'Purchase order placed with the supplier.');

        return Response::redirect(self::SHOW . (int) $id);
    }

    public function cancel(Request $request, string $id): Response
    {
        $actor = $this->session->requireUser();
        $this->csrf->assertValid($request);

        $this->orders->cancel($actor, (int) $id);
        $this->session->flash('success', 'Purchase order cancelled.');

        return Response::redirect(self::SHOW . (int) $id);
    }

    public function receive(Request $request, string $id): Response
    {
        $actor = $this->session->requireUser();
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
            return Response::redirect(self::SHOW . (int) $id);
        }

        $this->session->flash('success', 'Goods receipt recorded and stock updated.');
        return Response::redirect(self::SHOW . (int) $id);
    }

    /**
     * @return list<array{product_id:int,quantity:int,purchase_price:float}>
     */
    private function linesFrom(Request $request): array
    {
        return array_map(
            static fn (array $line): array => [
                'product_id' => $line['product_id'],
                'quantity' => $line['quantity'],
                'purchase_price' => $line['price'],
            ],
            OrderLineInput::parse($request, 'purchase_price'),
        );
    }
}
