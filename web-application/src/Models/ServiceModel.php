<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;
use PDO;
use RedBeanPHP\R;

/**
 * ServiceModel — handles the `services` table via RedBeanPHP.
 *
 * Public method signatures unchanged. Underneath, queries flow through
 * R::getAll / R::getRow / R::exec.
 */
class ServiceModel
{
    private const TABLE = 'services';

    public function __construct(?PDO $db = null)
    {
        Database::connect();
    }

    public function getAllServices(): array
    {
        return R::getAll('SELECT * FROM ' . self::TABLE . ' ORDER BY category ASC, name ASC');
    }

    public function getAllServicesByUserId(int $userId): array
    {
        return R::getAll(
            'SELECT DISTINCT s.*
             FROM ' . self::TABLE . ' s
             JOIN appointment a ON a.serviceID = s.ServiceID
             WHERE a.userID = ?
             ORDER BY s.name ASC',
            [$userId]
        );
    }

    public function getById(int $id): ?array
    {
        $row = R::getRow('SELECT * FROM ' . self::TABLE . ' WHERE ServiceID = ?', [$id]);
        return $row ?: null;
    }

    public function viewDetails(int $id): string
    {
        $service = $this->getById($id);
        if (!$service) {
            return 'Service not found.';
        }
        return sprintf(
            '%s (%s) — $%.2f, %d min. %s',
            $service['name'],
            $service['category'],
            (float) $service['price'],
            (int) $service['duration'],
            $service['description']
        );
    }

    public function create(array $data): ?int
    {
        R::exec(
            'INSERT INTO ' . self::TABLE . '
                (name, category, description, price, duration)
             VALUES (?, ?, ?, ?, ?)',
            [
                $data['name'],
                $data['category'],
                $data['description'] ?? '',
                (float) $data['price'],
                (int) $data['duration'],
            ]
        );
        $id = (int) R::getDatabaseAdapter()->getInsertID();
        return $id ?: null;
    }

    public function update(int $id, array $data): bool
    {
        $existing = $this->getById($id);
        if (!$existing) {
            return false;
        }

        R::exec(
            'UPDATE ' . self::TABLE . '
             SET name        = ?,
                 category    = ?,
                 description = ?,
                 price       = ?,
                 duration    = ?
             WHERE ServiceID = ?',
            [
                $data['name']        ?? $existing['name'],
                $data['category']    ?? $existing['category'],
                $data['description'] ?? $existing['description'],
                (float) ($data['price']    ?? $existing['price']),
                (int)   ($data['duration'] ?? $existing['duration']),
                $id,
            ]
        );
        return true;
    }

    public function editServices(int $id, array $data): bool
    {
        return $this->update($id, $data);
    }

    public function delete(int $id): bool
    {
        R::exec('DELETE FROM ' . self::TABLE . ' WHERE ServiceID = ?', [$id]);
        return true;
    }

    public function deleteServices(int $id): bool
    {
        return $this->delete($id);
    }
}
