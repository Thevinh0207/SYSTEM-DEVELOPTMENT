<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class MaintenanceMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        if (defined('MAINTENANCE_MODE') && MAINTENANCE_MODE === true) {
            $role = $_SESSION['user_role'] ?? 'guest';
            if ($role !== 'admin') {
                $response = new SlimResponse();
                $response->getBody()->write('Site is currently down for maintenance. Please check back later.');
                return $response
                    ->withStatus(503)
                    ->withHeader('Retry-After', '3600');
            }
        }

        return $handler->handle($request);
    }
}
