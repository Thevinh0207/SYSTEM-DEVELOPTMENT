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

    /**
     * Books a new appointment for the customer.
     * Delegates to AppointmentModel which checks slot availability first.
     * Returns the new AppointmentID, or null if the slot is taken.
     */
    public function bookAppointment(array $data): ?int
    {
        return $this->appointments->createAppointment($data);
    }

    /**
     * Returns all of a customer's appointments (past and upcoming).
     * Results include the service name so templates don't need a second query.
     */
    public function viewAppointments(int $userId): array
    {
        return $this->appointments->getAllAppointmentByUserId($userId);
    }

    /**
     * Cancels one of the customer's appointments.
     * Sets status to 'cancelled' — the appointment stays in the database.
     */
    public function cancelAppointment(int $appointmentId): bool
    {
        return $this->appointments->cancelAppointment($appointmentId);
    }
}
