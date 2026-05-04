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


   
}
