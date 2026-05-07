<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;
use PDO;
use RedBeanPHP\R;

/**
 * ReviewModel — handles the `reviews` table via RedBeanPHP.
 *
 * Public methods unchanged. Reviews link a user (userID) and an appointment
 * (appointmentID). The schema's UNIQUE key on appointmentID enforces 1:1.
 */
class ReviewModel
{
    private const TABLE = 'reviews';

    public function __construct(?PDO $db = null)
    {
        Database::connect();
    }

    public function getAll(): array
    {
        return R::getAll(
            'SELECT r.*,
                    CONCAT(u.firstName, " ", u.lastName) AS authorName,
                    s.name AS serviceName
             FROM ' . self::TABLE . ' r
             JOIN user u        ON r.userID        = u.userID
             JOIN appointment a ON r.appointmentID = a.AppointmentID
             JOIN services s    ON a.serviceID     = s.ServiceID
             ORDER BY r.reviewDate DESC'
        );
    }

    public function replyToReview(int $reviewId, string $reply): bool
    {
        $reply = trim($reply);

        if ($reply === '') {
            R::exec(
                'UPDATE ' . self::TABLE . '
                 SET reply = NULL, repliedAt = NULL
                 WHERE ReviewID = ?',
                [$reviewId]
            );
        } else {
            R::exec(
                'UPDATE ' . self::TABLE . '
                 SET reply = ?, repliedAt = NOW()
                 WHERE ReviewID = ?',
                [$reply, $reviewId]
            );
        }
        return true;
    }

    public function getById(int $id): ?array
    {
        $row = R::getRow('SELECT * FROM ' . self::TABLE . ' WHERE ReviewID = ?', [$id]);
        return $row ?: null;
    }

    public function getAllReviewsByUserId(int $userId): array
    {
        return R::getAll(
            'SELECT * FROM ' . self::TABLE . '
             WHERE userID = ?
             ORDER BY reviewDate DESC',
            [$userId]
        );
    }

    public function findReviewsByUserId(int $userId): bool
    {
        $count = (int) R::getCell(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE userID = ?',
            [$userId]
        );
        return $count > 0;
    }

    public function viewReview(int $id): string
    {
        $review = $this->getById($id);
        if (!$review) {
            return 'Review not found.';
        }
        return sprintf(
            'Review #%d — %d/5 — %s',
            $review['ReviewID'],
            (int) $review['rating'],
            $review['comment'] ?? ''
        );
    }

    public function createReview(array $data): ?int
    {
        $rating = (int) $data['rating'];
        if ($rating < 1 || $rating > 5) {
            return null;
        }

        try {
            R::exec(
                'INSERT INTO ' . self::TABLE . '
                    (userID, appointmentID, rating, comment, reviewDate)
                 VALUES (?, ?, ?, ?, ?)',
                [
                    (int) $data['userID'],
                    (int) $data['appointmentID'],
                    $rating,
                    $data['comment'] ?? null,
                    $data['reviewDate'] ?? date('Y-m-d'),
                ]
            );
        } catch (\Throwable $e) {
            // UNIQUE-key violation means already reviewed.
            return null;
        }

        $id = (int) R::getDatabaseAdapter()->getInsertID();
        return $id ?: null;
    }

    public function storeReviews(array $data): bool
    {
        return $this->createReview($data) !== null;
    }

    public function editReview(int $id, array $data): bool
    {
        $existing = $this->getById($id);
        if (!$existing) {
            return false;
        }

        R::exec(
            'UPDATE ' . self::TABLE . '
             SET rating  = ?,
                 comment = ?
             WHERE ReviewID = ?',
            [
                (int) ($data['rating']  ?? $existing['rating']),
                $data['comment'] ?? $existing['comment'],
                $id,
            ]
        );
        return true;
    }

    public function deleteReview(int $id): bool
    {
        R::exec('DELETE FROM ' . self::TABLE . ' WHERE ReviewID = ?', [$id]);
        return true;
    }
}
