<?php

use Psr\Http\Message\ResponseInterface as Response;

function isAdminAuthenticated(): bool
{
    return !empty($_SESSION['admin_authenticated']);
}

function getAdminUsername(): ?string
{
    $username = $_SESSION['admin_username'] ?? null;

    return is_string($username) && $username !== '' ? $username : null;
}

function getAdminDisplayName(): ?string
{
    $name = $_SESSION['admin_display_name'] ?? getAdminUsername();

    return is_string($name) && $name !== '' ? $name : null;
}

function getAuthError(): ?string
{
    $error = $_SESSION['auth_error'] ?? null;

    return is_string($error) && $error !== '' ? $error : null;
}

function getLastAuthUsername(): string
{
    $username = $_SESSION['auth_last_username'] ?? '';

    return is_string($username) ? $username : '';
}

function clearAuthFlash(): void
{
    unset($_SESSION['auth_error'], $_SESSION['auth_last_username']);
}

function attemptAdminLogin(array $data): bool
{
    $username = trim((string) ($data['username'] ?? ''));
    $password = (string) ($data['password'] ?? '');

    $_SESSION['auth_last_username'] = $username;

    $configuredUsername = trim((string) ($_ENV['ADMIN_USERNAME'] ?? ''));
    $configuredPassword = (string) ($_ENV['ADMIN_PASSWORD'] ?? '');
    $configuredPasswordHash = (string) ($_ENV['ADMIN_PASSWORD_HASH'] ?? '');

    if ($configuredUsername === '' || ($configuredPassword === '' && $configuredPasswordHash === '')) {
        $_SESSION['auth_error'] = 'Admin login is not configured yet. Add ADMIN_USERNAME and ADMIN_PASSWORD in .env.';

        return false;
    }

    $passwordMatches = $configuredPasswordHash !== ''
        ? password_verify($password, $configuredPasswordHash)
        : hash_equals($configuredPassword, $password);

    if (!hash_equals($configuredUsername, $username) || !$passwordMatches) {
        $_SESSION['auth_error'] = 'Invalid username or password.';

        return false;
    }

    $_SESSION['admin_authenticated'] = true;
    $_SESSION['admin_username'] = $configuredUsername;
    $_SESSION['admin_display_name'] = $_SESSION['admin_display_name'] ?? $configuredUsername;

    clearAuthFlash();

    return true;
}

function logoutAdmin(): void
{
    unset(
        $_SESSION['admin_authenticated'],
        $_SESSION['admin_username'],
        $_SESSION['admin_display_name'],
        $_SESSION['admin_profile_error'],
        $_SESSION['admin_profile_success']
    );
}

function redirectTo(Response $response, string $location): Response
{
    return $response
        ->withHeader('Location', $location)
        ->withStatus(302);
}

function requireAdminAuth(string $basePath): callable
{
    return function ($request, $handler) use ($basePath) {
        if (isAdminAuthenticated()) {
            return $handler->handle($request);
        }

        return redirectTo(new \Slim\Psr7\Response(), $basePath . '/admin/login');
    };
}

function isUserAuthenticated(): bool
{
    return !empty($_SESSION['user_authenticated']);
}

function getUserId(): ?int
{
    $userId = $_SESSION['user_id'] ?? null;

    return is_numeric($userId) ? (int) $userId : null;
}

function getUserEmail(): ?string
{
    $email = $_SESSION['user_email'] ?? null;

    return is_string($email) && $email !== '' ? $email : null;
}

function getUserName(): ?string
{
    $name = $_SESSION['user_name'] ?? null;

    return is_string($name) && $name !== '' ? $name : null;
}

function getUserAuthError(): ?string
{
    $error = $_SESSION['user_auth_error'] ?? null;

    return is_string($error) && $error !== '' ? $error : null;
}

function getAdminProfileError(): ?string
{
    $error = $_SESSION['admin_profile_error'] ?? null;

    return is_string($error) && $error !== '' ? $error : null;
}

function getAdminProfileSuccess(): ?string
{
    $message = $_SESSION['admin_profile_success'] ?? null;

    return is_string($message) && $message !== '' ? $message : null;
}

function consumeAdminProfileError(): ?string
{
    $error = getAdminProfileError();
    unset($_SESSION['admin_profile_error']);

    return $error;
}

function consumeAdminProfileSuccess(): ?string
{
    $message = getAdminProfileSuccess();
    unset($_SESSION['admin_profile_success']);

    return $message;
}

