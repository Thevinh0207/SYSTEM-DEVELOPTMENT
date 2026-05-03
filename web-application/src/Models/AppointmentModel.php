<?php

declare(strict_types=1);

namespace App\Models;

use RedBeanPHP\R;
use App\Helpers\BeanHelper;

class AppointmentModel
{
    public function getAll(): array
    {
        return BeanHelper::castBeanArray(R::findAll('appointment', 'ORDER BY id ASC'));
    }

    public function getById(int $id): ?\RedBeanPHP\OODBBean
    {
        $bean = R::load('appointment', $id);
        return BeanHelper::isValidBean($bean) ? BeanHelper::castBeanProperties($bean) : null;
    }

    public function create(array $data): \RedBeanPHP\OODBBean
    {
        $appointment = R::dispense('appointment');
        $appointment->date = $data['date'] ?? '';
        $appointment->time = $data['time'] ?? '';
        $appointment->notes = $data['notes'] ?? '';
        $appointment->customerId = $data['customerId'] ?? '';
        $appointment->serviceId = $data['serviceId'] ?? '';
        $appointment->paymentId = $data['paymentId'] ?? '';
        R::store($appointment);
        return BeanHelper::castBeanProperties($appointment);
    }

    public function update(int $id, array $data): ?\RedBeanPHP\OODBBean
    {
        $appointment = R::load('appointment', $id);
        if (!BeanHelper::isValidBean($appointment)) {
            return null;
        }

        $appointment->date = $data['date'] ?? $appointment->date;
        $appointment->time = $data['time'] ?? $appointment->time;
        $appointment->notes = $data['notes'] ?? $appointment->notes;
        $appointment->customerId = $data['customerId'] ?? $appointment->customerId;
        $appointment->serviceId = $data['serviceId'] ?? $appointment->serviceId;
        $appointment->paymentId = $data['paymentId'] ?? $appointment->paymentId;
        R::store($appointment);

        return BeanHelper::castBeanProperties($appointment);
    }


    public function delete(int $id): bool
    {
        $appointment = R::load('appointment', $id);
        if (!BeanHelper::isValidBean($appointment)) {
            return false;
        }
        
        R::trash($appointment);
        return true;
    }

    public function count(): int
    {
        return R::count('appointment');
    }

    public function getAllAppointment
}
