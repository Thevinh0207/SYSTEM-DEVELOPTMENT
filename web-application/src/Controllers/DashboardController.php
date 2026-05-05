<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AppointmentModel;
use App\Models\ReviewModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;
use Twig\Environment;

/**
 * Client-facing dashboard:
 *   /dashboard          → tabbed view (upcoming / past / reviews)
 *   /dashboard/review   → POST: create a review for a completed appointment
 */
class DashboardController extends BaseController
{
    public function __construct(
        Environment $twig,
        string $basePath,
        private AppointmentModel $appointments,
        private ReviewModel $reviews
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
        if (!in_array($tab, ['upcoming', 'past', 'reviews'], true)) {
            $tab = 'upcoming';
        }

        try {
            $appointments = $this->appointments->getAllAppointmentByUserId($userId);
            $reviews      = $this->reviews->getAllReviewsByUserId($userId);
        } catch (Throwable $e) {
            $appointments = $reviews = [];
        }

        $today    = date('Y-m-d');
        $upcoming = array_values(array_filter(
            $appointments,
            fn($a) => $a['date'] >= $today && !in_array($a['status'], ['cancelled', 'completed'], true)
        ));
        $past = array_values(array_filter(
            $appointments,
            fn($a) => $a['date'] < $today || in_array($a['status'], ['completed', 'cancelled'], true)
        ));

        $reviewedIds = [];
        foreach ($reviews as $rv) {
            $reviewedIds[(int) $rv['appointmentID']] = $rv;
        }

        $flash        = $_SESSION['dashboard_flash'] ?? null;
        $reviewErrors = $_SESSION['review_errors']   ?? [];
        $reviewForm   = $_SESSION['review_form']     ?? [];
        unset($_SESSION['dashboard_flash'], $_SESSION['review_errors'], $_SESSION['review_form']);

        return $this->render($response, 'dashboard.twig', [
            'user'         => $_SESSION['user'],
            'tab'          => $tab,
            'upcoming'     => $upcoming,
            'past'         => $past,
            'myReviews'    => $reviews,
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
            try {
                $appt = $this->appointments->getById($apptId);
            } catch (Throwable $e) {
                $appt = null;
            }
            if (!$appt || (int) $appt['userID'] !== $userId) {
                $errors['general'] = 'You can only review your own appointments.';
            } elseif ($appt['status'] !== 'completed') {
                $errors['general'] = 'You can only review completed appointments.';
            }
        }

        if ($errors) {
            $_SESSION['review_errors'] = $errors;
            $_SESSION['review_form']   = ['appointmentID' => $apptId, 'rating' => $rating, 'comment' => $comment];
            return $this->redirect('/dashboard?tab=past');
        }

        try {
            $newId = $this->reviews->createReview([
                'userID'        => $userId,
                'appointmentID' => $apptId,
                'rating'        => $rating,
                'comment'       => $comment !== '' ? $comment : null,
                'reviewDate'    => date('Y-m-d'),
            ]);
            $_SESSION['dashboard_flash'] = $newId
                ? ['type' => 'success', 'message' => 'Thanks! Your review has been posted.']
                : ['type' => 'error',   'message' => 'Could not save your review. You may have already reviewed this appointment.'];
        } catch (Throwable $e) {
            $_SESSION['dashboard_flash'] = ['type' => 'error', 'message' => 'You may have already reviewed this appointment.'];
        }

        return $this->redirect('/dashboard?tab=reviews');
    }
}
