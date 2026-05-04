<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ReviewModel;
use App\Models\ServiceModel;
use App\Models\UserModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class ReviewController
{
    public function __construct(
        private Environment $twig,
        private ReviewModel $reviewModel,
        private ServiceModel $serviceModel,
        private UserModel $userModel,
    ) {}

    // ─── Public ──────────────────────────────────────────────────────────────

    // GET /reviews
    public function index(Request $request, Response $response): Response
    {
        $enriched = array_map(function ($r) {
            $customer        = $this->userModel->getById((int) $r->customerId);
            $service         = $this->serviceModel->getById((int) $r->serviceId);
            $r->customerName = $customer
                ? $customer->firstName . ' ' . $customer->lastName
                : 'Anonymous';
            $r->serviceName  = $service?->name ?? 'Unknown Service';
            return $r;
        }, $this->reviewModel->getAll());

        return $this->render($response, 'reviews/index.twig', [
            'reviews' => $enriched,
        ]);
    }

    // ─── Customer-facing ─────────────────────────────────────────────────────

    // GET /reviews/write
    public function write(Request $request, Response $response): Response
    {
        $this->requireLogin();

        return $this->render($response, 'reviews/write.twig', [
            'services' => $this->serviceModel->getAll(),
            'errors'   => [],
            'old'      => [],
        ]);
    }

    // POST /reviews/write
    public function writeSubmit(Request $request, Response $response): Response
    {
        $this->requireLogin();

        $data               = $this->getPostData($request);
        $data['customerId'] = $_SESSION['user_id'];
        $data['reviewDate'] = date('Y-m-d');

        $errors = $this->validate($data);

        if (!empty($errors)) {
            return $this->render($response, 'reviews/write.twig', [
                'services' => $this->serviceModel->getAll(),
                'errors'   => $errors,
                'old'      => $data,
            ]);
        }

        $this->reviewModel->create($data);
        return $this->redirect($response, '/reviews/my-reviews?submitted=1');
    }

    // GET /reviews/my-reviews
    public function myReviews(Request $request, Response $response): Response
    {
        $this->requireLogin();

        $reviews = $this->reviewModel->getAllReviewsByCustomerId($_SESSION['user_id']);

        $enriched = array_map(function ($r) {
            $service        = $this->serviceModel->getById((int) $r->serviceId);
            $r->serviceName = $service?->name ?? 'Unknown Service';
            return $r;
        }, $reviews);

        $params = $request->getQueryParams();

        return $this->render($response, 'reviews/my-reviews.twig', [
            'reviews'       => $enriched,
            'justSubmitted' => isset($params['submitted']),
            'justDeleted'   => isset($params['deleted']),
            'justUpdated'   => isset($params['updated']),
        ]);
    }

    // GET /reviews/{id}/edit
    public function edit(Request $request, Response $response, array $args): Response
    {
        $this->requireLogin();

        $review = $this->reviewModel->getById((int) $args['id']);

        if ($review === null) {
            return $this->renderError($response, 404, 'Review not found.');
        }

        if ((int) $review->customerId !== $_SESSION['user_id']) {
            return $this->renderError($response, 403, 'You are not allowed to edit this review.');
        }

        return $this->render($response, 'reviews/edit.twig', [
            'review' => $review,
            'errors' => [],
            'old'    => [],
        ]);
    }

    // POST /reviews/{id}/edit
    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $this->requireLogin();

        $id     = (int) $args['id'];
        $review = $this->reviewModel->getById($id);

        if ($review === null) {
            return $this->renderError($response, 404, 'Review not found.');
        }

        if ((int) $review->customerId !== $_SESSION['user_id']) {
            return $this->renderError($response, 403, 'You are not allowed to edit this review.');
        }

        $data   = $this->getEditData($request);
        $errors = $this->validateEdit($data);

        if (!empty($errors)) {
            return $this->render($response, 'reviews/edit.twig', [
                'review' => $review,
                'errors' => $errors,
                'old'    => $data,
            ]);
        }

        $this->reviewModel->editReview($id, $data);
        return $this->redirect($response, '/reviews/my-reviews?updated=1');
    }

    // POST /reviews/{id}/delete
    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->requireLogin();

        $review = $this->reviewModel->getById((int) $args['id']);

        if ($review === null) {
            return $this->renderError($response, 404, 'Review not found.');
        }

        if ((int) $review->customerId !== $_SESSION['user_id']) {
            return $this->renderError($response, 403, 'You are not allowed to delete this review.');
        }

        $this->reviewModel->deleteReview((int) $args['id']);
        return $this->redirect($response, '/reviews/my-reviews?deleted=1');
    }

    // ─── Admin ───────────────────────────────────────────────────────────────

    // GET /admin/reviews
    public function adminIndex(Request $request, Response $response): Response
    {
        $this->requireAdmin();

        $enriched = array_map(function ($r) {
            $customer        = $this->userModel->getById((int) $r->customerId);
            $service         = $this->serviceModel->getById((int) $r->serviceId);
            $r->customerName = $customer
                ? $customer->firstName . ' ' . $customer->lastName
                : 'Unknown';
            $r->serviceName  = $service?->name ?? 'Unknown Service';
            return $r;
        }, $this->reviewModel->getAll());

        $params = $request->getQueryParams();

        return $this->render($response, 'reviews/admin-index.twig', [
            'reviews'     => $enriched,
            'justDeleted' => isset($params['deleted']),
        ]);
    }

    // POST /admin/reviews/{id}/delete
    public function adminDelete(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin();

        $deleted = $this->reviewModel->delete((int) $args['id']);

        if (!$deleted) {
            return $this->renderError($response, 404, 'Review not found or already deleted.');
        }

        return $this->redirect($response, '/admin/reviews?deleted=1');
    }

    // ─── Validation ──────────────────────────────────────────────────────────

    private function validate(array $data): array
    {
        $errors = [];

        if (empty($data['serviceId'])) {
            $errors['serviceId'][] = 'Please select a service.';
        } elseif (!ctype_digit((string) $data['serviceId'])) {
            $errors['serviceId'][] = 'Invalid service selected.';
        } elseif ($this->serviceModel->getById((int) $data['serviceId']) === null) {
            $errors['serviceId'][] = 'The selected service does not exist.';
        }

        if (!isset($data['rating']) || $data['rating'] === '') {
            $errors['rating'][] = 'Please select a rating.';
        } elseif (!ctype_digit((string) $data['rating']) || (int) $data['rating'] < 1 || (int) $data['rating'] > 5) {
            $errors['rating'][] = 'Rating must be between 1 and 5.';
        }

        if (!empty($data['comment']) && mb_strlen($data['comment']) > 1000) {
            $errors['comment'][] = 'Comment cannot exceed 1000 characters.';
        }

        return $errors;
    }

    private function validateEdit(array $data): array
    {
        $errors = [];

        if (!isset($data['rating']) || $data['rating'] === '') {
            $errors['rating'][] = 'Please select a rating.';
        } elseif (!ctype_digit((string) $data['rating']) || (int) $data['rating'] < 1 || (int) $data['rating'] > 5) {
            $errors['rating'][] = 'Rating must be between 1 and 5.';
        }

        if (!empty($data['comment']) && mb_strlen($data['comment']) > 1000) {
            $errors['comment'][] = 'Comment cannot exceed 1000 characters.';
        }

        return $errors;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function getPostData(Request $request): array
    {
        $body = (array) $request->getParsedBody();
        return [
            'serviceId' => trim($body['serviceId'] ?? ''),
            'rating'    => trim($body['rating']    ?? ''),
            'comment'   => trim($body['comment']   ?? ''),
        ];
    }

    private function getEditData(Request $request): array
    {
        $body = (array) $request->getParsedBody();
        return [
            'rating'  => trim($body['rating']  ?? ''),
            'comment' => trim($body['comment'] ?? ''),
        ];
    }

    private function requireLogin(): void
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
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