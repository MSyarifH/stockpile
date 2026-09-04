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

    // --- own profile (§1.2 "profil sendiri", allowed to every role) ---------

    public function testAnyRoleCanReadTheirOwnProfileWithoutBeingAnAdmin(): void
    {
        $ownProfile = $this->service->ownProfile($this->sales());

        self::assertSame(2, $ownProfile->id);
        self::assertSame('sales@example.test', $ownProfile->email);
    }

    /**
     * The account is taken from the session, never from the request, so there is
     * no id a Sales user could substitute to read someone else's record.
     */
    public function testOwnProfileIgnoresAnyIdAndUsesTheActor(): void
    {
        self::assertSame(1, $this->service->ownProfile($this->admin())->id);
        self::assertSame(2, $this->service->ownProfile($this->sales())->id);
    }

    /**
     * Changing your own password requires proving you know the current one.
     * Without it, anyone reaching an unattended signed-in browser could lock the
     * real owner out.
     */
    public function testChangingOwnPasswordRequiresTheCurrentOne(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->changeOwnPassword($this->sales(), 'not-my-password', 'a-new-password');
    }

    public function testChangingOwnPasswordSucceedsWithTheCorrectCurrentOne(): void
    {
        $this->service->changeOwnPassword($this->sales(), 'secret123', 'a-new-password');

        $updated = $this->repository->findById(2);
        self::assertNotNull($updated);
        self::assertTrue(password_verify('a-new-password', $updated->passwordHash));
        self::assertFalse(password_verify('secret123', $updated->passwordHash), 'The old password must stop working.');
    }

    public function testTheNewPasswordMustDifferFromTheCurrentOne(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->changeOwnPassword($this->sales(), 'secret123', 'secret123');
    }

    public function testAShortNewPasswordIsRejectedForOwnPasswordChangeToo(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->changeOwnPassword($this->sales(), 'secret123', 'short');
    }

    public function testChangingOwnPasswordNeverRequiresAdminRights(): void
    {
        $warehouse = new AuthenticatedUser(3, 'Warehouse', Role::WarehouseStaff);
        $this->repository->create(new User(
            3,
            'Warehouse',
            'wh@example.test',
            password_hash('secret123', PASSWORD_DEFAULT),
            Role::WarehouseStaff,
            true,
        ));

        // No AuthorizationException: this is the one account they may change.
        $this->service->changeOwnPassword($warehouse, 'secret123', 'another-password');

        $updated = $this->repository->findById(3);
        self::assertNotNull($updated);
        self::assertTrue(password_verify('another-password', $updated->passwordHash));
    }
}
