<?php

declare(strict_types=1);

namespace App\Models;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;

/**
 * AboutModel
 *
 * Data access for the `about_section` table — managed by RedBeanPHP.
 * Each row is one story-block on the About page (heading + body).
 */
class AboutModel
{
    public function findAll(): array
    {
        return R::findAll('about_section', 'ORDER BY sort_order ASC, id ASC');
    }

    public function load(int $id): ?OODBBean
    {
        $bean = R::load('about_section', $id);
        return $bean->id ? $bean : null;
    }

    public function create(array $data): OODBBean
    {
        $section            = R::dispense('about_section');
        $section->heading   = $data['heading'];
        $section->body      = $data['body'];
        $section->sortOrder = (int) ($data['sortOrder'] ?? 0);
        R::store($section);
        return $section;
    }

    public function save(OODBBean $section): void
    {
        R::store($section);
    }

    public function delete(OODBBean $section): void
    {
        R::trash($section);
    }
}
