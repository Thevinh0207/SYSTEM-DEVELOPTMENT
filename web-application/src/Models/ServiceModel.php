<?php

declare(strict_types=1);

namespace App\Models;

use RedBeanPHP\R;
use App\Helpers\BeanHelper;

class ServiceModel
{
    public function getAll(): array
    {
        return BeanHelper::castBeanArray(R::findAll('service', 'ORDER BY id ASC'));
    }

    public function getById(int $id): ?\RedBeanPHP\OODBBean
    {
        $bean = R::load('service', $id);
        return BeanHelper::isValidBean($bean) ? BeanHelper::castBeanProperties($bean) : null;
    }

    public function create(array $data): \RedBeanPHP\OODBBean
    {
        $service = R::dispense('service');
        $service->name = $data['name'] ?? '';
        $service->category = $data['category'] ?? '';
        $service->description = $data['description'] ?? '';
        $service->price = (float) ($data['price'] ?? 0.0);
        $service->duration = $data['duration'] ?? '';
        R::store($service);
        return BeanHelper::castBeanProperties($service);
    }

    public function update(int $id, array $data): ?\RedBeanPHP\OODBBean
    {
        $service = R::load('service', $id);
        if (!BeanHelper::isValidBean($service)) {
            return null;
        }

        $service->name = $data['name'] ?? $service->name;
        $service->category = $data['category'] ?? $service->category;
        $service->description = $data['description'] ?? $service->description;
        $service->price = (float) ($data['price'] ?? $service->price);
        $service->duration = $data['duration'] ?? $service->duration;
        R::store($service);

        return BeanHelper::castBeanProperties($service);
    }

    public function getAllServicesByCategory(string $category): array
    {
        return BeanHelper::castBeanArray(R::find('service', 'category = ? ORDER BY id ASC', [$category]));
    }

    public function editService(int $id, array $data): ?\RedBeanPHP\OODBBean
    {
        $service = R::load('service', $id);
        if (!BeanHelper::isValidBean($service)) {
            return null;
        }

        $service->name = $data['name'] ?? $service->name;
        $service->category = $data['category'] ?? $service->category;
        $service->description = $data['description'] ?? $service->description;
        $service->price = (float) ($data['price'] ?? $service->price);
        $service->duration = $data['duration'] ?? $service->duration;
        R::store($service);

        return BeanHelper::castBeanProperties($service);
    }

    public function deleteServiceById(int $id): bool
    {
        $service = R::load('service', $id);
        if (!BeanHelper::isValidBean($service)) {
            return false;
        }
        
        R::trash($service);
        return true;
    }
}
