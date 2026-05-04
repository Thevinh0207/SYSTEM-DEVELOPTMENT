<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\PaymentModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class PaymentController
{
    public function __construct(
        private Environment $twig,
        private PaymentModel $paymentModel,
    ) {}

    // ─── Customer-facing ─────────────────────────────────────────────────────

    // GET /payments/my-payments
    public function myPayments(Request $request, Response $response): Response
    {
        $this->requireLogin();

        $userId = (int) $_SESSION['user_id'];

        return $this->render($response, 'payments/my-payments.twig', [
            'payments' => $this->paymentModel->getRecentPaymentsByUserId($userId, 20),
        ]);
    }

    // GET /payments/my-payments/{id}  — client viewing one of their own
    public function myPaymentView(Request $request, Response $response, array $args): Response
    {
        $this->requireLogin();

        $userId  = (int) $_SESSION['user_id'];
        $payment = $this->paymentModel->getPaymentForUser((int) $args['id'], $userId);

        if ($payment === null) {
            return $this->renderError($response, 404, 'Payment not found.');
        }

        return $this->render($response, 'payments/my-payment-view.twig', [
            'payment' => $payment,
        ]);
    }

    // GET /admin/payments/insights — admin only
    public function insights(Request $request, Response $response): Response
    {
        $this->requireAdmin();

        return $this->render($response, 'payments/insights.twig', [
            'insights' => $this->paymentModel->getInsights(),
        ]);
    }

    // ─── Admin ───────────────────────────────────────────────────────────────

    // GET /admin/payments
    public function index(Request $request, Response $response): Response
    {
        $this->requireAdmin();

        $params = $request->getQueryParams();

        return $this->render($response, 'payments/index.twig', [
            'payments'    => $this->paymentModel->getAll(),
            'justDeleted' => isset($params['deleted']),
            'justUpdated' => isset($params['updated']),
            'justCreated' => isset($params['created']),
        ]);
    }

    // GET /admin/payments/{id}
    public function view(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin();

        $payment = $this->paymentModel->getById((int) $args['id']);

        if ($payment === null) {
            return $this->renderError($response, 404, 'Payment not found.');
        }

        return $this->render($response, 'payments/view.twig', [
            'payment' => $payment,
        ]);
    }

    // GET /admin/payments/create
    public function create(Request $request, Response $response): Response
    {
        $this->requireAdmin();

        return $this->render($response, 'payments/create.twig', [
            'errors' => [],
            'old'    => [],
        ]);
    }

    // POST /admin/payments/create
    public function createSubmit(Request $request, Response $response): Response
    {
        $this->requireAdmin();

        $data   = $this->getPostData($request);
        $errors = $this->validate($data);

        if (!empty($errors)) {
            return $this->render($response, 'payments/create.twig', [
                'errors' => $errors,
                'old'    => $data,
            ]);
        }

        $this->paymentModel->create($data);
        return $this->redirect($response, '/admin/payments?created=1');
    }

    // GET /admin/payments/{id}/edit
    public function edit(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin();

        $payment = $this->paymentModel->getById((int) $args['id']);

        if ($payment === null) {
            return $this->renderError($response, 404, 'Payment not found.');
        }

        return $this->render($response, 'payments/edit.twig', [
            'payment' => $payment,
            'errors'  => [],
            'old'     => [],
        ]);
    }

    // POST /admin/payments/{id}/edit
    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin();

        $id      = (int) $args['id'];
        $payment = $this->paymentModel->getById($id);

        if ($payment === null) {
            return $this->renderError($response, 404, 'Payment not found.');
        }

        $data   = $this->getPostData($request);
        $errors = $this->validate($data, isUpdate: true);

        if (!empty($errors)) {
            return $this->render($response, 'payments/edit.twig', [
                'payment' => $payment,
                'errors'  => $errors,
                'old'     => $data,
            ]);
        }

        $this->paymentModel->update($id, $data);
        return $this->redirect($response, '/admin/payments?updated=1');
    }

    // POST /admin/payments/{id}/delete
    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin();

        $deleted = $this->paymentModel->delete((int) $args['id']);

        if (!$deleted) {
            return $this->renderError($response, 404, 'Payment not found or already deleted.');
        }

        return $this->redirect($response, '/admin/payments?deleted=1');
    }

    // ─── Validation ──────────────────────────────────────────────────────────

    private function validate(array $data, bool $isUpdate = false): array
    {
        $errors    = [];
        $isPresent = fn(string $key): bool => isset($data[$key]) && $data[$key] !== '';

        if (!$isUpdate || $isPresent('amount')) {
            if (!isset($data['amount']) || $data['amount'] === '') {
                $errors['amount'][] = 'Amount is required.';
            } elseif (!is_numeric($data['amount']) || (float) $data['amount'] <= 0) {
                $errors['amount'][] = 'Amount must be a positive number.';
            }
        }

        $validTypes = ['cash', 'credit_card', 'debit_card', 'online'];
        if (!$isUpdate || $isPresent('paymentType')) {
            if (empty($data['paymentType'])) {
                $errors['paymentType'][] = 'Please select a payment type.';
            } elseif (!in_array($data['paymentType'], $validTypes, true)) {
                $errors['paymentType'][] = 'Invalid payment type selected.';
            }
        }

        $validStatuses = ['pending', 'completed', 'failed', 'refunded'];
        if (!$isUpdate || $isPresent('paymentStatus')) {
            if (empty($data['paymentStatus'])) {
                $errors['paymentStatus'][] = 'Please select a payment status.';
            } elseif (!in_array($data['paymentStatus'], $validStatuses, true)) {
                $errors['paymentStatus'][] = 'Invalid payment status selected.';
            }
        }

        return $errors;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function getPostData(Request $request): array
    {
        $body = (array) $request->getParsedBody();
        return [
            'amount'        => trim($body['amount']        ?? ''),
            'paymentType'   => trim($body['paymentType']   ?? ''),
            'paymentStatus' => trim($body['paymentStatus'] ?? ''),
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
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
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