<?php

declare(strict_types=1);

/**
 * Front controller and composition root.
 *
 * This is the ONLY file that knows about concrete implementations. Everything
 * below receives its collaborators through the constructor, which is what keeps
 * services free of PDO and testable without a database (ARCH-01).
 *
 * There is deliberately no DI container: the brief's FAQ #2 states manual
 * constructor injection is sufficient, and a reflection-based container would be
 * structure that solves no problem this project has (§0).
 */

use App\Controller\ApiController;
use App\Controller\AuthController;
use App\Controller\BusinessPartnerController;
use App\Controller\CategoryController;
use App\Controller\DashboardController;
use App\Controller\ProductController;
use App\Controller\ProfileController;
use App\Controller\ReportController;
use App\Controller\PurchaseOrderController;
use App\Controller\SalesOrderController;
use App\Controller\UserController;
use App\Controller\WarehouseController;
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
use App\Service\Exception\AuthorizationException;
use App\Service\ProductService;
use App\Service\ReportService;
use App\Service\PurchaseOrderService;
use App\Service\SalesOrderService;
use App\Service\StockService;
use App\Service\UserService;
use App\Service\WarehouseService;
use App\Support\Csrf;
use App\Support\CsvWriter;
use App\Support\Database;
use App\Support\Exception\HttpException;
use App\Support\Exception\ValidationException;
use App\Support\ImageUploader;
use App\Support\PdoTransactionManager;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\Session;
use App\Support\View;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/** @var array{env:string,db:array{host:string,port:string,name:string,user:string,password:string},uploads:array<string,mixed>} $config */
$config = require dirname(__DIR__) . '/config/config.php';

$request = Request::fromGlobals();
$session = new Session();
$session->start();
$view = new View(dirname(__DIR__) . '/views');
$csrf = new Csrf($session);

