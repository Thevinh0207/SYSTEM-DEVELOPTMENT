<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Data\ViewData;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Response as Psr7Response;
use Twig\Environment;

/**
 * Common helpers every controller uses: rendering Twig templates and
 * issuing redirects that respect the application's base path.
 *
 * Subclasses receive a Twig environment + base path through the constructor
 * and use $this->render() / $this->redirect() instead of touching the
 * response object directly.
 */
abstract class BaseController
{
    public function __construct(
        protected Environment $twig,
        protected string $basePath = ''
    ) {}

    protected function render(Response $response, string $template, array $data = []): Response
    {
        $defaults = [
            'images'      => ViewData::images(),
            'currentUser' => $_SESSION['user'] ?? null,
        ];
        $response->getBody()->write($this->twig->render($template, $data + $defaults));
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    protected function redirect(string $to): Response
    {
        $url = $this->basePath . ($to === '/' ? '/' : '/' . ltrim($to, '/'));
        return (new Psr7Response())->withHeader('Location', $url)->withStatus(302);
    }

    protected function flash(string $type, string $message, string $key = 'admin_flash'): void
    {
        $_SESSION[$key] = ['type' => $type, 'message' => $message];
    }
}
