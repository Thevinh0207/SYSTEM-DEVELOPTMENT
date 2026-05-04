<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class NotFoundController
{
    public function __construct(private Environment $twig) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $html = $this->twig->render('errors/404.twig', [
            'path'   => $request->getUri()->getPath(),
            'locale' => $request->getAttribute('locale', 'en'),
        ]);

        $response->getBody()->write($html);
        return $response
            ->withStatus(404)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
