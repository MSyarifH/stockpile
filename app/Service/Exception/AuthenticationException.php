<?php

declare(strict_types=1);

namespace App\Service\Exception;

use RuntimeException;

/**
 * Deliberately carries ONE message for every failure mode.
 *
 * AUTH-01 requires that a failed login must not reveal which part was wrong.
 * Distinguishing "no such account" from "wrong password" hands an attacker a
 * way to enumerate valid email addresses.
 */
final class AuthenticationException extends RuntimeException
{
    public static function invalidCredentials(): self
    {
        return new self('The email address or password is incorrect.');
    }
}
