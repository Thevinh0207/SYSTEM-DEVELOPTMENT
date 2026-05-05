<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;
use PDO;

/**
 * AppointmentModel — Database operations for the `appointment` table
 * ===================================================================
 * Handles booking, editing, cancelling, and querying appointments.
 *
 * Key design decisions:
 * - Appointments are never hard-deleted. Cancelling sets status = 'cancelled'.
 * - The isAvailable() check prevents double-booking the same service slot.
 * - JOIN queries are used to fetch service name and customer name in one query
 *   rather than making separate calls for each appointment.
 *
 * Table columns: AppointmentID, serviceID, userID, date, time, notes, status
 */

class AppointmentModel
{
    private const TABLE = 'appointment';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Returns all appointments with the service name and customer name joined in.
     * Used on the admin appointments dashboard.
     * Ordered newest first.
     */
    public function getAllAppointements(): array
    {
        return $this->db->query(
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
        )->fetchAll();
    }

    /**
     * Returns all appointments for a specific user, with the service name joined.
     * Used on the customer dashboard "My Appointments" tab.
     */
    public function getAllAppointmentByUserId(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT a.*, s.name AS serviceName
             FROM ' . self::TABLE . ' a
             JOIN services s ON a.serviceID = s.ServiceID
             WHERE a.userID = :userId
             ORDER BY a.date DESC, a.time DESC'
        );
        $stmt->execute([':userId' => $userId]);
        return $stmt->fetchAll();
    }

    /** Alias for getAllAppointmentByUserId() used in some older code. */
    public function getAppointmentByUserId(int $userId): array
    {
        return $this->getAllAppointmentByUserId($userId);
    }

    /**
     * Fetches a single appointment by its primary key.
     * Returns null if not found.
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ' . self::TABLE . ' WHERE AppointmentID = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
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

    /**
     * Creates a new appointment after checking availability.
     * Returns the new AppointmentID, or null if the slot is already taken.
     *
     * Required $data keys: serviceID, userID, date, time
     * Optional $data keys: notes, status
     */
    public function createAppointment(array $data): ?int
    {
        if (!$this->isAvailable((int) $data['serviceID'], $data['date'], $data['time'])) {
            return null;
        }

        $userID = $data['userID'] ?? null;
        $userID = ($userID === null || $userID === '') ? null : (int) $userID;

        // Guests must supply contact info; logged-in users can leave it blank.
        $guestName  = $userID === null ? trim((string) ($data['guestName']  ?? '')) : null;
        $guestEmail = $userID === null ? trim((string) ($data['guestEmail'] ?? '')) : null;
        $guestPhone = $userID === null ? trim((string) ($data['guestPhone'] ?? '')) : null;

        if ($userID === null && ($guestName === '' || ($guestEmail === '' && $guestPhone === ''))) {
            return null;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO ' . self::TABLE . '
                (serviceID, userID, guestName, guestEmail, guestPhone, date, time, notes, status)
             VALUES
                (:serviceID, :userID, :guestName, :guestEmail, :guestPhone, :date, :time, :notes, :status)'
        );

        $stmt->execute([
            ':serviceID'  => (int) $data['serviceID'],
            ':userID'     => $userID,
            ':guestName'  => $guestName  !== null && $guestName  !== '' ? $guestName  : null,
            ':guestEmail' => $guestEmail !== null && $guestEmail !== '' ? $guestEmail : null,
            ':guestPhone' => $guestPhone !== null && $guestPhone !== '' ? $guestPhone : null,
            ':date'       => $data['date'],
            ':time'       => $data['time'],
            ':notes'      => $data['notes'] ?? null,
            ':status'     => $data['status'] ?? self::STATUS_PENDING,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function editAppointment(int $id, array $data): bool
    {
        return $this->updateAppointment($id, $data);
    }

    /**
     * Updates an appointment's details. Any field not in $data keeps its
     * current value (safe partial update).
     */
    public function updateAppointment(int $id, array $data): bool
    {
        $existing = $this->getById($id);
        if (!$existing) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE ' . self::TABLE . '
             SET serviceID = :serviceID,
                 date      = :date,
                 time      = :time,
                 notes     = :notes,
                 status    = :status
             WHERE AppointmentID = :id'
        );

        return $stmt->execute([
            ':serviceID' => (int) ($data['serviceID'] ?? $existing['serviceID']),
            ':date'      => $data['date']   ?? $existing['date'],
            ':time'      => $data['time']   ?? $existing['time'],
            ':notes'     => $data['notes']  ?? $existing['notes'],
            ':status'    => $data['status'] ?? $existing['status'],
            ':id'        => $id,
        ]);
    }

    /**
     * Marks an appointment as cancelled.
     */
    public function cancelAppointment(int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE ' . self::TABLE . ' SET status = :status WHERE AppointmentID = :id'
        );
        return $stmt->execute([
            ':status' => self::STATUS_CANCELLED,
            ':id'     => $id,
        ]);
    }

    /**
     * Checks whether a service slot is free at the given date and time.
     * A slot is available if there are no non-cancelled appointments for
     * that same service on that date at that time.
     */
    public function isAvailable(int $serviceId, string $date, string $time): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . self::TABLE . '
             WHERE serviceID = :serviceID
               AND date = :date
               AND time = :time
               AND status != :cancelled'
        );
        $stmt->execute([
            ':serviceID' => $serviceId,
            ':date'      => $date,
            ':time'      => $time,
            ':cancelled' => self::STATUS_CANCELLED,
        ]);
        return (int) $stmt->fetchColumn() === 0;
    }
}
