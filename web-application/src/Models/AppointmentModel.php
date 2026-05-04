<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;
use PDO;

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

    public function getAllAppointements(): array
    {
        return $this->db->query(
            'SELECT a.*, s.name AS serviceName, u.firstName, u.lastName
             FROM ' . self::TABLE . ' a
             JOIN services s ON a.serviceID = s.ServiceID
             JOIN user u     ON a.userID    = u.userID
             ORDER BY a.date DESC, a.time DESC'
        )->fetchAll();
    }

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

    public function getAppointmentByUserId(int $userId): array
    {
        return $this->getAllAppointmentByUserId($userId);
    }

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

    public function createAppointment(array $data): ?int
    {
        if (!$this->isAvailable((int) $data['serviceID'], $data['date'], $data['time'])) {
            return null;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO ' . self::TABLE . '
                (serviceID, userID, date, time, notes, status)
             VALUES
                (:serviceID, :userID, :date, :time, :notes, :status)'
        );

        $stmt->execute([
            ':serviceID' => (int) $data['serviceID'],
            ':userID'    => (int) $data['userID'],
            ':date'      => $data['date'],
            ':time'      => $data['time'],
            ':notes'     => $data['notes'] ?? null,
            ':status'    => $data['status'] ?? self::STATUS_PENDING,
        ]);

        return (int) $this->db->lastInsertId();
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
