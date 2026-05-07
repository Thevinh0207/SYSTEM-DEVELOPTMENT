<?php

declare(strict_types=1);

namespace App\Models;

use App\Config;
use App\Database\Database;
use PDO;
use RedBeanPHP\R;

/**
 * UserModel — handles the `user` table via RedBeanPHP.
 *
 * Method signatures and return shapes are identical to the previous PDO-only
 * version so controllers and templates don't need to change. Internally every
 * query now flows through RedBean's adapter (R::getRow / R::getCell / R::exec).
 */
class UserModel
{
    private const TABLE = 'user';

    public const ROLE_ADMIN  = 'admin';
    public const ROLE_CLIENT = 'client';
    public const ROLE_GUEST  = 'guest';

    public function __construct(?PDO $db = null)
    {
        // Constructor still accepts a PDO for backwards compatibility, but
        // calling Database::connect() also boots RedBean if it wasn't already.
        Database::connect();
    }

    public function getAll(): array
    {
        return R::getAll('SELECT * FROM ' . self::TABLE . ' ORDER BY userID ASC');
    }

    public function getById(int $id): ?array
    {
        $row = R::getRow('SELECT * FROM ' . self::TABLE . ' WHERE userID = ?', [$id]);
        return $row ?: null;
    }

    public function findUserByEmail(string $email): ?array
    {
        $row = R::getRow('SELECT * FROM ' . self::TABLE . ' WHERE email = ? LIMIT 1', [$email]);
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

        R::exec(
            'INSERT INTO ' . self::TABLE . '
                (firstName, lastName, email, password, phoneNumber, role)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $data['firstName'],
                $data['lastName'],
                $data['email'],
                password_hash($data['password'], PASSWORD_BCRYPT),
                $data['phoneNumber'] ?? '',
                $role,
            ]
        );

        return (int) R::getDatabaseAdapter()->getInsertID();
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

        R::exec(
            'UPDATE ' . self::TABLE . '
             SET firstName   = ?,
                 lastName    = ?,
                 email       = ?,
                 phoneNumber = ?,
                 role        = ?
             WHERE userID = ?',
            [
                $data['firstName']   ?? $existing['firstName'],
                $data['lastName']    ?? $existing['lastName'],
                $data['email']       ?? $existing['email'],
                $data['phoneNumber'] ?? $existing['phoneNumber'],
                $data['role']        ?? $existing['role'],
                $id,
            ]
        );
        return true;
    }

    public function delete(int $id): bool
    {
        R::exec('DELETE FROM ' . self::TABLE . ' WHERE userID = ?', [$id]);
        return true;
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
