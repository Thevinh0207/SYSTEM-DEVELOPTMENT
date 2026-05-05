<?php

declare(strict_types=1);

use App\Data\ViewData;
use App\Models\AppointmentModel;
use App\Models\PaymentModel;
use App\Models\ReviewModel;
use App\Models\ServiceModel;
use App\Models\UserModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Slim\Psr7\Response as Psr7Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

session_start();

require dirname(__DIR__) . '/vendor/autoload.php';

$scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
$basePath = ($scriptDir === '/' || $scriptDir === '.') ? '' : rtrim($scriptDir, '/');

$app = AppFactory::create();
if ($basePath !== '') {
    $app->setBasePath($basePath);
}
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/templates'), [
    'cache' => false,
    'auto_reload' => true,
]);

$twig->addFunction(new TwigFunction('path', static function (string $path = '/') use ($basePath): string {
    return $basePath . ($path === '/' ? '/' : '/' . ltrim($path, '/'));
}));
$twig->addFunction(new TwigFunction('asset', static fn(string $path): string => $basePath . '/assets/' . ltrim($path, '/')));

$render = static function (Response $response, string $template, array $data = []) use ($twig): Response {
    $defaults = [
        'images'      => ViewData::images(),
        'currentUser' => $_SESSION['user'] ?? null,
    ];
    $response->getBody()->write($twig->render($template, $data + $defaults));
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
};

$redirect = static function (string $to) use ($basePath): Response {
    $url = $basePath . ($to === '/' ? '/' : '/' . ltrim($to, '/'));
    return (new Psr7Response())->withHeader('Location', $url)->withStatus(302);
};

// ─── ERROR HANDLERS ─────────────────────────────────────────────────────
$renderError = static function (int $code, string $title, string $message) use ($twig, $basePath): Response {
    $response = new Psr7Response();
    $html = $twig->render('Errors/generic.twig', [
        'code' => $code, 'title' => $title, 'message' => $message,
    ]);
    $response->getBody()->write($html);
    return $response->withStatus($code)->withHeader('Content-Type', 'text/html; charset=utf-8');
};

