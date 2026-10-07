<?php

declare(strict_types=1);

namespace App\Support;

use App\Entity\AuthenticatedUser;
use App\Entity\Role;
use App\Support\Exception\HttpException;

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
            // Sent over HTTPS only -- but conditionally, because a cookie marked
            // secure is never sent over plain HTTP at all. Hard-coding true would
            // make the session silently stop working on the http://localhost:8080
            // the setup instructions tell an assessor to use: they would log in,
            // get redirected, and appear logged out. The flag therefore follows
            // the scheme actually in use, including behind a TLS-terminating
            // proxy, which is the only form of this that is both safe and honest.
            'secure' => self::isHttps($_SERVER),
        ]);
        session_start();
    }

    /**
     * True when the current request reached us over TLS, directly or via a proxy.
     *
     * The server array is a parameter rather than read from $_SERVER inside,
     * which makes this a pure function of its input. start() cannot be unit
     * tested -- it calls session_start() -- but the rule that decides whether a
     * login cookie is marked secure is security-relevant enough to deserve
     * tests of its own, and this is what lets it have them.
     *
     * @param array<string,mixed> $server
     */
    public static function isHttps(array $server): bool
    {
        $https = $server['HTTPS'] ?? '';
        if ($https !== '' && strtolower((string) $https) !== 'off') {
            return true;
        }

        return strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
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

    /**
     * The signed-in user, or a 401 if there is none.
     *
     * Extracted from eight identical private helpers in the controllers
     * (see docs/quality/refactor-log.md). It belongs here because Session is
     * already the only class that knows how identity is stored; asking each
     * controller to translate "no session" into an HTTP status was duplication
     * with nothing to gain.
     */
    public function requireUser(): AuthenticatedUser
    {
        $user = $this->user();
        if ($user === null) {
            throw HttpException::unauthorised();
        }

        return $user;
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
