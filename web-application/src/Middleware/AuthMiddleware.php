<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * AuthMiddleware — Requires the User to be Logged In
 * =====================================================
 * Attach this to any route that should only be accessible to logged-in users.
 * If the user is not logged in (no session), they get redirected to /login.
 *
 * How middleware works in Slim:
 *   Request comes in → Middleware runs → (passes to) → Route handler
 *   The middleware can either let the request through (call $handler->handle())
 *   or block it and return a different response (like a redirect).
 *
 * Usage in index.php:
 *   $app->get('/dashboard', [DashboardController::class, 'index'])->add(new AuthMiddleware());
 */
class AuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        if (empty($_SESSION['user_id'])) {
            $response = new SlimResponse();
            return $response
                ->withHeader('Location', '/login')
                ->withStatus(302);
        }

        // Logged in — attach user info to the request so route handlers can read it
        // via $request->getAttribute('user_id') and $request->getAttribute('user_role')
        $request = $request
            ->withAttribute('user_id', (int) $_SESSION['user_id'])
            ->withAttribute('user_role', $_SESSION['user_role'] ?? 'guest');

        return $handler->handle($request);
    }
}
