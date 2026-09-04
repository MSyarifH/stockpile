<?php

declare(strict_types=1);

namespace App\Support\Exception;

use RuntimeException;

/**
 * Carries an HTTP status through the layers so ERR-01 can be honoured centrally:
 * one place decides how a failure is presented, and stack traces never reach a user.
 */
final class HttpException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public static function notFound(string $message = 'The page you requested does not exist.'): self
    {
        return new self($message, 404);
    }

    public static function forbidden(string $message = 'You do not have permission to do that.'): self
    {
        return new self($message, 403);
    }

    public static function unauthorised(string $message = 'You must sign in first.'): self
    {
        return new self($message, 401);
    }

    public static function badRequest(string $message = 'The request was not valid.'): self
    {
        return new self($message, 400);
    }

    public static function payloadTooLarge(string $message = 'The data you sent was too large.'): self
    {
        return new self($message, 413);
    }
}
