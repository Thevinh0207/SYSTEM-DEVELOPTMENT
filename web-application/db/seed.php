<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';

use App\Config;
use RedBeanPHP\R;

$db = Config::get('database', []);
R::setup(
    "mysql:host={$db['host']};port={$db['port']};dbname={$db['dbname']};charset={$db['charset']}",
    $db['user'],
    $db['pass']
);
R::freeze(false);
\RedBeanPHP\Util\DispenseHelper::setEnforceNamingPolicy(false);
$pdo = R::getDatabaseAdapter()->getDatabase()->getPDO();
$adminEmails = array_map('strtolower', Config::get('admin_emails', []));

// 1. Schema (re-create from scratch)
echo "→ Loading schema...\n";
$schema = file_get_contents(__DIR__ . '/schema.sql');
$pdo->exec($schema);

$pdo->beginTransaction();

try {
    // 2. USERS
    echo "→ Seeding users...\n";
    $userStmt = $pdo->prepare(
        'INSERT INTO user (first_name, last_name, email, password, phone_number, role)
         VALUES (:first_name, :last_name, :email, :password, :phone_number, :role)'
    );

    $users = [
        ['Alice',   'Admin',     'admin@example.com',   'admin123',   '514-555-0001', 'admin'],
        ['Bob',     'Customer',  'bob@example.com',     'password1',  '514-555-0002', 'client'],
        ['Charlie', 'Client',    'charlie@example.com', 'password2',  '514-555-0003', 'client'],
        ['Diana',   'Patron',    'diana@example.com',   'password3',  '514-555-0004', 'client'],
        ['Eric',    'User',      'eric@example.com',    'password4',  '514-555-0005', 'client'],
    ];

    foreach ($users as [$firstName, $lastName, $email, $plain, $phone, $role]) {
        // Auto-promote any seeded user whose email is in admin_emails.
        if (in_array(strtolower($email), $adminEmails, true)) {
            $role = 'admin';
        }
        $userStmt->execute([
            ':first_name'   => $firstName,
            ':last_name'    => $lastName,
            ':email'        => $email,
            ':password'     => password_hash($plain, PASSWORD_BCRYPT),
            ':phone_number' => $phone,
            ':role'         => $role,
        ]);
    }

    // 3. SERVICE CATEGORIES + SERVICES
    echo "→ Seeding service categories...\n";
    $svcCatStmt = $pdo->prepare(
        'INSERT INTO service_category (name, sort_order) VALUES (:name, :sort_order)'
    );
    $serviceCategories = [
        ['Extensions', 10],
        ['Nail Care',  20],
        ['Nail Art',   30],
    ];
    foreach ($serviceCategories as [$n, $s]) {
        $svcCatStmt->execute([':name' => $n, ':sort_order' => $s]);
    }
    // Map category names → ids for the inserts below.
    $svcCatId = [];
    foreach ($pdo->query('SELECT id, name FROM service_category') as $row) {
        $svcCatId[$row['name']] = (int) $row['id'];
    }

    echo "→ Seeding services...\n";
    $serviceStmt = $pdo->prepare(
        'INSERT INTO services (name, category_id, description, price, duration)
         VALUES (:name, :category_id, :description, :price, :duration)'
    );

    $services = [
        ['Gel-X Extensions',      'Extensions', 'Lightweight full-cover gel extensions with a natural look.', 60.00, 60],
        ['Manicure',              'Nail Care',  'Nail shaping, cuticle care, and polish application.',        40.00, 45],
        ['Colour Removal',        'Nail Care',  'Safe removal of existing polish before a new service.',      10.00, 15],
        ['Extension Removal',     'Nail Care',  'Gentle removal of a previous extension set.',                20.00, 30],
        ['Hard Gel Extensions',   'Extensions', 'Durable hard gel extensions with structure and shine.',      70.00, 75],
        ['Acrylic Set',           'Extensions', 'Classic acrylic extension set shaped and polished.',         60.00, 75],
        ['French Nail Art',       'Nail Art',   'French finish add-on.',                                      15.00, 10],
        ['Ombre Nail Art',        'Nail Art',   'Soft ombre finish add-on.',                                  15.00, 10],
        ['Chrome Nail Art',       'Nail Art',   'Chrome finish add-on.',                                      15.00, 10],
        ['Marble Nail Art',       'Nail Art',   'Marble design add-on.',                                      20.00, 15],
        ['Cat Eye Nail Art',      'Nail Art',   'Magnetic cat eye design add-on.',                            20.00, 15],
        ['Complex Art - Level 1', 'Nail Art',   'Simple custom nail art package.',                            25.00, 30],
        ['Complex Art - Level 2', 'Nail Art',   'Detailed custom nail art package.',                          45.00, 45],
        ['Complex Art - Level 3', 'Nail Art',   'Advanced custom nail art package.',                          65.00, 60],
    ];

    foreach ($services as [$name, $cat, $desc, $price, $duration]) {
        $serviceStmt->execute([
            ':name'        => $name,
            ':category_id' => $svcCatId[$cat],
            ':description' => $desc,
            ':price'       => $price,
            ':duration'    => $duration,
        ]);
    }

    // 4. APPOINTMENTS — snapshot the contact info on every row.
    echo "→ Seeding appointments...\n";
    $apptStmt = $pdo->prepare(
        'INSERT INTO appointment
            (service_id, user_id, guest_name, guest_email, guest_phone, date, time, notes, status)
         VALUES
            (:service_id, :user_id, :guest_name, :guest_email, :guest_phone, :date, :time, :notes, :status)'
    );

    // Map seeded users by id so we can snapshot their name/email/phone.
    $userById = [];
    foreach ($pdo->query('SELECT id, first_name, last_name, email, phone_number FROM user') as $u) {
        $userById[(int) $u['id']] = $u;
    }

    $appointments = [
        // [serviceID, userID, date, time, notes, status]
        [1, 2, '2026-05-10', '10:00:00', 'Gel-X with French finish.',          'confirmed'],
        [2, 2, '2026-05-15', '14:30:00', 'Prefers neutral tones.',             'confirmed'],
        [5, 3, '2026-05-12', '09:00:00', 'Almond shape, dark red gel.',        'pending'],
        [6, 4, '2026-05-08', '11:00:00', 'Medium length acrylic set.',         'completed'],
        [1, 5, '2026-05-09', '16:00:00', 'Glossy finish.',                     'completed'],
        [12, 3, '2026-05-20', '13:00:00', 'Floral art on accent nails.',       'pending'],
        [3, 4, '2026-04-28', '10:30:00', 'Colour removal before new set.',     'completed'],
    ];

    foreach ($appointments as [$serviceID, $userID, $date, $time, $notes, $status]) {
        $u = $userById[$userID] ?? null;
        $apptStmt->execute([
            ':service_id'  => $serviceID,
            ':user_id'     => $userID,
            ':guest_name'  => $u ? trim($u['first_name'] . ' ' . $u['last_name']) : 'Guest',
            ':guest_email' => $u ? $u['email']         : null,
            ':guest_phone' => $u ? $u['phone_number']  : null,
            ':date'        => $date,
            ':time'        => $time,
            ':notes'       => $notes,
            ':status'      => $status,
        ]);
    }

    // 5. REVIEWS  (only completed appointments — uq_review_per_appointment enforces 1:1)
    echo "→ Seeding reviews...\n";
    $reviewStmt = $pdo->prepare(
        'INSERT INTO reviews (user_id, appointment_id, rating, comment, review_date)
         VALUES (:user_id, :appointment_id, :rating, :comment, :review_date)'
    );

    $reviews = [
        // [userID, appointmentID, rating, comment, reviewDate]
        [4, 4, 5, 'My nails have never looked better — paraffin felt amazing.', '2026-05-08'],
        [5, 5, 4, 'Acrylics are flawless. A bit of a wait, but worth it.',     '2026-05-09'],
        [4, 7, 5, 'Quick polish change, super friendly staff.',                 '2026-04-28'],
    ];

    foreach ($reviews as [$userID, $appointmentID, $rating, $comment, $date]) {
        $reviewStmt->execute([
            ':user_id'        => $userID,
            ':appointment_id' => $appointmentID,
            ':rating'         => $rating,
            ':comment'        => $comment,
            ':review_date'    => $date,
        ]);
    }

    // 6. PAYMENTS
    echo "→ Seeding payments...\n";
    $payStmt = $pdo->prepare(
        'INSERT INTO payments
            (appointment_id, payment_from, payment_from_name, payment_from_email, payment_from_phone,
             payment_type, payment_amount, payment_status)
         VALUES
            (:appointment_id, :payment_from, :payment_from_name, :payment_from_email, :payment_from_phone,
             :payment_type, :payment_amount, :payment_status)'
    );

    $payments = [
        // [apptID, paymentFrom (NULL = guest), name, email, phone, type, amount, status]
        [1, 2,    'Bob Customer',   'bob@example.com',     '514-555-0002', 'credit_card', 25.00, 'paid'],
        [2, 2,    'Bob Customer',   'bob@example.com',     '514-555-0002', 'credit_card', 35.00, 'paid'],
        [3, 3,    'Charlie Client', 'charlie@example.com', '514-555-0003', 'online',      40.00, 'pending'],
        [4, 4,    'Diana Patron',   'diana@example.com',   '514-555-0004', 'cash',        55.00, 'paid'],
        [5, 5,    'Eric User',      'eric@example.com',    '514-555-0005', 'credit_card', 55.00, 'paid'],
        [6, 3,    'Charlie Client', 'charlie@example.com', '514-555-0003', 'credit_card',  5.00, 'pending'],
        [7, null, 'Jane Walk-In',   'jane.walkin@mail.com','514-555-9999', 'cash',        15.00, 'paid'],
    ];

    foreach ($payments as [$appointmentID, $from, $name, $email, $phone, $type, $amount, $status]) {
        $payStmt->execute([
            ':appointment_id'     => $appointmentID,
            ':payment_from'       => $from,
            ':payment_from_name'  => $name,
            ':payment_from_email' => $email,
            ':payment_from_phone' => $phone,
            ':payment_type'       => $type,
            ':payment_amount'     => $amount,
            ':payment_status'     => $status,
        ]);
    }

    // 7. FAQ
    echo "→ Seeding FAQ...\n";
    $faqStmt = $pdo->prepare(
        'INSERT INTO faq (category, question, answer, sort_order)
         VALUES (:category, :question, :answer, :sort_order)'
    );
    $faqs = [
        ['Deposit & Cancellation', 'Deposit Required',     'A non-refundable $20 deposit is required to secure your appointment. This deposit will be applied to your service total.', 10],
        ['Deposit & Cancellation', 'Cancellation Policy',  'We require at least 48 hours notice for cancellations or rescheduling. Cancellations made within 48 hours will result in forfeiture of your deposit.',                          20],
        ['Deposit & Cancellation', 'No-Show Policy',       'If you miss your appointment without notice, your deposit will be lost and a new deposit will be required for future bookings.',                                              30],
        ['Deposit & Cancellation', 'Late Arrivals',        'We have a 15-minute grace period. If you arrive more than 15 minutes late, your appointment may need to be rescheduled.',                                                       40],
        ['Services',               'Do I need to remove my old set?', 'Yes, proper removal is required before getting a new set. We offer removal services for $20 if done with a new set, or as a standalone service.',                    50],
        ['Services',               'Can I bring my own polish?',      'We use professional-grade gel polish for all services. For hygiene reasons, we cannot use customer-provided products.',                                              60],
    ];
    foreach ($faqs as [$cat, $q, $a, $sort]) {
        $faqStmt->execute([
            ':category'   => $cat,
            ':question'   => $q,
            ':answer'     => $a,
            ':sort_order' => $sort,
        ]);
    }

    // 8. ABOUT SECTIONS
    echo "→ Seeding About sections...\n";
    $aboutStmt = $pdo->prepare(
        'INSERT INTO about_section (heading, body, sort_order)
         VALUES (:heading, :body, :sort_order)'
    );
    $about = [
        ['How it started',
         "I painted my first French tip when I was thirteen, sitting at the kitchen table with a cheap polish kit my mom picked up at the pharmacy. The next morning my best friend asked who did my nails — and that was it. I spent the rest of high school running a tiny salon out of my bedroom, charging my classmates ten bucks a manicure and watching every nail-art tutorial I could find online.",
         10],
        ['Getting certified',
         "Right out of high school I enrolled in a DEP in Esthétique — a year and a half of practical training, sanitation theory, anatomy, and more manicure drills than I can count. Earning that certificate was the moment it stopped feeling like a hobby and started feeling like a career.",
         20],
        ['The leap',
         "After my DEP I gave myself a deadline: one year of working at a chain salon to learn the business side, then strike out on my own. Studio Doki opened in spring 2025 above a coffee shop in Plateau, with a single chair, a second-hand UV lamp, and a wall I painted blush pink at 2 a.m. the night before my first client.",
         30],
        ['What I love',
         "Chrome finishes that look like liquid metal. Almond shapes. The exact moment a client sees the final result and tilts her hand toward the window. Skincare hauls, lip oil collections, building playlists for each appointment, and the slow magic of an hour where someone gets to sit still and be cared for.",
         40],
        ['The studio',
         "Studio Doki is small on purpose — one client at a time, no rushing, no overlapping appointments. I source gels and acrylics from brands that actually disclose their ingredients, sterilize tools after every visit, and keep the playlist on shuffle. Bring a coffee. Stay a while.",
         50],
    ];
    foreach ($about as [$h, $b, $sort]) {
        $aboutStmt->execute([
            ':heading'    => $h,
            ':body'       => $b,
            ':sort_order' => $sort,
        ]);
    }

    $pdo->commit();
    echo "\n✓ Seed complete.\n";
    echo "  Admin login:  admin@example.com / admin123\n";
    echo "  Client login: bob@example.com   / password1\n";

} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "✗ Seed failed: " . $e->getMessage() . "\n");
    exit(1);
}
