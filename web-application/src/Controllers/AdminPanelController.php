<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AppointmentModel;
use App\Models\ReviewModel;
use App\Models\ServiceModel;
use App\Models\UserModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;
use Twig\Environment;

/**
 * AdminPanelController — admin dashboard + service CRUD + review replies.
 *
 * Every action calls $this->guard() first to ensure the visitor is signed in
 * as an admin (redirects guests to /login, returns a 403 for clients).
 */
class AdminPanelController extends BaseController
{
    public function __construct(
        Environment $twig,
        string $basePath,
        private AppointmentModel $appointments,
        private ServiceModel $services,
        private ReviewModel $reviews
    ) {
        parent::__construct($twig, $basePath);
    }

    // ── Dashboard view ───────────────────────────────────────────────────
    public function dashboard(Request $r, Response $response, array $args = []): Response
    {
        if ($block = $this->guard()) return $block;

        $section = (string) ($args['section'] ?? 'appointments');
        $flash   = $_SESSION['admin_flash'] ?? null;
        unset($_SESSION['admin_flash']);

        try {
            $data = [
                'appointments' => $this->appointments->getAllAppointements(),
                'services'     => $this->services->getAllServices(),
                'reviews'      => $this->reviews->getAll(),
            ];
        } catch (Throwable $e) {
            $data = ['appointments' => [], 'services' => [], 'reviews' => []];
        }

        return $this->render($response, 'admin.twig', [
            'section' => $section,
            'flash'   => $flash,
        ] + $data);
    }

    // ── Services: create ─────────────────────────────────────────────────
    public function newService(Request $r, Response $response): Response
    {
        if ($block = $this->guard()) return $block;
        $form   = $_SESSION['admin_form']   ?? [];
        $errors = $_SESSION['admin_errors'] ?? [];
        unset($_SESSION['admin_form'], $_SESSION['admin_errors']);
        return $this->render($response, 'admin/service-form.twig', [
            'mode'    => 'create',
            'service' => $form,
            'errors'  => $errors,
        ]);
    }

    public function createService(Request $r, Response $response): Response
    {
        if ($block = $this->guard()) return $block;

        $data = $this->extractServiceForm($r);
        if ($errors = $this->validateService($data)) {
            $_SESSION['admin_errors'] = $errors;
            $_SESSION['admin_form']   = $data;
            return $this->redirect('/admin/services/new');
        }

        try {
            $this->services->create($data);
            $this->flash('success', 'Service created.');
        } catch (Throwable $e) {
            $this->flash('error', 'Could not create service.');
        }
        return $this->redirect('/admin/services');
    }

    // ── Services: edit ───────────────────────────────────────────────────
    public function editService(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;

        $id      = (int) $args['id'];
        $service = $this->services->getById($id);
        if (!$service) {
            $this->flash('error', 'Service not found.');
            return $this->redirect('/admin/services');
        }

        $form = $_SESSION['admin_form'] ?? [
            'name'        => $service['name'],
            'category'    => $service['category'],
            'description' => $service['description'],
            'price'       => $service['price'],
            'duration'    => $service['duration'],
        ];
        $errors = $_SESSION['admin_errors'] ?? [];
        unset($_SESSION['admin_form'], $_SESSION['admin_errors']);

        return $this->render($response, 'admin/service-form.twig', [
            'mode'    => 'edit',
            'id'      => $id,
            'service' => $form,
            'errors'  => $errors,
        ]);
    }

    public function updateService(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;

        $id   = (int) $args['id'];
        $data = $this->extractServiceForm($r);

        if ($errors = $this->validateService($data)) {
            $_SESSION['admin_errors'] = $errors;
            $_SESSION['admin_form']   = $data;
            return $this->redirect("/admin/services/{$id}/edit");
        }

        try {
            $ok = $this->services->update($id, $data);
            $ok ? $this->flash('success', 'Service updated.')
                : $this->flash('error', 'Service not found.');
        } catch (Throwable $e) {
            $this->flash('error', 'Could not update service.');
        }
        return $this->redirect('/admin/services');
    }

    public function deleteService(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;

        try {
            $this->services->delete((int) $args['id']);
            $this->flash('success', 'Service deleted.');
        } catch (Throwable $e) {
            // FK constraint: ON DELETE RESTRICT — service is referenced by appointments.
            $this->flash('error', 'Cannot delete: this service is used by existing appointments.');
        }
        return $this->redirect('/admin/services');
    }

    // ── Reviews: reply ───────────────────────────────────────────────────
    public function replyToReview(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;

        $reply = (string) (((array) $r->getParsedBody())['reply'] ?? '');
        try {
            $ok = $this->reviews->replyToReview((int) $args['id'], $reply);
            if (!$ok) {
                $this->flash('error', 'Could not save reply.');
            } else {
                $this->flash('success', trim($reply) === '' ? 'Reply removed.' : 'Reply saved.');
            }
        } catch (Throwable $e) {
            $this->flash('error', 'Could not save reply.');
        }
        return $this->redirect('/admin/reviews');
    }

    // ── Helpers ──────────────────────────────────────────────────────────
    /** Returns a Response (redirect/403) if the current user isn't an admin, otherwise null. */
    private function guard(): ?Response
    {
        if (empty($_SESSION['user'])) {
            return $this->redirect('/login');
        }
        if (($_SESSION['user']['role'] ?? null) !== UserModel::ROLE_ADMIN) {
            $response = new \Slim\Psr7\Response();
            $html = $this->twig->render('Errors/generic.twig', [
                'code'    => 403,
                'title'   => 'Access denied',
                'message' => 'You must be signed in as an admin to view this page.',
            ]);
            $response->getBody()->write($html);
            return $response->withStatus(403)->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
        return null;
    }

    private function extractServiceForm(Request $r): array
    {
        $body = (array) $r->getParsedBody();
        return [
            'name'        => trim((string) ($body['name']        ?? '')),
            'category'    => trim((string) ($body['category']    ?? '')),
            'description' => trim((string) ($body['description'] ?? '')),
            'price'       => trim((string) ($body['price']       ?? '')),
            'duration'    => trim((string) ($body['duration']    ?? '')),
        ];
    }

    private function validateService(array $data): array
    {
        $errors = [];
        if ($data['name']        === '') $errors['name']        = 'Name is required.';
        if ($data['category']    === '') $errors['category']    = 'Category is required.';
        if ($data['description'] === '') $errors['description'] = 'Description is required.';
        if ($data['price'] === '' || !is_numeric($data['price']) || (float) $data['price'] < 0) {
            $errors['price'] = 'Price must be a non-negative number.';
        }
        if ($data['duration'] === '' || !ctype_digit((string) $data['duration']) || (int) $data['duration'] <= 0) {
            $errors['duration'] = 'Duration must be a positive whole number of minutes.';
        }
        return $errors;
    }
}
