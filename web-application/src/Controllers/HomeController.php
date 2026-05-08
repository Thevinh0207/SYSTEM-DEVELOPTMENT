<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Data\ViewData;
use App\Models\AboutModel;
use App\Models\FaqModel;
use App\Models\ServiceModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;
use Twig\Environment;

class HomeController extends BaseController
{
    public function __construct(
        Environment $twig,
        string $basePath,
        private ?ServiceModel $services = null,
        private ?FaqModel $faqs         = null,
        private ?AboutModel $about      = null
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
            $rows = $this->services ? $this->services->findAllWithCategory() : [];
        } catch (Throwable $e) {
            $rows = [];
        }
        $grouped = [];
        foreach ($rows as $s) {
            $grouped[$s['category']][] = [
                'id'          => (int) $s['id'],
                'name'        => $s['name'],
                'description' => $s['description'],
                'duration'    => $s['duration'] . ' min',
                'price'       => '$' . number_format((float) $s['price'], 2),
            ];
        }
        ksort($grouped);

        return $this->render($response, 'services.twig', [
            'active'        => 'services',
            'servicesByCat' => $grouped,
        ]);
    }

    public function serviceDetail(Request $r, Response $response): Response
    {
        return $this->render($response, 'service-detail.twig', ['active' => 'services']);
    }

    public function about(Request $r, Response $response): Response
    {
        try {
            $beans = $this->about ? $this->about->findAll() : [];
        } catch (Throwable $e) {
            $beans = [];
        }
        $sections = [];
        foreach ($beans as $b) {
            $sections[] = ['heading' => $b->heading, 'body' => $b->body];
        }
        return $this->render($response, 'about.twig', [
            'active'   => 'about',
            'sections' => $sections,
        ]);
    }

    public function faq(Request $r, Response $response): Response
    {
        try {
            $grouped = $this->faqs ? $this->faqs->findGroupedByCategory() : [];
        } catch (Throwable $e) {
            $grouped = [];
        }
        $byCategory = [];
        foreach ($grouped as $category => $beans) {
            $rows = [];
            foreach ($beans as $b) {
                $rows[] = ['question' => $b->question, 'answer' => $b->answer];
            }
            $byCategory[$category] = $rows;
        }
        return $this->render($response, 'faq.twig', [
            'active'    => 'faq',
            'faqGroups' => $byCategory,
        ]);
    }
}
