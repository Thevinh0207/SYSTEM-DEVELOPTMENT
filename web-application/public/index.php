<?php

declare(strict_types=1);

use App\Config;
use App\Controllers\AdminPanelController;
use App\Controllers\AuthController;
use App\Controllers\BookingController;
use App\Controllers\DashboardController;
use App\Controllers\HomeController;
use App\Models\AppointmentModel;
use App\Models\PaymentModel;
use App\Models\ReviewModel;
use App\Models\ServiceModel;
use App\Models\UserModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Slim\Psr7\Response as Psr7Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

// Hide PHP 8.5 deprecation noise from RedBeanPHP 5.7 (dynamic properties +
// renamed PDO constant). They don't affect behavior. Real errors still show.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

session_start();

require dirname(__DIR__) . '/vendor/autoload.php';

// ─── Database (RedBeanPHP) ──────────────────────────────────────────────
$db = Config::get('database', []);
R::setup(
    "mysql:host={$db['host']};port={$db['port']};dbname={$db['dbname']};charset={$db['charset']}",
    $db['user'],
    $db['pass']
);
R::freeze(false);

// ─── Base path detection ────────────────────────────────────────────────
$scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
$basePath  = ($scriptDir === '/' || $scriptDir === '.') ? '' : rtrim($scriptDir, '/');

// ─── Slim app ───────────────────────────────────────────────────────────
$app = AppFactory::create();
if ($basePath !== '') {
    $app->setBasePath($basePath);
}
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

// ─── Twig ───────────────────────────────────────────────────────────────
$twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/templates'), [
    'cache'       => false,
    'auto_reload' => true,
]);
$twig->addFunction(new TwigFunction('path', static fn(string $p = '/'): string =>
    $basePath . ($p === '/' ? '/' : '/' . ltrim($p, '/'))));
$twig->addFunction(new TwigFunction('asset', static fn(string $p): string =>
    $basePath . '/assets/' . ltrim($p, '/')));

// ─── Controllers (manual DI) ────────────────────────────────────────────
$home      = new HomeController($twig, $basePath, new ServiceModel());
$auth      = new AuthController($twig, $basePath, new UserModel());
$booking   = new BookingController(
    $twig, $basePath,
    new ServiceModel(),
    new AppointmentModel(),
    new PaymentModel()
);
$dashboard = new DashboardController(
    $twig, $basePath,
    new AppointmentModel(),
    new ReviewModel(),
    new ServiceModel()
);
$admin     = new AdminPanelController(
    $twig, $basePath,
    new AppointmentModel(),
    new ServiceModel(),
    new ReviewModel(),
    new PaymentModel()
);

// ─── Error handlers ─────────────────────────────────────────────────────
$renderError = static function (int $code, string $title, string $message) use ($twig): Response {
    $response = new Psr7Response();
    $html = $twig->render('Errors/generic.twig', compact('code', 'title', 'message'));
    $response->getBody()->write($html);
    return $response->withStatus($code)->withHeader('Content-Type', 'text/html; charset=utf-8');
};

$errorMiddleware->setErrorHandler(
    HttpNotFoundException::class,
    static function (Request $r, Throwable $e) use ($twig): Response {
        $response = new Psr7Response();
        $html = $twig->render('Errors/404.twig', [
            'requestedPath' => $r->getUri()->getPath(),
            'locale'        => 'en',
        ]);
        $response->getBody()->write($html);
        return $response->withStatus(404)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
);
$errorMiddleware->setErrorHandler(
    HttpMethodNotAllowedException::class,
    static fn() => $renderError(405, 'Method not allowed', 'That action is not available on this page.')
);
$errorMiddleware->setDefaultErrorHandler(
    static function (Request $r, Throwable $e, bool $details) use ($renderError): Response {
        $msg = $details ? $e->getMessage() : 'Something went wrong on our end. Please try again later.';
        return $renderError(500, 'Server error', $msg);
    }
);

// ─── Routes ─────────────────────────────────────────────────────────────
// Static / marketing
$app->get('/',                            [$home, 'home']);
$app->get('/services',                    [$home, 'services']);
$app->get('/services/gel-x-extensions',   [$home, 'serviceDetail']);
$app->get('/services/{id:[0-9]+}',        [$home, 'serviceDetail']);
$app->get('/about',                       [$home, 'about']);
$app->get('/faq',                         [$home, 'faq']);

// Booking flow
$app->get ('/book',           [$booking, 'gate']);
$app->get ('/book/info',      [$booking, 'showInfo']);
$app->post('/book/info',      [$booking, 'submitInfo']);
$app->get ('/book/service',   [$booking, 'showService']);
$app->post('/book/service',   [$booking, 'submitService']);
$app->get ('/book/date',      [$booking, 'showDate']);
$app->post('/book/date',      [$booking, 'submitDate']);
$app->get ('/book/payment',   [$booking, 'showPayment']);
$app->post('/book/payment',   [$booking, 'submitPayment']);
$app->get ('/book/confirmed', [$booking, 'confirmed']);

// Auth
$app->get ('/login',    [$auth, 'showLogin']);
$app->post('/login',    [$auth, 'login']);
$app->get ('/register', [$auth, 'showRegister']);
$app->post('/register', [$auth, 'register']);
$app->get ('/logout',   [$auth, 'logout']);
$app->post('/logout',   [$auth, 'logout']);

// Client dashboard
$app->get ('/dashboard',        [$dashboard, 'index']);
$app->post('/dashboard/review', [$dashboard, 'postReview']);

// Admin panel
$app->get ('/admin/services/new',                    [$admin, 'newService']);
$app->post('/admin/services/new',                    [$admin, 'createService']);
$app->get ('/admin/services/{id:[0-9]+}/edit',       [$admin, 'editService']);
$app->post('/admin/services/{id:[0-9]+}/edit',       [$admin, 'updateService']);
$app->post('/admin/services/{id:[0-9]+}/delete',     [$admin, 'deleteService']);
$app->get ('/admin/appointments/new',                [$admin, 'newAppointment']);
$app->post('/admin/appointments/new',                [$admin, 'createAppointment']);
$app->get ('/admin/appointments/{id:[0-9]+}/edit',   [$admin, 'editAppointment']);
$app->post('/admin/appointments/{id:[0-9]+}/edit',   [$admin, 'updateAppointment']);
$app->post('/admin/appointments/{id:[0-9]+}/cancel', [$admin, 'cancelAppointment']);
$app->post('/admin/reviews/{id:[0-9]+}/reply',       [$admin, 'replyToReview']);
// Section route — keep last so specific routes above take precedence.
$app->get ('/admin[/{section}]', [$admin, 'dashboard']);

$app->run();
