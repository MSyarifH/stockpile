<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\UserService;
use App\Support\Csrf;
use App\Support\Exception\ValidationException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * §1.2 grants every role "profil sendiri" — the ability to see their own
 * account and change their own password.
 *
 * No id is ever read from the request: the account acted on is always the one
 * in the session, so there is no object to authorise and nothing to tamper with.
 */
final class ProfileController
{
    public function __construct(
        private readonly UserService $users,
        private readonly Session $session,
        private readonly View $view,
        private readonly Csrf $csrf,
    ) {
    }

    public function show(Request $request): Response
    {
        $actor = $this->session->requireUser();

        return Response::html($this->view->renderInLayout('profile.show', [
            'title' => 'My profile',
            'account' => $this->users->ownProfile($actor),
            'csrfToken' => $this->csrf->token(),
            'errors' => [],
        ]));
    }

    public function changePassword(Request $request): Response
    {
        $actor = $this->session->requireUser();
        $this->csrf->assertValid($request);

        try {
            $this->users->changeOwnPassword(
                $actor,
                $request->string('current_password'),
                $request->string('password'),
            );
        } catch (ValidationException $e) {
            return Response::html($this->view->renderInLayout('profile.show', [
                'title' => 'My profile',
                'account' => $this->users->ownProfile($actor),
                'csrfToken' => $this->csrf->token(),
                'errors' => $e->errors(),
            ]), 422);
        }

        // The session id is regenerated for the same reason it is at login: the
        // credentials backing this session have changed, so any id captured
        // beforehand must stop being useful.
        $this->session->login($actor);
        $this->session->flash('success', 'Your password has been changed.');

        return Response::redirect('/profile');
    }
}
