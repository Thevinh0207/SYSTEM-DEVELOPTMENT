<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

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

        $request = $request
            ->withAttribute('user_id', (int) $_SESSION['user_id'])
            ->withAttribute('user_role', $_SESSION['user_role'] ?? 'guest');

        return $handler->handle($request);
    }
}
