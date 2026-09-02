<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Read-only view of the HTTP request.
 *
 * Exists so that no Controller or Service reads $_GET/$_POST/$_SERVER directly.
 * That is what makes the layers below testable without a web server (ARCH-01).
 */
final class Request
{
    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     * @param array<string,mixed> $server
     * @param array<string,mixed> $files
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $server = [],
        private readonly array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        /** @var string $uri */
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            is_string($path) ? rtrim($path, '/') ?: '/' : '/',
            $_GET,
            $_POST,
            $_SERVER,
            $_FILES,
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function integer(string $key, int $default = 0): int
    {
        $value = $this->input($key);
        return is_numeric($value) ? (int) $value : $default;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->body + $this->query;
    }

    /** @return array<string,mixed>|null */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        return is_array($file) ? $file : null;
    }

    public function wantsJson(): bool
    {
        $accept = (string) ($this->server['HTTP_ACCEPT'] ?? '');
        return str_starts_with($this->path, '/api/') || str_contains($accept, 'application/json');
    }
}
