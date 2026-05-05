<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;
use PDO;

/**
 * PaymentModel — Database operations for the `payments` table
 * =============================================================
 * Handles the $20 deposit payments made during booking.
 * Supports both registered users and guests (guest checkout).
 *
 * Key design: payments snapshot the payer's contact info at the time of payment.
 * This means even if a user later changes their email, the payment record still
 * shows what their email was when they paid.
 *
 * Table columns:
 *   paymentID, appointmentID, paymentFrom (userID or NULL for guests),
 *   paymentFromName, paymentFromEmail, paymentFromPhone,
 *   paymentType, paymentAmount, paymentStatus, created_at
 */

class PaymentModel
{
    private const TABLE = 'payments';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID    = 'paid';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_REFUND  = 'refunded';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function getPayment(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ' . self::TABLE . ' WHERE paymentID = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function viewPayment(int $id): string
    {
        $payment = $this->getPayment($id);
        if (!$payment) {
            return 'Payment not found.';
        }
        return sprintf(
            'Payment #%d — %s $%.2f (%s)',
            $payment['paymentID'],
            $payment['paymentType'],
            (float) $payment['paymentAmount'],
            $payment['paymentStatus']
        );
    }

    public function getByAppointmentId(int $appointmentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE appointmentID = :id LIMIT 1'
        );
        $stmt->execute([':id' => $appointmentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(array $data): ?int
    {
        // paymentFrom: NULL = guest checkout, otherwise user.userID
        $from = $data['paymentFrom'] ?? null;
        $from = ($from === null || $from === '') ? null : (int) $from;

        // Snapshot contact info. For registered users we look up missing fields.
        $name  = trim((string) ($data['paymentFromName']  ?? ''));
        $email = trim((string) ($data['paymentFromEmail'] ?? ''));
        $phone = trim((string) ($data['paymentFromPhone'] ?? ''));

        if ($from !== null && ($name === '' || $email === '' || $phone === '')) {
            $lookup = $this->db->prepare('SELECT firstName, lastName, email, phoneNumber FROM user WHERE userID = :id');
            $lookup->execute([':id' => $from]);
            if ($u = $lookup->fetch()) {
                $name  = $name  !== '' ? $name  : trim($u['firstName'] . ' ' . $u['lastName']);
                $email = $email !== '' ? $email : (string) $u['email'];
                $phone = $phone !== '' ? $phone : (string) $u['phoneNumber'];
            }
        }

        // Guest checkout: name + at least one contact channel are mandatory.
        if ($from === null && ($name === '' || ($email === '' && $phone === ''))) {
            return null;
        }
        if ($name === '') {
            $name = 'Guest';
        }

        $stmt = $this->db->prepare(
            'INSERT INTO ' . self::TABLE . '
                (appointmentID, paymentFrom, paymentFromName, paymentFromEmail, paymentFromPhone,
                 paymentType, paymentAmount, paymentStatus)
             VALUES
                (:appointmentID, :paymentFrom, :paymentFromName, :paymentFromEmail, :paymentFromPhone,
                 :paymentType, :paymentAmount, :paymentStatus)'
        );

        $ok = $stmt->execute([
            ':appointmentID'    => (int) $data['appointmentID'],
            ':paymentFrom'      => $from,
            ':paymentFromName'  => $name,
            ':paymentFromEmail' => $email !== '' ? $email : null,
            ':paymentFromPhone' => $phone !== '' ? $phone : null,
            ':paymentType'      => $data['paymentType'],
            ':paymentAmount'    => (float) $data['paymentAmount'],
            ':paymentStatus'    => $data['paymentStatus'] ?? self::STATUS_PENDING,
        ]);

        return $ok ? (int) $this->db->lastInsertId() : null;
    }

    public function isFromGuest(array $payment): bool
    {
        return empty($payment['paymentFrom']);
    }

    public function updateStatus(int $id, string $status): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE ' . self::TABLE . ' SET paymentStatus = :status WHERE paymentID = :id'
        );
        return $stmt->execute([':status' => $status, ':id' => $id]);
    }

    // ── ADMIN-ONLY ─────────────────────────────────────────────────────────
    // Caller MUST verify role === 'admin' before calling these.

    public function getAllPayments(): array
    {
        return $this->db->query(
            'SELECT p.paymentID,
                    p.paymentFrom,
                    p.paymentFromName       AS payerName,
                    p.paymentFromEmail      AS payerEmail,
                    p.paymentFromPhone      AS payerPhone,
                    p.paymentType,
                    p.paymentAmount,
                    p.paymentStatus,
                    p.created_at,
                    a.AppointmentID,
                    a.date                  AS appointmentDate,
                    a.time                  AS appointmentTime,
                    CASE WHEN p.paymentFrom IS NULL THEN "guest" ELSE "client" END AS payerType
             FROM ' . self::TABLE . ' p
             JOIN appointment a ON p.appointmentID = a.AppointmentID
             ORDER BY p.created_at DESC'
        )->fetchAll();
    }

    public function getInsights(): array
    {
        $totals = $this->db->query(
            'SELECT
                COUNT(*)                                     AS total_count,
                COALESCE(SUM(paymentAmount), 0)              AS total_revenue,
                COALESCE(SUM(CASE WHEN paymentStatus = "paid"    THEN paymentAmount END), 0) AS revenue_paid,
                COALESCE(SUM(CASE WHEN paymentStatus = "pending" THEN paymentAmount END), 0) AS revenue_pending,
                COALESCE(AVG(paymentAmount), 0)              AS average_amount
             FROM ' . self::TABLE
        )->fetch();

        $byStatus = $this->db->query(
            'SELECT paymentStatus, COUNT(*) AS count, SUM(paymentAmount) AS total
             FROM ' . self::TABLE . '
             GROUP BY paymentStatus'
        )->fetchAll();

        $byType = $this->db->query(
            'SELECT paymentType, COUNT(*) AS count, SUM(paymentAmount) AS total
             FROM ' . self::TABLE . '
             GROUP BY paymentType'
        )->fetchAll();

        return [
            'totals'    => $totals,
            'by_status' => $byStatus,
            'by_type'   => $byType,
        ];
    }

    // ── CLIENT-SCOPED ──────────────────────────────────────────────────────
    // Returns only payments tied to the given user's appointments.

    public function getRecentPaymentsByUserId(int $userId, int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare(
            'SELECT p.paymentID, p.paymentType, p.paymentAmount, p.paymentStatus,
                    p.created_at, a.date AS appointmentDate, s.name AS serviceName
             FROM ' . self::TABLE . ' p
             JOIN appointment a ON p.appointmentID = a.AppointmentID
             JOIN services    s ON a.serviceID     = s.ServiceID
             WHERE a.userID = :userId
             ORDER BY p.created_at DESC
             LIMIT ' . $limit
        );
        $stmt->execute([':userId' => $userId]);
        return $stmt->fetchAll();
    }

    public function getPaymentForUser(int $paymentId, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*
             FROM ' . self::TABLE . ' p
             JOIN appointment a ON p.appointmentID = a.AppointmentID
             WHERE p.paymentID = :pid AND a.userID = :uid
             LIMIT 1'
        );
        $stmt->execute([':pid' => $paymentId, ':uid' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
