<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controller\AuthController;
use App\Controller\BusinessPartnerController;
use App\Controller\CategoryController;
use App\Controller\DashboardController;
use App\Controller\ProductController;
use App\Controller\ProfileController;
use App\Controller\PurchaseOrderController;
use App\Controller\SalesOrderController;
use App\Controller\UserController;
use App\Controller\WarehouseController;
use App\Entity\AuthenticatedUser;
use App\Entity\PartnerType;
use App\Entity\Role;
use App\Repository\BusinessPartnerRepository;
use App\Repository\CategoryRepository;
use App\Repository\DashboardRepository;
use App\Repository\MySqlProductRepository;
use App\Repository\MySqlPurchaseOrderRepository;
use App\Repository\MySqlSalesOrderRepository;
use App\Repository\MySqlStockLedgerRepository;
use App\Repository\MySqlStockRepository;
use App\Repository\MySqlUserRepository;
use App\Repository\WarehouseRepository;
use App\Service\AuthService;
use App\Service\BusinessPartnerService;
use App\Service\CategoryService;
use App\Service\DashboardService;
use App\Service\ProductService;
use App\Service\PurchaseOrderService;
use App\Service\SalesOrderService;
use App\Service\StockService;
use App\Service\UserService;
use App\Service\WarehouseService;
use App\Support\Csrf;
use App\Support\Exception\HttpException;
use App\Support\ImageUploader;
use App\Support\PdoTransactionManager;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;
use Tests\Support\FakeSession;

/**
 * Every page renders, for a real signed-in user, against real MySQL.
 *
 * The gap this fills: until now the HTTP layer had no automated test at all.
 * Controllers were verified by clicking, which is how the stock filter shipped
 * returning HTTP 500 while a test counted its zero rows and passed
 * (docs/testing/known-bugs.md). A page that throws, or a template that reads a
 * variable nobody passes, now fails here.
 *
 * Why integration and not unit: CategoryRepository, WarehouseRepository and
 * BusinessPartnerRepository are concrete PDO classes on purpose. ADR-001 puts a
 * repository behind an interface only where a service BRANCHES on its data;
 * those three are plain CRUD, so they have no fake, and inventing interfaces for
 * them purely to make this test possible would be the speculative abstraction
 * section 0 penalises. Using the real ones against the real database is the
 * honest way to cover this layer.
 *
 * Strictly read-only. It creates nothing and deletes nothing, so it cannot
 * disturb the demo data -- the write actions are covered by the service tests,
 * where the rules actually live.
 */
final class ControllerRenderingTest extends IntegrationTestCase
{
    private AuthenticatedUser $admin;
    private FakeSession $session;
    private View $view;
    private Csrf $csrf;

    protected function setUp(): void
    {
        parent::setUp();

        $row = $this->pdo->query(
            "SELECT id, name FROM users WHERE role = 'Admin' AND is_active = 1 ORDER BY id LIMIT 1"
        )->fetch();
        self::assertIsArray($row, 'The seed must contain an active Admin.');

        $this->admin = new AuthenticatedUser((int) $row['id'], (string) $row['name'], Role::Admin);
        $this->session = new FakeSession($this->admin);
        $this->view = new View(dirname(__DIR__, 2) . '/views');
        $this->csrf = new Csrf($this->session);

        // The same values public/index.php shares on every request; without them
        // the layout renders with undefined variables.
        $this->view->share('csrfToken', $this->csrf->token());
        $this->view->share('flashes', []);
        $this->view->share('user', $this->admin);
        $this->view->share('currentPath', '/');
    }

    private function get(string $path): Request
    {
        return new Request('GET', $path, [], []);
    }

    private function assertRenders(Response $response, string $expectedFragment): void
    {
        self::assertSame(200, $response->status());
        self::assertStringContainsString($expectedFragment, $response->body());
    }

    private function firstId(string $table): int
    {
        return (int) $this->pdo->query("SELECT id FROM {$table} ORDER BY id LIMIT 1")->fetchColumn();
    }

    // --- the wiring, mirroring public/index.php ----------------------------

    private function productService(): ProductService
    {
        return new ProductService(
            new MySqlProductRepository($this->pdo),
            new PdoTransactionManager($this->pdo),
        );
    }

    private function warehouseService(): WarehouseService
    {
        return new WarehouseService(
            new WarehouseRepository($this->pdo),
            new PdoTransactionManager($this->pdo),
        );
    }

    private function partnerService(): BusinessPartnerService
    {
        return new BusinessPartnerService(new BusinessPartnerRepository($this->pdo));
    }

    private function stockService(): StockService
    {
        return new StockService(
            new MySqlStockRepository($this->pdo),
            new MySqlStockLedgerRepository($this->pdo),
            new PdoTransactionManager($this->pdo),
        );
    }

    // --- the pages ---------------------------------------------------------

    public function testTheLoginPageRendersForAnAnonymousVisitor(): void
    {
        $anonymous = new FakeSession(null);
        $view = new View(dirname(__DIR__, 2) . '/views');
        $csrf = new Csrf($anonymous);
        $view->share('csrfToken', $csrf->token());
        $view->share('flashes', []);

        $controller = new AuthController(
            new AuthService(new MySqlUserRepository($this->pdo)),
            $anonymous,
            $view,
            $csrf,
        );

        $this->assertRenders($controller->showLogin(), 'name="email"');
    }

    public function testTheDashboardRendersItsAggregates(): void
    {
        $controller = new DashboardController(
            new DashboardService(new DashboardRepository($this->pdo), new MySqlProductRepository($this->pdo)),
            $this->session,
            $this->view,
        );

        $this->assertRenders($controller->index(), 'Dashboard');
    }

