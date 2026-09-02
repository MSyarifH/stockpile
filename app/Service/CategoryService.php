<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuthenticatedUser;
use App\Entity\Category;
use App\Entity\Role;
use App\Repository\CategoryRepository;
use App\Service\Exception\AuthorizationException;
use App\Support\Exception\HttpException;
use App\Support\Exception\ValidationException;

final class CategoryService
{
    public function __construct(private readonly CategoryRepository $categories)
    {
    }

    /** @return list<Category> */
    public function list(): array
    {
        return $this->categories->all();
    }

    public function find(int $id): Category
    {
        $category = $this->categories->findById($id);
        if ($category === null) {
            throw HttpException::notFound('That category does not exist.');
        }

        return $category;
    }

    public function create(AuthenticatedUser $actor, string $name, string $description): int
    {
        $this->assertAdmin($actor);
        $this->assertNameAvailable($name, null);

        return $this->categories->create(new Category(null, trim($name), trim($description)));
    }

    public function update(AuthenticatedUser $actor, int $id, string $name, string $description): void
    {
        $this->assertAdmin($actor);
        $this->find($id);
        $this->assertNameAvailable($name, $id);

        $this->categories->update($id, new Category($id, trim($name), trim($description)));
    }

    private function assertNameAvailable(string $name, ?int $exceptId): void
    {
        if ($this->categories->nameExists(trim($name), $exceptId)) {
            throw new ValidationException(['name' => 'A category with that name already exists.']);
        }
    }

    private function assertAdmin(AuthenticatedUser $actor): void
    {
        if ($actor->role !== Role::Admin) {
            throw new AuthorizationException('Only an Admin may manage categories.');
        }
    }
}
