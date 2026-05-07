<?php

declare(strict_types=1);

namespace App\Models;

use App\Config;
use RedBeanPHP\OODBBean;
use RedBeanPHP\R;

/**
 * UserModel
 *
 * Data access layer for the `user` table — managed entirely by RedBeanPHP.
 * Beans are returned as RedBean objects; controllers can read columns via
 * $bean->firstName, $bean->email, etc., and convert with $bean->export().
 */
class UserModel
{
    public const ROLE_ADMIN  = 'admin';
    public const ROLE_CLIENT = 'client';
    public const ROLE_GUEST  = 'guest';

    public function findAll(): array
    {
        return R::findAll('user', 'ORDER BY id ASC');
    }

    public function findByEmail(string $email): ?OODBBean
    {
        return R::findOne('user', 'email = ?', [$email]);
    }

    public function load(int $id): ?OODBBean
    {
        $bean = R::load('user', $id);
        return $bean->id ? $bean : null;
    }

    public function create(array $data): ?OODBBean
    {
        if (!$this->validateRegEx($data)) {
            return null;
        }
        if ($this->findByEmail($data['email'])) {
            return null;
        }

        $user              = R::dispense('user');
        $user->firstName   = $data['firstName'];
        $user->lastName    = $data['lastName'];
        $user->email       = $data['email'];
        $user->password    = password_hash($data['password'], PASSWORD_BCRYPT);
        $user->phoneNumber = $data['phoneNumber'] ?? '';
        $user->role        = $this->isAdminEmail($data['email'])
            ? self::ROLE_ADMIN
            : ($data['role'] ?? self::ROLE_CLIENT);

        R::store($user);
        return $user;
    }

    public function save(OODBBean $user): void
    {
        R::store($user);
    }

    public function delete(OODBBean $user): void
    {
        R::trash($user);
    }

    public function login(string $email, string $password): ?OODBBean
    {
        $user = $this->findByEmail($email);
        if (!$user || !password_verify($password, $user->password)) {
            return null;
        }
        return $user;
    }

    public function isAdmin(int $userId): bool
    {
        $user = $this->load($userId);
        return $user !== null && $user->role === self::ROLE_ADMIN;
    }

    public function isAdminEmail(string $email): bool
    {
        $admins = Config::get('admin_emails', []);
        return in_array(strtolower(trim($email)), array_map('strtolower', $admins), true);
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
