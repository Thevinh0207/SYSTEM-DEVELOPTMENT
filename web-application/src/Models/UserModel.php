<?php

declare(strict_types=1);

namespace App\Models;

use RedBeanPHP\R;
use App\Helpers\BeanHelper;

class UserModel
{
    public function getAll(): array
    {
        return BeanHelper::castBeanArray(R::findAll('user', 'ORDER BY id ASC'));
    }

    public function getById(int $id): ?\RedBeanPHP\OODBBean
    {
        $bean = R::load('user', $id);
        return BeanHelper::isValidBean($bean) ? BeanHelper::castBeanProperties($bean) : null;
    }

    public function create(array $data): \RedBeanPHP\OODBBean
    {
        $user = R::dispense('user');
        $user->firstName = $data['firstName'] ?? '';
        $user->lastName = $data['lastName'] ?? '';
        $user->email = $data['email'] ?? '';
        $user->password = $data['password'] ?? '';
        $user->phoneNumber = $data['phoneNumber'] ?? '';
        $user->role = $data['role'] ?? 'guest';
        R::store($user);
        return BeanHelper::castBeanProperties($user);
    }

    public function update(int $id, array $data): ?\RedBeanPHP\OODBBean
    {
        $user = R::load('user', $id);
        if (!BeanHelper::isValidBean($user)) {
            return null;
        }

        $user->firstName = $data['firstName'] ?? $user->firstName;
        $user->lastName = $data['lastName'] ?? $user->lastName;
        $user->email = $data['email'] ?? $user->email;
        $user->password = $data['password'] ?? $user->password;
        $user->phoneNumber = $data['phoneNumber'] ?? $user->phoneNumber;
        $user->role = $data['role'] ?? $user->role;
        R::store($user);

        return BeanHelper::castBeanProperties($user);
    }

    public function isAdmin(int $id): bool
    {
        $user = R::load('user', $id);
        return BeanHelper::isValidBean($user) && $user->role === 'admin';
    }
    
    public function findUserByEmail(string $email): ?\RedBeanPHP\OODBBean
    {
        $user = R::findOne('user', 'email = ?', [$email]);
        return BeanHelper::isValidBean($user) ? BeanHelper::castBeanProperties($user) : null;
    }

    public function findUserByUserId(int $userId): ?\RedBeanPHP\OODBBean
    {
        $user = R::findOne('user', 'id = ?', [$userId]);
        return BeanHelper::isValidBean($user) ? BeanHelper::castBeanProperties($user) : null;
    }


}
