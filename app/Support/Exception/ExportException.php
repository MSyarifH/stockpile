<?php

declare(strict_types=1);

namespace App\Support\Exception;

use RuntimeException;

/**
 * A CSV export could not be written.
 *
 * In practice this means a PHP stream (php://temp or php://output) could not be
 * opened -- an environment failure such as an exhausted file-descriptor limit,
 * not anything the request did. Dedicated rather than generic so a caller can
 * catch "the export failed" without also catching every other RuntimeException
 * in the call stack.
 */
final class ExportException extends RuntimeException
{
    public static function streamUnavailable(string $stream): self
    {
        return new self(sprintf('Could not open %s for the CSV export.', $stream));
    }
}
