<?php
require __DIR__ . '/../vendor/autoload.php';
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

use App\Config;
use App\Models\AppointmentModel;
use App\Models\PaymentModel;
use App\Models\ReviewModel;
use App\Models\ServiceModel;
use App\Models\UserModel;
use RedBeanPHP\R;

$db = Config::get('database', []);
R::setup(
    "mysql:host={$db['host']};port={$db['port']};dbname={$db['dbname']};charset={$db['charset']}",
    $db['user'], $db['pass']
);
R::freeze(false);

$ok = function ($label, $fn) {
    try { $fn(); echo "✓ $label\n"; }
    catch (\Throwable $e) { echo "✗ $label — " . $e->getMessage() . "\n"; }
};

$ok('admin: edit appointment status', function () {
    $a = (new AppointmentModel())->load(1);
    $a->status = 'completed';
    R::store($a);
    $a = (new AppointmentModel())->load(1);
    if ($a->status !== 'completed') throw new \Exception('not persisted');
    $a->status = 'confirmed';
    R::store($a);
});

$ok('admin: edit appointment slot (date/time/service/notes)', function () {
    $a = (new AppointmentModel())->load(2);
    $a->serviceID = 3;
    $a->date  = '2030-09-01';
    $a->time  = '11:00:00';
    $a->notes = 'Updated by admin';
    R::store($a);
    $a = (new AppointmentModel())->load(2);
    if ($a->serviceID !== '3' && $a->serviceID !== 3) throw new \Exception('serviceID not saved: ' . $a->serviceID);
});

$ok('admin: cancel appointment', function () {
    $a = (new AppointmentModel())->load(3);
    (new AppointmentModel())->cancel($a);
    $a = (new AppointmentModel())->load(3);
    if ($a->status !== 'cancelled') throw new \Exception('not cancelled');
});

$ok('admin: edit service (price+name)', function () {
    $s = (new ServiceModel())->load(1);
    $s->name  = 'Gel-X PREMIUM';
    $s->price = 99.99;
    R::store($s);
    $s = (new ServiceModel())->load(1);
    if ($s->name !== 'Gel-X PREMIUM') throw new \Exception('name not saved');
});

$ok('admin: delete service (no FK conflict)', function () {
    $s = (new ServiceModel())->create([
        'name' => 'TempDel', 'category' => 'Test', 'description' => 'temp',
        'price' => 1, 'duration' => 5,
    ]);
    (new ServiceModel())->delete($s);
});

$ok('admin: reply to review', function () {
    $r = (new ReviewModel())->load(1);
    (new ReviewModel())->reply($r, 'Thanks for the kind words!');
    $r = (new ReviewModel())->load(1);
    if ($r->reply !== 'Thanks for the kind words!') throw new \Exception('reply not saved');
});

$ok('admin: clear review reply', function () {
    $r = (new ReviewModel())->load(1);
    (new ReviewModel())->reply($r, '');
    $r = (new ReviewModel())->load(1);
    if ($r->reply !== null) throw new \Exception('reply not cleared');
});

$ok('client: register + login', function () {
    $email = 'edit_test_' . time() . '@example.com';
    $u = (new UserModel())->create([
        'firstName'   => 'Edit', 'lastName' => 'Tester', 'email' => $email,
        'password'    => 'password123', 'phoneNumber' => '5145550000', 'role' => 'client',
    ]);
    if (!$u || !$u->id) throw new \Exception('register failed');
    $login = (new UserModel())->login($email, 'password123');
    if (!$login) throw new \Exception('login failed');
    R::trash($u);
});

$ok('payment: create from booking', function () {
    $p = (new PaymentModel())->create([
        'appointmentID'    => 1,
        'paymentFrom'      => 2,
        'paymentFromName'  => 'Bob Customer',
        'paymentFromEmail' => 'bob@example.com',
        'paymentFromPhone' => '514-555-0002',
        'paymentType'      => 'credit_card',
        'paymentAmount'    => 20,
        'paymentStatus'    => 'paid',
    ]);
    if (!$p || !$p->id) throw new \Exception('payment create failed');
    R::trash($p);
});
