<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ServiceModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class ServiceController
{
    public function __construct(
        private Environment $twig,
        private ServiceModel $serviceModel,
    ) {}

    // ─── Public ──────────────────────────────────────────────────────────────

    // GET /services
    public function index(Request $request, Response $response): Response
    {
        return $this->render($response, 'services/index.twig', [
            'services' => $this->serviceModel->getAll(),
        ]);
    }

    // GET /services/{id}
    public function view(Request $request, Response $response, array $args): Response
    {
        $service = $this->serviceModel->getById((int) $args['id']);

        if ($service === null) {
            return $this->renderError($response, 404, 'Service not found.');
        }

        return $this->render($response, 'services/view.twig', [
            'service' => $service,
        ]);
    }

    // GET /services/category?category=manicure
    public function byCategory(Request $request, Response $response): Response
    {
        $category = trim($request->getQueryParams()['category'] ?? '');

        if ($category === '') {
            return $this->redirect($response, '/services');
        }

        return $this->render($response, 'services/by-category.twig', [
            'services' => $this->serviceModel->getAllServicesByCategory($category),
            'category' => $category,
        ]);
    }

    // ─── Admin ───────────────────────────────────────────────────────────────

    // GET /admin/services/create
    public function create(Request $request, Response $response): Response
    {
        $this->requireAdmin();

        return $this->render($response, 'services/create.twig', [
            'errors' => [],
            'old'    => [],
        ]);
    }

    // POST /admin/services/create
    public function createSubmit(Request $request, Response $response): Response
    {
        $this->requireAdmin();

        $data   = $this->getPostData($request);
        $errors = $this->validate($data);

        if (!empty($errors)) {
            return $this->render($response, 'services/create.twig', [
                'errors' => $errors,
                'old'    => $data,
            ]);
        }

        $this->serviceModel->create($data);
        return $this->redirect($response, '/services?created=1');
    }

    // GET /admin/services/{id}/edit
    public function edit(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin();

        $service = $this->serviceModel->getById((int) $args['id']);

        if ($service === null) {
            return $this->renderError($response, 404, 'Service not found.');
        }

        return $this->render($response, 'services/edit.twig', [
            'service' => $service,
            'errors'  => [],
            'old'     => [],
        ]);
    }

    // POST /admin/services/{id}/edit
    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin();

        $id      = (int) $args['id'];
        $service = $this->serviceModel->getById($id);

        if ($service === null) {
            return $this->renderError($response, 404, 'Service not found.');
        }

        $data   = $this->getPostData($request);
        $errors = $this->validate($data, isUpdate: true);

        if (!empty($errors)) {
            return $this->render($response, 'services/edit.twig', [
                'service' => $service,
                'errors'  => $errors,
                'old'     => $data,
            ]);
        }

        $this->serviceModel->update($id, $data);
        return $this->redirect($response, '/services?updated=1');
    }

    // POST /admin/services/{id}/delete
    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin();

        $deleted = $this->serviceModel->deleteServiceById((int) $args['id']);

        if (!$deleted) {
            return $this->renderError($response, 404, 'Service not found or already deleted.');
        }

        return $this->redirect($response, '/services?deleted=1');
    }

    // ─── Validation ──────────────────────────────────────────────────────────

    private function validate(array $data, bool $isUpdate = false): array
    {
        $errors    = [];
        $isPresent = fn(string $key): bool => isset($data[$key]) && $data[$key] !== '';

        if (!$isUpdate || $isPresent('name')) {
            if (empty($data['name'])) {
                $errors['name'][] = 'Service name is required.';
            } elseif (mb_strlen($data['name']) > 100) {
                $errors['name'][] = 'Service name cannot exceed 100 characters.';
            }
        }

        if (!$isUpdate || $isPresent('category')) {
            if (empty($data['category'])) {
                $errors['category'][] = 'Category is required.';
            } elseif (mb_strlen($data['category']) > 100) {
                $errors['category'][] = 'Category cannot exceed 100 characters.';
            }
        }

        if (!$isUpdate || $isPresent('price')) {
            if (!isset($data['price']) || $data['price'] === '') {
                $errors['price'][] = 'Price is required.';
            } elseif (!is_numeric($data['price']) || (float) $data['price'] < 0) {
                $errors['price'][] = 'Price must be a valid non-negative number.';
            }
        }

        if (!$isUpdate || $isPresent('duration')) {
            if (empty($data['duration'])) {
                $errors['duration'][] = 'Duration is required.';
            } elseif (mb_strlen($data['duration']) > 50) {
                $errors['duration'][] = 'Duration cannot exceed 50 characters.';
            }
        }

        if ($isPresent('description') && mb_strlen($data['description']) > 500) {
            $errors['description'][] = 'Description cannot exceed 500 characters.';
        }

        return $errors;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function getPostData(Request $request): array
    {
        $body = (array) $request->getParsedBody();
        return [
            'name'        => trim($body['name']        ?? ''),
            'category'    => trim($body['category']    ?? ''),
            'description' => trim($body['description'] ?? ''),
            'price'       => trim($body['price']       ?? ''),
            'duration'    => trim($body['duration']    ?? ''),
        ];
    }

    private function requireAdmin(): void
    {
        if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
            header('Location: /login');
            exit;
        }
    }

    private function render(Response $response, string $template, array $data = []): Response
    {
        $response->getBody()->write($this->twig->render($template, $data));
        return $response;
    }

    private function renderError(Response $response, int $code, string $message): Response
    {
        $response->getBody()->write($this->twig->render('errors/generic.twig', [
            'code'    => $code,
            'message' => $message,
        ]));
        return $response->withStatus($code);
    }

    private function redirect(Response $response, string $url): Response
    {
        return $response->withHeader('Location', $url)->withStatus(302);
    }
}