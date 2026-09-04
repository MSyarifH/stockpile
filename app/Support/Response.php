<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

/**
 * An HTTP response that the front controller sends after the route handler returns.
 *
 * Two flavours exist:
 *  1. Buffered (the default) — body is a string held in memory.
 *  2. Streamed — body is a callback that writes directly to php://output.
 *     Used by CSV exports so the file is flushed row-by-row and never
 *     buffered as a single multi-megabyte string (OOM prevention).
 */
final class Response
{
    /** @var (Closure():void)|null */
    private ?Closure $streamCallback;

    /** @param array<string,string> $headers */
    private function __construct(
        private readonly string $body,
        private readonly int $status,
        private readonly array $headers,
        ?Closure $streamCallback = null,
    ) {
        $this->streamCallback = $streamCallback;
    }

    /** @param array<string,string> $headers */
    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, $headers + ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * API-01 requires a JSON body and an accurate status code, never an HTML error page.
     */
    public static function json(mixed $data, int $status = 200): self
    {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return new self($encoded, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self('', $status, ['Location' => $to]);
    }

    /** @param array<string,string> $headers */
    public static function raw(string $body, int $status, array $headers): self
    {
        return new self($body, $status, $headers);
    }

    /**
     * A response whose body is produced by a callback writing directly to
     * php://output, so no intermediate string is ever held in memory.
     *
     * @param callable():void       $callback  writes the body bytes
     * @param array<string,string>  $headers
     */
    public static function streamed(callable $callback, int $status, array $headers): self
    {
        return new self('', $status, $headers, Closure::fromCallable($callback));
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($this->streamCallback !== null) {
            ($this->streamCallback)();
        } else {
            echo $this->body;
        }
    }
}
