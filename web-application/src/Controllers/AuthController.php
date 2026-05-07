<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;
use Twig\Environment;

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
        return $this->redirect($user->role === UserModel::ROLE_ADMIN ? '/admin' : '/dashboard');
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

        if ($form['phoneNumber'] === '') {
            $errors['phoneNumber'] = 'Phone number is required.';
        } else {
            $digits = preg_replace('/[\s\-\+\(\)]/', '', $form['phoneNumber']);
            if (!ctype_digit($digits)) {
                $errors['phoneNumber'] = 'Phone number must contain digits only (you can use + - spaces or parentheses).';
            } elseif (strlen($digits) < 7 || strlen($digits) > 15) {
                $errors['phoneNumber'] = 'Phone number must be between 7 and 15 digits.';
            }
        }

        if (strlen($password) < 8) $errors['password'] = 'Password must be at least 8 characters.';
        if ($password !== $passwordConfirm) $errors['passwordConfirm'] = 'Passwords do not match.';

        if ($errors) {
            $_SESSION['auth_errors'] = $errors;
            $_SESSION['auth_form']   = $form;
            return $this->redirect('/register');
        }

        try {
            if ($this->users->findByEmail($form['email'])) {
                $_SESSION['auth_errors'] = ['email' => 'An account with that email already exists.'];
                $_SESSION['auth_form']   = $form;
                return $this->redirect('/register');
            }
            $created = $this->users->create($form + ['password' => $password]);
        } catch (Throwable $e) {
            $_SESSION['auth_errors'] = ['general' => 'Registration service unavailable. Please try again later.'];
            $_SESSION['auth_form']   = $form;
            return $this->redirect('/register');
        }

        if (!$created) {
            $_SESSION['auth_errors'] = ['general' => 'Could not create account. Please check your details.'];
            $_SESSION['auth_form']   = $form;
            return $this->redirect('/register');
        }

        $this->signInUser($created);
        return $this->redirect($created->role === UserModel::ROLE_ADMIN ? '/admin' : '/dashboard');
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

        return $this->redirect('/?logged_out=1');
    }

    private function signInUser($userBean): void
    {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'          => (int) $userBean->id,
            'firstName'   => $userBean->firstName,
            'lastName'    => $userBean->lastName,
            'email'       => $userBean->email,
            'phoneNumber' => $userBean->phoneNumber,
            'role'        => $userBean->role,
        ];
    }
}
