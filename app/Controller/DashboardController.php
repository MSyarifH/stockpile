<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Role;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * DASH-01, first slice.
 *
 * Each role currently sees only its own landing page; the aggregation figures
 * arrive in Phase 7 once there is transactional data to aggregate. The role
 * routing is here from the start because every later page hangs off it.
 */
final class DashboardController
{
    public function __construct(
        private readonly Session $session,
        private readonly View $view,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->session->user();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $template = match ($user->role) {
            Role::Admin => 'dashboard.admin',
            Role::Sales => 'dashboard.sales',
            Role::WarehouseStaff => 'dashboard.warehouse',
        };

        return Response::html($this->view->renderInLayout($template, [
            'title' => 'Dashboard',
            'user' => $user,
        ]));
    }
}
