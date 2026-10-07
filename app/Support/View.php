<?php

declare(strict_types=1);

namespace App\Support;

use App\Entity\AuthenticatedUser;
use App\Support\Exception\TemplateNotFoundException;

/**
 * Minimal template renderer over plain PHP files.
 *
 * Templates receive an `$e()` helper and are expected to use it for every value that
 * came from a user or the database (§4.2 output escaping), and an `$icon()` helper
 * for the self-hosted icon sprite.
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
            throw new TemplateNotFoundException($template);
        }

        $data = array_merge($this->shared, $data);
        $data['e'] = static fn (mixed $value): string
            => htmlspecialchars((string) (is_scalar($value) ? $value : ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $data['view'] = $this;

        // Renders one icon from the sprite inlined by views/partial/icon-sprite.php,
        // which every full page includes once (see scripts/build-icon-sprite.py).
        // Decorative by default: an icon that
        // sits next to its own text label is hidden from assistive technology,
        // because announcing "package Products" reads worse than "Products".
        // Pass $label when the icon is the only thing in a control.
        $data['icon'] = static function (string $name, ?string $label = null): string {
            $name = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $attributes = $label === null
                ? ' aria-hidden="true"'
                : sprintf(
                    ' role="img" aria-label="%s"',
                    htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                );

            return sprintf(
                '<svg class="icon"%s><use href="#i-%s"></use></svg>',
                $attributes,
                $name,
            );
        };

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
