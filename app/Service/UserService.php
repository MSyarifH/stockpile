<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuthenticatedUser;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Exception\AuthorizationException;
use App\Support\Exception\HttpException;
use App\Support\Exception\ValidationException;

/**
 * User administration (USR-01). Admin only — enforced here rather than in the
 * controller so the rule cannot be bypassed by reaching the endpoint directly.
 */
final class UserService
{
    private const MIN_PASSWORD_LENGTH = 8;

    public function __construct(private readonly UserRepository $users)
    {
    }

    /** @return list<User> */
    public function list(AuthenticatedUser $actor): array
    {
        $this->assertAdmin($actor);
        return $this->users->all();
    }

    public function find(AuthenticatedUser $actor, int $id): User
    {
        $this->assertAdmin($actor);
        $user = $this->users->findById($id);

        if ($user === null) {
            throw HttpException::notFound('That user does not exist.');
        }

        return $user;
    }

    /**
     * @param array{name:string,email:string,role:string,password:string,is_active?:bool} $data
     */
    public function create(AuthenticatedUser $actor, array $data): int
    {
        $this->assertAdmin($actor);

        $role = $this->parseRole($data['role']);
        $this->assertPasswordAcceptable($data['password']);
        $this->assertEmailAvailable($data['email'], null);

        return $this->users->create(new User(
            null,
            $data['name'],
            strtolower($data['email']),
            password_hash($data['password'], PASSWORD_DEFAULT),
            $role,
            $data['is_active'] ?? true,
        ));
    }

    /**
     * @param array{name:string,email:string,role:string,is_active?:bool} $data
     */
    public function update(AuthenticatedUser $actor, int $id, array $data): void
    {
        $this->assertAdmin($actor);
        $existing = $this->find($actor, $id);
        $role = $this->parseRole($data['role']);
        $this->assertEmailAvailable($data['email'], $id);

        // An Admin must not be able to demote or disable themselves and lock
        // everyone out of user administration.
        if ($actor->id === $id && $role !== Role::Admin) {
            throw new AuthorizationException('You cannot change your own role.');
        }

        $this->users->update($id, new User(
            $id,
            $data['name'],
            strtolower($data['email']),
            $existing->passwordHash,
            $role,
            $data['is_active'] ?? $existing->isActive,
        ));
    }

    public function changePassword(AuthenticatedUser $actor, int $id, string $password): void
    {
        $this->assertAdmin($actor);
        $this->assertPasswordAcceptable($password);
        $this->find($actor, $id);

        $this->users->updatePassword($id, password_hash($password, PASSWORD_DEFAULT));
    }

    public function setActive(AuthenticatedUser $actor, int $id, bool $isActive): void
    {
        $this->assertAdmin($actor);
        $this->find($actor, $id);

        if ($actor->id === $id && !$isActive) {
            throw new AuthorizationException('You cannot deactivate your own account.');
        }

        $this->users->setActive($id, $isActive);
    }

    private function assertAdmin(AuthenticatedUser $actor): void
    {
        if (!$actor->isAdmin()) {
            throw new AuthorizationException('Only an Admin may manage user accounts.');
        }
    }

    private function assertEmailAvailable(string $email, ?int $exceptId): void
    {
        if ($this->users->emailExists(strtolower($email), $exceptId)) {
            throw new ValidationException(['email' => 'That email address is already in use.']);
        }
    }

    private function assertPasswordAcceptable(string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new ValidationException([
                'password' => sprintf('Password must be at least %d characters.', self::MIN_PASSWORD_LENGTH),
            ]);
        }
    }

    private function parseRole(string $role): Role
    {
        $parsed = Role::tryFrom($role);
        if ($parsed === null) {
            throw new ValidationException(['role' => 'That role is not valid.']);
        }
        return $parsed;
    }
}
