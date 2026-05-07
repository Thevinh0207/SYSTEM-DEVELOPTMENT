<?php

declare(strict_types=1);

namespace App\Models;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;

/**
 * ReviewModel
 *
 * Data access for the `reviews` table — managed by RedBeanPHP.
 */
class ReviewModel
{
    public function findAll(): array
    {
        return R::findAll('reviews', 'ORDER BY reviewDate DESC');
    }

    public function findByUserId(int $userId): array
    {
        return R::find('reviews', 'userID = ? ORDER BY reviewDate DESC', [$userId]);
    }

    public function findByAppointmentId(int $appointmentId): ?OODBBean
    {
        return R::findOne('reviews', 'appointmentID = ?', [$appointmentId]);
    }

    public function load(int $id): ?OODBBean
    {
        $bean = R::load('reviews', $id);
        return $bean->id ? $bean : null;
    }

    public function create(array $data): ?OODBBean
    {
        $rating = (int) $data['rating'];
        if ($rating < 1 || $rating > 5) {
            return null;
        }

        $review                = R::dispense('reviews');
        $review->userID        = (int) $data['userID'];
        $review->appointmentID = (int) $data['appointmentID'];
        $review->rating        = $rating;
        $review->comment       = $data['comment'] ?? null;
        $review->reviewDate    = $data['reviewDate'] ?? date('Y-m-d');
        $review->reply         = null;
        $review->repliedAt     = null;

        try {
            R::store($review);
        } catch (\Throwable $e) {
            // UNIQUE constraint on appointmentID — already reviewed.
            return null;
        }
        return $review;
    }

    public function save(OODBBean $review): void
    {
        R::store($review);
    }

    public function delete(OODBBean $review): void
    {
        R::trash($review);
    }

    public function reply(OODBBean $review, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            $review->reply     = null;
            $review->repliedAt = null;
        } else {
            $review->reply     = $text;
            $review->repliedAt = date('Y-m-d H:i:s');
        }
        R::store($review);
    }
}
