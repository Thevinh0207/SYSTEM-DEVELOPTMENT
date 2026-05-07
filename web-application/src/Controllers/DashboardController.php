<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AppointmentModel;
use App\Models\ReviewModel;
use App\Models\ServiceModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;
use Twig\Environment;

class DashboardController extends BaseController
{
    public function __construct(
        Environment $twig,
        string $basePath,
        private AppointmentModel $appointments,
        private ReviewModel $reviews,
        private ServiceModel $services
    ) {
        parent::__construct($twig, $basePath);
    }

    public function index(Request $r, Response $response): Response
    {
        if (empty($_SESSION['user'])) {
            return $this->redirect('/login');
        }

        $userId = (int) $_SESSION['user']['id'];
        $tab    = (string) ($r->getQueryParams()['tab'] ?? 'upcoming');
        if ($tab === 'reviews') $tab = 'past';
        if (!in_array($tab, ['upcoming', 'past'], true)) {
            $tab = 'upcoming';
        }

        try {
            $appointments = $this->appointments->findByUserId($userId);
            $reviews      = $this->reviews->findByUserId($userId);
        } catch (Throwable $e) {
            $appointments = $reviews = [];
        }

        // Map service names by id once so we can decorate appointments.
        $serviceNames = [];
        foreach ($this->services->findAll() as $svc) {
            $serviceNames[(int) $svc->id] = $svc->name;
        }

        $today    = date('Y-m-d');
        $upcoming = [];
        $past     = [];
        foreach ($appointments as $a) {
            $row = [
                'id'          => (int) $a->id,
                'serviceID'   => (int) $a->serviceID,
                'serviceName' => $serviceNames[(int) $a->serviceID] ?? '—',
                'date'        => $a->date,
                'time'        => $a->time,
                'status'      => $a->status,
                'notes'       => $a->notes,
            ];
            $isPast = $row['date'] < $today
                || in_array($row['status'], ['completed', 'cancelled'], true);
            if ($isPast) {
                $past[] = $row;
            } elseif ($row['status'] !== 'cancelled') {
                $upcoming[] = $row;
            }
        }

        // Index reviews by appointment id for inline display.
        $reviewedIds = [];
        foreach ($reviews as $rv) {
            $reviewedIds[(int) $rv->appointmentID] = [
                'id'            => (int) $rv->id,
                'appointmentID' => (int) $rv->appointmentID,
                'rating'        => (int) $rv->rating,
                'comment'       => $rv->comment,
                'reviewDate'    => $rv->reviewDate,
                'reply'         => $rv->reply,
                'repliedAt'     => $rv->repliedAt,
            ];
        }

        // For the Reviews-left count in the profile sidebar.
        $myReviewsCount = count($reviews);

        $flash        = $_SESSION['dashboard_flash'] ?? null;
        $reviewErrors = $_SESSION['review_errors']   ?? [];
        $reviewForm   = $_SESSION['review_form']     ?? [];
        unset($_SESSION['dashboard_flash'], $_SESSION['review_errors'], $_SESSION['review_form']);

        return $this->render($response, 'dashboard.twig', [
            'user'         => $_SESSION['user'],
            'tab'          => $tab,
            'upcoming'     => $upcoming,
            'past'         => $past,
            'myReviews'    => array_fill(0, $myReviewsCount, true), // length-only sentinel
            'reviewedIds'  => $reviewedIds,
            'flash'        => $flash,
            'reviewErrors' => $reviewErrors,
            'reviewForm'   => $reviewForm,
        ]);
    }

    public function postReview(Request $r, Response $response): Response
    {
        if (empty($_SESSION['user'])) {
            return $this->redirect('/login');
        }

        $userId  = (int) $_SESSION['user']['id'];
        $body    = (array) $r->getParsedBody();
        $apptId  = (int) ($body['appointmentID'] ?? 0);
        $rating  = (int) ($body['rating']        ?? 0);
        $comment = trim((string) ($body['comment'] ?? ''));

        $errors = [];
        if ($apptId <= 0)              $errors['general'] = 'Invalid appointment.';
        if ($rating < 1 || $rating > 5) $errors['rating'] = 'Please pick a rating between 1 and 5 stars.';
        if (strlen($comment) > 255)    $errors['comment'] = 'Comment must be 255 characters or fewer.';

        if (!$errors) {
            $appt = $this->appointments->load($apptId);
            if (!$appt || (int) $appt->userID !== $userId) {
                $errors['general'] = 'You can only review your own appointments.';
            } elseif ($appt->status !== 'completed') {
                $errors['general'] = 'You can only review completed appointments.';
            }
        }

        if ($errors) {
            $_SESSION['review_errors'] = $errors;
            $_SESSION['review_form']   = ['appointmentID' => $apptId, 'rating' => $rating, 'comment' => $comment];
            return $this->redirect('/dashboard?tab=past');
        }

        $review = $this->reviews->create([
            'userID'        => $userId,
            'appointmentID' => $apptId,
            'rating'        => $rating,
            'comment'       => $comment !== '' ? $comment : null,
            'reviewDate'    => date('Y-m-d'),
        ]);

        $_SESSION['dashboard_flash'] = $review
            ? ['type' => 'success', 'message' => 'Thanks! Your review has been posted.']
            : ['type' => 'error',   'message' => 'Could not save your review. You may have already reviewed this appointment.'];

        return $this->redirect('/dashboard?tab=past');
    }
}
