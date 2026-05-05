<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;
use PDO;

/**
 * ReviewModel — Database operations for the `reviews` table
 * ===========================================================
 * Handles customer reviews — creating, reading, editing, and deleting them.
 *
 * Reviews are linked to both a user (userID) and an appointment (appointmentID),
 * so each review knows which appointment it's for and who wrote it.
 * Rating is an integer 1–5, validated before insert.
 */
class ReviewModel
{
    private const TABLE = 'reviews';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Returns all reviews ordered newest first.
     * Used on the admin reviews page and the public reviews list.
     */
    public function getAll(): array
    {
        return $this->db->query(
            'SELECT * FROM ' . self::TABLE . ' ORDER BY reviewDate DESC'
        )->fetchAll();
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ' . self::TABLE . ' WHERE ReviewID = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Returns all reviews written by a specific user.
     * Used on the "My Reviews" tab of the customer dashboard.
     */
    public function getAllReviewsByUserId(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM ' . self::TABLE . '
             WHERE userID = :userId
             ORDER BY reviewDate DESC'
        );
        $stmt->execute([':userId' => $userId]);
        return $stmt->fetchAll();
    }

    public function findReviewsByUserId(int $userId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM ' . self::TABLE . ' WHERE userID = :userId LIMIT 1'
        );
        $stmt->execute([':userId' => $userId]);
        return (bool) $stmt->fetchColumn();
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

    /**
     * Creates a new review.
     * Returns the new ReviewID on success, or null if the rating is invalid.
     *
     * Required $data keys: userID, appointmentID, rating (1–5)
     * Optional $data keys: comment, reviewDate (defaults to today)
     */
    public function createReview(array $data): ?int
    {
        $rating = (int) $data['rating'];
        if ($rating < 1 || $rating > 5) {
            return null;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO ' . self::TABLE . '
                (userID, appointmentID, rating, comment, reviewDate)
             VALUES
                (:userID, :appointmentID, :rating, :comment, :reviewDate)'
        );

        $ok = $stmt->execute([
            ':userID'        => (int) $data['userID'],
            ':appointmentID' => (int) $data['appointmentID'],
            ':rating'        => $rating,
            ':comment'       => $data['comment'] ?? null,
            ':reviewDate'    => $data['reviewDate'] ?? date('Y-m-d'),
        ]);

        return $ok ? (int) $this->db->lastInsertId() : null;
    }

    /**
     * Alias for createReview() — returns bool instead of ID.
     * Kept for compatibility with the class diagram method name.
     */
    public function storeReviews(array $data): bool
    {
        return $this->createReview($data) !== null;
    }

    /**
     * Updates a review's rating and/or comment.
     * Falls back to existing values for any field not provided in $data.
     */
    public function editReview(int $id, array $data): bool
    {
        $existing = $this->getById($id);
        if (!$existing) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE ' . self::TABLE . '
             SET rating  = :rating,
                 comment = :comment
             WHERE ReviewID = :id'
        );

        return $stmt->execute([
            ':rating'  => (int) ($data['rating']  ?? $existing['rating']),
            ':comment' => $data['comment'] ?? $existing['comment'],
            ':id'      => $id,
        ]);
    }

    public function deleteReview(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM ' . self::TABLE . ' WHERE ReviewID = :id');
        return $stmt->execute([':id' => $id]);
    }
}
