<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;
use PDO;

class ServiceModel
{
    private const TABLE = 'services';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function getAllServices(): array
    {
        return $this->db->query(
            'SELECT * FROM ' . self::TABLE . ' ORDER BY category ASC, name ASC'
        )->fetchAll();
    }

    public function getAllServicesByUserId(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT s.*
             FROM ' . self::TABLE . ' s
             JOIN appointment a ON a.serviceID = s.ServiceID
             WHERE a.userID = :userId
             ORDER BY s.name ASC'
        );
        $stmt->execute([':userId' => $userId]);
        return $stmt->fetchAll();
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ' . self::TABLE . ' WHERE ServiceID = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
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
        $stmt = $this->db->prepare(
            'INSERT INTO ' . self::TABLE . '
                (name, category, description, price, duration)
             VALUES
                (:name, :category, :description, :price, :duration)'
        );

        $ok = $stmt->execute([
            ':name'        => $data['name'],
            ':category'    => $data['category'],
            ':description' => $data['description'] ?? '',
            ':price'       => (float) $data['price'],
            ':duration'    => (int) $data['duration'],
        ]);

        return $ok ? (int) $this->db->lastInsertId() : null;
    }

    public function update(int $id, array $data): bool
    {
        $existing = $this->getById($id);
        if (!$existing) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE ' . self::TABLE . '
             SET name        = :name,
                 category    = :category,
                 description = :description,
                 price       = :price,
                 duration    = :duration
             WHERE ServiceID = :id'
        );

        return $stmt->execute([
            ':name'        => $data['name']        ?? $existing['name'],
            ':category'    => $data['category']    ?? $existing['category'],
            ':description' => $data['description'] ?? $existing['description'],
            ':price'       => (float) ($data['price']    ?? $existing['price']),
            ':duration'    => (int)   ($data['duration'] ?? $existing['duration']),
            ':id'          => $id,
        ]);
    }

    public function editServices(int $id, array $data): bool
    {
        return $this->update($id, $data);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM ' . self::TABLE . ' WHERE ServiceID = :id');
        return $stmt->execute([':id' => $id]);
    }

    public function deleteServices(int $id): bool
    {
        return $this->delete($id);
    }
}
