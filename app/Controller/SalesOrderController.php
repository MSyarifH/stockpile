<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuthenticatedUser;
use App\Entity\PartnerType;
use App\Entity\SalesOrderStatus;
use App\Repository\OrderFilter;
use App\Service\BusinessPartnerService;
use App\Service\Exception\InsufficientStockException;
use App\Service\ProductService;
use App\Service\SalesOrderService;
use App\Service\WarehouseService;
use App\Support\Csrf;
use App\Support\Exception\HttpException;
use App\Support\Exception\ValidationException;
use App\Support\Request;
use App\Support\Response;
use App\Support\QueryString;
use App\Support\Session;
use App\Support\View;

/**
 * SO-01. The controller performs no authorization of its own beyond the coarse
 * route guard — every rule is asserted by SalesOrderService.
 */
final class SalesOrderController
{
    public function __construct(
        private readonly SalesOrderService $orders,
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
        $status = SalesOrderStatus::tryFrom($request->string('status'));
        $filter = new OrderFilter(
            $request->string('q'),
            $status?->value,
            $request->string('sort') === OrderFilter::SORT_ASC ? OrderFilter::SORT_ASC : OrderFilter::SORT_DESC,
        );

        return Response::html($this->view->renderInLayout('sales.index', [
            'title' => 'Sales orders',
            'page' => $this->orders->search($actor, $filter, $request->integer('page', 1)),
            'filter' => $filter,
            'statuses' => SalesOrderStatus::cases(),
            'query' => new QueryString($request->all()),
        ]));
    }

    public function show(Request $request, string $id): Response
    {
        $actor = $this->requireUser();

        return Response::html($this->view->renderInLayout('sales.show', [
            'title' => 'Sales order',
            'order' => $this->orders->find($actor, (int) $id),
        ]));
    }

    public function create(Request $request): Response
    {
        $actor = $this->requireUser();

        return Response::html($this->view->renderInLayout('sales.form', [
            'title' => 'New sales order',
            'csrfToken' => $this->csrf->token(),
            'customers' => $this->partners->list(PartnerType::Customer, true),
            'warehouses' => $this->warehouses->list(true),
            'products' => $this->products->list($actor, true),
            'values' => ['customer_id' => '', 'warehouse_id' => '', 'order_date' => date('Y-m-d')],
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
                $request->integer('customer_id'),
                $request->integer('warehouse_id'),
                $request->string('order_date'),
                $this->linesFrom($request),
            );
        } catch (ValidationException $e) {
            return Response::html($this->view->renderInLayout('sales.form', [
                'title' => 'New sales order',
                'csrfToken' => $this->csrf->token(),
                'customers' => $this->partners->list(PartnerType::Customer, true),
                'warehouses' => $this->warehouses->list(true),
                'products' => $this->products->list($actor, true),
                'values' => [
                    'customer_id' => $request->string('customer_id'),
                    'warehouse_id' => $request->string('warehouse_id'),
                    'order_date' => $request->string('order_date'),
                ],
                'errors' => $e->errors(),
            ]), 422);
        }

        $this->session->flash('success', 'Sales order saved as a draft.');
        return Response::redirect('/sales-orders/' . $id);
    }

    public function submit(Request $request, string $id): Response
    {
        return $this->transition($request, (int) $id, function (AuthenticatedUser $actor, int $orderId): string {
            $this->orders->submit($actor, $orderId);
            return 'Sales order submitted for approval.';
        });
    }

    public function approve(Request $request, string $id): Response
    {
        return $this->transition($request, (int) $id, function (AuthenticatedUser $actor, int $orderId): string {
            $this->orders->approve($actor, $orderId);
            return 'Sales order approved.';
        });
    }

    public function reject(Request $request, string $id): Response
    {
        return $this->transition($request, (int) $id, function (AuthenticatedUser $actor, int $orderId): string {
            $this->orders->reject($actor, $orderId);
            return 'Sales order sent back to draft.';
        });
    }

    public function cancel(Request $request, string $id): Response
    {
        return $this->transition($request, (int) $id, function (AuthenticatedUser $actor, int $orderId): string {
            $this->orders->cancel($actor, $orderId);
            return 'Sales order cancelled.';
        });
    }

    public function issue(Request $request, string $id): Response
    {
        return $this->transition($request, (int) $id, function (AuthenticatedUser $actor, int $orderId): string {
            $this->orders->issueGoods($actor, $orderId);
            return 'Goods issued and stock updated.';
        });
    }

    /**
     * Shared shape for every state change: verify CSRF, run the service, turn a
     * domain failure into a message rather than an error page.
     *
     * InsufficientStockException is caught here specifically because it is an
     * expected business outcome (decision D3 allows an approved order to fail at
     * issue), not a fault.
     *
     * @param callable(AuthenticatedUser,int):string $action
     */
    private function transition(Request $request, int $id, callable $action): Response
    {
        $actor = $this->requireUser();
        $this->csrf->assertValid($request);

        try {
            $message = $action($actor, $id);
            $this->session->flash('success', $message);
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors()));
        } catch (InsufficientStockException $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return Response::redirect('/sales-orders/' . $id);
    }

    /**
     * @return list<array{product_id:int,quantity:int,selling_price:float}>
     */
    private function linesFrom(Request $request): array
    {
        $raw = $request->input('items', []);
        if (!is_array($raw)) {
            return [];
        }

        $productIds = is_array($raw['product_id'] ?? null) ? $raw['product_id'] : [];
        $quantities = is_array($raw['quantity'] ?? null) ? $raw['quantity'] : [];
        $prices = is_array($raw['selling_price'] ?? null) ? $raw['selling_price'] : [];

        $lines = [];
        foreach ($productIds as $index => $productId) {
            if (!is_numeric($productId) || (int) $productId <= 0) {
                continue;
            }
            $lines[] = [
                'product_id' => (int) $productId,
                'quantity' => (int) ($quantities[$index] ?? 0),
                'selling_price' => (float) ($prices[$index] ?? 0),
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
