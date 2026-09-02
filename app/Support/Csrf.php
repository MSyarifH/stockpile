<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Exception\HttpException;

/**
 * Synchroniser token. Every state-changing POST carries one.
 *
 * SameSite=Lax already blocks most cross-site POSTs, but it is a browser
 * behaviour, not a server guarantee. This check is the server's own.
 */
final class Csrf
{
    private const KEY = 'csrf.token';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->put(self::KEY, $token);
        }
        return $token;
    }

    public function assertValid(Request $request): void
    {
        $submitted = $request->string('_token');
        $expected = $this->token();

        // hash_equals, not ===, so comparison time does not leak the token.
        if ($submitted === '' || !hash_equals($expected, $submitted)) {
            throw HttpException::forbidden('Invalid or missing security token. Please try again.');
        }
    }
}
