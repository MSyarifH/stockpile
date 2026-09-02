<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuthenticatedUser;
use App\Repository\UserRepository;
use App\Service\Exception\AuthenticationException;

/**
 * Authentication rules. Contains no session and no HTTP: it answers only
 * "do these credentials identify an account that is allowed to sign in?"
 *
 * Storing the session is the caller's job, which is what lets this be unit-tested.
 */
final class AuthService
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * @throws AuthenticationException when the credentials are wrong OR the account is inactive
     */
    public function attempt(string $email, string $password): AuthenticatedUser
    {
        $user = $this->users->findByEmail($email);

        if ($user === null) {
            // Hash anyway so a missing account and a wrong password take a similar
            // amount of time. Otherwise response timing reveals which emails exist.
            password_verify($password, '$2y$10$usesomesillystringfoeindeedsillystringjustforthetiming');
            throw AuthenticationException::invalidCredentials();
        }

        if (!password_verify($password, $user->passwordHash)) {
            throw AuthenticationException::invalidCredentials();
        }

        // Same message as a wrong password: an inactive account should not be
        // distinguishable from a non-existent one.
        if (!$user->isActive) {
            throw AuthenticationException::invalidCredentials();
        }

        return $user->toAuthenticated();
    }
}
