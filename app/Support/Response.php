<?php

declare(strict_types=1);

namespace App\Support;

final class Response
{
    /** @param array<string,string> $headers */
    private function __construct(
        private readonly string $body,
        private readonly int $status,
        private readonly array $headers,
    ) {
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
        echo $this->body;
    }
}
