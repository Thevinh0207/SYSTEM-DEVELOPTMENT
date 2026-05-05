<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Data\ViewData;
use App\Models\ServiceModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;
use Twig\Environment;

/**
 * Public marketing pages: home, services list, FAQ, about.
 * The services list is the only one that reads from the database — it groups
 * the live `services` rows by category so deleting a service in /admin makes
 * it disappear from the public menu immediately.
 */
class HomeController extends BaseController
{
    public function __construct(
        Environment $twig,
        string $basePath,
        private ?ServiceModel $services = null
    ) {
        parent::__construct($twig, $basePath);
    }

    public function home(Request $r, Response $response): Response
    {
        return $this->render($response, 'home.twig', [
            'active'   => 'home',
            'featured' => ViewData::featuredServices(),
        ]);
    }

    public function services(Request $r, Response $response): Response
    {
        try {
            $rows = $this->services ? $this->services->getAllServices() : [];
        } catch (Throwable $e) {
            $rows = [];
        }

        // Group by category so the template can render one section per group.
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['category']][] = [
                'name'        => $row['name'],
                'description' => $row['description'],
                'duration'    => $row['duration'] . ' min',
                'price'       => '$' . number_format((float) $row['price'], 2),
            ];
        }
        ksort($grouped);

        return $this->render($response, 'services.twig', [
            'active'         => 'services',
            'servicesByCat'  => $grouped,
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
