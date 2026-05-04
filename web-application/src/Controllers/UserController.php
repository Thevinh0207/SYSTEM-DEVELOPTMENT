<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class UserController
{
    public function __construct(
        private Environment $twig,
        private UserModel $userModel,
    ) {}

    // ─── Auth ────────────────────────────────────────────────────────────────

    // GET /login
    public function login(Request $request, Response $response): Response
    {
        if (!empty($_SESSION['user_id'])) {
            return $this->redirect($response, '/');
        }

        $params = $request->getQueryParams();

        return $this->render($response, 'users/login.twig', [
            'errors'      => [],
            'old'         => [],
            'justRegistered' => isset($params['registered']),
        ]);
    }

    // POST /login
    public function loginSubmit(Request $request, Response $response): Response
    {
        $body     = (array) $request->getParsedBody();
        $email    = trim($body['email']    ?? '');
        $password = $body['password']      ?? '';
        $errors   = [];

        if (empty($email)) {
            $errors['email'][] = 'Email is required.';
        }

        if (empty($password)) {
            $errors['password'][] = 'Password is required.';
        }

        if (empty($errors)) {
            $user = $this->userModel->findUserByEmail($email);

            if ($user === null || !password_verify($password, $user->password)) {
                $errors['general'][] = 'Invalid email or password.';
            }
        }

        if (!empty($errors)) {
            return $this->render($response, 'users/login.twig', [
                'errors' => $errors,
                'old'    => ['email' => $email],
            ]);
        }

        $_SESSION['user_id'] = $user->id;
        $_SESSION['role']    = $user->role;
        $_SESSION['name']    = $user->firstName . ' ' . $user->lastName;

        $destination = $_SESSION['role'] === 'admin'
            ? '/admin/appointments'
            : '/appointments/my-appointments';

        return $this->redirect($response, $destination);
    }

    // GET /register
    public function register(Request $request, Response $response): Response
    {
        if (!empty($_SESSION['user_id'])) {
            return $this->redirect($response, '/');
        }

        return $this->render($response, 'users/register.twig', [
            'errors' => [],
            'old'    => [],
        ]);
    }

    // POST /register
    public function registerSubmit(Request $request, Response $response): Response
    {
        $data   = $this->getPostData($request);
        $errors = $this->validateRegister($data);

        if (!empty($errors)) {
            return $this->render($response, 'users/register.twig', [
                'errors' => $errors,
                'old'    => $data,
            ]);
        }

        $data['password'] = password_hash($data['password'], PASSWORD_BCRYPT);
        $data['role']     = 'guest';

        $this->userModel->create($data);
        return $this->redirect($response, '/login?registered=1');
    }

    // GET /logout
    public function logout(Request $request, Response $response): Response
    {
        session_destroy();
        return $this->redirect($response, '/login');
    }

    // ─── Customer — own profile ───────────────────────────────────────────────

    // GET /profile
    public function profile(Request $request, Response $response): Response
    {
        $this->requireLogin();

        $user = $this->userModel->getById($_SESSION['user_id']);

        if ($user === null) {
            return $this->renderError($response, 404, 'User not found.');
        }

        $params = $request->getQueryParams();

        return $this->render($response, 'users/profile.twig', [
            'user'        => $user,
            'errors'      => [],
            'old'         => [],
            'justUpdated' => isset($params['updated']),
        ]);
    }

    // POST /profile
    public function profileSubmit(Request $request, Response $response): Response
    {
        $this->requireLogin();

        $id   = $_SESSION['user_id'];
        $user = $this->userModel->getById($id);

        if ($user === null) {
            return $this->renderError($response, 404, 'User not found.');
        }

        $data   = $this->getProfileData($request);
        $errors = $this->validateProfile($data, $id);

        if (!empty($errors)) {
            return $this->render($response, 'users/profile.twig', [
                'user'   => $user,
                'errors' => $errors,
                'old'    => $data,
            ]);
        }

        if (!empty($data['password'])) {
            $data['password'] = password_hash($data['password'], PASSWORD_BCRYPT);
        } else {
            unset($data['password']);
        }

        $this->userModel->update($id, $data);

        $_SESSION['name'] = ($data['firstName'] ?? $user->firstName)
            . ' ' . ($data['lastName'] ?? $user->lastName);

        return $this->redirect($response, '/profile?updated=1');
    }

    // ─── Admin ───────────────────────────────────────────────────────────────

    // GET /admin/users
    public function index(Request $request, Response $response): Response
    {
        $this->requireAdmin();

        $params = $request->getQueryParams();

        return $this->render($response, 'users/index.twig', [
            'users'       => $this->userModel->getAll(),
            'justDeleted' => isset($params['deleted']),
            'justUpdated' => isset($params['updated']),
        ]);
    }

    // GET /admin/users/{id}
    public function view(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin();

        $user = $this->userModel->getById((int) $args['id']);

        if ($user === null) {
            return $this->renderError($response, 404, 'User not found.');
        }

        return $this->render($response, 'users/view.twig', [
            'user' => $user,
        ]);
    }

    // GET /admin/users/{id}/edit
    public function edit(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin();

        $user = $this->userModel->getById((int) $args['id']);

        if ($user === null) {
            return $this->renderError($response, 404, 'User not found.');
        }

        return $this->render($response, 'users/edit.twig', [
            'user'   => $user,
            'errors' => [],
            'old'    => [],
        ]);
    }

    // POST /admin/users/{id}/edit
    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin();

        $id   = (int) $args['id'];
        $user = $this->userModel->getById($id);

        if ($user === null) {
            return $this->renderError($response, 404, 'User not found.');
        }

        $data   = $this->getPostData($request);
        $errors = $this->validateUpdate($data, $id);

        if (!empty($errors)) {
            return $this->render($response, 'users/edit.twig', [
                'user'   => $user,
                'errors' => $errors,
                'old'    => $data,
            ]);
        }

        if (!empty($data['password'])) {
            $data['password'] = password_hash($data['password'], PASSWORD_BCRYPT);
        } else {
            unset($data['password']);
        }

        $this->userModel->update($id, $data);
        return $this->redirect($response, '/admin/users?updated=1');
    }

    // POST /admin/users/{id}/delete
    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin();

        $id = (int) $args['id'];

        if ($id === $_SESSION['user_id']) {
            return $this->renderError($response, 403, 'You cannot delete your own account.');
        }

        $user = $this->userModel->getById($id);

        if ($user === null) {
            return $this->renderError($response, 404, 'User not found.');
        }

        // Note: add a delete() method to UserModel to enable this properly
        // $this->userModel->delete($id);

        return $this->redirect($response, '/admin/users?deleted=1');
    }

    // ─── Validation ──────────────────────────────────────────────────────────

    private function validateRegister(array $data): array
    {
        $errors = [];

        if (empty($data['firstName'])) {
            $errors['firstName'][] = 'First name is required.';
        }

        if (empty($data['lastName'])) {
            $errors['lastName'][] = 'Last name is required.';
        }

        if (empty($data['email'])) {
            $errors['email'][] = 'Email is required.';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'Please enter a valid email address.';
        } elseif ($this->userModel->findUserByEmail($data['email']) !== null) {
            $errors['email'][] = 'An account with this email already exists.';
        }

        if (empty($data['password'])) {
            $errors['password'][] = 'Password is required.';
        } elseif (mb_strlen($data['password']) < 8) {
            $errors['password'][] = 'Password must be at least 8 characters.';
        }

        if (empty($data['phoneNumber'])) {
            $errors['phoneNumber'][] = 'Phone number is required.';
        } elseif (!preg_match('/^\+?[\d\s\-]{7,15}$/', $data['phoneNumber'])) {
            $errors['phoneNumber'][] = 'Please enter a valid phone number.';
        }

        return $errors;
    }

    private function validateUpdate(array $data, int $currentId): array
    {
        $errors = [];

        if (empty($data['firstName'])) {
            $errors['firstName'][] = 'First name is required.';
        }

        if (empty($data['lastName'])) {
            $errors['lastName'][] = 'Last name is required.';
        }

        if (empty($data['email'])) {
            $errors['email'][] = 'Email is required.';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'Please enter a valid email address.';
        } else {
            $existing = $this->userModel->findUserByEmail($data['email']);
            if ($existing !== null && (int) $existing->id !== $currentId) {
                $errors['email'][] = 'This email is already used by another account.';
            }
        }

        if (!empty($data['password']) && mb_strlen($data['password']) < 8) {
            $errors['password'][] = 'Password must be at least 8 characters.';
        }

        if (empty($data['phoneNumber'])) {
            $errors['phoneNumber'][] = 'Phone number is required.';
        } elseif (!preg_match('/^\+?[\d\s\-]{7,15}$/', $data['phoneNumber'])) {
            $errors['phoneNumber'][] = 'Please enter a valid phone number.';
        }

        $validRoles = ['guest', 'admin'];
        if (!empty($data['role']) && !in_array($data['role'], $validRoles, true)) {
            $errors['role'][] = 'Invalid role selected.';
        }

        return $errors;
    }

    private function validateProfile(array $data, int $currentId): array
    {
        $errors = [];

        if (empty($data['firstName'])) {
            $errors['firstName'][] = 'First name is required.';
        }

        if (empty($data['lastName'])) {
            $errors['lastName'][] = 'Last name is required.';
        }

        if (empty($data['email'])) {
            $errors['email'][] = 'Email is required.';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'Please enter a valid email address.';
        } else {
            $existing = $this->userModel->findUserByEmail($data['email']);
            if ($existing !== null && (int) $existing->id !== $currentId) {
                $errors['email'][] = 'This email is already used by another account.';
            }
        }

        if (!empty($data['password']) && mb_strlen($data['password']) < 8) {
            $errors['password'][] = 'New password must be at least 8 characters.';
        }

        if (empty($data['phoneNumber'])) {
            $errors['phoneNumber'][] = 'Phone number is required.';
        } elseif (!preg_match('/^\+?[\d\s\-]{7,15}$/', $data['phoneNumber'])) {
            $errors['phoneNumber'][] = 'Please enter a valid phone number.';
        }

        return $errors;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function getPostData(Request $request): array
    {
        $body = (array) $request->getParsedBody();
        return [
            'firstName'   => trim($body['firstName']   ?? ''),
            'lastName'    => trim($body['lastName']    ?? ''),
            'email'       => trim($body['email']       ?? ''),
            'password'    => $body['password']         ?? '',
            'phoneNumber' => trim($body['phoneNumber'] ?? ''),
            'role'        => trim($body['role']        ?? 'guest'),
        ];
    }

    private function getProfileData(Request $request): array
    {
        $body = (array) $request->getParsedBody();
        return [
            'firstName'   => trim($body['firstName']   ?? ''),
            'lastName'    => trim($body['lastName']    ?? ''),
            'email'       => trim($body['email']       ?? ''),
            'password'    => $body['password']         ?? '',
            'phoneNumber' => trim($body['phoneNumber'] ?? ''),
        ];
    }

    private function requireLogin(): void
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
    }

    private function requireAdmin(): void
    {
        if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
            header('Location: /login');
            exit;
        }
    }

    private function render(Response $response, string $template, array $data = []): Response
    {
        $response->getBody()->write($this->twig->render($template, $data));
        return $response;
    }

    private function renderError(Response $response, int $code, string $message): Response
    {
        $response->getBody()->write($this->twig->render('errors/generic.twig', [
            'code'    => $code,
            'message' => $message,
        ]));
        return $response->withStatus($code);
    }

    private function redirect(Response $response, string $url): Response
    {
        return $response->withHeader('Location', $url)->withStatus(302);
    }
}