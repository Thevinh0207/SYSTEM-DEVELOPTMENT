<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class MaintenanceController
{
    public function __construct(private Environment $twig) {}

    public function show(Request $request, Response $response): Response
    {
        $html = $this->twig->render('maintenance.twig', [
            'retryAfter' => 3600,
            'locale'     => $request->getAttribute('locale', 'en'),
        ]);

        $response->getBody()->write($html);
        return $response
            ->withStatus(503)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Retry-After', '3600');
    }
}
