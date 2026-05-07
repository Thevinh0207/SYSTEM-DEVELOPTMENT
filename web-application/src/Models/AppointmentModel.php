<?php

declare(strict_types=1);

namespace App\Models;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;

/**
 * AppointmentModel
 *
 * Data access for the `appointment` table — managed by RedBeanPHP.
 */
class AppointmentModel
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public function findAll(): array
    {
        return R::findAll('appointment', 'ORDER BY date DESC, time DESC');
    }

    public function findByUserId(int $userId): array
    {
        return R::find('appointment', 'userID = ? ORDER BY date DESC, time DESC', [$userId]);
    }

    public function load(int $id): ?OODBBean
    {
        $bean = R::load('appointment', $id);
        return $bean->id ? $bean : null;
    }

    public function create(array $data): ?OODBBean
    {
        if (!$this->isAvailable((string) $data['date'], (string) $data['time'])) {
            return null;
        }

        $userId     = $data['userID'] ?? null;
        $userId     = ($userId === null || $userId === '') ? null : (int) $userId;
        $guestName  = $userId === null ? trim((string) ($data['guestName']  ?? '')) : null;
        $guestEmail = $userId === null ? trim((string) ($data['guestEmail'] ?? '')) : null;
        $guestPhone = $userId === null ? trim((string) ($data['guestPhone'] ?? '')) : null;

        if ($userId === null && ($guestName === '' || ($guestEmail === '' && $guestPhone === ''))) {
            return null;
        }

        $appt             = R::dispense('appointment');
        $appt->serviceID  = (int) $data['serviceID'];
        $appt->userID     = $userId;
        $appt->guestName  = ($guestName  !== null && $guestName  !== '') ? $guestName  : null;
        $appt->guestEmail = ($guestEmail !== null && $guestEmail !== '') ? $guestEmail : null;
        $appt->guestPhone = ($guestPhone !== null && $guestPhone !== '') ? $guestPhone : null;
        $appt->date       = $data['date'];
        $appt->time       = $data['time'];
        $appt->notes      = $data['notes'] ?? null;
        $appt->status     = $data['status'] ?? self::STATUS_PENDING;

        R::store($appt);
        return $appt;
    }

    public function save(OODBBean $appt): void
    {
        R::store($appt);
    }

    public function cancel(OODBBean $appt): void
    {
        $appt->status = self::STATUS_CANCELLED;
        R::store($appt);
    }

    public function delete(OODBBean $appt): void
    {
        R::trash($appt);
    }

    /**
     * True if no non-cancelled appointment exists at the given date+time.
     * Single-chair salon → only one slot occupied per timestamp.
     */
    public function isAvailable(string $date, string $time, ?int $ignoreId = null): bool
    {
        if ($ignoreId === null) {
            $count = R::count('appointment', 'date = ? AND time = ? AND status != ?',
                [$date, $time, self::STATUS_CANCELLED]);
        } else {
            $count = R::count('appointment',
                'date = ? AND time = ? AND status != ? AND id != ?',
                [$date, $time, self::STATUS_CANCELLED, $ignoreId]);
        }
        return $count === 0;
    }
}
