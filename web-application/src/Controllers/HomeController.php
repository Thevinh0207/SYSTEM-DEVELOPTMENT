<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Data\ViewData;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Public, mostly-static pages: home, services list, FAQ, about.
 * No database state — these just render the marketing pages.
 */
class HomeController extends BaseController
{
    public function home(Request $r, Response $response): Response
    {
        return $this->render($response, 'home.twig', [
            'active'   => 'home',
            'featured' => ViewData::featuredServices(),
        ]);
    }

    public function services(Request $r, Response $response): Response
    {
        return $this->render($response, 'services.twig', [
            'active'      => 'services',
            'serviceRows' => ViewData::nailCareServices(),
            'extensions'  => ViewData::extensionGroups(),
            'nailArt'     => ViewData::nailArtServices(),
        ]);
    }

    public function serviceDetail(Request $r, Response $response): Response
    {
        return $this->render($response, 'service-detail.twig', ['active' => 'services']);
    }

    public function about(Request $r, Response $response): Response
    {
        return $this->render($response, 'about.twig', ['active' => 'about']);
    }

    public function faq(Request $r, Response $response): Response
    {
        return $this->render($response, 'faq.twig', ['active' => 'faq']);
    }
}
