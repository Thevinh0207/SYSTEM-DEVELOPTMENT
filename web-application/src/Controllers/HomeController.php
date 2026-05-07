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
                'id'          => (int) $row['ServiceID'],
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

    public function serviceDetail(Request $r, Response $response, array $args = []): Response
    {
        $service = null;
        $reviews = [];

        if (isset($args['id'])) {
            try {
                $service = $this->services?->getById((int) $args['id']);
                $reviews = $service ? (new \App\Models\ReviewModel())->getByServiceId((int) $args['id']) : [];
            } catch (Throwable $e) {
                $service = null;
                $reviews = [];
            }
        }

        if (!$service) {
            $service = [
                'ServiceID' => 1,
                'name' => 'Gel-X Extensions',
                'category' => 'Extensions',
                'description' => 'Our Gel-X extension service gives you long-lasting, durable nails with a natural look and feel. Unlike traditional acrylic, Gel-X extensions are gentler on your natural nails while providing strength and flexibility.',
                'price' => 60.00,
                'duration' => 60,
            ];
        }

        return $this->render($response, 'service-detail.twig', [
            'active' => 'services',
            'service' => [
                'id' => (int) $service['ServiceID'],
                'name' => $service['name'],
                'category' => $service['category'],
                'description' => $service['description'],
                'price' => '$' . number_format((float) $service['price'], 2),
                'duration' => (int) $service['duration'] . ' minutes',
            ],
            'reviews' => $reviews,
        ]);
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
