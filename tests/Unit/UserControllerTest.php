<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\UserController;
use App\Entity\AuthenticatedUser;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\InMemoryUserRepository;
use App\Service\Exception\AuthorizationException;
use App\Service\UserService;
use App\Support\Csrf;
use App\Support\Exception\HttpException;
use App\Support\View;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSession;

/**
 * The HTTP layer of user administration (USR-01), exercised without a web server.
 *
 * This is the closest this suite gets to an end-to-end test, and it exists
 * because the layer it covers was the one with no tests at all: controllers were
 * verified only by clicking. It renders the REAL templates through the REAL
 * View, so a template that references a variable nobody passes fails here
 * instead of in front of a user.
 *
 * What it deliberately does not do is start a session or open a socket --
 * FakeSession replaces session_start() and nothing else.
 */
final class UserControllerTest extends TestCase
{
    /** Hashed once: password_hash is deliberately slow, and nothing here verifies it. */
    private const HASH = '$2y$10$abcdefghijklmnopqrstuvabcdefghijklmnopqrstuvwxyz01234';

    private function view(): View
    {
        $view = new View(dirname(__DIR__, 2) . '/views');
        // The same four values public/index.php shares on every request. Without
        // them the layout renders with undefined variables.
        $view->share('flashes', []);
        $view->share('currentPath', '/users');
        return $view;
    }

    private function controller(?AuthenticatedUser $actor): UserController
    {
        $repository = new InMemoryUserRepository([
            new User(1, 'Rizky Admin', 'admin@example.com', self::HASH, Role::Admin, true),
            new User(2, 'Sinta Sales', 'sales1@example.com', self::HASH, Role::Sales, true),
        ]);
        $session = new FakeSession($actor);
        $view = $this->view();
        $view->share('user', $actor);
        $csrf = new Csrf($session);
        $view->share('csrfToken', $csrf->token());

        return new UserController(new UserService($repository), $session, $view, $csrf);
    }

    private function admin(): AuthenticatedUser
    {
        return new AuthenticatedUser(1, 'Rizky Admin', Role::Admin);
    }

    public function testTheListPageRendersEveryAccountForAnAdmin(): void
    {
        $response = $this->controller($this->admin())->index();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('admin@example.com', $response->body());
        self::assertStringContainsString('sales1@example.com', $response->body());
    }

    public function testTheCreateFormRendersWithACsrfToken(): void
    {
        $response = $this->controller($this->admin())->create();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('name="_token"', $response->body());
    }

    public function testAnAnonymousRequestIsRejectedBeforeAnythingIsRendered(): void
    {
        // requireUser() throws; the page is never built. Hiding the menu item is
        // not what stops this -- there is no menu involved here at all.
        $this->expectException(HttpException::class);

        $this->controller(null)->index();
    }

    public function testASalesUserCannotReachUserAdministration(): void
    {
        $sales = new AuthenticatedUser(2, 'Sinta Sales', Role::Sales);

        $this->expectException(AuthorizationException::class);

        $this->controller($sales)->index();
    }
}
