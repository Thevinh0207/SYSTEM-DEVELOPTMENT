<?php

declare(strict_types=1);

namespace App\Models;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;

/**
 * PaymentModel
 *
 * Data access for the `payments` table — managed by RedBeanPHP.
 *
 * Payments snapshot the payer's contact info so historical records stay
 * accurate even if the user later changes their profile. Guest checkouts
 * have paymentFrom = NULL.
 */
class PaymentModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID    = 'paid';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_REFUND  = 'refunded';

    public function findAll(): array
    {
        return R::findAll('payments', 'ORDER BY created_at DESC');
    }

    public function findByUserId(int $userId): array
    {
        return R::find('payments', 'paymentFrom = ? ORDER BY created_at DESC', [$userId]);
    }

    public function findByAppointmentId(int $appointmentId): ?OODBBean
    {
        return R::findOne('payments', 'appointmentID = ?', [$appointmentId]);
    }

    public function load(int $id): ?OODBBean
    {
        $bean = R::load('payments', $id);
        return $bean->id ? $bean : null;
    }

    public function create(array $data): ?OODBBean
    {
        $from = $data['paymentFrom'] ?? null;
        $from = ($from === null || $from === '') ? null : (int) $from;

        $name  = trim((string) ($data['paymentFromName']  ?? ''));
        $email = trim((string) ($data['paymentFromEmail'] ?? ''));
        $phone = trim((string) ($data['paymentFromPhone'] ?? ''));

        // Snapshot missing fields from the user table for registered payers.
        if ($from !== null && ($name === '' || $email === '' || $phone === '')) {
            $u = R::load('user', $from);
            if ($u->id) {
                $name  = $name  !== '' ? $name  : trim($u->firstName . ' ' . $u->lastName);
                $email = $email !== '' ? $email : (string) $u->email;
                $phone = $phone !== '' ? $phone : (string) $u->phoneNumber;
            }
        }

        if ($from === null && ($name === '' || ($email === '' && $phone === ''))) {
            return null;
        }
        if ($name === '') {
            $name = 'Guest';
        }

        $payment                   = R::dispense('payments');
        $payment->appointmentID    = (int) $data['appointmentID'];
        $payment->paymentFrom      = $from;
        $payment->paymentFromName  = $name;
        $payment->paymentFromEmail = $email !== '' ? $email : null;
        $payment->paymentFromPhone = $phone !== '' ? $phone : null;
        $payment->paymentType      = $data['paymentType'];
        $payment->paymentAmount    = (float) $data['paymentAmount'];
        $payment->paymentStatus    = $data['paymentStatus'] ?? self::STATUS_PENDING;

        R::store($payment);
        return $payment;
    }

    public function save(OODBBean $payment): void
    {
        R::store($payment);
    }

    public function delete(OODBBean $payment): void
    {
        R::trash($payment);
    }
}
