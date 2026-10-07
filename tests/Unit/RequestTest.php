<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Path normalisation in Request::fromGlobals().
 *
 * The router matches on an exact string, so /products and /products/ must
 * become the same path or half the links in the application 404 depending on
 * whether someone typed a trailing slash. The awkward case is "/" itself:
 * rtrim('/', '/') is the empty string, and an empty path matches no route at
 * all -- the home page would 404 while every other page worked.
 */
final class RequestTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    /** @return array<string,array{string,string}> */
    public static function uris(): array
    {
        return [
            'root' => ['/', '/'],
            'root keeps its slash' => ['/', '/'],
            'plain path' => ['/products', '/products'],
            'trailing slash is dropped' => ['/products/', '/products'],
            'nested path' => ['/sales-orders/11', '/sales-orders/11'],
            'nested with trailing slash' => ['/sales-orders/11/', '/sales-orders/11'],
            'query string is not part of the path' => ['/products?page=2', '/products'],
            'trailing slash before a query string' => ['/products/?page=2', '/products'],
        ];
    }

    #[DataProvider('uris')]
    public function testNormalisesThePathTheRouterWillMatchOn(string $uri, string $expected): void
    {
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        self::assertSame($expected, Request::fromGlobals()->path());
    }

    public function testAMissingRequestUriFallsBackToTheRoot(): void
    {
        unset($_SERVER['REQUEST_URI']);
        $_SERVER['REQUEST_METHOD'] = 'GET';

        self::assertSame('/', Request::fromGlobals()->path());
    }

    public function testTheMethodIsUpperCasedAndDefaultsToGet(): void
    {
        $_SERVER['REQUEST_URI'] = '/products';
        $_SERVER['REQUEST_METHOD'] = 'post';
        self::assertSame('POST', Request::fromGlobals()->method());

        unset($_SERVER['REQUEST_METHOD']);
        self::assertSame('GET', Request::fromGlobals()->method());
    }
}