try {
    // --- infrastructure --------------------------------------------------
    $pdo = Database::connect($config['db']);
    $transactions = new PdoTransactionManager($pdo);

    /** @var array{path:string,max_bytes:int,allowed_mime:list<string>} $uploads */
    $uploads = $config['uploads'];
    $imageUploader = new ImageUploader($uploads['path'], $uploads['max_bytes'], $uploads['allowed_mime']);

    // --- repositories ----------------------------------------------------
    $userRepository = new MySqlUserRepository($pdo);
    $categoryRepository = new CategoryRepository($pdo);
    $dashboardRepository = new DashboardRepository($pdo);
    $warehouseRepository = new WarehouseRepository($pdo);
    $partnerRepository = new BusinessPartnerRepository($pdo);
    $productRepository = new MySqlProductRepository($pdo);
    $stockRepository = new MySqlStockRepository($pdo);
    $ledgerRepository = new MySqlStockLedgerRepository($pdo);
    $purchaseOrderRepository = new MySqlPurchaseOrderRepository($pdo);
    $salesOrderRepository = new MySqlSalesOrderRepository($pdo);

    // --- services --------------------------------------------------------
    $authService = new AuthService($userRepository);
    $userService = new UserService($userRepository);
    $categoryService = new CategoryService($categoryRepository);
    $warehouseService = new WarehouseService($warehouseRepository, $transactions);
    $partnerService = new BusinessPartnerService($partnerRepository);
    $productService = new ProductService($productRepository, $transactions);
    // StockService is the single writer of stock; order services call it.
    $stockService = new StockService($stockRepository, $ledgerRepository, $transactions);
    $purchaseOrderService = new PurchaseOrderService($purchaseOrderRepository, $stockService, $transactions);
    $salesOrderService = new SalesOrderService($salesOrderRepository, $stockService, $transactions);
    $dashboardService = new DashboardService($dashboardRepository, $productRepository);
    $reportService = new ReportService($ledgerRepository, $salesOrderRepository);

    // --- controllers -----------------------------------------------------
    $authController = new AuthController($authService, $session, $view, $csrf);
    $dashboardController = new DashboardController($dashboardService, $session, $view);
    $reportController = new ReportController($reportService, new CsvWriter(), $session, $view);
    $apiController = new ApiController($productService, $session);
    $profileController = new ProfileController($userService, $session, $view, $csrf);
    $userController = new UserController($userService, $session, $view, $csrf);
    $categoryController = new CategoryController($categoryService, $session, $view, $csrf);
    $warehouseController = new WarehouseController($warehouseService, $session, $view, $csrf);
    $partnerController = new BusinessPartnerController($partnerService, $session, $view, $csrf);
    $purchaseOrderController = new PurchaseOrderController(
        $purchaseOrderService,
        $productService,
        $partnerService,
        $warehouseService,
        $session,
        $view,
        $csrf,
    );
    $salesOrderController = new SalesOrderController(
        $salesOrderService,
        $productService,
        $partnerService,
        $warehouseService,
        $session,
        $view,
        $csrf,
    );
    $productController = new ProductController(
        $productService,
        $categoryService,
        $imageUploader,
        $session,
        $view,
        $csrf,
    );

    // Values every layout needs. Flash messages are read once per request.
    $view->share('csrfToken', $csrf->token());
    $view->share('flashes', $session->takeFlash());
    $view->share('user', $session->user());
    // The layout marks the current link with aria-current; it needs the path to
    // do that, and a view has no business reading the request itself.
    $view->share('currentPath', $request->path());

    // PHP silently empties $_POST when post_max_size is exceeded, so this must
    // be checked BEFORE anything looks for a CSRF token — otherwise an
    // over-sized upload is reported as a security failure (BUG-05).
    if ($request->bodyWasDiscarded()) {
        throw HttpException::payloadTooLarge(
            'That upload was larger than the server accepts. The limit is '
            . ini_get('post_max_size') . ' per request; images must be 2 MB or smaller.'
        );
    }

    // --- routes ----------------------------------------------------------
    // Third argument: null = public, [] = any signed-in user, [Role...] = those roles.
    // This is the COARSE guard only; per-record rules live in the services.
    $router = new Router($session);

    $router->get('/', static fn (): Response => Response::redirect('/dashboard'), null);
    $router->get('/login', $authController->showLogin(...), null);
    $router->post('/login', $authController->login(...), null);
    $router->post('/logout', $authController->logout(...), []);

    $router->get('/dashboard', $dashboardController->index(...), []);

    // §1.2 grants "profil sendiri" to every role, so [] (any signed-in user).
    // The account acted on comes from the session, never from the request.
    $router->get('/profile', $profileController->show(...), []);
    $router->post('/profile/password', $profileController->changePassword(...), []);

    $router->get('/users', $userController->index(...), [Role::Admin]);
    $router->get('/users/create', $userController->create(...), [Role::Admin]);
    $router->post('/users', $userController->store(...), [Role::Admin]);
    $router->get('/users/{id}/edit', $userController->edit(...), [Role::Admin]);
    $router->post('/users/{id}', $userController->update(...), [Role::Admin]);
    $router->post('/users/{id}/active', $userController->toggleActive(...), [Role::Admin]);

    // Catalogue is readable by every signed-in role (§1.2: Sales sees the
    // catalogue, Warehouse sees products and stock); only Admin may change it.
    $router->get('/products', $productController->index(...), []);
    $router->get('/products/create', $productController->create(...), [Role::Admin]);
    $router->post('/products', $productController->store(...), [Role::Admin]);
    $router->get('/products/{id}', $productController->show(...), []);
    $router->get('/products/{id}/edit', $productController->edit(...), [Role::Admin]);
    $router->post('/products/{id}', $productController->update(...), [Role::Admin]);
    $router->post('/products/{id}/active', $productController->toggleActive(...), [Role::Admin]);

    $router->get('/categories', $categoryController->index(...), [Role::Admin]);
    $router->get('/categories/create', $categoryController->create(...), [Role::Admin]);
    $router->post('/categories', $categoryController->store(...), [Role::Admin]);
    $router->get('/categories/{id}/edit', $categoryController->edit(...), [Role::Admin]);
    $router->post('/categories/{id}', $categoryController->update(...), [Role::Admin]);

    $router->get('/warehouses', $warehouseController->index(...), [Role::Admin]);
    $router->get('/warehouses/create', $warehouseController->create(...), [Role::Admin]);
    $router->post('/warehouses', $warehouseController->store(...), [Role::Admin]);
    $router->get('/warehouses/{id}/edit', $warehouseController->edit(...), [Role::Admin]);
    $router->post('/warehouses/{id}', $warehouseController->update(...), [Role::Admin]);
    $router->post('/warehouses/{id}/active', $warehouseController->toggleActive(...), [Role::Admin]);

    // Purchase orders: Sales has no part in purchasing (§1.2). The finer rule —
    // only an Admin may PLACE an order with the supplier (D1) — is in the service.
    $purchasing = [Role::Admin, Role::WarehouseStaff];
    $router->get('/purchase-orders', $purchaseOrderController->index(...), $purchasing);
    $router->get('/purchase-orders/create', $purchaseOrderController->create(...), $purchasing);
    $router->post('/purchase-orders', $purchaseOrderController->store(...), $purchasing);
    $router->get('/purchase-orders/{id}', $purchaseOrderController->show(...), $purchasing);
    $router->post('/purchase-orders/{id}/place', $purchaseOrderController->place(...), $purchasing);
    $router->post('/purchase-orders/{id}/cancel', $purchaseOrderController->cancel(...), $purchasing);
    $router->post('/purchase-orders/{id}/receive', $purchaseOrderController->receive(...), $purchasing);

    // Sales orders are visible to every signed-in role, but the SERVICE decides
    // what each one may see and do: Sales sees only its own orders, only an
    // Admin who did not raise the order may approve it (D2), and only Admin or
    // Warehouse Staff may issue goods.
    $router->get('/sales-orders', $salesOrderController->index(...), []);
    $router->get('/sales-orders/create', $salesOrderController->create(...), [Role::Admin, Role::Sales]);
    $router->post('/sales-orders', $salesOrderController->store(...), [Role::Admin, Role::Sales]);
    $router->get('/sales-orders/{id}', $salesOrderController->show(...), []);
    $router->post('/sales-orders/{id}/submit', $salesOrderController->submit(...), []);
    $router->post('/sales-orders/{id}/approve', $salesOrderController->approve(...), []);
    $router->post('/sales-orders/{id}/reject', $salesOrderController->reject(...), []);
    $router->post('/sales-orders/{id}/cancel', $salesOrderController->cancel(...), []);
    $router->post('/sales-orders/{id}/issue', $salesOrderController->issue(...), []);

    // REPORT-01. Sales may export their own orders; stock movements are for
    // Admin and Warehouse Staff, enforced inside ReportService.
    $router->get('/reports', $reportController->index(...), []);
    $router->get('/reports/stock-movements', $reportController->stockMovements(...), []);
    $router->get('/reports/order-status', $reportController->orderStatus(...), []);

    // API-01. A JSON contract, separate from the HTML pages. Authentication is
    // checked inside the controller so an unauthenticated call receives 401
    // JSON rather than the login redirect a page would get.
    $router->get('/api/products/{sku}/availability', $apiController->productAvailability(...), null);

    // Suppliers and customers share a controller. The type is bound HERE, from
    // the route, so it can never be influenced by request data.
    foreach ([PartnerType::Supplier, PartnerType::Customer] as $partnerType) {
        $base = '/' . $partnerType->urlSegment();

        $router->get($base, static fn (): Response
            => $partnerController->index($partnerType), [Role::Admin]);
        $router->get($base . '/create', static fn (): Response
            => $partnerController->create($partnerType), [Role::Admin]);
        $router->post($base, static fn (Request $r): Response
            => $partnerController->store($r, $partnerType), [Role::Admin]);
        $router->get($base . '/{id}/edit', static fn (Request $r, string $id): Response
            => $partnerController->edit($partnerType, $id), [Role::Admin]);
        $router->post($base . '/{id}', static fn (Request $r, string $id): Response
            => $partnerController->update($r, $partnerType, $id), [Role::Admin]);
        $router->post($base . '/{id}/active', static fn (Request $r, string $id): Response
            => $partnerController->toggleActive($r, $partnerType, $id), [Role::Admin]);
    }

    $router->dispatch($request)->send();
} catch (Throwable $exception) {
    handleFailure($exception, $request, $view, $config['env']);
}

