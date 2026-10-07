<?php

declare(strict_types=1);

namespace App\Support\Exception;

use RuntimeException;

/**
 * A view was asked for a template file that does not exist.
 *
 * Always a programming error -- a mistyped template name or a view deleted
 * without its caller -- never something a user can cause, because template
 * names are never taken from the request. It is dedicated rather than a plain
 * RuntimeException so the failure handler can tell "the developer named a
 * template wrong" apart from any other runtime fault, which reach the browser
 * as the same generic 500.
 */
final class TemplateNotFoundException extends RuntimeException
{
    public function __construct(string $template)
    {
        parent::__construct(sprintf('Template "%s" was not found.', $template));
    }
}
