<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;
use PDO;
use RedBeanPHP\R;

/**
 * PaymentModel — handles the `payments` table via RedBeanPHP.
 *
 * Public methods unchanged. Stores a snapshot of the payer's contact info so
 * historical payments stay accurate even if a user later edits their profile.
 * Guests have paymentFrom = NULL but still have name/email/phone on the row.
 */
class PaymentModel
{
    private const TABLE = 'payments';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID    = 'paid';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_REFUND  = 'refunded';

    public function __construct(?PDO $db = null)
    {
        Database::connect();
    }

    public function getPayment(int $id): ?array
    {
        $row = R::getRow('SELECT * FROM ' . self::TABLE . ' WHERE paymentID = ?', [$id]);
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
        $row = R::getRow(
            'SELECT * FROM ' . self::TABLE . ' WHERE appointmentID = ? LIMIT 1',
            [$appointmentId]
        );
        return $row ?: null;
    }

    public function create(array $data): ?int
    {
        $from = $data['paymentFrom'] ?? null;
        $from = ($from === null || $from === '') ? null : (int) $from;

        $name  = trim((string) ($data['paymentFromName']  ?? ''));
        $email = trim((string) ($data['paymentFromEmail'] ?? ''));
        $phone = trim((string) ($data['paymentFromPhone'] ?? ''));

        // Snapshot missing fields from the user table for registered payers.
        if ($from !== null && ($name === '' || $email === '' || $phone === '')) {
            $u = R::getRow(
                'SELECT firstName, lastName, email, phoneNumber FROM user WHERE userID = ?',
                [$from]
            );
            if ($u) {
                $name  = $name  !== '' ? $name  : trim($u['firstName'] . ' ' . $u['lastName']);
                $email = $email !== '' ? $email : (string) $u['email'];
                $phone = $phone !== '' ? $phone : (string) $u['phoneNumber'];
            }
        }

        if ($from === null && ($name === '' || ($email === '' && $phone === ''))) {
            return null;
        }
        if ($name === '') {
            $name = 'Guest';
        }

        R::exec(
            'INSERT INTO ' . self::TABLE . '
                (appointmentID, paymentFrom, paymentFromName, paymentFromEmail, paymentFromPhone,
                 paymentType, paymentAmount, paymentStatus)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $data['appointmentID'],
                $from,
                $name,
                $email !== '' ? $email : null,
                $phone !== '' ? $phone : null,
                $data['paymentType'],
                (float) $data['paymentAmount'],
                $data['paymentStatus'] ?? self::STATUS_PENDING,
            ]
        );

        $id = (int) R::getDatabaseAdapter()->getInsertID();
        return $id ?: null;
    }

    public function isFromGuest(array $payment): bool
    {
        return empty($payment['paymentFrom']);
    }

    public function updateStatus(int $id, string $status): bool
    {
        R::exec(
            'UPDATE ' . self::TABLE . ' SET paymentStatus = ? WHERE paymentID = ?',
            [$status, $id]
        );
        return true;
    }

    // ── ADMIN-ONLY ─────────────────────────────────────────────────────────
    public function getAllPayments(): array
    {
        return R::getAll(
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
        );
    }

    public function getInsights(): array
    {
        $totals = R::getRow(
            'SELECT
                COUNT(*)                                     AS total_count,
                COALESCE(SUM(paymentAmount), 0)              AS total_revenue,
                COALESCE(SUM(CASE WHEN paymentStatus = "paid"    THEN paymentAmount END), 0) AS revenue_paid,
                COALESCE(SUM(CASE WHEN paymentStatus = "pending" THEN paymentAmount END), 0) AS revenue_pending,
                COALESCE(AVG(paymentAmount), 0)              AS average_amount
             FROM ' . self::TABLE
        );

        $byStatus = R::getAll(
            'SELECT paymentStatus, COUNT(*) AS count, SUM(paymentAmount) AS total
             FROM ' . self::TABLE . '
             GROUP BY paymentStatus'
        );

        $byType = R::getAll(
            'SELECT paymentType, COUNT(*) AS count, SUM(paymentAmount) AS total
             FROM ' . self::TABLE . '
             GROUP BY paymentType'
        );

        return [
            'totals'    => $totals ?: [],
            'by_status' => $byStatus,
            'by_type'   => $byType,
        ];
    }

    // ── CLIENT-SCOPED ──────────────────────────────────────────────────────
    public function getRecentPaymentsByUserId(int $userId, int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        return R::getAll(
            'SELECT p.paymentID, p.paymentType, p.paymentAmount, p.paymentStatus,
                    p.created_at, a.date AS appointmentDate, s.name AS serviceName
             FROM ' . self::TABLE . ' p
             JOIN appointment a ON p.appointmentID = a.AppointmentID
             JOIN services    s ON a.serviceID     = s.ServiceID
             WHERE a.userID = ?
             ORDER BY p.created_at DESC
             LIMIT ' . $limit,
            [$userId]
        );
    }

    public function getPaymentForUser(int $paymentId, int $userId): ?array
    {
        $row = R::getRow(
            'SELECT p.*
             FROM ' . self::TABLE . ' p
             JOIN appointment a ON p.appointmentID = a.AppointmentID
             WHERE p.paymentID = ? AND a.userID = ?
             LIMIT 1',
            [$paymentId, $userId]
        );
        return $row ?: null;
    }
}
