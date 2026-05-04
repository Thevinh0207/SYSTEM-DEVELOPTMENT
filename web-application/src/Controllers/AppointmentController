<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AppointmentModel;
use App\Models\ServiceModel;
use App\Models\UserModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class AppointmentController
{
    public function __construct(
        private Environment $twig,
        private AppointmentModel $appointmentModel,
        private ServiceModel $serviceModel,
        private UserModel $userModel,
    ) {}

    // ─── Customer-facing ─────────────────────────────────────────────────────

    // GET /appointments/book
    public function book(Request $request, Response $response): Response
    {
        $this->requireLogin();

        return $this->render($response, 'appointments/book.twig', [
            'services' => $this->serviceModel->getAll(),
            'errors'   => [],
            'old'      => [],
        ]);
    }

    // POST /appointments/book
    public function bookSubmit(Request $request, Response $response): Response
    {
        $this->requireLogin();

        $data               = $this->getPostData($request);
        $data['customerId'] = $_SESSION['user_id'];
        $errors             = $this->validate($data);

        if (!empty($errors)) {
            return $this->render($response, 'appointments/book.twig', [
                'services' => $this->serviceModel->getAll(),
                'errors'   => $errors,
                'old'      => $data,
            ]);
        }

        $this->appointmentModel->create($data);
        return $this->redirect($response, '/appointments/my-appointments?booked=1');
    }

    // GET /appointments/my-appointments
    public function myAppointments(Request $request, Response $response): Response
    {
        $this->requireLogin();

        $userId = $_SESSION['user_id'];
        $all    = $this->appointmentModel->getAll();

        $appointments = array_values(array_filter(
            $all,
            fn($a) => (int) $a->customerId === $userId
        ));

        $enriched = array_map(function ($a) {
            $service         = $this->serviceModel->getById((int) $a->serviceId);
            $a->serviceName  = $service?->name  ?? 'Unknown Service';
            $a->servicePrice = $service?->price ?? 0.0;
            return $a;
        }, $appointments);

        $params = $request->getQueryParams();

        return $this->render($response, 'appointments/my-appointments.twig', [
            'appointments' => $enriched,
            'justBooked'   => isset($params['booked']),
        ]);
    }

    // ─── Admin ───────────────────────────────────────────────────────────────

    // GET /admin/appointments
    public function index(Request $request, Response $response): Response
    {
        $this->requireAdmin();

        $enriched = array_map(function ($a) {
            $customer        = $this->userModel->getById((int) $a->customerId);
            $service         = $this->serviceModel->getById((int) $a->serviceId);
            $a->customerName = $customer
                ? $customer->firstName . ' ' . $customer->lastName
                : 'Unknown Customer';
            $a->serviceName  = $service?->name ?? 'Unknown Service';
            return $a;
        }, $this->appointmentModel->getAll());

        $params = $request->getQueryParams();

        return $this->render($response, 'appointments/index.twig', [
            'appointments' => $enriched,
            'count'        => $this->appointmentModel->count(),
            'justDeleted'  => isset($params['deleted']),
            'justUpdated'  => isset($params['updated']),
        ]);
    }

    // GET /admin/appointments/{id}
    public function view(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin();

        $appointment = $this->appointmentModel->getById((int) $args['id']);

        if ($appointment === null) {
            return $this->renderError($response, 404, 'Appointment not found.');
        }

        return $this->render($response, 'appointments/view.twig', [
            'appointment' => $appointment,
            'customer'    => $this->userModel->getById((int) $appointment->customerId),
            'service'     => $this->serviceModel->getById((int) $appointment->serviceId),
        ]);
    }

    // GET /admin/appointments/{id}/edit
    public function edit(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin();

        $appointment = $this->appointmentModel->getById((int) $args['id']);

        if ($appointment === null) {
            return $this->renderError($response, 404, 'Appointment not found.');
        }

        return $this->render($response, 'appointments/edit.twig', [
            'appointment' => $appointment,
            'services'    => $this->serviceModel->getAll(),
            'customers'   => $this->userModel->getAll(),
            'errors'      => [],
            'old'         => [],
        ]);
    }

    // POST /admin/appointments/{id}/edit
    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin();

        $id          = (int) $args['id'];
        $appointment = $this->appointmentModel->getById($id);

        if ($appointment === null) {
            return $this->renderError($response, 404, 'Appointment not found.');
        }

        $data   = $this->getPostData($request);
        $errors = $this->validate($data, isUpdate: true);

        if (!empty($errors)) {
            return $this->render($response, 'appointments/edit.twig', [
                'appointment' => $appointment,
                'services'    => $this->serviceModel->getAll(),
                'customers'   => $this->userModel->getAll(),
                'errors'      => $errors,
                'old'         => $data,
            ]);
        }

        $this->appointmentModel->update($id, $data);
        return $this->redirect($response, '/admin/appointments?updated=1');
    }

    // POST /admin/appointments/{id}/delete
    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin();

        $deleted = $this->appointmentModel->delete((int) $args['id']);

        if (!$deleted) {
            return $this->renderError($response, 404, 'Appointment not found or already deleted.');
        }

        return $this->redirect($response, '/admin/appointments?deleted=1');
    }

    // ─── Validation ──────────────────────────────────────────────────────────

    private function validate(array $data, bool $isUpdate = false): array
    {
        $errors    = [];
        $isPresent = fn(string $key): bool => isset($data[$key]) && $data[$key] !== '';

        if (!$isUpdate || $isPresent('date')) {
            if (empty($data['date'])) {
                $errors['date'][] = 'Please select a date.';
            } elseif (!$this->isValidDate($data['date'])) {
                $errors['date'][] = 'Please enter a valid date (YYYY-MM-DD).';
            } elseif (!$isUpdate && $data['date'] < date('Y-m-d')) {
                $errors['date'][] = 'Appointment date cannot be in the past.';
            }
        }

        if (!$isUpdate || $isPresent('time')) {
            if (empty($data['time'])) {
                $errors['time'][] = 'Please select a time.';
            } elseif (!$this->isValidTime($data['time'])) {
                $errors['time'][] = 'Please enter a valid time (HH:MM).';
            }
        }

        if (!$isUpdate && empty($data['customerId'])) {
            $errors['customerId'][] = 'A customer is required.';
        }

        if (!$isUpdate || $isPresent('serviceId')) {
            if (empty($data['serviceId'])) {
                $errors['serviceId'][] = 'Please select a service.';
            } elseif (!ctype_digit((string) $data['serviceId'])) {
                $errors['serviceId'][] = 'Invalid service selected.';
            } elseif ($this->serviceModel->getById((int) $data['serviceId']) === null) {
                $errors['serviceId'][] = 'The selected service does not exist.';
            }
        }

        if ($isPresent('paymentId') && !ctype_digit((string) $data['paymentId'])) {
            $errors['paymentId'][] = 'Invalid payment reference.';
        }

        if ($isPresent('notes') && mb_strlen($data['notes']) > 1000) {
            $errors['notes'][] = 'Notes cannot exceed 1000 characters.';
        }

        return $errors;
    }

    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    private function isValidTime(string $time): bool
    {
        return (bool) preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)
            && strtotime($time) !== false;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function getPostData(Request $request): array
    {
        $body = (array) $request->getParsedBody();
        return [
            'date'       => trim($body['date']       ?? ''),
            'time'       => trim($body['time']       ?? ''),
            'notes'      => trim($body['notes']      ?? ''),
            'customerId' => trim($body['customerId'] ?? ''),
            'serviceId'  => trim($body['serviceId']  ?? ''),
            'paymentId'  => trim($body['paymentId']  ?? ''),
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