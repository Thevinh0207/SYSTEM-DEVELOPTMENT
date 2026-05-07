<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AppointmentModel;
use App\Models\PaymentModel;
use App\Models\ReviewModel;
use App\Models\ServiceModel;
use App\Models\UserModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;
use Throwable;
use Twig\Environment;

class AdminPanelController extends BaseController
{
    public function __construct(
        Environment $twig,
        string $basePath,
        private AppointmentModel $appointments,
        private ServiceModel $services,
        private ReviewModel $reviews,
        private PaymentModel $payments
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
                'appointments' => $this->loadAppointmentRows(),
                'services'     => $this->loadServiceRows(),
                'reviews'      => $this->loadReviewRows(),
                'payments'     => $this->loadPaymentRows(),
                'insights'     => $this->buildInsights(),
            ];
        } catch (Throwable $e) {
            $data = ['appointments' => [], 'services' => [], 'reviews' => [], 'payments' => [], 'insights' => []];
        }

        return $this->render($response, 'admin.twig', [
            'section' => $section,
            'flash'   => $flash,
        ] + $data);
    }

    // ── Services CRUD ────────────────────────────────────────────────────
    public function newService(Request $r, Response $response): Response
    {
        if ($block = $this->guard()) return $block;
        $form   = $_SESSION['admin_form']   ?? [];
        $errors = $_SESSION['admin_errors'] ?? [];
        unset($_SESSION['admin_form'], $_SESSION['admin_errors']);
        return $this->render($response, 'admin/service-form.twig', [
            'mode' => 'create', 'service' => $form, 'errors' => $errors,
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

    public function editService(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;

        $id      = (int) $args['id'];
        $service = $this->services->load($id);
        if (!$service) {
            $this->flash('error', 'Service not found.');
            return $this->redirect('/admin/services');
        }

        $form = $_SESSION['admin_form'] ?? [
            'name'        => $service->name,
            'category'    => $service->category,
            'description' => $service->description,
            'price'       => $service->price,
            'duration'    => $service->duration,
        ];
        $errors = $_SESSION['admin_errors'] ?? [];
        unset($_SESSION['admin_form'], $_SESSION['admin_errors']);

        return $this->render($response, 'admin/service-form.twig', [
            'mode' => 'edit', 'id' => $id, 'service' => $form, 'errors' => $errors,
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
            $service = $this->services->load($id);
            if (!$service) {
                $this->flash('error', 'Service not found.');
            } else {
                $service->name        = $data['name'];
                $service->category    = $data['category'];
                $service->description = $data['description'];
                $service->price       = (float) $data['price'];
                $service->duration    = (int)   $data['duration'];
                $this->services->save($service);
                $this->flash('success', 'Service updated.');
            }
        } catch (Throwable $e) {
            $this->flash('error', 'Could not update service.');
        }
        return $this->redirect('/admin/services');
    }

    public function deleteService(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;
        try {
            $service = $this->services->load((int) $args['id']);
            if ($service) {
                $this->services->delete($service);
                $this->flash('success', 'Service deleted.');
            } else {
                $this->flash('error', 'Service not found.');
            }
        } catch (Throwable $e) {
            $this->flash('error', 'Cannot delete: this service is used by existing appointments.');
        }
        return $this->redirect('/admin/services');
    }

    // ── Appointments: create / edit / cancel ─────────────────────────────
    public function newAppointment(Request $r, Response $response): Response
    {
        if ($block = $this->guard()) return $block;

        $form   = $_SESSION['admin_form']   ?? ['status' => 'pending'];
        $errors = $_SESSION['admin_errors'] ?? [];
        unset($_SESSION['admin_form'], $_SESSION['admin_errors']);

        return $this->render($response, 'admin/appointment-form.twig', [
            'mode'        => 'create',
            'appointment' => $form,
            'services'    => $this->loadServiceRows(),
            'errors'      => $errors,
        ]);
    }

    public function createAppointment(Request $r, Response $response): Response
    {
        if ($block = $this->guard()) return $block;

        $body = (array) $r->getParsedBody();
        $data = [
            'serviceID'  => (int) ($body['serviceID'] ?? 0),
            'date'       => trim((string) ($body['date']        ?? '')),
            'time'       => trim((string) ($body['time']        ?? '')),
            'notes'      => trim((string) ($body['notes']       ?? '')),
            'status'     => trim((string) ($body['status']      ?? 'pending')),
            'guestName'  => trim((string) ($body['guestName']   ?? '')),
            'guestEmail' => trim((string) ($body['guestEmail']  ?? '')),
            'guestPhone' => trim((string) ($body['guestPhone']  ?? '')),
        ];

        $errors = [];
        if ($data['serviceID'] <= 0) $errors['serviceID'] = 'Service is required.';
        if ($data['date'] === '')    $errors['date']      = 'Date is required.';
        if ($data['time'] === '')    $errors['time']      = 'Time is required.';
        if (!in_array($data['status'], ['pending','confirmed','completed','cancelled'], true)) {
            $errors['status'] = 'Invalid status.';
        }
        if ($data['guestName'] === '') {
            $errors['guestName'] = 'Customer name is required.';
        }
        if ($data['guestEmail'] === '' && $data['guestPhone'] === '') {
            $errors['guestEmail'] = 'Provide at least an email or phone.';
        }
        if ($errors) {
            $_SESSION['admin_errors'] = $errors;
            $_SESSION['admin_form']   = $data;
            return $this->redirect('/admin/appointments/new');
        }

        try {
            $appt = $this->appointments->create($data);
            if ($appt) {
                $this->flash('success', 'Appointment #' . $appt->id . ' created.');
            } else {
                $this->flash('error', 'That time slot is already booked. Pick another time.');
                $_SESSION['admin_form'] = $data;
                return $this->redirect('/admin/appointments/new');
            }
        } catch (Throwable $e) {
            $this->flash('error', 'Could not create appointment.');
            $_SESSION['admin_form'] = $data;
            return $this->redirect('/admin/appointments/new');
        }
        return $this->redirect('/admin/appointments');
    }

    public function editAppointment(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;

        $id   = (int) $args['id'];
        $appt = $this->appointments->load($id);
        if (!$appt) {
            $this->flash('error', 'Appointment not found.');
            return $this->redirect('/admin');
        }

        $form = $_SESSION['admin_form'] ?? [
            'serviceID' => (int) $appt->serviceID,
            'date'      => $appt->date,
            'time'      => $appt->time,
            'notes'     => $appt->notes,
            'status'    => $appt->status,
        ];
        $errors = $_SESSION['admin_errors'] ?? [];
        unset($_SESSION['admin_form'], $_SESSION['admin_errors']);

        return $this->render($response, 'admin/appointment-form.twig', [
            'mode'        => 'edit',
            'id'          => $id,
            'appointment' => $form,
            'services'    => $this->loadServiceRows(),
            'errors'      => $errors,
        ]);
    }

    public function updateAppointment(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;

        $id   = (int) $args['id'];
        $body = (array) $r->getParsedBody();
        $data = [
            'serviceID' => (int) ($body['serviceID'] ?? 0),
            'date'      => trim((string) ($body['date']   ?? '')),
            'time'      => trim((string) ($body['time']   ?? '')),
            'notes'     => trim((string) ($body['notes']  ?? '')),
            'status'    => trim((string) ($body['status'] ?? '')),
        ];

        $errors = [];
        if ($data['serviceID'] <= 0)   $errors['serviceID'] = 'Service is required.';
        if ($data['date'] === '')      $errors['date']      = 'Date is required.';
        if ($data['time'] === '')      $errors['time']      = 'Time is required.';
        if (!in_array($data['status'], ['pending','confirmed','completed','cancelled'], true)) {
            $errors['status'] = 'Invalid status.';
        }
        if ($errors) {
            $_SESSION['admin_errors'] = $errors;
            $_SESSION['admin_form']   = $data;
            return $this->redirect("/admin/appointments/{$id}/edit");
        }

        try {
            $appt = $this->appointments->load($id);
            if (!$appt) {
                $this->flash('error', 'Appointment not found.');
            } else {
                $slotChanged = (int) $appt->serviceID !== $data['serviceID']
                    || $appt->date !== $data['date']
                    || $appt->time !== $data['time'];
                if ($slotChanged && !$this->appointments->isAvailable($data['date'], $data['time'], $id)) {
                    $this->flash('error', 'That time slot is taken — pick another time.');
                } else {
                    $appt->serviceID = $data['serviceID'];
                    $appt->date      = $data['date'];
                    $appt->time      = $data['time'];
                    $appt->notes     = $data['notes'];
                    $appt->status    = $data['status'];
                    $this->appointments->save($appt);
                    $this->flash('success', 'Appointment updated.');
                }
            }
        } catch (Throwable $e) {
            $this->flash('error', 'Could not update appointment.');
        }
        return $this->redirect('/admin/appointments');
    }

    public function cancelAppointment(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;
        try {
            $appt = $this->appointments->load((int) $args['id']);
            if ($appt) {
                $this->appointments->cancel($appt);
                $this->flash('success', 'Appointment cancelled.');
            } else {
                $this->flash('error', 'Appointment not found.');
            }
        } catch (Throwable $e) {
            $this->flash('error', 'Could not cancel appointment.');
        }
        return $this->redirect('/admin/appointments');
    }

    // ── Reviews: reply ───────────────────────────────────────────────────
    public function replyToReview(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;

        $reply = (string) (((array) $r->getParsedBody())['reply'] ?? '');
        try {
            $review = $this->reviews->load((int) $args['id']);
            if (!$review) {
                $this->flash('error', 'Review not found.');
            } else {
                $this->reviews->reply($review, $reply);
                $this->flash('success', trim($reply) === '' ? 'Reply removed.' : 'Reply saved.');
            }
        } catch (Throwable $e) {
            $this->flash('error', 'Could not save reply.');
        }
        return $this->redirect('/admin/reviews');
    }

    // ── Helpers ──────────────────────────────────────────────────────────
    private function guard(): ?Response
    {
        if (empty($_SESSION['user'])) {
            return $this->redirect('/login');
        }
        if (($_SESSION['user']['role'] ?? null) !== UserModel::ROLE_ADMIN) {
            $response = new \Slim\Psr7\Response();
            $html = $this->twig->render('Errors/generic.twig', [
                'code' => 403, 'title' => 'Access denied',
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

    /**
     * Build display-ready appointment rows by joining beans with their
     * service + user beans in PHP. Avoids leaking SQL into the controllers.
     */
    private function loadAppointmentRows(): array
    {
        $rows = [];
        foreach ($this->appointments->findAll() as $a) {
            $svc  = R::load('services', (int) $a->serviceID);
            $user = $a->userID ? R::load('user', (int) $a->userID) : null;

            $rows[] = [
                'id'            => (int) $a->id,
                'serviceID'     => (int) $a->serviceID,
                'serviceName'   => $svc->id ? $svc->name : '—',
                'date'          => $a->date,
                'time'          => $a->time,
                'status'        => $a->status,
                'notes'         => $a->notes,
                'customerName'  => $user && $user->id
                    ? trim($user->firstName . ' ' . $user->lastName)
                    : ($a->guestName ?: 'Guest'),
                'customerEmail' => $user && $user->id ? $user->email       : $a->guestEmail,
                'customerPhone' => $user && $user->id ? $user->phoneNumber : $a->guestPhone,
                'customerType'  => $a->userID ? 'client' : 'guest',
            ];
        }
        return $rows;
    }

    private function loadServiceRows(): array
    {
        $rows = [];
        foreach ($this->services->findAll() as $s) {
            $rows[] = [
                'id'          => (int) $s->id,
                'name'        => $s->name,
                'category'    => $s->category,
                'description' => $s->description,
                'price'       => $s->price,
                'duration'    => $s->duration,
            ];
        }
        return $rows;
    }

    private function loadReviewRows(): array
    {
        $rows = [];
        foreach ($this->reviews->findAll() as $rv) {
            $author = R::load('user', (int) $rv->userID);
            $appt   = R::load('appointment', (int) $rv->appointmentID);
            $svc    = $appt->id ? R::load('services', (int) $appt->serviceID) : null;
            $rows[] = [
                'id'            => (int) $rv->id,
                'authorName'    => $author->id ? trim($author->firstName . ' ' . $author->lastName) : 'Anonymous',
                'serviceName'   => $svc && $svc->id ? $svc->name : '—',
                'rating'        => (int) $rv->rating,
                'comment'       => $rv->comment,
                'reviewDate'    => $rv->reviewDate,
                'reply'         => $rv->reply,
                'repliedAt'     => $rv->repliedAt,
            ];
        }
        return $rows;
    }

    private function loadPaymentRows(): array
    {
        $rows = [];
        foreach ($this->payments->findAll() as $p) {
            $appt = R::load('appointment', (int) $p->appointmentID);
            $rows[] = [
                'id'              => (int) $p->id,
                'paymentFrom'     => $p->paymentFrom,
                'payerName'       => $p->paymentFromName,
                'payerEmail'      => $p->paymentFromEmail,
                'payerPhone'      => $p->paymentFromPhone,
                'paymentType'     => $p->paymentType,
                'paymentAmount'   => $p->paymentAmount,
                'paymentStatus'   => $p->paymentStatus,
                'created_at'      => $p->created_at,
                'appointmentDate' => $appt->id ? $appt->date : null,
                'appointmentTime' => $appt->id ? $appt->time : null,
                'payerType'       => $p->paymentFrom ? 'client' : 'guest',
            ];
        }
        return $rows;
    }

    private function buildInsights(): array
    {
        $totalRevenue   = 0.0;
        $revenuePaid    = 0.0;
        $revenuePending = 0.0;
        $count          = 0;

        foreach ($this->payments->findAll() as $p) {
            $amount = (float) $p->paymentAmount;
            $count++;
            $totalRevenue += $amount;
            if ($p->paymentStatus === 'paid')    $revenuePaid    += $amount;
            if ($p->paymentStatus === 'pending') $revenuePending += $amount;
        }

        return [
            'totals' => [
                'total_count'     => $count,
                'total_revenue'   => $totalRevenue,
                'revenue_paid'    => $revenuePaid,
                'revenue_pending' => $revenuePending,
                'average_amount'  => $count > 0 ? $totalRevenue / $count : 0.0,
            ],
        ];
    }
}
