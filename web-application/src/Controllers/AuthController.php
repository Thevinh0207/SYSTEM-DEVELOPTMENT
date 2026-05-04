<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class AuthController
{
    public function __construct(
        private Environment $twig,
        private UserModel $users
    ) {}

    public function showLogin(Request $request, Response $response): Response
    {
        $html = $this->twig->render('auth/login.twig', [
            'csrf_token' => $request->getAttribute('csrf_token'),
            'error'      => $_SESSION['flash_error'] ?? null,
            'locale'     => $request->getAttribute('locale', 'en'),
        ]);
        unset($_SESSION['flash_error']);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function login(Request $request, Response $response): Response
    {
        $body  = (array) $request->getParsedBody();
        $email = trim((string) ($body['email'] ?? ''));
        $pass  = (string) ($body['password'] ?? '');

        $user = $this->users->findUserByEmail($email);

        if (!$user || !password_verify($pass, $user->password)) {
            $_SESSION['flash_error'] = 'Invalid email or password.';
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        session_regenerate_id(true);
        $_SESSION['user_id']   = (int) $user->id;
        $_SESSION['user_role'] = $user->role;

        return $response->withHeader('Location', '/')->withStatus(302);
    }

    public function showRegister(Request $request, Response $response): Response
    {
        $html = $this->twig->render('auth/register.twig', [
            'csrf_token' => $request->getAttribute('csrf_token'),
            'error'      => $_SESSION['flash_error'] ?? null,
            'locale'     => $request->getAttribute('locale', 'en'),
        ]);
        unset($_SESSION['flash_error']);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function register(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        $email = trim((string) ($body['email'] ?? ''));
        $pass  = (string) ($body['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
            $_SESSION['flash_error'] = 'Invalid email or password too short (min 8 chars).';
            return $response->withHeader('Location', '/register')->withStatus(302);
        }

        if ($this->users->findUserByEmail($email)) {
            $_SESSION['flash_error'] = 'An account with that email already exists.';
            return $response->withHeader('Location', '/register')->withStatus(302);
        }

        $this->users->create([
            'firstName'   => trim((string) ($body['firstName'] ?? '')),
            'lastName'    => trim((string) ($body['lastName']  ?? '')),
            'email'       => $email,
            'password'    => password_hash($pass, PASSWORD_DEFAULT),
            'phoneNumber' => trim((string) ($body['phoneNumber'] ?? '')),
            'role'        => 'user',
        ]);

        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    public function logout(Request $request, Response $response): Response
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();

        return $response->withHeader('Location', '/login')->withStatus(302);
    }
}
