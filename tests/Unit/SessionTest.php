<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Session::start() cannot be unit tested -- it calls session_start(), which
 * needs a real request. What CAN be tested, and matters most, is the single
 * decision inside it: whether the login cookie is marked `secure`.
 *
 * That flag is not cosmetic in either direction. Set when it should not be, the
 * cookie is never sent over plain HTTP and the application appears to log
 * everyone straight back out. Unset when it should be set, the session ID
 * travels in clear text over the network.
 */
final class SessionTest extends TestCase
{
    /** @return array<string,array{array<string,mixed>,bool}> */
    public static function schemes(): array
    {
        return [
            // Apache sets HTTPS=on; it is absent entirely on a plain request.
            'plain http (no HTTPS key)' => [[], false],
            'apache HTTPS=on' => [['HTTPS' => 'on'], true],
            'HTTPS=On, mixed case' => [['HTTPS' => 'On'], true],
            // IIS sets the key to the literal string "off" rather than removing
            // it -- the classic trap this check exists for.
            'IIS HTTPS=off means NOT https' => [['HTTPS' => 'off'], false],
            'HTTPS=OFF, mixed case' => [['HTTPS' => 'OFF'], false],
            'HTTPS empty string' => [['HTTPS' => ''], false],
            // Behind a TLS-terminating proxy the connection to PHP is plain
            // HTTP; only the forwarded header reveals the original scheme.
            'proxy forwarded https' => [['HTTP_X_FORWARDED_PROTO' => 'https'], true],
            'proxy forwarded HTTPS uppercase' => [['HTTP_X_FORWARDED_PROTO' => 'HTTPS'], true],
            'proxy forwarded http' => [['HTTP_X_FORWARDED_PROTO' => 'http'], false],
        ];
    }

    /** @param array<string,mixed> $server */
    #[DataProvider('schemes')]
    public function testDetectsWhetherTheRequestArrivedOverTls(array $server, bool $expected): void
    {
        self::assertSame($expected, Session::isHttps($server));
    }

    public function testTheLocalDemoOverPlainHttpIsNotTreatedAsSecure(): void
    {
        // The exact shape of the request an assessor makes to
        // http://localhost:8080. If this ever returned true, the cookie would
        // carry `secure`, never be sent back, and login would silently fail.
        $server = ['HTTP_HOST' => 'localhost:8080', 'REQUEST_SCHEME' => 'http'];

        self::assertFalse(Session::isHttps($server));
    }
}
