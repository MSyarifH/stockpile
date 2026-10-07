<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuthenticatedUser;
use App\Entity\Warehouse;
use App\Service\WarehouseService;
use App\Support\Csrf;
use App\Support\Exception\HttpException;
use App\Support\Exception\ValidationException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;

final class WarehouseController
{
    /** Every write action redirects back to the list (POST/Redirect/GET). */
    private const INDEX = '/warehouses';

    public function __construct(
        private readonly WarehouseService $warehouses,
        private readonly Session $session,
        private readonly View $view,
        private readonly Csrf $csrf,
    ) {
    }

    public function index(): Response
    {
        $this->session->requireUser();

        return Response::html($this->view->renderInLayout('warehouse.index', [
            'title' => 'Warehouses',
            'warehouses' => $this->warehouses->list(),
        ]));
    }

    public function create(): Response
    {
        $this->session->requireUser();

        return Response::html($this->view->renderInLayout('warehouse.form', [
            'title' => 'Add warehouse',
            'csrfToken' => $this->csrf->token(),
            'editing' => null,
            'values' => ['name' => '', 'location' => '', 'is_active' => true],
            'errors' => [],
        ]));
    }

    public function store(Request $request): Response
    {
        $actor = $this->session->requireUser();
        $this->csrf->assertValid($request);

        try {
            $data = Validator::validate($request->all(), [
                'name' => 'required|max_length:120',
                'location' => 'required|max_length:190',
            ]);
            $this->warehouses->create(
                $actor,
                (string) $data['name'],
                (string) $data['location'],
                $request->string('is_active') !== '',
            );
        } catch (ValidationException $e) {
            return $this->formWithErrors($request, $e->errors(), null);
        }

        $this->session->flash('success', 'Warehouse created. Stock rows added for every product.');
        return Response::redirect(self::INDEX);
    }

    public function edit(Request $request, string $id): Response
    {
        $this->session->requireUser();
        $warehouse = $this->warehouses->find((int) $id);

        return Response::html($this->view->renderInLayout('warehouse.form', [
            'title' => 'Edit warehouse',
            'csrfToken' => $this->csrf->token(),
            'editing' => $warehouse,
            'values' => [
                'name' => $warehouse->name,
                'location' => $warehouse->location,
                'is_active' => $warehouse->isActive,
            ],
            'errors' => [],
        ]));
    }

    public function update(Request $request, string $id): Response
    {
        $actor = $this->session->requireUser();
        $this->csrf->assertValid($request);
        $warehouse = $this->warehouses->find((int) $id);

        try {
            $data = Validator::validate($request->all(), [
                'name' => 'required|max_length:120',
                'location' => 'required|max_length:190',
            ]);
            $this->warehouses->update(
                $actor,
                (int) $id,
                (string) $data['name'],
                (string) $data['location'],
                $request->string('is_active') !== '',
            );
        } catch (ValidationException $e) {
            return $this->formWithErrors($request, $e->errors(), $warehouse);
        }

        $this->session->flash('success', 'Warehouse updated.');
        return Response::redirect(self::INDEX);
    }

    public function toggleActive(Request $request, string $id): Response
    {
        $actor = $this->session->requireUser();
        $this->csrf->assertValid($request);

        $activate = $request->string('activate') === '1';
        $this->warehouses->setActive($actor, (int) $id, $activate);

        $this->session->flash('success', $activate ? 'Warehouse activated.' : 'Warehouse deactivated.');
        return Response::redirect(self::INDEX);
    }

    /** @param array<string,string> $errors */
    private function formWithErrors(Request $request, array $errors, ?Warehouse $editing): Response
    {
        return Response::html($this->view->renderInLayout('warehouse.form', [
            'title' => $editing === null ? 'Add warehouse' : 'Edit warehouse',
            'csrfToken' => $this->csrf->token(),
            'editing' => $editing,
            'values' => [
                'name' => $request->string('name'),
                'location' => $request->string('location'),
                'is_active' => $request->string('is_active') !== '',
            ],
            'errors' => $errors,
        ]), 422);
    }
}
