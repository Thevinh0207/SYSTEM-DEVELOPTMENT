<?php

declare(strict_types=1);

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
        ['Classic Manicure',    'Manicure', 'Nail shaping, cuticle care, and regular polish.',     25.00,  30],
        ['Gel Manicure',        'Manicure', 'Long-lasting gel polish with UV cure.',               40.00,  45],
        ['Acrylic Full Set',    'Manicure', 'Acrylic extensions with shape and polish.',           55.00,  75],
        ['Classic Pedicure',    'Pedicure', 'Foot soak, exfoliation, nail care, polish.',          35.00,  45],
        ['Spa Pedicure',        'Pedicure', 'Deluxe pedicure with paraffin wax and massage.',      55.00,  60],
        ['Nail Art (per nail)', 'Nail Art', 'Custom hand-painted nail art designs.',                5.00,  10],
        ['Polish Change',       'Manicure', 'Quick polish removal and reapplication.',             15.00,  20],
        ['Dip Powder',          'Manicure', 'Durable dip powder application, no UV needed.',       45.00,  50],
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
        [1, 2, '2026-05-10', '10:00:00', 'First time client — pink polish.',  'confirmed'],
        [4, 2, '2026-05-15', '14:30:00', 'Prefers neutral tones.',            'confirmed'],
        [2, 3, '2026-05-12', '09:00:00', 'Almond shape, dark red gel.',       'pending'],
        [5, 4, '2026-05-08', '11:00:00', 'Add paraffin treatment.',           'completed'],
        [3, 5, '2026-05-09', '16:00:00', 'Coffin shape, medium length.',      'completed'],
        [6, 3, '2026-05-20', '13:00:00', 'Floral art on accent nails.',       'pending'],
        [7, 4, '2026-04-28', '10:30:00', 'Quick polish change.',              'completed'],
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
