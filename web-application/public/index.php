<?php

declare(strict_types=1);

use App\Data\ViewData;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
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
$app->addErrorMiddleware(true, true, true);

$twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/templates'), [
    'cache' => false,
    'auto_reload' => true,
]);

$twig->addFunction(new TwigFunction('path', static function (string $path = '/') use ($basePath): string {
    return $basePath . ($path === '/' ? '/' : '/' . ltrim($path, '/'));
}));
$twig->addFunction(new TwigFunction('asset', static fn(string $path): string => $basePath . '/assets/' . ltrim($path, '/')));

$render = static function (Response $response, string $template, array $data = []) use ($twig): Response {
    $response->getBody()->write($twig->render($template, $data + ['images' => ViewData::images()]));
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
};

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

$app->get('/book', fn(Request $request, Response $response): Response => $render($response, 'booking/service.twig', [
    'active' => 'book',
    'bookingServices' => ViewData::bookingServices(),
]));

$app->get('/book/date', fn(Request $request, Response $response): Response => $render($response, 'booking/date.twig', [
    'active' => 'book',
    'days' => range(1, 31),
    'times' => ViewData::bookingTimes(),
]));

$app->get('/book/account', fn(Request $request, Response $response): Response => $render($response, 'booking/account.twig', [
    'active' => 'book',
    'summary' => ['date' => 'March 30, 2026 at 2:00 PM'],
]));

$app->get('/book/info', fn(Request $request, Response $response): Response => $render($response, 'booking/info.twig', [
    'active' => 'book',
    'summary' => ['date' => 'March 30, 2026 at 2:00 PM'],
]));

$app->get('/book/payment', fn(Request $request, Response $response): Response => $render($response, 'booking/payment.twig', [
    'active' => 'book',
    'summary' => ['date' => 'March 30, 2026 at 2:00 PM', 'name' => 'Sarah Johnson'],
]));

$app->get('/book/confirmed', fn(Request $request, Response $response): Response => $render($response, 'booking/confirmed.twig', [
    'active' => 'book',
]));

$app->get('/login', fn(Request $request, Response $response): Response => $render($response, 'auth/login.twig'));
$app->get('/register', fn(Request $request, Response $response): Response => $render($response, 'auth/register.twig'));
$app->get('/dashboard', fn(Request $request, Response $response): Response => $render($response, 'dashboard.twig'));

$app->get('/admin[/{section}]', function (Request $request, Response $response, array $args) use ($render): Response {
    $section = (string) ($args['section'] ?? 'appointments');

    return $render($response, 'admin.twig', [
        'section' => $section,
        'services' => ViewData::adminServices(),
        'reviews' => ViewData::reviews(),
        'days' => range(1, 31),
    ]);
});

$app->run();
