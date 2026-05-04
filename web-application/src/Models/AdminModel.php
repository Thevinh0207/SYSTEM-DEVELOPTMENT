<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Admin operations — methods only an admin role should call.
 * Caller must verify role beforehand (see RoleMiddleware / UserModel::isAdmin()).
 *
 * Maps to the `Admin` class in the class diagram.
 */
class AdminModel
{
    public function __construct(
        private ServiceModel $services,
        private AppointmentModel $appointments
    ) {}

    // ── Services ───────────────────────────────────────────────────────────
    public function addService(array $data): ?int
    {
        return $this->services->create($data);
    }

    public function editService(int $id, array $data): bool
    {
        return $this->services->update($id, $data);
    }

    public function deleteService(int $id): bool
    {
        return $this->services->delete($id);
    }

    // ── Appointments ───────────────────────────────────────────────────────
    public function addAppointment(array $data): ?int
    {
        return $this->appointments->createAppointment($data);
    }

    public function editAppointment(int $id, array $data): bool
    {
        return $this->appointments->editAppointment($id, $data);
    }

    public function deleteAppointment(int $id): bool
    {
        return $this->appointments->cancelAppointment($id);
    }

    public function viewAppointments(): array
    {
        return $this->appointments->getAllAppointements();
    }
}
