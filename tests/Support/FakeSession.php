<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Entity\AuthenticatedUser;
use App\Support\Session;

/**
 * A Session that works in a CLI test process.
 *
 * Only two things make the real Session unusable here. start() calls
 * session_start(), which needs headers that do not exist outside a request,
 * and the signed-in user is read back out of $_SESSION after login()
 * regenerates the session ID -- also impossible in CLI.
 *
 * Everything else in Session is ordinary array access on $_SESSION, so
 * overriding those two is enough: flash messages, get/put and therefore the
 * CSRF token all keep their real behaviour rather than being faked. That
 * matters, because a controller test that faked the CSRF token would stop
 * proving anything about the token.
 */
final class FakeSession extends Session
{
    public function __construct(private readonly ?AuthenticatedUser $signedInAs = null)
    {
        $_SESSION = [];
    }

    public function start(): void
    {
        // No session_start(): $_SESSION is already a usable array in CLI.
    }

    public function user(): ?AuthenticatedUser
    {
        return $this->signedInAs;
    }
}
