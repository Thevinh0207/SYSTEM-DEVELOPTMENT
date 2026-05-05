<?php

declare(strict_types=1);

namespace App\Models;

use App\Config;
use App\Database\Database;
use PDO;

/**
 * UserModel — Database operations for the `user` table
 * ======================================================
 * Handles everything related to user accounts: creating them, logging in,
 * fetching them by ID or email, updating profile details, and deletion.
 *
 * All methods talk to MySQL through PDO prepared statements, which protect
 * against SQL injection by keeping data separate from the SQL query.
 */
class UserModel
{
    private const TABLE = 'user';

    public const ROLE_ADMIN  = 'admin';
    public const ROLE_CLIENT = 'client';
    public const ROLE_GUEST  = 'guest';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function getAll(): array
    {
        return $this->db->query('SELECT * FROM ' . self::TABLE . ' ORDER BY userID ASC')->fetchAll();
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ' . self::TABLE . ' WHERE userID = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findUserByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ' . self::TABLE . ' WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findUserByUserId(int $userId): ?array
    {
        return $this->getById($userId);
    }

    public function signUp(array $data): ?int
    {
        if (!$this->validateRegEx($data)) {
            return null;
        }
        if ($this->findUserByEmail($data['email'])) {
            return null;
        }

        $role = $this->isAdminEmail($data['email']) ? self::ROLE_ADMIN : ($data['role'] ?? self::ROLE_CLIENT);

        $stmt = $this->db->prepare(
            'INSERT INTO ' . self::TABLE . '
                (firstName, lastName, email, password, phoneNumber, role)
             VALUES
                (:firstName, :lastName, :email, :password, :phoneNumber, :role)'
        );

        $stmt->execute([
            ':firstName'   => $data['firstName'],
            ':lastName'    => $data['lastName'],
            ':email'       => $data['email'],
            ':password'    => password_hash($data['password'], PASSWORD_BCRYPT),
            ':phoneNumber' => $data['phoneNumber'] ?? '',
            ':role'        => $role,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function isAdminEmail(string $email): bool
    {
        $admins = Config::get('admin_emails', []);
        return in_array(strtolower(trim($email)), array_map('strtolower', $admins), true);
    }

    public function login(string $email, string $password): ?array
    {
        $user = $this->findUserByEmail($email);
        if (!$user || !password_verify($password, $user['password'])) {
            return null;
        }
        return $user;
    }

    public function logout(): bool
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        return true;
    }

    public function isAdmin(int $userId): bool
    {
        $user = $this->getById($userId);
        return $user !== null && $user['role'] === self::ROLE_ADMIN;
    }

    public function update(int $id, array $data): bool
    {
        $existing = $this->getById($id);
        if (!$existing) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE ' . self::TABLE . '
             SET firstName = :firstName,
                 lastName  = :lastName,
                 email     = :email,
                 phoneNumber = :phoneNumber,
                 role      = :role
             WHERE userID = :id'
        );

        return $stmt->execute([
            ':firstName'   => $data['firstName']   ?? $existing['firstName'],
            ':lastName'    => $data['lastName']    ?? $existing['lastName'],
            ':email'       => $data['email']       ?? $existing['email'],
            ':phoneNumber' => $data['phoneNumber'] ?? $existing['phoneNumber'],
            ':role'        => $data['role']        ?? $existing['role'],
            ':id'          => $id,
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM ' . self::TABLE . ' WHERE userID = :id');
        return $stmt->execute([':id' => $id]);
    }

    public function validateRegEx(array $data): bool
    {
        if (empty($data['firstName']) || !preg_match('/^[\p{L}\s\'-]{1,50}$/u', $data['firstName'])) {
            return false;
        }
        if (empty($data['lastName']) || !preg_match('/^[\p{L}\s\'-]{1,50}$/u', $data['lastName'])) {
            return false;
        }
        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if (empty($data['password']) || strlen($data['password']) < 8) {
            return false;
        }
        if (!empty($data['phoneNumber']) && !preg_match('/^[0-9\-\+\s\(\)]{7,20}$/', $data['phoneNumber'])) {
            return false;
        }
        return true;
    }
}
