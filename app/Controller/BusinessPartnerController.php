<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuthenticatedUser;
use App\Entity\BusinessPartner;
use App\Entity\PartnerType;
use App\Service\BusinessPartnerService;
use App\Support\Csrf;
use App\Support\Exception\HttpException;
use App\Support\Exception\ValidationException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;

/**
 * One controller drives both /suppliers and /customers. The type is fixed by
 * the route, never taken from user input, so a request cannot switch which
 * table it operates on.
 */
final class BusinessPartnerController
{
    public function __construct(
        private readonly BusinessPartnerService $partners,
        private readonly Session $session,
        private readonly View $view,
        private readonly Csrf $csrf,
    ) {
    }

    public function index(PartnerType $type): Response
    {
        $this->session->requireUser();

        return Response::html($this->view->renderInLayout('partner.index', [
            'title' => $type->pluralLabel(),
            'type' => $type,
            'partners' => $this->partners->list($type),
        ]));
    }

    public function create(PartnerType $type): Response
    {
        $this->session->requireUser();

        return Response::html($this->view->renderInLayout('partner.form', [
            'title' => 'Add ' . strtolower($type->label()),
            'type' => $type,
            'csrfToken' => $this->csrf->token(),
            'editing' => null,
            'values' => ['name' => '', 'contact' => '', 'address' => '', 'is_active' => true],
            'errors' => [],
        ]));
    }

    public function store(Request $request, PartnerType $type): Response
    {
        $actor = $this->session->requireUser();
        $this->csrf->assertValid($request);

        try {
            $this->partners->create($actor, $type, $this->validate($request));
        } catch (ValidationException $e) {
            return $this->formWithErrors($request, $type, $e->errors(), null);
        }

        $this->session->flash('success', $type->label() . ' created.');
        return Response::redirect('/' . $type->urlSegment());
    }

    public function edit(PartnerType $type, string $id): Response
    {
        $this->session->requireUser();
        $partner = $this->partners->find($type, (int) $id);

        return Response::html($this->view->renderInLayout('partner.form', [
            'title' => 'Edit ' . strtolower($type->label()),
            'type' => $type,
            'csrfToken' => $this->csrf->token(),
            'editing' => $partner,
            'values' => [
                'name' => $partner->name,
                'contact' => $partner->contact,
                'address' => $partner->address,
                'is_active' => $partner->isActive,
            ],
            'errors' => [],
        ]));
    }

    public function update(Request $request, PartnerType $type, string $id): Response
    {
        $actor = $this->session->requireUser();
        $this->csrf->assertValid($request);
        $partner = $this->partners->find($type, (int) $id);

        try {
            $this->partners->update($actor, $type, (int) $id, $this->validate($request));
        } catch (ValidationException $e) {
            return $this->formWithErrors($request, $type, $e->errors(), $partner);
        }

        $this->session->flash('success', $type->label() . ' updated.');
        return Response::redirect('/' . $type->urlSegment());
    }

    public function toggleActive(Request $request, PartnerType $type, string $id): Response
    {
        $actor = $this->session->requireUser();
        $this->csrf->assertValid($request);

        $activate = $request->string('activate') === '1';
        $this->partners->setActive($actor, $type, (int) $id, $activate);

        $this->session->flash('success', $activate ? $type->label() . ' activated.' : $type->label() . ' deactivated.');
        return Response::redirect('/' . $type->urlSegment());
    }

    /** @return array{name:string,contact:string,address:string,is_active:bool} */
    private function validate(Request $request): array
    {
        $data = Validator::validate($request->all(), [
            'name' => 'required|max:150',
            'contact' => 'optional|max:120',
            'address' => 'optional|max:255',
        ]);

        return [
            'name' => (string) $data['name'],
            'contact' => (string) ($data['contact'] ?? ''),
            'address' => (string) ($data['address'] ?? ''),
            'is_active' => $request->string('is_active') !== '',
        ];
    }

    /** @param array<string,string> $errors */
    private function formWithErrors(
        Request $request,
        PartnerType $type,
        array $errors,
        ?BusinessPartner $editing,
    ): Response {
        return Response::html($this->view->renderInLayout('partner.form', [
            'title' => ($editing === null ? 'Add ' : 'Edit ') . strtolower($type->label()),
            'type' => $type,
            'csrfToken' => $this->csrf->token(),
            'editing' => $editing,
            'values' => [
                'name' => $request->string('name'),
                'contact' => $request->string('contact'),
                'address' => $request->string('address'),
                'is_active' => $request->string('is_active') !== '',
            ],
            'errors' => $errors,
        ]), 422);
    }
}
