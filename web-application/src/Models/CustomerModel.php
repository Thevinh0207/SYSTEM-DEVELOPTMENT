<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Customer (client role) operations.
 * Maps to the `Customer` class in the class diagram.
 */
class CustomerModel
{
    public function __construct(private AppointmentModel $appointments) {}

    public function bookAppointment(array $data): ?int
    {
        return $this->appointments->createAppointment($data);
    }

    public function viewAppointments(int $userId): array
    {
        return $this->appointments->getAllAppointmentByUserId($userId);
    }

    public function cancelAppointment(int $appointmentId): bool
    {
        return $this->appointments->cancelAppointment($appointmentId);
    }
}
