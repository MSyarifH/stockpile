<?php

declare(strict_types=1);

namespace App\Support;

use App\Entity\AuthenticatedUser;
use RuntimeException;

/**
 * Minimal template renderer over plain PHP files.
 *
 * Templates receive an `$e()` helper and are expected to use it for every value that
 * came from a user or the database (§4.2 output escaping).
 */
final class View
{
    /** @var array<string,mixed> */
    private array $shared = [];

    public function __construct(private readonly string $templatePath)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $file = $this->templatePath . '/' . str_replace('.', '/', $template) . '.php';
        if (!is_file($file)) {
            throw new RuntimeException(sprintf('Template "%s" was not found.', $template));
        }

        $data = array_merge($this->shared, $data);
        $data['e'] = static fn (mixed $value): string
            => htmlspecialchars((string) (is_scalar($value) ? $value : ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $data['view'] = $this;

        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }

    /** @param array<string,mixed> $data */
    public function renderInLayout(string $template, array $data = [], string $layout = 'layout.app'): string
    {
        $content = $this->render($template, $data);
        return $this->render($layout, $data + ['content' => $content]);
    }
}
