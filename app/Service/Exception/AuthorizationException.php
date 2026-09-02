<?php

declare(strict_types=1);

namespace App\Service\Exception;

use RuntimeException;

/**
 * Raised by services, not controllers, so a rule holds identically for the HTML
 * form, the JSON API and any CLI script (§4.2 "authorization always on the server").
 */
final class AuthorizationException extends RuntimeException
{
}
