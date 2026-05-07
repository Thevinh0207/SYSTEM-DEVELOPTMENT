<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Data\ViewData;
use App\Models\AppointmentModel;
use App\Models\PaymentModel;
use App\Models\ServiceModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;
use Twig\Environment;

/**
 * Booking flow per the project flowchart:
 *   /book               → gate (logged-in skip; guests see choices)
 *   /book/info          → guest contact info
 *   /book/service       → pick service
 *   /book/date          → pick date+time. Saves appointment to DB on submit.
 *   /book/payment       → deposit. Saves payment row, marks appointment confirmed.
 *   /book/confirmed     → overview + return-to-home link.
 *
 * Booking state lives in $_SESSION['booking'] and is wiped on the final step.
 */
class BookingController extends BaseController
{
    public function __construct(
        Environment $twig,
        string $basePath,
        private ServiceModel $services,
        private AppointmentModel $appointments,
        private PaymentModel $payments
    ) {
        parent::__construct($twig, $basePath);
    }

    // ── Step 1 — gate ────────────────────────────────────────────────────
    public function gate(Request $r, Response $response): Response
    {
        if (($r->getQueryParams()['new'] ?? '') === '1') {
            unset($_SESSION['booking'], $_SESSION['booking_form'], $_SESSION['booking_errors']);
        }

        $serviceId = (int) ($r->getQueryParams()['service'] ?? 0);
        if ($serviceId > 0) {
            $this->selectService($serviceId);
        }

        if (!empty($_SESSION['user'])) {
            return !empty($_SESSION['booking']['serviceId'])
                ? $this->redirect('/book/date')
                : $this->redirect('/book/service');
        }
        return $this->render($response, 'booking/account.twig', [
            'active'  => 'book',
            'summary' => $_SESSION['booking'] ?? [],
        ]);
    }

    // ── Step 2 — guest contact info ──────────────────────────────────────
    public function showInfo(Request $r, Response $response): Response
    {
        if (!empty($_SESSION['user'])) {
            return $this->redirect('/book/service');
        }
        $errors = $_SESSION['booking_errors'] ?? [];
        unset($_SESSION['booking_errors']);
        return $this->render($response, 'booking/info.twig', [
            'active'  => 'book',
            'summary' => $_SESSION['booking'] ?? [],
            'user'    => null,
            'form'    => $_SESSION['booking_form']['info'] ?? [],
            'errors'  => $errors,
        ]);
    }

    public function submitInfo(Request $r, Response $response): Response
    {
        if (!empty($_SESSION['user'])) {
            return $this->redirect('/book/service');
        }

        $body = (array) $r->getParsedBody();
        $form = [
            'firstName'   => trim((string) ($body['firstName']   ?? '')),
            'lastName'    => trim((string) ($body['lastName']    ?? '')),
            'email'       => trim((string) ($body['email']       ?? '')),
            'phoneNumber' => trim((string) ($body['phoneNumber'] ?? '')),
        ];

        $errors = [];
        if ($form['firstName'] === '') $errors['firstName'] = 'First name is required.';
        if ($form['lastName']  === '') $errors['lastName']  = 'Last name is required.';
        if ($form['email'] === '') {
            $errors['email'] = 'Email is required.';
        } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        }
        if ($form['phoneNumber'] === '') {
            $errors['phoneNumber'] = 'Phone number is required.';
        } elseif (!preg_match('/^[0-9\-\+\s\(\)]{7,20}$/', $form['phoneNumber'])) {
            $errors['phoneNumber'] = 'Phone number looks invalid.';
        }

        if ($errors) {
            $_SESSION['booking_errors']       = $errors;
            $_SESSION['booking_form']['info'] = $form;
            return $this->redirect('/book/info');
        }

