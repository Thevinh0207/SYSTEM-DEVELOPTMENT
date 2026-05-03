<?php

declare(strict_types=1);

session_start();

define('MAINTENANCE_MODE', false);


//todo: add the direcotries for models, controllers, services, middleware, and templates
// **DON'T FORGET TO CHECK THE DOCUMENTATIONS BEFORE STARTING**
use App\Services\OtpService;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use RedBeanPHP\R;
use Slim\Factory\AppFactory;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

require __DIR__ . '/vendor/autoload.php';

// ─── 1. DATABASE ────────────────────────────────────────────────────────────────

$dbPath = __DIR__ . '/var/todos.db';
R::setup('sqlite:' . $dbPath);
R::freeze(false);

$model = new TodoModel();

if (R::count('todo') === 0) {
    foreach (['Buy groceries', 'Write tests', 'Read a good book'] as $task) {
        $model->create($task);
    }
}

// ─── 2. TEMPLATE ENGINE ────────────────────────────────────────────────────────────────

$loader = new FilesystemLoader(__DIR__ . '/templates');
$twig   = new Environment($loader, [
    'cache'       => false,
    'auto_reload' => true,
]);

// ─── 3. I18N ──────────────────────────────────────────────────────────────────────


// ─── 4. DEPENDENCY INJECTION CONTAINER ─────────────────────────────────────────────


// ─── 5. APPLICATION ────────────────────────────────────────────────────────────────

// ─── 6. MIDDLEWARE ──────────────────────────────────────────────────────────────

// ─── 7. HTML ROUTES ────────────────────────────────────────────────────────────────

// ─── 8. LANGUAGE ROUTES ────────────────────────────────────────────────────────────────

// ─── 9. AUTH ROUTES ────────────────────────────────────────────────────────────────

// ─── 10. TEMPORARY DEBUG ROUTE ────────────────────────────────────────────────────────────────

