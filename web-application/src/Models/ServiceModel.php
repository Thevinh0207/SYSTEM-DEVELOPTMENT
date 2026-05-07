<?php

declare(strict_types=1);

namespace App\Models;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;

/**
 * ServiceModel
 *
 * Data access for the `services` table — managed by RedBeanPHP.
 */
class ServiceModel
{
    public function findAll(): array
    {
        return R::findAll('services', 'ORDER BY category ASC, name ASC');
    }

    public function load(int $id): ?OODBBean
    {
        $bean = R::load('services', $id);
        return $bean->id ? $bean : null;
    }

    public function create(array $data): OODBBean
    {
        $service              = R::dispense('services');
        $service->name        = $data['name'];
        $service->category    = $data['category'];
        $service->description = $data['description'] ?? '';
        $service->price       = (float) $data['price'];
        $service->duration    = (int) $data['duration'];
        R::store($service);
        return $service;
    }

    public function save(OODBBean $service): void
    {
        R::store($service);
    }

    public function delete(OODBBean $service): void
    {
        R::trash($service);
    }
}
