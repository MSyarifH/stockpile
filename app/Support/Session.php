<?php

declare(strict_types=1);

namespace App\Support;

use App\Entity\AuthenticatedUser;
use App\Entity\Role;

/**
 * Session wrapper. The only place that touches $_SESSION.
 */
class Session
{
    private const USER_ID = 'auth.user_id';
    private const USER_NAME = 'auth.user_name';
    private const USER_ROLE = 'auth.user_role';
    private const FLASH = 'flash';

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'httponly' => true,   // not readable from JavaScript
            'samesite' => 'Lax',  // blunts cross-site request forgery
            'path' => '/',
        ]);
        session_start();
    }

    /**
     * AUTH-01 requires the session ID to change after login. Without this, an ID an
     * attacker planted before login would remain valid afterwards (session fixation).
     */
    public function login(AuthenticatedUser $user): void
    {
        $this->start();
        session_regenerate_id(true);
        $_SESSION[self::USER_ID] = $user->id;
        $_SESSION[self::USER_NAME] = $user->name;
        $_SESSION[self::USER_ROLE] = $user->role->value;
    }

    public function logout(): void
    {
        $this->start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public function user(): ?AuthenticatedUser
    {
        $this->start();
        $id = $_SESSION[self::USER_ID] ?? null;
        $name = $_SESSION[self::USER_NAME] ?? null;
        $role = $_SESSION[self::USER_ROLE] ?? null;

        if (!is_int($id) || !is_string($name) || !is_string($role)) {
            return null;
        }

        $parsed = Role::tryFrom($role);
        return $parsed === null ? null : new AuthenticatedUser($id, $name, $parsed);
    }

    public function isAuthenticated(): bool
    {
        return $this->user() !== null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();
        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function flash(string $type, string $message): void
    {
        $this->start();
        $messages = $_SESSION[self::FLASH] ?? [];
        $messages[] = ['type' => $type, 'message' => $message];
        $_SESSION[self::FLASH] = $messages;
    }

    /** @return list<array{type:string,message:string}> */
    public function takeFlash(): array
    {
        $this->start();
        /** @var list<array{type:string,message:string}> $messages */
        $messages = $_SESSION[self::FLASH] ?? [];
        unset($_SESSION[self::FLASH]);
        return $messages;
    }
}
