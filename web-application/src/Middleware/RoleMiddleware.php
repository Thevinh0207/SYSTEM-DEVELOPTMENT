<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class RoleMiddleware implements MiddlewareInterface
{
    public const GUEST  = 'guest';
    public const CLIENT = 'client';
    public const ADMIN  = 'admin';

    private const ALL_ROLES = [self::GUEST, self::CLIENT, self::ADMIN];

    private array $allowed;

    public function __construct(string ...$allowed)
    {
        $allowed = array_values(array_unique($allowed));

        foreach ($allowed as $role) {
            if (!in_array($role, self::ALL_ROLES, true)) {
                throw new \InvalidArgumentException("Unknown role: {$role}");
            }
        }

        $this->allowed = $allowed ?: self::ALL_ROLES;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $role = $this->resolveRole();
        $userId = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;

        if (!in_array($role, $this->allowed, true)) {
            return $this->deny($role);
        }

        return $handler->handle(
            $request
                ->withAttribute('user_role', $role)
                ->withAttribute('user_id', $userId !== null ? (int) $userId : null)
        );
    }

    private function resolveRole(): string
    {
        $sessionUser = $_SESSION['user'] ?? null;
        $userId = $sessionUser['id'] ?? $_SESSION['user_id'] ?? null;

        if (empty($userId)) {
            return self::GUEST;
        }

        $role = $sessionUser['role'] ?? $_SESSION['user_role'] ?? self::CLIENT;

        return in_array($role, self::ALL_ROLES, true) ? $role : self::CLIENT;
    }

    private function deny(string $role): Response
    {
        $response = new SlimResponse();

        if ($role === self::GUEST) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $response->getBody()->write('403 Forbidden — your role is not permitted to access this resource.');
        return $response->withStatus(403);
    }
}