/**
 * Single place that turns a failure into an HTTP response (ERR-01).
 *
 * Two rules hold regardless of the failure: an unauthenticated user is sent to
 * the login page rather than shown a bare 401, and no database message or stack
 * trace ever reaches the browser. Details go to the container log instead.
 */
function handleFailure(Throwable $exception, Request $request, View $view, string $environment): void
{
    [$status, $message] = match (true) {
        $exception instanceof HttpException => [$exception->status(), $exception->getMessage()],
        $exception instanceof AuthorizationException => [403, $exception->getMessage()],
        // A validation failure that reaches here is one a controller did not
        // catch in order to re-render its form — a state transition posted
        // directly, for instance. It is still the client's fault, not a server
        // fault, so it must not become a 500: ERR-01 asks for an accurate
        // status, and a 500 also hides a usable message behind
        // "Something went wrong".
        $exception instanceof ValidationException => [422, implode(' ', $exception->errors())],
        // A foreign key that does not exist arrives as an integrity-constraint
        // violation from MySQL. The value came from the request, so it is a
        // validation problem rather than a server fault. The driver's message is
        // NOT passed on — it would leak table and constraint names.
        $exception instanceof PDOException && $exception->getCode() === '23000'
            => [422, 'One of the selected records does not exist, or would break a rule. Please check the form.'],
        default => [500, 'Something went wrong. Please try again.'],
    };

    // Logged for 4xx caused by an exception too, because an unexpected 422 from
    // a constraint violation is worth seeing in the log even though the client
    // is shown a friendly message.
    if ($status >= 500 || $exception instanceof PDOException) {
        // Logged, never displayed. §8.2 treats a leaked stack trace as a failure.
        error_log(sprintf(
            '[%s] %s in %s:%d',
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
        ));
    }

    // API-01: a JSON client must receive JSON with an accurate status, never an
    // HTML error page and never a redirect.
    if ($request->wantsJson()) {
        Response::json(['error' => $message, 'status' => $status], $status)->send();
        return;
    }

    if ($status === 401) {
        Response::redirect('/login')->send();
        return;
    }

    Response::html($view->render('error', ['status' => $status, 'message' => $message]), $status)->send();
}