$errorMiddleware->setErrorHandler(
    HttpNotFoundException::class,
    static function (Request $request, Throwable $e) use ($twig): Response {
        $response = new Psr7Response();
        $html = $twig->render('Errors/404.twig', [
            'path'   => $request->getUri()->getPath(),
            'locale' => 'en',
        ]);
        $response->getBody()->write($html);
        return $response->withStatus(404)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
);

$errorMiddleware->setErrorHandler(
    HttpMethodNotAllowedException::class,
    static function (Request $request, Throwable $e) use ($renderError): Response {
        return $renderError(405, 'Method not allowed', 'That action is not available on this page. Please go back and try again.');
    }
);

// Generic catch-all (500 etc.) — only kicks in for unhandled exceptions.
$errorMiddleware->setDefaultErrorHandler(
    static function (Request $request, Throwable $e, bool $displayErrorDetails) use ($renderError): Response {
        $message = $displayErrorDetails ? $e->getMessage() : 'Something went wrong on our end. Please try again later.';
        return $renderError(500, 'Server error', $message);
    }
);

// ─── HOME / STATIC ──────────────────────────────────────────────────────
$app->get('/', fn(Request $request, Response $response): Response => $render($response, 'home.twig', [
    'active' => 'home',
    'featured' => ViewData::featuredServices(),
]));

$app->get('/services', fn(Request $request, Response $response): Response => $render($response, 'services.twig', [
    'active' => 'services',
    'serviceRows' => ViewData::nailCareServices(),
    'extensions' => ViewData::extensionGroups(),
    'nailArt' => ViewData::nailArtServices(),
]));

$app->get('/services/gel-x-extensions', fn(Request $request, Response $response): Response => $render($response, 'service-detail.twig', [
    'active' => 'services',
]));

$app->get('/faq', fn(Request $request, Response $response): Response => $render($response, 'faq.twig', [
    'active' => 'faq',
]));

// ─── BOOKING FLOW ───────────────────────────────────────────────────────
// State lives in $_SESSION['booking'] — populated step-by-step, cleared on confirm.

// Step 1: pick service (POST stores choice, redirects to step 2)
$app->get('/book', function (Request $request, Response $response) use ($render): Response {
    $errors = $_SESSION['booking_errors'] ?? [];
    unset($_SESSION['booking_errors']);

    try {
        $services = (new ServiceModel())->getAllServices();
    } catch (Throwable $e) {
        $services = [];
    }

    // Map DB rows into the shape the service-pick template expects.
    $bookingServices = array_map(static fn(array $s): array => [
        'id'       => (int) $s['ServiceID'],
        'name'     => $s['name'],
        'duration' => $s['duration'] . ' min',
        'price'    => '$' . number_format((float) $s['price'], 2),
        'priceNum' => (float) $s['price'],
        'image'    => 'brush',  // template fallback
    ], $services);
    if ($bookingServices === []) {
        $bookingServices = ViewData::bookingServices();
    }

    return $render($response, 'booking/service.twig', [
        'active'          => 'book',
        'bookingServices' => $bookingServices,
        'errors'          => $errors,
    ]);
});

$app->post('/book', function (Request $request, Response $response) use ($redirect): Response {
    $body      = (array) $request->getParsedBody();
    $serviceId = (int) ($body['serviceId'] ?? 0);

    if ($serviceId <= 0) {
        $_SESSION['booking_errors'] = ['service' => 'Please select a service.'];
        return $redirect('/book');
    }

    try {
        $service = (new ServiceModel())->getById($serviceId);
    } catch (Throwable $e) {
        $service = null;
    }

    if (!$service) {
        $demoService = null;
        foreach (ViewData::bookingServices() as $candidate) {
            if ((int) $candidate['id'] === $serviceId) {
                $demoService = $candidate;
                break;
            }
        }
        if (!$demoService) {
            $_SESSION['booking_errors'] = ['service' => 'That service is no longer available.'];
            return $redirect('/book');
        }

        $_SESSION['booking'] = [
            'serviceId' => (int) $demoService['id'],
            'service'   => $demoService['name'],
            'duration'  => $demoService['duration'],
            'price'     => $demoService['price'],
            'priceNum'  => (float) $demoService['priceNum'],
        ];
        return $redirect('/book/date');
    }

    $_SESSION['booking'] = [
        'serviceId' => (int) $service['ServiceID'],
        'service'   => $service['name'],
        'duration'  => $service['duration'] . ' min',
        'price'     => '$' . number_format((float) $service['price'], 2),
        'priceNum'  => (float) $service['price'],
    ];
    return $redirect('/book/date');
});

// Step 2: pick date + time
$app->get('/book/date', function (Request $request, Response $response) use ($render, $redirect): Response {
    if (empty($_SESSION['booking']['service'])) {
        return $redirect('/book');
    }
    $errors = $_SESSION['booking_errors'] ?? [];
    unset($_SESSION['booking_errors']);
    return $render($response, 'booking/date.twig', [
        'active'  => 'book',
        'times'   => ViewData::bookingTimes(),
        'form'    => $_SESSION['booking_form']['date'] ?? [],
        'errors'  => $errors,
        'summary' => $_SESSION['booking'] ?? [],
    ]);
});

$app->post('/book/date', function (Request $request, Response $response) use ($redirect) {
    $body = (array) $request->getParsedBody();
    $date = trim((string) ($body['date'] ?? ''));
    $time = trim((string) ($body['time'] ?? ''));
    $errors = [];

    if ($date === '') {
        $errors['date'] = 'Please choose a date.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
        $errors['date'] = 'Please enter a valid date (YYYY-MM-DD).';
    } elseif (strtotime($date) < strtotime('today')) {
        $errors['date'] = 'Date cannot be in the past.';
    }

    if ($time === '') {
        $errors['time'] = 'Please choose a time.';
    }

    if (!$errors && !empty($_SESSION['booking']['serviceId'])) {
        try {
            $available = (new AppointmentModel())->isAvailable(
                (int) $_SESSION['booking']['serviceId'],
                $date,
                $time
            );
            if (!$available) {
                $errors['time'] = 'That time slot is no longer available. Please choose another time.';
            }
        } catch (Throwable $e) {
            // Keep the public booking demo usable before the local database is seeded.
            // The final save step still protects real bookings once the database is ready.
        }
    }

    if ($errors) {
        $_SESSION['booking_errors'] = $errors;
        $_SESSION['booking_form']['date'] = ['date' => $date, 'time' => $time];
        return $redirect('/book/date');
    }

    $_SESSION['booking']['date'] = $date;
    $_SESSION['booking']['time'] = $time;
    unset($_SESSION['booking_form']['date']);
    return $redirect('/book/account');
});

// Step 3: account choice (login / register / guest) — pure GET, links forward
$app->get('/book/account', function (Request $request, Response $response) use ($render, $redirect) {
    if (empty($_SESSION['booking']['date']) || empty($_SESSION['booking']['time'])) {
        return $redirect('/book/date');
    }
    return $render($response, 'booking/account.twig', [
        'active'  => 'book',
        'summary' => $_SESSION['booking'] ?? [],
    ]);
});

// Step 4: contact info
$app->get('/book/info', function (Request $request, Response $response) use ($render, $redirect): Response {
    if (empty($_SESSION['booking']['date'])) {
        return $redirect('/book/date');
    }
    $errors = $_SESSION['booking_errors'] ?? [];
    unset($_SESSION['booking_errors']);
    return $render($response, 'booking/info.twig', [
        'active'  => 'book',
        'summary' => $_SESSION['booking'] ?? [],
        'user'    => $_SESSION['user'] ?? null,
        'form'    => $_SESSION['booking_form']['info'] ?? [],
        'errors'  => $errors,
    ]);
});

$app->post('/book/info', function (Request $request, Response $response) use ($redirect) {
    $body = (array) $request->getParsedBody();
    $form = [
        'firstName'   => trim((string) ($body['firstName']   ?? '')),
        'lastName'    => trim((string) ($body['lastName']    ?? '')),
        'email'       => trim((string) ($body['email']       ?? '')),
        'phoneNumber' => trim((string) ($body['phoneNumber'] ?? '')),
    ];
    $errors = [];

    if ($form['firstName'] === '') $errors['firstName'] = 'First name is required.';
    if ($form['lastName']  === '') $errors['lastName']  = 'Last name is required.';
    if ($form['email']     === '') {
        $errors['email'] = 'Email is required.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }
    if ($form['phoneNumber'] === '') {
        $errors['phoneNumber'] = 'Phone number is required.';
    } elseif (!preg_match('/^[0-9\-\+\s\(\)]{7,20}$/', $form['phoneNumber'])) {
        $errors['phoneNumber'] = 'Phone number looks invalid.';
    }

    if ($errors) {
        $_SESSION['booking_errors']        = $errors;
        $_SESSION['booking_form']['info']  = $form;
        return $redirect('/book/info');
    }

    $_SESSION['booking']['contact'] = $form;
    unset($_SESSION['booking_form']['info']);
    return $redirect('/book/payment');
});

// Step 5: payment (collected but not processed — payment is out of scope)
$app->get('/book/payment', function (Request $request, Response $response) use ($render, $redirect): Response {
    if (empty($_SESSION['booking']['contact'])) {
        return $redirect('/book/info');
    }
    $errors = $_SESSION['booking_errors'] ?? [];
    unset($_SESSION['booking_errors']);
    return $render($response, 'booking/payment.twig', [
        'active'  => 'book',
        'summary' => $_SESSION['booking'] ?? [],
        'form'    => $_SESSION['booking_form']['payment'] ?? [],
        'errors'  => $errors,
    ]);
});

$app->post('/book/payment', function (Request $request, Response $response) use ($redirect) {
    $body = (array) $request->getParsedBody();
    $form = [
        'cardNumber' => trim((string) ($body['cardNumber'] ?? '')),
        'cardExpiry' => trim((string) ($body['cardExpiry'] ?? '')),
        'cardCvv'    => trim((string) ($body['cardCvv']    ?? '')),
        'cardName'   => trim((string) ($body['cardName']   ?? '')),
    ];
    $errors = [];

    $digits = preg_replace('/\D/', '', $form['cardNumber']);
    if ($digits === '' || strlen($digits) < 13 || strlen($digits) > 19) {
        $errors['cardNumber'] = 'Card number must be 13–19 digits.';
    }
    if (!preg_match('#^(0[1-9]|1[0-2])/\d{2}$#', $form['cardExpiry'])) {
        $errors['cardExpiry'] = 'Expiry must be MM/YY.';
    }
    if (!preg_match('/^\d{3,4}$/', $form['cardCvv'])) {
        $errors['cardCvv'] = 'CVV must be 3 or 4 digits.';
    }
    if ($form['cardName'] === '') {
        $errors['cardName'] = 'Cardholder name is required.';
    }

    if ($errors) {
        $_SESSION['booking_errors']         = $errors;
        $_SESSION['booking_form']['payment'] = ['cardName' => $form['cardName']];
        return $redirect('/book/payment');
    }

    // Persist the appointment (and a payment row to record the deposit).
    $booking = $_SESSION['booking'] ?? null;
    $contact = $booking['contact'] ?? null;

    if (!$booking || !$contact || empty($booking['serviceId'])) {
        $_SESSION['booking_errors'] = ['general' => 'Booking session expired. Please start again.'];
        return $redirect('/book');
    }

    $userId = $_SESSION['user']['id'] ?? null;

    try {
        $appointmentId = (new AppointmentModel())->createAppointment([
            'serviceID'  => (int) $booking['serviceId'],
            'userID'     => $userId,
            'guestName'  => $userId ? null : trim($contact['firstName'] . ' ' . $contact['lastName']),
            'guestEmail' => $userId ? null : $contact['email'],
            'guestPhone' => $userId ? null : $contact['phoneNumber'],
            'date'       => $booking['date'],
            'time'       => $booking['time'],
            'notes'      => null,
            'status'     => 'confirmed',
        ]);
    } catch (Throwable $e) {
        $_SESSION['booking_errors'] = ['general' => 'Could not save your appointment. Please try again.'];
        return $redirect('/book/payment');
    }

    if (!$appointmentId) {
        $_SESSION['booking_errors'] = ['general' => 'That time slot was just taken. Please pick another time.'];
        return $redirect('/book/date');
    }

    // Record the deposit (no real charge — payment processing is out of scope).
    try {
        (new PaymentModel())->create([
            'appointmentID'    => $appointmentId,
            'paymentFrom'      => $userId,
            'paymentFromName'  => trim($contact['firstName'] . ' ' . $contact['lastName']),
            'paymentFromEmail' => $contact['email'],
            'paymentFromPhone' => $contact['phoneNumber'],
            'paymentType'      => 'credit_card',
            'paymentAmount'    => 20.00,
            'paymentStatus'    => 'paid',
        ]);
    } catch (Throwable $e) {
        // Non-fatal — appointment is already saved.
    }

    $_SESSION['booking']['id'] = $appointmentId;
    return $redirect('/book/confirmed');
});

// Step 6: confirmed — guests are redirected back to /book; logged-in users to dashboard
$app->get('/book/confirmed', function (Request $request, Response $response) use ($render, $redirect) {
    $booking = $_SESSION['booking'] ?? null;
    if (!$booking || empty($booking['contact'])) {
        return $redirect('/book');
    }

    $isGuest = empty($_SESSION['user']);

    $view = [
        'active'   => 'book',
        'isGuest'  => $isGuest,
        'booking'  => [
            'service' => $booking['service'] ?? '—',
            'date'    => $booking['date']    ?? '—',
            'time'    => $booking['time']    ?? '—',
            'email'   => $booking['contact']['email'] ?? '',
            'deposit' => 20.00,
        ],
    ];

    // Clear booking session so reload doesn't replay the flow.
    unset($_SESSION['booking'], $_SESSION['booking_form'], $_SESSION['booking_errors']);

    return $render($response, 'booking/confirmed.twig', $view);
});

// ─── AUTH (wired to UserModel + MariaDB) ────────────────────────────────
$app->get('/login', function (Request $request, Response $response) use ($render): Response {
    $errors = $_SESSION['auth_errors'] ?? [];
    $form   = $_SESSION['auth_form']   ?? [];
    unset($_SESSION['auth_errors'], $_SESSION['auth_form']);
    return $render($response, 'auth/login.twig', ['errors' => $errors, 'form' => $form]);
});

$app->post('/login', function (Request $request, Response $response) use ($redirect): Response {
    $body  = (array) $request->getParsedBody();
    $email = trim((string) ($body['email']    ?? ''));
    $pass  = (string)       ($body['password'] ?? '');

    if ($email === '' || $pass === '') {
        $_SESSION['auth_errors'] = ['general' => 'Please enter your email and password.'];
        $_SESSION['auth_form']   = ['email' => $email];
        return $redirect('/login');
    }

    try {
        $users = new UserModel();
        $user  = $users->login($email, $pass);
    } catch (Throwable $e) {
        $_SESSION['auth_errors'] = ['general' => 'Login service unavailable. Please try again later.'];
        return $redirect('/login');
    }

    if (!$user) {
        $_SESSION['auth_errors'] = ['general' => 'Invalid email or password.'];
        $_SESSION['auth_form']   = ['email' => $email];
        return $redirect('/login');
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'          => (int) $user['userID'],
        'firstName'   => $user['firstName'],
        'lastName'    => $user['lastName'],
        'email'       => $user['email'],
        'phoneNumber' => $user['phoneNumber'],
        'role'        => $user['role'],
    ];
    $_SESSION['user_id'] = $_SESSION['user']['id'];
    $_SESSION['user_role'] = $_SESSION['user']['role'];

    return $redirect($user['role'] === UserModel::ROLE_ADMIN ? '/admin' : '/dashboard');
});

$app->get('/register', function (Request $request, Response $response) use ($render): Response {
    $errors = $_SESSION['auth_errors'] ?? [];
    $form   = $_SESSION['auth_form']   ?? [];
    unset($_SESSION['auth_errors'], $_SESSION['auth_form']);
    return $render($response, 'auth/register.twig', ['errors' => $errors, 'form' => $form]);
});

$app->post('/register', function (Request $request, Response $response) use ($redirect): Response {
    $body = (array) $request->getParsedBody();
    $form = [
        'firstName'   => trim((string) ($body['firstName']   ?? '')),
        'lastName'    => trim((string) ($body['lastName']    ?? '')),
        'email'       => trim((string) ($body['email']       ?? '')),
        'phoneNumber' => trim((string) ($body['phoneNumber'] ?? '')),
    ];
    $password        = (string) ($body['password']        ?? '');
    $passwordConfirm = (string) ($body['passwordConfirm'] ?? '');

    $errors = [];
    if ($form['firstName'] === '') $errors['firstName'] = 'First name is required.';
    if ($form['lastName']  === '') $errors['lastName']  = 'Last name is required.';
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Please enter a valid email.';
    if (strlen($password) < 8) $errors['password'] = 'Password must be at least 8 characters.';
    if ($password !== $passwordConfirm) $errors['passwordConfirm'] = 'Passwords do not match.';

    if ($errors) {
        $_SESSION['auth_errors'] = $errors;
        $_SESSION['auth_form']   = $form;
        return $redirect('/register');
    }

    try {
        $users  = new UserModel();
        if ($users->findUserByEmail($form['email'])) {
            $_SESSION['auth_errors'] = ['email' => 'An account with that email already exists.'];
            $_SESSION['auth_form']   = $form;
            return $redirect('/register');
        }
        $userId = $users->signUp($form + ['password' => $password]);
    } catch (Throwable $e) {
        $_SESSION['auth_errors'] = ['general' => 'Registration service unavailable. Please try again later.'];
        $_SESSION['auth_form']   = $form;
        return $redirect('/register');
    }

    if (!$userId) {
        $_SESSION['auth_errors'] = ['general' => 'Could not create account. Please check your details.'];
        $_SESSION['auth_form']   = $form;
        return $redirect('/register');
    }

    $created = $users->getById($userId);
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'          => (int) $created['userID'],
        'firstName'   => $created['firstName'],
        'lastName'    => $created['lastName'],
        'email'       => $created['email'],
        'phoneNumber' => $created['phoneNumber'],
        'role'        => $created['role'],
    ];
    $_SESSION['user_id'] = $_SESSION['user']['id'];
    $_SESSION['user_role'] = $_SESSION['user']['role'];

    return $redirect($created['role'] === UserModel::ROLE_ADMIN ? '/admin' : '/dashboard');
});

$logout = static function () use ($redirect): Response {
    // 1. Wipe in-memory session data.
    $_SESSION = [];

    // 2. Expire the session cookie in the browser so the dead session ID
    //    isn't sent back on the next request.
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]
        );
    }

    // 3. Destroy the session file on the server.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    return $redirect('/');
};

