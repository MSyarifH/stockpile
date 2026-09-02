<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuthenticatedUser;
use App\Entity\Role;
use App\Service\ReportService;
use App\Support\Exception\HttpException;
use App\Support\Exception\ValidationException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * REPORT-01. CSV export for a date range.
 */
final class ReportController
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly Session $session,
        private readonly View $view,
    ) {
    }

    public function index(Request $request): Response
    {
        $actor = $this->requireUser();

        return Response::html($this->view->renderInLayout('report.index', [
            'title' => 'Reports',
            'from' => $request->string('from', date('Y-m-01')),
            'to' => $request->string('to', date('Y-m-d')),
            'canExportStock' => $actor->is(Role::Admin, Role::WarehouseStaff),
            'error' => null,
        ]));
    }

    public function stockMovements(Request $request): Response
    {
        return $this->download(
            $request,
            fn (AuthenticatedUser $actor, string $from, string $to): array
                => $this->reports->stockMovements($actor, $from, $to),
        );
    }

    public function orderStatus(Request $request): Response
    {
        return $this->download(
            $request,
            fn (AuthenticatedUser $actor, string $from, string $to): array
                => $this->reports->orderStatus($actor, $from, $to),
        );
    }

    /**
     * @param callable(AuthenticatedUser,string,string):array{0:string,1:list<list<string>>} $build
     */
    private function download(Request $request, callable $build): Response
    {
        $actor = $this->requireUser();
        $from = $request->string('from', date('Y-m-01'));
        $to = $request->string('to', date('Y-m-d'));

        try {
            [$filename, $rows] = $build($actor, $from, $to);
        } catch (ValidationException $e) {
            return Response::html($this->view->renderInLayout('report.index', [
                'title' => 'Reports',
                'from' => $from,
                'to' => $to,
                'canExportStock' => $actor->is(Role::Admin, Role::WarehouseStaff),
                'error' => implode(' ', $e->errors()),
            ]), 422);
        }

        return Response::raw($this->reports->toCsv($rows), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            // The filename carries the date range, so two exports never
            // overwrite each other in the downloads folder.
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store',
        ]);
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
