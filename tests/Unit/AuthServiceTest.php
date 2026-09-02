<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\Role;
use App\Entity\User;
use App\Repository\InMemoryUserRepository;
use App\Service\AuthService;
use App\Service\Exception\AuthenticationException;
use PHPUnit\Framework\TestCase;

/**
 * Logic area 1 — authentication rules.
 *
 * Runs against InMemoryUserRepository: no PDO, no session, no network (TEST-01).
 */
final class AuthServiceTest extends TestCase
{
    private const PASSWORD = 'Password123!';

    private function serviceWith(User ...$users): AuthService
    {
        return new AuthService(new InMemoryUserRepository($users));
    }

    private function user(
        int $id,
        string $email,
        Role $role = Role::Sales,
        bool $active = true,
        string $password = self::PASSWORD,
    ): User {
        return new User($id, 'Test User', $email, password_hash($password, PASSWORD_DEFAULT), $role, $active);
    }

    public function testSignsInWithCorrectCredentials(): void
    {
        $service = $this->serviceWith($this->user(1, 'sales@example.test', Role::Sales));

        $authenticated = $service->attempt('sales@example.test', self::PASSWORD);

        self::assertSame(1, $authenticated->id);
        self::assertSame(Role::Sales, $authenticated->role);
    }

    public function testRejectsWrongPassword(): void
    {
        $service = $this->serviceWith($this->user(1, 'sales@example.test'));

        $this->expectException(AuthenticationException::class);
        $service->attempt('sales@example.test', 'not-the-password');
    }

    public function testRejectsUnknownEmail(): void
    {
        $service = $this->serviceWith($this->user(1, 'sales@example.test'));

        $this->expectException(AuthenticationException::class);
        $service->attempt('nobody@example.test', self::PASSWORD);
    }

    /**
     * AUTH-01: "user tidak aktif tidak dapat login" — even with the right password.
     */
    public function testRejectsInactiveAccountEvenWithCorrectPassword(): void
    {
        $service = $this->serviceWith($this->user(1, 'left@example.test', Role::Sales, false));

        $this->expectException(AuthenticationException::class);
        $service->attempt('left@example.test', self::PASSWORD);
    }

    /**
     * AUTH-01: the message must not reveal which part was wrong. If the three
     * failure modes produced different text, an attacker could enumerate which
     * email addresses exist and which accounts are merely disabled.
     */
    public function testEveryFailureModeReturnsTheSameMessage(): void
    {
        $service = $this->serviceWith(
            $this->user(1, 'active@example.test'),
            $this->user(2, 'disabled@example.test', Role::Sales, false),
        );

        $messages = [];
        foreach (
            [
            ['unknown@example.test', self::PASSWORD],   // no such account
            ['active@example.test', 'wrong-password'],  // wrong password
            ['disabled@example.test', self::PASSWORD],  // inactive account
            ] as [$email, $password]
        ) {
            try {
                $service->attempt($email, $password);
                self::fail('Expected authentication to be rejected for ' . $email);
            } catch (AuthenticationException $e) {
                $messages[] = $e->getMessage();
            }
        }

        self::assertCount(3, $messages);
        self::assertCount(1, array_unique($messages), 'Failure messages must be indistinguishable.');
    }

    /**
     * The identity carried through the request must not include the password
     * hash: nothing downstream of authentication has any use for it.
     */
    public function testAuthenticatedIdentityDoesNotExposeThePasswordHash(): void
    {
        $service = $this->serviceWith($this->user(9, 'admin@example.test', Role::Admin));

        $authenticated = $service->attempt('admin@example.test', self::PASSWORD);

        self::assertObjectNotHasProperty('passwordHash', $authenticated);
        self::assertTrue($authenticated->isAdmin());
    }
}