$app->post('/logout', fn(Request $req, Response $res): Response => $logout());
$app->get('/logout',  fn(Request $req, Response $res): Response => $logout());

$app->get('/dashboard', function (Request $request, Response $response) use ($render, $redirect): Response {
    if (empty($_SESSION['user'])) {
        return $redirect('/login');
    }

    $userId = (int) $_SESSION['user']['id'];

    try {
        $appointments = (new AppointmentModel())->getAllAppointmentByUserId($userId);
        $reviews      = (new ReviewModel())->getAllReviewsByUserId($userId);
    } catch (Throwable $e) {
        $appointments = [];
        $reviews      = [];
    }

    $today    = date('Y-m-d');
    $upcoming = array_values(array_filter($appointments, fn($a) => $a['date'] >= $today && $a['status'] !== 'cancelled'));
    $past     = array_values(array_filter($appointments, fn($a) => $a['date'] <  $today || $a['status'] === 'completed'));

    return $render($response, 'dashboard.twig', [
        'user'      => $_SESSION['user'],
        'upcoming'  => $upcoming,
        'past'      => $past,
        'myReviews' => $reviews,
    ]);
});

// ─── ADMIN ──────────────────────────────────────────────────────────────
$app->get('/admin[/{section}]', function (Request $request, Response $response, array $args) use ($render, $renderError, $redirect): Response {
    if (empty($_SESSION['user'])) {
        return $redirect('/login');
    }
    if (($_SESSION['user']['role'] ?? null) !== UserModel::ROLE_ADMIN) {
        return $renderError(403, 'Access denied', 'You must be signed in as an admin to view this page.');
    }

    $section = (string) ($args['section'] ?? 'appointments');

    try {
        $appointments = (new AppointmentModel())->getAllAppointements();
        $reviews      = (new ReviewModel())->getAll();
    } catch (Throwable $e) {
        $appointments = [];
        $reviews      = [];
    }

    return $render($response, 'admin.twig', [
        'section'      => $section,
        'services'     => ViewData::adminServices(),
        'appointments' => $appointments,
        'reviews'      => $reviews,
    ]);
});

$app->run();