        $_SESSION['booking']['contact'] = $form;
        unset($_SESSION['booking_form']['info']);
        return !empty($_SESSION['booking']['serviceId'])
            ? $this->redirect('/book/date')
            : $this->redirect('/book/service');
    }

    // ── Step 3 — pick service ────────────────────────────────────────────
    public function showService(Request $r, Response $response): Response
    {
        if (empty($_SESSION['user']) && empty($_SESSION['booking']['contact'])) {
            return $this->redirect('/book/info');
        }
        $errors = $_SESSION['booking_errors'] ?? [];
        unset($_SESSION['booking_errors']);
        return $this->render($response, 'booking/service.twig', [
            'active'          => 'book',
            'bookingServices' => $this->loadServices(),
            'errors'          => $errors,
        ]);
    }

    public function submitService(Request $r, Response $response): Response
    {
        if (empty($_SESSION['user']) && empty($_SESSION['booking']['contact'])) {
            return $this->redirect('/book/info');
        }

        $serviceId = (int) (((array) $r->getParsedBody())['serviceId'] ?? 0);
        if ($serviceId <= 0) {
            $_SESSION['booking_errors'] = ['service' => 'Please select a service.'];
            return $this->redirect('/book/service');
        }

        if (!$this->selectService($serviceId)) {
            $_SESSION['booking_errors'] = ['service' => 'That service is no longer available.'];
            return $this->redirect('/book/service');
        }

        return $this->redirect('/book/date');
    }

    // ── Step 4 — pick date+time, save appointment ───────────────────────
    public function showDate(Request $r, Response $response): Response
    {
        if (empty($_SESSION['booking']['serviceId'])) {
            return $this->redirect('/book/service');
        }
        $errors = $_SESSION['booking_errors'] ?? [];
        unset($_SESSION['booking_errors']);
        return $this->render($response, 'booking/date.twig', [
            'active'  => 'book',
            'times'   => ViewData::bookingTimes(),
            'form'    => $_SESSION['booking_form']['date'] ?? [],
            'errors'  => $errors,
            'summary' => $_SESSION['booking'] ?? [],
        ]);
    }

    public function submitDate(Request $r, Response $response): Response
    {
        $booking = $_SESSION['booking'] ?? [];
        if (empty($booking['serviceId'])) {
            return $this->redirect('/book/service');
        }
        if (empty($_SESSION['user']) && empty($booking['contact'])) {
            return $this->redirect('/book/info');
        }

        $body    = (array) $r->getParsedBody();
        $date    = trim((string) ($body['date'] ?? ''));
        $timeRaw = trim((string) ($body['time'] ?? ''));
        $errors  = [];

        if ($date === '') {
            $errors['date'] = 'Please choose a date.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
            $errors['date'] = 'Please enter a valid date (YYYY-MM-DD).';
        } elseif (strtotime($date) < strtotime('today')) {
            $errors['date'] = 'Date cannot be in the past.';
        }

        $time = '';
        if ($timeRaw === '') {
            $errors['time'] = 'Please choose a time.';
        } else {
            $ts = strtotime($timeRaw);
            if ($ts === false) {
                $errors['time'] = 'Please choose a valid time.';
            } else {
                $time = date('H:i:s', $ts);
            }
        }

        if ($errors) {
            $_SESSION['booking_errors']       = $errors;
            $_SESSION['booking_form']['date'] = ['date' => $date, 'time' => $timeRaw];
            return $this->redirect('/book/date');
        }

        // Snapshot contact info from session user (logged-in) or guest form.
        $userId  = $_SESSION['user']['id'] ?? null;
        $contact = $userId
            ? [
                'firstName'   => $_SESSION['user']['firstName'],
                'lastName'    => $_SESSION['user']['lastName'],
                'email'       => $_SESSION['user']['email'],
                'phoneNumber' => $_SESSION['user']['phoneNumber'],
            ]
            : $booking['contact'];

        try {
            $appointmentId = $this->appointments->createAppointment([
                'serviceID'  => (int) $booking['serviceId'],
                'userID'     => $userId,
                'guestName'  => $userId ? null : trim($contact['firstName'] . ' ' . $contact['lastName']),
                'guestEmail' => $userId ? null : $contact['email'],
                'guestPhone' => $userId ? null : $contact['phoneNumber'],
                'date'       => $date,
                'time'       => $time,
                'notes'      => null,
                'status'     => 'pending',
            ]);
        } catch (Throwable $e) {
            $_SESSION['booking_errors'] = ['general' => 'Could not save your appointment. Please try again.'];
            return $this->redirect('/book/date');
        }

        if (!$appointmentId) {
            $_SESSION['booking_errors']['time'] = 'That time slot is no longer available. Please pick another time.';
            $_SESSION['booking_form']['date']   = ['date' => $date, 'time' => $timeRaw];
            return $this->redirect('/book/date');
        }

        $_SESSION['booking']['id']         = $appointmentId;
        $_SESSION['booking']['date']       = $date;
        $_SESSION['booking']['time']       = $timeRaw;
        $_SESSION['booking']['timeStored'] = $time;
        $_SESSION['booking']['contact']    = $contact;
        unset($_SESSION['booking_form']['date']);
        return $this->redirect('/book/payment');
    }

    // ── Step 5 — deposit ─────────────────────────────────────────────────
    public function showPayment(Request $r, Response $response): Response
    {
        if (empty($_SESSION['booking']['id'])) {
            return $this->redirect('/book/date');
        }
        $errors = $_SESSION['booking_errors'] ?? [];
        unset($_SESSION['booking_errors']);
        return $this->render($response, 'booking/payment.twig', [
            'active'  => 'book',
            'summary' => $_SESSION['booking'] ?? [],
            'form'    => $_SESSION['booking_form']['payment'] ?? [],
            'errors'  => $errors,
        ]);
    }

    public function submitPayment(Request $r, Response $response): Response
    {
        $body = (array) $r->getParsedBody();
        $form = [
            'cardNumber' => trim((string) ($body['cardNumber'] ?? '')),
            'cardExpiry' => trim((string) ($body['cardExpiry'] ?? '')),
            'cardCvv'    => trim((string) ($body['cardCvv']    ?? '')),
            'cardName'   => trim((string) ($body['cardName']   ?? '')),
        ];

        $errors = [];
        $digits = preg_replace('/\D/', '', $form['cardNumber']);
        if ($digits === '' || strlen($digits) < 13 || strlen($digits) > 19) {
            $errors['cardNumber'] = 'Card number must be 13–19 digits.';
        }
        if (!preg_match('#^(0[1-9]|1[0-2])/\d{2}$#', $form['cardExpiry'])) {
            $errors['cardExpiry'] = 'Expiry must be MM/YY.';
        }
        if (!preg_match('/^\d{3,4}$/', $form['cardCvv'])) {
            $errors['cardCvv'] = 'CVV must be 3 or 4 digits.';
        }
        if ($form['cardName'] === '') {
            $errors['cardName'] = 'Cardholder name is required.';
        }

        if ($errors) {
            $_SESSION['booking_errors']          = $errors;
            $_SESSION['booking_form']['payment'] = ['cardName' => $form['cardName']];
            return $this->redirect('/book/payment');
        }

        $booking = $_SESSION['booking'] ?? null;
        $contact = $booking['contact'] ?? null;
        if (!$booking || empty($booking['id']) || !$contact) {
            $_SESSION['booking_errors'] = ['general' => 'Booking session expired. Please start again.'];
            return $this->redirect('/book');
        }

        $userId = $_SESSION['user']['id'] ?? null;

        try {
            $this->payments->create([
                'appointmentID'    => (int) $booking['id'],
                'paymentFrom'      => $userId,
                'paymentFromName'  => trim($contact['firstName'] . ' ' . $contact['lastName']),
                'paymentFromEmail' => $contact['email'],
                'paymentFromPhone' => $contact['phoneNumber'],
                'paymentType'      => 'credit_card',
                'paymentAmount'    => 20.00,
                'paymentStatus'    => 'paid',
            ]);
            $this->appointments->updateAppointment((int) $booking['id'], ['status' => 'confirmed']);
        } catch (Throwable $e) {
            // Non-fatal — appointment is already saved.
        }

        return $this->redirect('/book/confirmed');
    }

    // ── Step 6 — overview ────────────────────────────────────────────────
    public function confirmed(Request $r, Response $response): Response
    {
        $booking = $_SESSION['booking'] ?? null;
        if (!$booking || empty($booking['id']) || empty($booking['contact'])) {
            return $this->redirect('/book');
        }

        $view = [
            'active'  => 'book',
            'isGuest' => empty($_SESSION['user']),
            'booking' => [
                'id'      => (int) $booking['id'],
                'service' => $booking['service'] ?? '—',
                'date'    => $booking['date']    ?? '—',
                'time'    => $booking['time']    ?? '—',
                'email'   => $booking['contact']['email'] ?? '',
                'deposit' => 20.00,
            ],
        ];
        unset($_SESSION['booking'], $_SESSION['booking_form'], $_SESSION['booking_errors']);
        return $this->render($response, 'booking/confirmed.twig', $view);
    }

    /** Maps DB rows into the shape the picker template expects. */
    private function loadServices(): array
    {
        try {
            $services = $this->services->getAllServices();
        } catch (Throwable $e) {
            $services = [];
        }
        return array_map(static fn(array $s): array => [
            'id'       => (int) $s['ServiceID'],
            'name'     => $s['name'],
            'duration' => $s['duration'] . ' min',
            'price'    => '$' . number_format((float) $s['price'], 2),
            'priceNum' => (float) $s['price'],
            'image'    => 'brush',
        ], $services);
    }

    private function selectService(int $serviceId): bool
    {
        try {
            $service = $this->services->getById($serviceId);
        } catch (Throwable $e) {
            $service = null;
        }

        if (!$service) {
            return false;
        }

        $_SESSION['booking']['serviceId'] = (int) $service['ServiceID'];
        $_SESSION['booking']['service']   = $service['name'];
        $_SESSION['booking']['duration']  = $service['duration'] . ' min';
        $_SESSION['booking']['price']     = '$' . number_format((float) $service['price'], 2);
        $_SESSION['booking']['priceNum']  = (float) $service['price'];
        return true;
    }
}
