<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;
use PDO;
use RedBeanPHP\R;

/**
 * AppointmentModel — handles the `appointment` table via RedBeanPHP.
 *
 * Public methods/return shapes unchanged. All SQL routes through R::*.
 *
 * Notes:
 * - Appointments are never hard-deleted; cancellation sets status='cancelled'.
 * - isAvailable() prevents double-booking the studio time slot.
 * - JOIN queries fetch service + customer info in one round-trip.
 */
class AppointmentModel
{
    private const TABLE = 'appointment';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public function __construct(?PDO $db = null)
    {
        Database::connect();
    }

    public function getAllAppointements(): array
    {
        return R::getAll(
            'SELECT a.*,
                    s.name AS serviceName,
                    COALESCE(CONCAT(u.firstName, " ", u.lastName), a.guestName, "Guest") AS customerName,
                    COALESCE(u.email,       a.guestEmail) AS customerEmail,
                    COALESCE(u.phoneNumber, a.guestPhone) AS customerPhone,
                    CASE WHEN a.userID IS NULL THEN "guest" ELSE "client" END AS customerType
             FROM ' . self::TABLE . ' a
             JOIN services s ON a.serviceID = s.ServiceID
             LEFT JOIN user u ON a.userID    = u.userID
             ORDER BY a.date DESC, a.time DESC'
        );
    }

    public function getAllAppointmentByUserId(int $userId): array
    {
        return R::getAll(
            'SELECT a.*, s.name AS serviceName
             FROM ' . self::TABLE . ' a
             JOIN services s ON a.serviceID = s.ServiceID
             WHERE a.userID = ?
             ORDER BY a.date DESC, a.time DESC',
            [$userId]
        );
    }

    public function getAppointmentByUserId(int $userId): array
    {
        return $this->getAllAppointmentByUserId($userId);
    }

    public function getById(int $id): ?array
    {
        $row = R::getRow('SELECT * FROM ' . self::TABLE . ' WHERE AppointmentID = ?', [$id]);
        return $row ?: null;
    }

    public function viewAppointment(int $id): string
    {
        $appt = $this->getById($id);
        if (!$appt) {
            return 'Appointment not found.';
        }
        return sprintf(
            'Appointment #%d — %s at %s (status: %s)',
            $appt['AppointmentID'],
            $appt['date'],
            $appt['time'],
            $appt['status']
        );
    }

    public function createAppointment(array $data): ?int
    {
        if (!$this->isAvailable((int) $data['serviceID'], $data['date'], $data['time'])) {
            return null;
        }

        $userID = $data['userID'] ?? null;
        $userID = ($userID === null || $userID === '') ? null : (int) $userID;

        $guestName  = $userID === null ? trim((string) ($data['guestName']  ?? '')) : null;
        $guestEmail = $userID === null ? trim((string) ($data['guestEmail'] ?? '')) : null;
        $guestPhone = $userID === null ? trim((string) ($data['guestPhone'] ?? '')) : null;

        if ($userID === null && ($guestName === '' || ($guestEmail === '' && $guestPhone === ''))) {
            return null;
        }

        R::exec(
            'INSERT INTO ' . self::TABLE . '
                (serviceID, userID, guestName, guestEmail, guestPhone, date, time, notes, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $data['serviceID'],
                $userID,
                ($guestName  !== null && $guestName  !== '') ? $guestName  : null,
                ($guestEmail !== null && $guestEmail !== '') ? $guestEmail : null,
                ($guestPhone !== null && $guestPhone !== '') ? $guestPhone : null,
                $data['date'],
                $data['time'],
                $data['notes'] ?? null,
                $data['status'] ?? self::STATUS_PENDING,
            ]
        );

        return (int) R::getDatabaseAdapter()->getInsertID();
    }

    public function editAppointment(int $id, array $data): bool
    {
        return $this->updateAppointment($id, $data);
    }

    public function updateAppointment(int $id, array $data): bool
    {
        $existing = $this->getById($id);
        if (!$existing) {
            return false;
        }

        $serviceId = (int) ($data['serviceID'] ?? $existing['serviceID']);
        $date      = (string) ($data['date'] ?? $existing['date']);
        $time      = (string) ($data['time'] ?? $existing['time']);
        $slotChanged = $serviceId !== (int) $existing['serviceID']
            || $date !== (string) $existing['date']
            || $time !== (string) $existing['time'];

        if ($slotChanged && !$this->isAvailable($serviceId, $date, $time, $id)) {
            return false;
        }

        R::exec(
            'UPDATE ' . self::TABLE . '
             SET serviceID = ?,
                 date      = ?,
                 time      = ?,
                 notes     = ?,
                 status    = ?
             WHERE AppointmentID = ?',
            [
                $serviceId,
                $date,
                $time,
                $data['notes']  ?? $existing['notes'],
                $data['status'] ?? $existing['status'],
                $id,
            ]
        );
        return true;
    }

    public function cancelAppointment(int $id): bool
    {
        R::exec(
            'UPDATE ' . self::TABLE . ' SET status = ? WHERE AppointmentID = ?',
            [self::STATUS_CANCELLED, $id]
        );
        return true;
    }

    public function isAvailable(int $serviceId, string $date, string $time, ?int $ignoreAppointmentId = null): bool
    {
        if ($ignoreAppointmentId === null) {
            $count = (int) R::getCell(
                'SELECT COUNT(*) FROM ' . self::TABLE . '
                 WHERE date = ? AND time = ? AND status != ?',
                [$date, $time, self::STATUS_CANCELLED]
            );
        } else {
            $count = (int) R::getCell(
                'SELECT COUNT(*) FROM ' . self::TABLE . '
                 WHERE date = ? AND time = ? AND status != ? AND AppointmentID != ?',
                [$date, $time, self::STATUS_CANCELLED, $ignoreAppointmentId]
            );
        }
        return $count === 0;
    }

    public function getBookedTimesByDate(string $date): array
    {
        $rows = R::getAll(
            'SELECT TIME_FORMAT(time, "%H:%i") AS time
             FROM ' . self::TABLE . '
             WHERE date = ? AND status != ?
             ORDER BY time ASC',
            [$date, self::STATUS_CANCELLED]
        );
        return array_column($rows, 'time');
    }
}
