<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database\Database;

$pdo = Database::connect(Config::get('database', []));
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
        'INSERT INTO user (firstName, lastName, email, password, phoneNumber, role)
         VALUES (:firstName, :lastName, :email, :password, :phoneNumber, :role)'
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
            ':firstName'   => $firstName,
            ':lastName'    => $lastName,
            ':email'       => $email,
            ':password'    => password_hash($plain, PASSWORD_BCRYPT),
            ':phoneNumber' => $phone,
            ':role'        => $role,
        ]);
    }

    // 3. SERVICES
    echo "→ Seeding services...\n";
    $serviceStmt = $pdo->prepare(
        'INSERT INTO services (name, category, description, price, duration)
         VALUES (:name, :category, :description, :price, :duration)'
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
            ':category'    => $cat,
            ':description' => $desc,
            ':price'       => $price,
            ':duration'    => $duration,
        ]);
    }

    // 4. APPOINTMENTS
    echo "→ Seeding appointments...\n";
    $apptStmt = $pdo->prepare(
        'INSERT INTO appointment (serviceID, userID, date, time, notes, status)
         VALUES (:serviceID, :userID, :date, :time, :notes, :status)'
    );

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
        $apptStmt->execute([
            ':serviceID' => $serviceID,
            ':userID'    => $userID,
            ':date'      => $date,
            ':time'      => $time,
            ':notes'     => $notes,
            ':status'    => $status,
        ]);
    }

    // 5. REVIEWS  (only completed appointments — uq_review_per_appointment enforces 1:1)
    echo "→ Seeding reviews...\n";
    $reviewStmt = $pdo->prepare(
        'INSERT INTO reviews (userID, appointmentID, rating, comment, reviewDate)
         VALUES (:userID, :appointmentID, :rating, :comment, :reviewDate)'
    );

    $reviews = [
        // [userID, appointmentID, rating, comment, reviewDate]
        [4, 4, 5, 'My nails have never looked better — paraffin felt amazing.', '2026-05-08'],
        [5, 5, 4, 'Acrylics are flawless. A bit of a wait, but worth it.',     '2026-05-09'],
        [4, 7, 5, 'Quick polish change, super friendly staff.',                 '2026-04-28'],
    ];

    foreach ($reviews as [$userID, $appointmentID, $rating, $comment, $date]) {
        $reviewStmt->execute([
            ':userID'        => $userID,
            ':appointmentID' => $appointmentID,
            ':rating'        => $rating,
            ':comment'       => $comment,
            ':reviewDate'    => $date,
        ]);
    }

    // 6. PAYMENTS
    echo "→ Seeding payments...\n";
    $payStmt = $pdo->prepare(
        'INSERT INTO payments
            (appointmentID, paymentFrom, paymentFromName, paymentFromEmail, paymentFromPhone,
             paymentType, paymentAmount, paymentStatus)
         VALUES
            (:appointmentID, :paymentFrom, :paymentFromName, :paymentFromEmail, :paymentFromPhone,
             :paymentType, :paymentAmount, :paymentStatus)'
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
            ':appointmentID'    => $appointmentID,
            ':paymentFrom'      => $from,
            ':paymentFromName'  => $name,
            ':paymentFromEmail' => $email,
            ':paymentFromPhone' => $phone,
            ':paymentType'      => $type,
            ':paymentAmount'    => $amount,
            ':paymentStatus'    => $status,
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
