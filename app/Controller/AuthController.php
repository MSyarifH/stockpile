<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AuthService;
use App\Service\Exception\AuthenticationException;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * AUTH-01 / AUTH-02.
 *
 * The controller decides only what an HTTP client sees. Whether the credentials
 * are acceptable is AuthService's decision, and storing the identity is Session's.
 */
final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly Session $session,
        private readonly View $view,
        private readonly Csrf $csrf,
    ) {
    }

    public function showLogin(): Response
    {
        if ($this->session->isAuthenticated()) {
            return Response::redirect('/dashboard');
        }

        return Response::html($this->view->render('auth.login', [
            'title' => 'Sign in',
            'csrfToken' => $this->csrf->token(),
            'email' => '',
            'error' => null,
        ]));
    }

    public function login(Request $request): Response
    {
        $this->csrf->assertValid($request);

        $email = $request->string('email');
        $password = $request->string('password');

        try {
            $user = $this->auth->attempt($email, $password);
        } catch (AuthenticationException $e) {
            // 401 with the form re-rendered. The email is kept so the user does not
            // retype it (VAL-01); the password never is.
            return Response::html($this->view->render('auth.login', [
                'title' => 'Sign in',
                'csrfToken' => $this->csrf->token(),
                'email' => $email,
                'error' => $e->getMessage(),
            ]), 401);
        }

        $this->session->login($user);
        $this->session->flash('success', 'Welcome back, ' . $user->name . '.');

        return Response::redirect('/dashboard');
    }

    public function logout(Request $request): Response
    {
        $this->csrf->assertValid($request);
        $this->session->logout();

        return Response::redirect('/login');
    }
}
