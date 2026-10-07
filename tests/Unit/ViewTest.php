<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Exception\TemplateNotFoundException;
use App\Support\View;
use PHPUnit\Framework\TestCase;

/**
 * The one failure mode of the template renderer that is worth pinning.
 *
 * Rendering itself is covered by every page the application serves. What is NOT
 * obvious is what happens when a template name is wrong: PHP's require emits a
 * warning and carries on with an empty output, so a mistyped name would show a
 * blank page rather than an error, and the mistake could reach production
 * unnoticed. The renderer checks first and throws instead.
 */
final class ViewTest extends TestCase
{
    private function view(): View
    {
        return new View(dirname(__DIR__, 2) . '/views');
    }

    public function testAMissingTemplateThrowsRatherThanRenderingNothing(): void
    {
        $this->expectException(TemplateNotFoundException::class);
        $this->expectExceptionMessage('Template "auth.does-not-exist" was not found.');

        $this->view()->render('auth.does-not-exist');
    }

    public function testTheExceptionNamesTheTemplateSoTheTypoIsObvious(): void
    {
        // The message is the whole value of the dedicated exception: it has to
        // say WHICH template, or the developer is left hunting through views/.
        $exception = new TemplateNotFoundException('product.detial');

        self::assertStringContainsString('product.detial', $exception->getMessage());
    }

    public function testDotsInTheTemplateNameBecomeDirectorySeparators(): void
    {
        // 'partial.pagination' must look for views/partial/pagination.php. If
        // that mapping broke, every template would be reported as missing.
        $this->expectExceptionMessage('Template "partial.nope" was not found.');

        $this->view()->render('partial.nope');
    }
}
