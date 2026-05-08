<?php

declare(strict_types=1);

namespace App\Models;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;

/**
 * FaqModel — managed by RedBeanPHP.
 * Each FAQ row has a free-text `category` for grouping on the public page.
 */
class FaqModel
{
    public function findAll(): array
    {
        return R::findAll('faq', 'ORDER BY sort_order ASC, id ASC');
    }

    public function load(int $id): ?OODBBean
    {
        $bean = R::load('faq', $id);
        return $bean->id ? $bean : null;
    }

    public function create(array $data): OODBBean
    {
        $faq             = R::dispense('faq');
        $faq->category   = $data['category']  ?? 'General';
        $faq->question   = $data['question'];
        $faq->answer     = $data['answer'];
        $faq->sortOrder  = (int) ($data['sortOrder'] ?? 0);
        R::store($faq);
        return $faq;
    }

    public function save(OODBBean $faq): void
    {
        R::store($faq);
    }

    public function delete(OODBBean $faq): void
    {
        R::trash($faq);
    }

    /**
     * Returns FAQ entries grouped by their (free-text) category.
     * Result: [ 'Category A' => [ ['question'=>..., 'answer'=>...], ...], ... ]
     */
    public function findGroupedByCategory(): array
    {
        $grouped = [];
        foreach ($this->findAll() as $faq) {
            $grouped[$faq->category][] = [
                'question' => $faq->question,
                'answer'   => $faq->answer,
            ];
        }
        return $grouped;
    }
}
