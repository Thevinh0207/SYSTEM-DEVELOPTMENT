<?php

declare(strict_types=1);

namespace App\Models;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;

/**
 * ServiceCategoryModel — admin-managed list of service categories.
 */
class ServiceCategoryModel
{
    public function findAll(): array
    {
        return R::findAll('service_category', 'ORDER BY sort_order ASC, name ASC');
    }

    public function load(int $id): ?OODBBean
    {
        $bean = R::load('service_category', $id);
        return $bean->id ? $bean : null;
    }

    public function findByName(string $name): ?OODBBean
    {
        return R::findOne('service_category', 'name = ?', [$name]);
    }

    public function create(array $data): OODBBean
    {
        $cat            = R::dispense('service_category');
        $cat->name      = $data['name'];
        $cat->sortOrder = (int) ($data['sortOrder'] ?? 0);
        R::store($cat);
        return $cat;
    }

    public function save(OODBBean $cat): void
    {
        R::store($cat);
    }

    public function delete(OODBBean $cat): void
    {
        R::trash($cat);
    }
}
