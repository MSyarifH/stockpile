<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Role;
use App\Service\UserService;
use App\Support\Csrf;
use App\Support\Exception\HttpException;
use App\Support\Exception\ValidationException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;

/**
 * USR-01. Admin-only, but note the controller does not check that:
 * UserService does. The route guard is a coarse first filter only.
 */
final class UserController
{
    /** Every write action redirects back to the list (POST/Redirect/GET). */
    private const INDEX = '/users';

    public function __construct(
        private readonly UserService $users,
        private readonly Session $session,
        private readonly View $view,
        private readonly Csrf $csrf,
    ) {
    }

    public function index(): Response
    {
        $actor = $this->session->requireUser();

        return Response::html($this->view->renderInLayout('user.index', [
            'title' => 'Users',
            'user' => $actor,
            'users' => $this->users->list($actor),
        ]));
    }

    public function create(): Response
    {
        $actor = $this->session->requireUser();

        return Response::html($this->view->renderInLayout('user.form', [
            'title' => 'Add user',
            'user' => $actor,
            'csrfToken' => $this->csrf->token(),
            'editing' => null,
            'values' => ['name' => '', 'email' => '', 'role' => Role::Sales->value, 'is_active' => true],
            'errors' => [],
            'roles' => Role::all(),
        ]));
    }

    public function store(Request $request): Response
    {
        $actor = $this->session->requireUser();
        $this->csrf->assertValid($request);

        try {
            $data = Validator::validate($request->all(), [
                'name' => 'required|max_length:120',
                'email' => 'required|email|max_length:190',
                'role' => 'required|in:Admin,Sales,WarehouseStaff',
                'password' => 'required|max_length:200',
            ]);

            $this->users->create($actor, [
                'name' => (string) $data['name'],
                'email' => (string) $data['email'],
                'role' => (string) $data['role'],
                'password' => (string) $data['password'],
                'is_active' => $request->string('is_active') !== '',
            ]);
        } catch (ValidationException $e) {
            return $this->renderFormWithErrors($actor, $request, $e->errors(), null);
        }

        $this->session->flash('success', 'User created.');
        return Response::redirect(self::INDEX);
    }

    public function edit(Request $request, string $id): Response
    {
        $actor = $this->session->requireUser();
        $existing = $this->users->find($actor, (int) $id);

        return Response::html($this->view->renderInLayout('user.form', [
            'title' => 'Edit user',
            'user' => $actor,
            'csrfToken' => $this->csrf->token(),
            'editing' => $existing,
            'values' => [
                'name' => $existing->name,
                'email' => $existing->email,
                'role' => $existing->role->value,
                'is_active' => $existing->isActive,
            ],
            'errors' => [],
            'roles' => Role::all(),
        ]));
    }

    public function update(Request $request, string $id): Response
    {
        $actor = $this->session->requireUser();
        $this->csrf->assertValid($request);

        try {
            $data = Validator::validate($request->all(), [
                'name' => 'required|max_length:120',
                'email' => 'required|email|max_length:190',
                'role' => 'required|in:Admin,Sales,WarehouseStaff',
            ]);

            $this->users->update($actor, (int) $id, [
                'name' => (string) $data['name'],
                'email' => (string) $data['email'],
                'role' => (string) $data['role'],
                'is_active' => $request->string('is_active') !== '',
            ]);

            $password = $request->string('password');
            if ($password !== '') {
                $this->users->changePassword($actor, (int) $id, $password);
            }
        } catch (ValidationException $e) {
            return $this->renderFormWithErrors($actor, $request, $e->errors(), $this->users->find($actor, (int) $id));
        }

        $this->session->flash('success', 'User updated.');
        return Response::redirect(self::INDEX);
    }

    public function toggleActive(Request $request, string $id): Response
    {
        $actor = $this->session->requireUser();
        $this->csrf->assertValid($request);

        $activate = $request->string('activate') === '1';
        $this->users->setActive($actor, (int) $id, $activate);

        $this->session->flash('success', $activate ? 'User activated.' : 'User deactivated.');
        return Response::redirect(self::INDEX);
    }

    /**
     * @param array<string,string> $errors
     */
    private function renderFormWithErrors(
        \App\Entity\AuthenticatedUser $actor,
        Request $request,
        array $errors,
        ?\App\Entity\User $editing,
    ): Response {
        return Response::html($this->view->renderInLayout('user.form', [
            'title' => $editing === null ? 'Add user' : 'Edit user',
            'user' => $actor,
            'csrfToken' => $this->csrf->token(),
            'editing' => $editing,
            // Submitted values are echoed back so nothing typed is lost (VAL-01).
            'values' => [
                'name' => $request->string('name'),
                'email' => $request->string('email'),
                'role' => $request->string('role'),
                'is_active' => $request->string('is_active') !== '',
            ],
            'errors' => $errors,
            'roles' => Role::all(),
        ]), 422);
    }
}
