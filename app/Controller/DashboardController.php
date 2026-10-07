<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Role;
use App\Service\DashboardService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * DASH-01. Each role gets its own figures, and the role decides which QUERY
 * runs — not merely which numbers are displayed.
 */
final class DashboardController
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly Session $session,
        private readonly View $view,
    ) {
    }

    public function index(): Response
    {
        $user = $this->session->user();
        if ($user === null) {
            return Response::redirect('/login');
        }

        [$template, $data] = match ($user->role) {
            Role::Admin => ['dashboard.admin', $this->dashboard->forAdmin()],
            Role::Sales => ['dashboard.sales', $this->dashboard->forSales($user)],
            Role::WarehouseStaff => ['dashboard.warehouse', $this->dashboard->forWarehouse()],
        };

        return Response::html($this->view->renderInLayout($template, $data + [
            'title' => 'Dashboard',
            'user' => $user,
            'salesStatuses' => $this->dashboard->salesStatuses(),
            'purchaseStatuses' => $this->dashboard->purchaseStatuses(),
        ]));
    }
}
