<?php

declare(strict_types=1);

namespace App\Models;

use RedBeanPHP\R;
use App\Helpers\BeanHelper;

class PaymentModel
{
    public function getAll(): array
    {
        return BeanHelper::castBeanArray(R::findAll('payment', 'ORDER BY id ASC'));
    }

    public function getById(int $id): ?\RedBeanPHP\OODBBean
    {
        $bean = R::load('payment', $id);
        return BeanHelper::isValidBean($bean) ? BeanHelper::castBeanProperties($bean) : null;
    }

    public function create(array $data): \RedBeanPHP\OODBBean
    {
        $payment = R::dispense('payment');
        $payment->paymentType = $data['paymentType'] ?? '';
        $payment->amount = (float) ($data['amount'] ?? 0.0);
        $payment->paymentStatus = $data['paymentStatus'] ?? '';
        R::store($payment);
        return BeanHelper::castBeanProperties($payment);
    }

    public function update(int $id, array $data): ?\RedBeanPHP\OODBBean
    {
        $payment = R::load('payment', $id);
        if (!BeanHelper::isValidBean($payment)) {
            return null;
        }

        $payment->amount = (float) ($data['amount'] ?? $payment->amount);
        $payment->paymentType = $data['paymentType'] ?? $payment->paymentType;
        $payment->paymentStatus = $data['paymentStatus'] ?? $payment->paymentStatus;
        R::store($payment);

        return BeanHelper::castBeanProperties($payment);
    }


    public function delete(int $id): bool
    {
        $payment = R::load('payment', $id);
        if (!BeanHelper::isValidBean($payment)) {
            return false;
        }
        
        R::trash($payment);
        return true;
    }

    public function getByTeamId(int $teamId): array
    {
        return BeanHelper::castBeanArray(R::findAll('activity', 'team_id = ? ORDER BY activity_date DESC, activity_time DESC', [$teamId]));
    }

    public function getUpcoming(): array
    {
        $today = date('Y-m-d');
        return BeanHelper::castBeanArray(R::findAll('activity', 'activity_date >= ? ORDER BY activity_date ASC, activity_time ASC', [$today]));
    }

    public function count(): int
    {
        return R::count('activity');
    }
}
