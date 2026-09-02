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

use App\Controller\AuthController;
use App\Controller\DashboardController;
use App\Controller\UserController;
use App\Entity\Role;
use App\Repository\MySqlUserRepository;
use App\Service\AuthService;
use App\Service\Exception\AuthorizationException;
use App\Service\UserService;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Exception\HttpException;
use App\Support\PdoTransactionManager;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\Session;
use App\Support\View;

require dirname(__DIR__) . '/vendor/autoload.php';

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

    // --- repositories ----------------------------------------------------
    $userRepository = new MySqlUserRepository($pdo);

    // --- services --------------------------------------------------------
    $authService = new AuthService($userRepository);
    $userService = new UserService($userRepository);

    // --- controllers -----------------------------------------------------
    $authController = new AuthController($authService, $session, $view, $csrf);
    $dashboardController = new DashboardController($session, $view);
    $userController = new UserController($userService, $session, $view, $csrf);

    // Values every layout needs. Flash messages are read once per request.
    $view->share('csrfToken', $csrf->token());
    $view->share('flashes', $session->takeFlash());
    $view->share('user', $session->user());

    // --- routes ----------------------------------------------------------
    // Third argument: null = public, [] = any signed-in user, [Role...] = those roles.
    // This is the COARSE guard only; per-record rules live in the services.
    $router = new Router($session);

    $router->get('/', static fn (): Response => Response::redirect('/dashboard'), null);
    $router->get('/login', $authController->showLogin(...), null);
    $router->post('/login', $authController->login(...), null);
    $router->post('/logout', $authController->logout(...), []);

    $router->get('/dashboard', $dashboardController->index(...), []);

    $router->get('/users', $userController->index(...), [Role::Admin]);
    $router->get('/users/create', $userController->create(...), [Role::Admin]);
    $router->post('/users', $userController->store(...), [Role::Admin]);
    $router->get('/users/{id}/edit', $userController->edit(...), [Role::Admin]);
    $router->post('/users/{id}', $userController->update(...), [Role::Admin]);
    $router->post('/users/{id}/active', $userController->toggleActive(...), [Role::Admin]);

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
        default => [500, 'Something went wrong. Please try again.'],
    };

    if ($status >= 500) {
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