function getUserAuthSuccess(): ?string
{
    $message = $_SESSION['user_auth_success'] ?? null;

    return is_string($message) && $message !== '' ? $message : null;
}

function consumeUserAuthError(): ?string
{
    $error = getUserAuthError();
    unset($_SESSION['user_auth_error']);

    return $error;
}

function consumeUserAuthSuccess(): ?string
{
    $message = getUserAuthSuccess();
    unset($_SESSION['user_auth_success']);

    return $message;
}

function getLastUserName(): string
{
    $name = $_SESSION['user_auth_last_name'] ?? '';

    return is_string($name) ? $name : '';
}

function getLastUserEmail(): string
{
    $email = $_SESSION['user_auth_last_email'] ?? '';

    return is_string($email) ? $email : '';
}

function clearUserAuthFlash(): void
{
    unset(
        $_SESSION['user_auth_error'],
        $_SESSION['user_auth_success'],
        $_SESSION['user_auth_last_name'],
        $_SESSION['user_auth_last_email']
    );
}

function loginUserSession(array $user): void
{
    $_SESSION['user_authenticated'] = true;
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_name'] = (string) $user['name'];
    $_SESSION['user_email'] = (string) $user['email'];
}

function attemptUserLogin(array $data, \App\Model\UserModel $userModel): bool
{
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $password = (string) ($data['password'] ?? '');

    $_SESSION['user_auth_last_email'] = $email;

    if ($email === '' || $password === '') {
        $_SESSION['user_auth_error'] = 'Please enter your email and password.';

        return false;
    }

    $user = $userModel->verifyCredentials($email, $password);

    if ($user === null) {
        $_SESSION['user_auth_error'] = 'Invalid email or password.';

        return false;
    }

    loginUserSession($user);
    clearUserAuthFlash();

    return true;
}

function attemptUserRegistration(array $data, \App\Model\UserModel $userModel): bool
{
    $name = trim((string) ($data['name'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $password = (string) ($data['password'] ?? '');
    $confirmPassword = (string) ($data['confirm_password'] ?? '');

    $_SESSION['user_auth_last_name'] = $name;
    $_SESSION['user_auth_last_email'] = $email;

    if ($name === '' || $email === '' || $password === '' || $confirmPassword === '') {
        $_SESSION['user_auth_error'] = 'Please fill in all required fields.';

        return false;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['user_auth_error'] = 'Please enter a valid email address.';

        return false;
    }

    if (strlen($password) < 8) {
        $_SESSION['user_auth_error'] = 'Password must be at least 8 characters long.';

        return false;
    }

    if (!hash_equals($password, $confirmPassword)) {
        $_SESSION['user_auth_error'] = 'Passwords do not match.';

        return false;
    }

    if ($userModel->findByEmail($email) !== null) {
        $_SESSION['user_auth_error'] = 'An account with that email already exists.';

        return false;
    }

    $userId = $userModel->create($name, $email, $password);
    $user = $userModel->findById($userId);

    if ($user === null) {
        $_SESSION['user_auth_error'] = 'Your account could not be created. Please try again.';

        return false;
    }

    loginUserSession($user);
    $_SESSION['user_profile_success'] = 'Your account has been created.';

    return true;
}

function logoutUser(): void
{
    unset(
        $_SESSION['user_authenticated'],
        $_SESSION['user_id'],
        $_SESSION['user_name'],
        $_SESSION['user_email'],
        $_SESSION['user_auth_error'],
        $_SESSION['user_auth_success'],
        $_SESSION['user_profile_error'],
        $_SESSION['user_profile_success']
    );
}

function logoutCurrentAccount(): void
{
    logoutUser();
    logoutAdmin();
}

function getUserProfileError(): ?string
{
    $error = $_SESSION['user_profile_error'] ?? null;

    return is_string($error) && $error !== '' ? $error : null;
}

function getUserProfileSuccess(): ?string
{
    $message = $_SESSION['user_profile_success'] ?? null;

    return is_string($message) && $message !== '' ? $message : null;
}

function consumeUserProfileError(): ?string
{
    $error = getUserProfileError();
    unset($_SESSION['user_profile_error']);

    return $error;
}

function consumeUserProfileSuccess(): ?string
{
    $message = getUserProfileSuccess();
    unset($_SESSION['user_profile_success']);

    return $message;
}

function requireUserAuth(string $basePath): callable
{
    return function ($request, $handler) use ($basePath) {
        if (isUserAuthenticated()) {
            return $handler->handle($request);
        }

        return redirectTo(new \Slim\Psr7\Response(), $basePath . '/login');
    };
}
