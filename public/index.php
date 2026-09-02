<?php

declare(strict_types=1);

/**
 * Front controller and composition root.
 *
 * Every object graph is wired here, by hand. There is deliberately no DI
 * container: the brief's FAQ #2 states manual constructor injection is
 * sufficient, and a reflection-based container would be structure that
 * solves no problem this project actually has (§0).
 *
 * Routing and wiring arrive in Phase 1; this placeholder exists so that
 * Apache has an entry point and directory listing is never reachable.
 */

http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
echo "Inventory & Order Management System\n";
echo "Environment is up. Application routing lands in Phase 1.\n";
