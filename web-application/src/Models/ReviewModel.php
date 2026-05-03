<?php

declare(strict_types=1);

namespace App\Models;

use RedBeanPHP\R;
use App\Helpers\BeanHelper;

class ReviewModel
{
    public function getAll(): array
    {
        return BeanHelper::castBeanArray(R::findAll('review', 'ORDER BY id ASC'));
    }

    public function getById(int $id): ?\RedBeanPHP\OODBBean
    {
        $bean = R::load('review', $id);
        return BeanHelper::isValidBean($bean) ? BeanHelper::castBeanProperties($bean) : null;
    }

    public function create(array $data): \RedBeanPHP\OODBBean
    {
        $review = R::dispense('review');
        $review->customerId = $data['customerId'] ?? '';
        $review->serviceId = $data['serviceId'] ?? '';
        $review->rating = (int) ($data['rating'] ?? 0);
        $review->comment = $data['comment'] ?? '';
        $review->reviewDate = $data['reviewDate'] ?? '';
        R::store($review);
        return BeanHelper::castBeanProperties($review);
    }

    public function update(int $id, array $data): ?\RedBeanPHP\OODBBean
    {
        $review = R::load('review', $id);
        if (!BeanHelper::isValidBean($review)) {
            return null;
        }

        $review->customerId = $data['customerId'] ?? $review->customerId;
        $review->serviceId = $data['serviceId'] ?? $review->serviceId;
        $review->comment = $data['comment'] ?? $review->comment;
        $review->reviewDate = $data['reviewDate'] ?? $review->reviewDate;
        $review->rating = (int) ($data['rating'] ?? $review->rating);
        R::store($review);

        return BeanHelper::castBeanProperties($review);
    }


    public function delete(int $id): bool
    {
        $review = R::load('review', $id);
        if (!BeanHelper::isValidBean($review)) {
            return false;
        }
        
        R::trash($review);
        return true;
    }

    public funtion getAllReviewsByCustomerId(int $customerId): array
    {
        return BeanHelper::castBeanArray(R::findAll('review', 'customer_id = ? ORDER BY review_date DESC', [$customerId]));
    }

    public function editReview(int $id, array $data): ?\RedBeanPHP\OODBBean
    {
        $review = R::load('review', $id);
        if (!BeanHelper::isValidBean($review)) {
            return null;
        }

        $review->comment = $data['comment'] ?? $review->comment;
        $review->rating = (int) ($data['rating'] ?? $review->rating);
        R::store($review);

        return BeanHelper::castBeanProperties($review);
    }

    public function deleteReview(int $id): bool
    {
        $review = R::load('review', $id);
        if (!BeanHelper::isValidBean($review)) {
            return false;
        }
        
        R::trash($review);
        return true;
    }

}