    public function testTheProfilePageRendersForTheSignedInUser(): void
    {
        $controller = new ProfileController(
            new UserService(new MySqlUserRepository($this->pdo)),
            $this->session,
            $this->view,
            $this->csrf,
        );

        $this->assertRenders($controller->show(), 'name="_token"');
    }

    public function testUserAdministrationRendersItsListAndForms(): void
    {
        $controller = new UserController(
            new UserService(new MySqlUserRepository($this->pdo)),
            $this->session,
            $this->view,
            $this->csrf,
        );

        $this->assertRenders($controller->index(), '@example.com');
        $this->assertRenders($controller->create(), 'name="_token"');
        $this->assertRenders($controller->edit($this->get('/users/1/edit'), (string) $this->admin->id), 'name="email"');
    }

    public function testCategoryPagesRender(): void
    {
        $controller = new CategoryController(
            new CategoryService(new CategoryRepository($this->pdo)),
            $this->session,
            $this->view,
            $this->csrf,
        );
        $id = $this->firstId('categories');

        $this->assertRenders($controller->index(), 'name="_token"');
        $this->assertRenders($controller->create(), 'name="name"');
        $this->assertRenders($controller->edit($this->get("/categories/{$id}/edit"), (string) $id), 'name="name"');
    }

    public function testWarehousePagesRender(): void
    {
        $controller = new WarehouseController(
            $this->warehouseService(),
            $this->session,
            $this->view,
            $this->csrf,
        );
        $id = $this->firstId('warehouses');

        $this->assertRenders($controller->index(), 'Warehouses');
        $this->assertRenders($controller->create(), 'name="name"');
        $this->assertRenders($controller->edit($this->get("/warehouses/{$id}/edit"), (string) $id), 'name="location"');
    }

    public function testPartnerPagesRenderForBothTypes(): void
    {
        $controller = new BusinessPartnerController(
            $this->partnerService(),
            $this->session,
            $this->view,
            $this->csrf,
        );

        $this->assertRenders($controller->index(PartnerType::Supplier), 'name="_token"');
        $this->assertRenders($controller->index(PartnerType::Customer), 'name="_token"');
    }

    public function testProductPagesRender(): void
    {
        $uploads = dirname(__DIR__, 2) . '/public/uploads';
        $controller = new ProductController(
            $this->productService(),
            new CategoryService(new CategoryRepository($this->pdo)),
            new ImageUploader($uploads, 2_097_152, ['image/jpeg', 'image/png', 'image/webp']),
            $this->session,
            $this->view,
            $this->csrf,
        );
        $id = $this->firstId('products');

        $this->assertRenders($controller->index($this->get('/products')), 'name="q"');
        $this->assertRenders($controller->create(), 'name="sku"');
        $this->assertRenders($controller->show($this->get("/products/{$id}"), (string) $id), 'SKU');
        $this->assertRenders($controller->edit($this->get("/products/{$id}/edit"), (string) $id), 'name="sku"');
    }

    public function testPurchaseOrderPagesRender(): void
    {
        $controller = new PurchaseOrderController(
            new PurchaseOrderService(
                new MySqlPurchaseOrderRepository($this->pdo),
                $this->stockService(),
                new PdoTransactionManager($this->pdo),
            ),
            $this->productService(),
            $this->partnerService(),
            $this->warehouseService(),
            $this->session,
            $this->view,
            $this->csrf,
        );
        $id = $this->firstId('purchase_orders');

        $this->assertRenders($controller->index($this->get('/purchase-orders')), 'Purchase orders');
        $this->assertRenders($controller->create(), 'name="_token"');
        $this->assertRenders($controller->show($this->get("/purchase-orders/{$id}"), (string) $id), 'Purchase order');
    }

    public function testSalesOrderPagesRender(): void
    {
        $controller = new SalesOrderController(
            new SalesOrderService(
                new MySqlSalesOrderRepository($this->pdo),
                $this->stockService(),
                new PdoTransactionManager($this->pdo),
            ),
            $this->productService(),
            $this->partnerService(),
            $this->warehouseService(),
            $this->session,
            $this->view,
            $this->csrf,
        );
        $id = $this->firstId('sales_orders');

        $this->assertRenders($controller->index($this->get('/sales-orders')), 'Sales orders');
        $this->assertRenders($controller->create(), 'name="_token"');
        $this->assertRenders($controller->show($this->get("/sales-orders/{$id}"), (string) $id), 'Sales order');
    }

    public function testAnAnonymousVisitorIsSentToLoginRatherThanShownTheDashboard(): void
    {
        // The dashboard redirects instead of throwing, because arriving here
        // without a session is the ordinary "your session expired" case, not an
        // attack -- a bare 401 page would be a worse answer than the login form.
        // The Router guards the route as well; this is the second line.
        $controller = new DashboardController(
            new DashboardService(new DashboardRepository($this->pdo), new MySqlProductRepository($this->pdo)),
            new FakeSession(null),
            $this->view,
        );

        // Only the status is asserted: Response exposes no header accessor, and
        // adding one to production code purely so a test can read it would be
        // the test dictating the design. 302 with an empty body is enough to
        // distinguish a redirect from a rendered dashboard.
        $response = $controller->index();

        self::assertSame(302, $response->status());
        self::assertSame('', $response->body());
    }

    public function testUserAdministrationRefusesAnAnonymousRequestOutright(): void
    {
        // Administration is different: there is no friendly fallback, and
        // requireUser() throws before a single row is read.
        $controller = new UserController(
            new UserService(new MySqlUserRepository($this->pdo)),
            new FakeSession(null),
            $this->view,
            $this->csrf,
        );

        $this->expectException(HttpException::class);
        $controller->index();
    }
}
