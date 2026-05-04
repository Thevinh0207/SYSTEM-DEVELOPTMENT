<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class AdminController
{
    private const FLAG_FILE = __DIR__ . '/../../var/maintenance.flag';

    public function __construct(private Environment $twig) {}

    public function dashboard(Request $request, Response $response): Response
    {
        $html = $this->twig->render('admin/dashboard.twig', [
            'maintenance' => $this->isMaintenanceOn(),
            'locale'      => $request->getAttribute('locale', 'en'),
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function enableMaintenance(Request $request, Response $response): Response
    {
        $dir = dirname(self::FLAG_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents(self::FLAG_FILE, (string) time());

        return $response
            ->withHeader('Location', '/admin')
            ->withStatus(302);
    }

    public function disableMaintenance(Request $request, Response $response): Response
    {
        if (file_exists(self::FLAG_FILE)) {
            unlink(self::FLAG_FILE);
        }

        return $response
            ->withHeader('Location', '/admin')
            ->withStatus(302);
    }

    private function isMaintenanceOn(): bool
    {
        return (defined('MAINTENANCE_MODE') && MAINTENANCE_MODE === true)
            || file_exists(self::FLAG_FILE);
    }
}
