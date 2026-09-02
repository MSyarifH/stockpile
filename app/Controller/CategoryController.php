<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuthenticatedUser;
use App\Service\CategoryService;
use App\Support\Csrf;
use App\Support\Exception\HttpException;
use App\Support\Exception\ValidationException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;

final class CategoryController
{
    public function __construct(
        private readonly CategoryService $categories,
        private readonly Session $session,
        private readonly View $view,
        private readonly Csrf $csrf,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->requireUser();

        return Response::html($this->view->renderInLayout('category.index', [
            'title' => 'Categories',
            'categories' => $this->categories->list(),
        ]));
    }

    public function create(Request $request): Response
    {
        $this->requireUser();

        return Response::html($this->view->renderInLayout('category.form', [
            'title' => 'Add category',
            'csrfToken' => $this->csrf->token(),
            'editing' => null,
            'values' => ['name' => '', 'description' => ''],
            'errors' => [],
        ]));
    }

    public function store(Request $request): Response
    {
        $actor = $this->requireUser();
        $this->csrf->assertValid($request);

        try {
            $data = Validator::validate($request->all(), [
                'name' => 'required|max:120',
                'description' => 'optional|max:255',
            ]);
            $this->categories->create($actor, (string) $data['name'], (string) ($data['description'] ?? ''));
        } catch (ValidationException $e) {
            return $this->formWithErrors($request, $e->errors(), null);
        }

        $this->session->flash('success', 'Category created.');
        return Response::redirect('/categories');
    }

    public function edit(Request $request, string $id): Response
    {
        $this->requireUser();
        $category = $this->categories->find((int) $id);

        return Response::html($this->view->renderInLayout('category.form', [
            'title' => 'Edit category',
            'csrfToken' => $this->csrf->token(),
            'editing' => $category,
            'values' => ['name' => $category->name, 'description' => $category->description],
            'errors' => [],
        ]));
    }

    public function update(Request $request, string $id): Response
    {
        $actor = $this->requireUser();
        $this->csrf->assertValid($request);
        $category = $this->categories->find((int) $id);

        try {
            $data = Validator::validate($request->all(), [
                'name' => 'required|max:120',
                'description' => 'optional|max:255',
            ]);
            $this->categories->update($actor, (int) $id, (string) $data['name'], (string) ($data['description'] ?? ''));
        } catch (ValidationException $e) {
            return $this->formWithErrors($request, $e->errors(), $category);
        }

        $this->session->flash('success', 'Category updated.');
        return Response::redirect('/categories');
    }

    /** @param array<string,string> $errors */
    private function formWithErrors(Request $request, array $errors, ?\App\Entity\Category $editing): Response
    {
        return Response::html($this->view->renderInLayout('category.form', [
            'title' => $editing === null ? 'Add category' : 'Edit category',
            'csrfToken' => $this->csrf->token(),
            'editing' => $editing,
            'values' => [
                'name' => $request->string('name'),
                'description' => $request->string('description'),
            ],
            'errors' => $errors,
        ]), 422);
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
