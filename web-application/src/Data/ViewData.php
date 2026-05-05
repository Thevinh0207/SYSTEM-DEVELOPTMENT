<?php

declare(strict_types=1);

namespace App\Data;

final class ViewData
{
    public static function images(): array
    {
        return [
            'hero' => 'https://images.unsplash.com/photo-1604654894610-df63bc536371?auto=format&fit=crop&w=1800&q=80',
            'brush' => 'https://images.unsplash.com/photo-1610992015732-2449b76344bc?auto=format&fit=crop&w=900&q=80',
            'hands' => 'https://images.unsplash.com/photo-1632345031435-8727f6897d53?auto=format&fit=crop&w=900&q=80',
            'acrylic' => 'https://images.unsplash.com/photo-1607779097040-26e80aa78e66?auto=format&fit=crop&w=900&q=80',
            'faq' => 'https://images.unsplash.com/photo-1522337660859-02fbefca4702?auto=format&fit=crop&w=1200&q=80',
            'shelf' => 'https://images.unsplash.com/photo-1522337660859-02fbefca4702?auto=format&fit=crop&w=1200&q=80',
            'gallery1' => 'https://images.unsplash.com/photo-1519014816548-bf5fe059798b?auto=format&fit=crop&w=500&q=80',
            'gallery2' => 'https://images.unsplash.com/photo-1600948836101-f9ffda59d250?auto=format&fit=crop&w=500&q=80',
            'gallery3' => 'https://images.unsplash.com/photo-1610992015732-2449b76344bc?auto=format&fit=crop&w=500&q=80',
            'gallery4' => 'https://images.unsplash.com/photo-1604654894610-df63bc536371?auto=format&fit=crop&w=500&q=80',
            'gallery5' => 'https://images.unsplash.com/photo-1607779097040-26e80aa78e66?auto=format&fit=crop&w=500&q=80',
            'gallery6' => 'https://images.unsplash.com/photo-1522337660859-02fbefca4702?auto=format&fit=crop&w=500&q=80',
        ];
    }

    public static function featuredServices(): array
    {
        return [
            ['name' => 'Gel-X Extensions', 'price' => '$60+', 'image' => 'brush', 'url' => '/services/gel-x-extensions'],
            ['name' => 'French Manicure', 'price' => '$40+', 'image' => 'hands', 'url' => '/services'],
            ['name' => 'Acrylic Set', 'price' => '$60+', 'image' => 'acrylic', 'url' => '/services'],
        ];
    }

    public static function nailCareServices(): array
    {
        return [
            ['name' => 'Manicure', 'duration' => '45 min', 'price' => '$40'],
            ['name' => 'Colour Removal', 'duration' => '15 min', 'price' => '$10'],
            ['name' => 'Extension Removal', 'duration' => '30 min', 'price' => '$20'],
        ];
    }

    public static function extensionGroups(): array
    {
        return [
            ['name' => 'Gel-X', 'items' => [['Extensions', '$60+'], ['Infill', '$50+'], ['Removal', '$20']]],
            ['name' => 'Hard Gel', 'items' => [['Extensions', '$70+'], ['Infill', '$60+'], ['Removal', '$20']]],
            ['name' => 'Acrylic', 'items' => [['Extensions', '$60+'], ['Infill', '$50+'], ['Removal', '$20']]],
        ];
    }

    public static function nailArtServices(): array
    {
        return [
            ['name' => 'French / Ombre / Chrome', 'duration' => '10 min', 'price' => '$15'],
            ['name' => 'Marble / Cat Eye', 'duration' => '15 min', 'price' => '$5-20'],
            ['name' => 'Strass (per nail)', 'duration' => '10 min', 'price' => '$5-20'],
            ['name' => 'Complex Art - Level 1', 'duration' => '30 min', 'price' => '$25'],
            ['name' => 'Complex Art - Level 2', 'duration' => '45 min', 'price' => '$45'],
            ['name' => 'Complex Art - Level 3', 'duration' => '60+ min', 'price' => '$65+'],
        ];
    }

    public static function bookingServices(): array
    {
        return [
            ['id' => 1, 'name' => 'Gel-X Extensions', 'duration' => '60 min', 'price' => '$60+', 'priceNum' => 60.00, 'image' => 'brush'],
            ['id' => 2, 'name' => 'Manicure', 'duration' => '45 min', 'price' => '$40', 'priceNum' => 40.00, 'image' => 'hands'],
            ['id' => 3, 'name' => 'Acrylic Set', 'duration' => '75 min', 'price' => '$60+', 'priceNum' => 60.00, 'image' => 'acrylic'],
            ['id' => 4, 'name' => 'Hard Gel Extensions', 'duration' => '75 min', 'price' => '$70+', 'priceNum' => 70.00, 'image' => 'brush'],
        ];
    }

    public static function adminServices(): array
    {
        return [
            ['Gel-X Extensions', 'Extensions', '$60+', '60 min'],
            ['Manicure', 'Nail Care', '$40', '45 min'],
            ['Hard Gel Extensions', 'Extensions', '$70+', '75 min'],
            ['Acrylic Set', 'Extensions', '$60+', '75 min'],
            ['French Nail Art', 'Nail Art', '$15', '10 min'],
            ['Ombre Nail Art', 'Nail Art', '$15', '10 min'],
            ['Chrome Nail Art', 'Nail Art', '$15', '10 min'],
            ['Marble Nail Art', 'Nail Art', '$5-20', '15 min'],
            ['Cat Eye Nail Art', 'Nail Art', '$5-20', '15 min'],
            ['Colour Removal', 'Nail Care', '$10', '15 min'],
            ['Extension Removal', 'Nail Care', '$20', '30 min'],
        ];
    }

    public static function reviews(): array
    {
        return [];
    }

    public static function bookingTimes(): array
    {
        return ['9:00 AM', '10:00 AM', '11:00 AM', '12:00 PM', '1:00 PM', '2:00 PM', '3:00 PM', '4:00 PM', '5:00 PM', '6:00 PM'];
    }
}
