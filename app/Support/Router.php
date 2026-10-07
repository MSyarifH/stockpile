<?php

declare(strict_types=1);

namespace App\Support;

use App\Entity\Role;
use App\Support\Exception\HttpException;

/**
 * Route table.
 *
 * Roles declared here are the COARSE guard: may this role reach this URL at all.
 * Fine-grained rules ("may this user approve THIS order") belong in the service
 * layer, so they hold for the JSON API and CLI scripts too, not just the browser.
 */
final class Router
{
    /** @var list<array{method:string,pattern:string,handler:callable,roles:list<Role>|null}> */
    private array $routes = [];

    public function __construct(private readonly Session $session)
    {
    }

    /** @param list<Role>|null $roles null = public route */
    public function get(string $pattern, callable $handler, ?array $roles = []): void
    {
        $this->add('GET', $pattern, $handler, $roles);
    }

    /** @param list<Role>|null $roles */
    public function post(string $pattern, callable $handler, ?array $roles = []): void
    {
        $this->add('POST', $pattern, $handler, $roles);
    }

    /** @param list<Role>|null $roles */
    private function add(string $method, string $pattern, callable $handler, ?array $roles): void
    {
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'handler' => $handler,
            'roles' => $roles,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;

        // RFC 9110: HEAD must be served wherever GET is, identically but with
        // no body. Apache drops the body for a HEAD response, so matching it as
        // GET is all that is needed — and it is what makes `curl -I` and any
        // health check behave correctly instead of receiving 405.
        $method = $request->method() === 'HEAD' ? 'GET' : $request->method();

        foreach ($this->routes as $route) {
            $parameters = $this->match($route['pattern'], $request->path());
            if ($parameters === null) {
                continue;
            }

            $pathMatched = true;
            if ($route['method'] !== $method) {
                continue;
            }

            $this->guard($route['roles']);

            /** @var Response */
            return ($route['handler'])($request, ...array_values($parameters));
        }

        // A path that exists but was reached with the wrong verb is a 405, not a 404.
        throw $pathMatched
            ? new HttpException('That action is not allowed on this URL.', 405)
            : HttpException::notFound();
    }

    /**
     * @param list<Role>|null $roles
     */
    private function guard(?array $roles): void
    {
        if ($roles === null) {
            return; // public route
        }

        $user = $this->session->user();
        if ($user === null) {
            throw HttpException::unauthorised();
        }

        // An empty list means "any authenticated user".
        if ($roles !== [] && !in_array($user->role, $roles, true)) {
            throw HttpException::forbidden();
        }
    }

    /**
     * Converts "/users/{id}/edit" into a regex and returns the captured parameters.
     *
     * @return array<string,string>|null null when the pattern does not match
     */
    private function match(string $pattern, string $path): ?array
    {
        if ($pattern === $path) {
            return [];
        }

        if (!str_contains($pattern, '{')) {
            return null;
        }

        $regex = preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $pattern);
        if (!is_string($regex)) {
            return null;
        }

        if (preg_match('#^' . $regex . '$#', $path, $matches) !== 1) {
            return null;
        }

        return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
    }
}
