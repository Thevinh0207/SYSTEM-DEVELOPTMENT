<?php

// AdminController.php
// Handles the admin dashboard and the maintenance mode toggle.

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class AdminController
{
    /**
     * Path to the maintenance flag file.
     * When this file EXISTS, maintenance mode is ON.
     * When it DOESN'T EXIST, maintenance mode is OFF.
     * The file contains the timestamp when maintenance was enabled.
     */

    private const FLAG_FILE = __DIR__ . '/../../var/maintenance.flag';

    public function __construct(private Environment $twig) {}

    /**
     * GET /admin — Main admin dashboard.
     * Passes whether maintenance mode is currently on so the template can
     * show the correct toggle button state.
     */

    public function dashboard(Request $request, Response $response): Response
    {
        $html = $this->twig->render('admin/dashboard.twig', [
            'maintenance' => $this->isMaintenanceOn(),
            'locale'      => $request->getAttribute('locale', 'en'),
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * POST /admin/maintenance/enable — Turns maintenance mode on.
     * Creates the flag file in var/ which MaintenanceMiddleware checks on every request.
     */

    public function enableMaintenance(Request $request, Response $response): Response
    {
        $dir = dirname(self::FLAG_FILE);
        // Create the var/ directory if it doesn't exist yet
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // Write the current timestamp to the flag file (content doesn't matter, existence does)
        file_put_contents(self::FLAG_FILE, (string) time());

        return $response
            ->withHeader('Location', '/admin')
            ->withStatus(302);
    }

    /**
     * POST /admin/maintenance/disable — Turns maintenance mode off.
     * Deletes the flag file so the site becomes accessible again.
     */

    public function disableMaintenance(Request $request, Response $response): Response
    {
        if (file_exists(self::FLAG_FILE)) {
            unlink(self::FLAG_FILE);
        }

        return $response
            ->withHeader('Location', '/admin')
            ->withStatus(302);
    }

    /**
     * Checks whether maintenance mode is currently active.
     * Mode is ON if either the MAINTENANCE_MODE constant is true
     * OR the flag file exists (flag file takes priority — allows dynamic toggle).
     */

    private function isMaintenanceOn(): bool
    {
        return (defined('MAINTENANCE_MODE') && MAINTENANCE_MODE === true)
            || file_exists(self::FLAG_FILE);
    }
}
