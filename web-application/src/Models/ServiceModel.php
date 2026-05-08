<?php

declare(strict_types=1);

namespace App\Models;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;

/**
 * ServiceModel — managed by RedBeanPHP.
 * Each row references a service_category row via category_id (FK).
 */
class ServiceModel
{
    public function findAll(): array
    {
        return R::findAll('services', 'ORDER BY name ASC');
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
        $service->categoryId  = (int) $data['categoryId'];
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

    /**
     * Returns services with category name attached, ready for templates.
     * Each row: ['id', 'name', 'category', 'description', 'price', 'duration']
     */
    public function findAllWithCategory(): array
    {
        $catNames = [];
        foreach (R::findAll('service_category', 'ORDER BY sort_order ASC') as $c) {
            $catNames[(int) $c->id] = $c->name;
        }

        $rows = [];
        foreach ($this->findAll() as $s) {
            $rows[] = [
                'id'          => (int) $s->id,
                'name'        => $s->name,
                'categoryId'  => (int) $s->categoryId,
                'category'    => $catNames[(int) $s->categoryId] ?? '—',
                'description' => $s->description,
                'price'       => $s->price,
                'duration'    => $s->duration,
            ];
        }
        return $rows;
    }
}
