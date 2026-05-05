<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;
use Twig\Environment;

/**
 * AuthController — login, register, logout.
 *
 * Talks to UserModel for password verification and account creation.
 * Sessions are regenerated on successful login/register to prevent fixation.
 * Logout wipes session data, expires the cookie, and destroys the session.
 */
class AuthController extends BaseController
{
    public function __construct(
        Environment $twig,
        string $basePath,
        private UserModel $users
    ) {
        parent::__construct($twig, $basePath);
    }

    public function showLogin(Request $r, Response $response): Response
    {
        $errors = $_SESSION['auth_errors'] ?? [];
        $form   = $_SESSION['auth_form']   ?? [];
        unset($_SESSION['auth_errors'], $_SESSION['auth_form']);
        return $this->render($response, 'auth/login.twig', ['errors' => $errors, 'form' => $form]);
    }

    public function login(Request $r, Response $response): Response
    {
        $body  = (array) $r->getParsedBody();
        $email = trim((string) ($body['email']    ?? ''));
        $pass  = (string)       ($body['password'] ?? '');

        if ($email === '' || $pass === '') {
            $_SESSION['auth_errors'] = ['general' => 'Please enter your email and password.'];
            $_SESSION['auth_form']   = ['email' => $email];
            return $this->redirect('/login');
        }

        try {
            $user = $this->users->login($email, $pass);
        } catch (Throwable $e) {
            $_SESSION['auth_errors'] = ['general' => 'Login service unavailable. Please try again later.'];
            return $this->redirect('/login');
        }

        if (!$user) {
            $_SESSION['auth_errors'] = ['general' => 'Invalid email or password.'];
            $_SESSION['auth_form']   = ['email' => $email];
            return $this->redirect('/login');
        }

        $this->signInUser($user);
        return $this->redirect($user['role'] === UserModel::ROLE_ADMIN ? '/admin' : '/dashboard');
    }

    public function showRegister(Request $r, Response $response): Response
    {
        $errors = $_SESSION['auth_errors'] ?? [];
        $form   = $_SESSION['auth_form']   ?? [];
        unset($_SESSION['auth_errors'], $_SESSION['auth_form']);
        return $this->render($response, 'auth/register.twig', ['errors' => $errors, 'form' => $form]);
    }

    public function register(Request $r, Response $response): Response
    {
        $body = (array) $r->getParsedBody();
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
            return $this->redirect('/register');
        }

        try {
            if ($this->users->findUserByEmail($form['email'])) {
                $_SESSION['auth_errors'] = ['email' => 'An account with that email already exists.'];
                $_SESSION['auth_form']   = $form;
                return $this->redirect('/register');
            }
            $userId = $this->users->signUp($form + ['password' => $password]);
        } catch (Throwable $e) {
            $_SESSION['auth_errors'] = ['general' => 'Registration service unavailable. Please try again later.'];
            $_SESSION['auth_form']   = $form;
            return $this->redirect('/register');
        }

        if (!$userId) {
            $_SESSION['auth_errors'] = ['general' => 'Could not create account. Please check your details.'];
            $_SESSION['auth_form']   = $form;
            return $this->redirect('/register');
        }

        $created = $this->users->getById($userId);
        $this->signInUser($created);
        return $this->redirect($created['role'] === UserModel::ROLE_ADMIN ? '/admin' : '/dashboard');
    }

    public function logout(Request $r, Response $response): Response
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        return $this->redirect('/');
    }

    private function signInUser(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'          => (int) $user['userID'],
            'firstName'   => $user['firstName'],
            'lastName'    => $user['lastName'],
            'email'       => $user['email'],
            'phoneNumber' => $user['phoneNumber'],
            'role'        => $user['role'],
        ];
    }
}
