<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\AuthenticatedUser;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\InMemoryUserRepository;
use App\Service\Exception\AuthorizationException;
use App\Service\UserService;
use App\Support\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Logic area 2 — user administration authorization and validation (USR-01).
 *
 * These prove the rules hold in the SERVICE, so they cannot be bypassed by
 * calling an endpoint directly. §8.2 treats frontend-only authorization as a
 * critical failure.
 */
final class UserServiceTest extends TestCase
{
    private InMemoryUserRepository $repository;
    private UserService $service;

    protected function setUp(): void
    {
        $this->repository = new InMemoryUserRepository([
            new User(1, 'The Admin', 'admin@example.test', password_hash('secret123', PASSWORD_DEFAULT), Role::Admin, true),
            new User(2, 'A Seller', 'sales@example.test', password_hash('secret123', PASSWORD_DEFAULT), Role::Sales, true),
        ]);
        $this->service = new UserService($this->repository);
    }

    private function admin(): AuthenticatedUser
    {
        return new AuthenticatedUser(1, 'The Admin', Role::Admin);
    }

    private function sales(): AuthenticatedUser
    {
        return new AuthenticatedUser(2, 'A Seller', Role::Sales);
    }

    public function testSalesCannotListUsers(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service->list($this->sales());
    }

    public function testWarehouseStaffCannotCreateUsers(): void
    {
        $warehouse = new AuthenticatedUser(3, 'Warehouse', Role::WarehouseStaff);

        $this->expectException(AuthorizationException::class);
        $this->service->create($warehouse, [
            'name' => 'Someone',
            'email' => 'someone@example.test',
            'role' => Role::Sales->value,
            'password' => 'longenough1',
        ]);
    }

    public function testAdminCreatesUserAndPasswordIsHashedNotStoredPlainly(): void
    {
        $id = $this->service->create($this->admin(), [
            'name' => 'New Staff',
            'email' => 'New.Staff@Example.test',
            'role' => Role::WarehouseStaff->value,
            'password' => 'longenough1',
        ]);

        $created = $this->repository->findById($id);
        self::assertNotNull($created);
        self::assertNotSame('longenough1', $created->passwordHash);
        self::assertTrue(password_verify('longenough1', $created->passwordHash));
        // Email is normalised so "A@b.test" and "a@b.test" cannot both exist.
        self::assertSame('new.staff@example.test', $created->email);
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create($this->admin(), [
            'name' => 'Impostor',
            'email' => 'sales@example.test',
            'role' => Role::Sales->value,
            'password' => 'longenough1',
        ]);
    }

    public function testShortPasswordIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create($this->admin(), [
            'name' => 'Weak',
            'email' => 'weak@example.test',
            'role' => Role::Sales->value,
            'password' => 'short',
        ]);
    }

    public function testInvalidRoleIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create($this->admin(), [
            'name' => 'Odd',
            'email' => 'odd@example.test',
            'role' => 'Superuser',
            'password' => 'longenough1',
        ]);
    }

    /**
     * An Admin who could demote or disable themselves could leave the system
     * with nobody able to administer users. A control that can wedge the
     * business is not a safe control.
     */
    public function testAdminCannotChangeTheirOwnRole(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service->update($this->admin(), 1, [
            'name' => 'The Admin',
            'email' => 'admin@example.test',
            'role' => Role::Sales->value,
        ]);
    }

    public function testAdminCannotDeactivateTheirOwnAccount(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service->setActive($this->admin(), 1, false);
    }

    public function testAdminCanDeactivateAnotherUser(): void
    {
        $this->service->setActive($this->admin(), 2, false);

        $updated = $this->repository->findById(2);
        self::assertNotNull($updated);
        self::assertFalse($updated->isActive);
    }
}
