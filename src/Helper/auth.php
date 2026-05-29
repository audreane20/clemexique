<?php

use App\Helper\Locale;
use Psr\Http\Message\ResponseInterface as Response;

function authTrans(string $key): string
{
    static $messagesByLang = [];

    $lang = Locale::normalize($_SESSION['lang'] ?? Locale::DEFAULT);

    if (!isset($messagesByLang[$lang])) {
        $messagesByLang[$lang] = require __DIR__ . '/../translations/messages.' . $lang . '.php';
    }

    return $messagesByLang[$lang][$key] ?? $key;
}

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

function getAuthSuccess(): ?string
{
    $message = $_SESSION['auth_success'] ?? null;

    return is_string($message) && $message !== '' ? $message : null;
}

function getLastAuthUsername(): string
{
    $username = $_SESSION['auth_last_username'] ?? '';

    return is_string($username) ? $username : '';
}

function clearAuthFlash(): void
{
    unset($_SESSION['auth_error'], $_SESSION['auth_success'], $_SESSION['auth_last_username']);
}

function isAdminVerificationPending(): bool
{
    return !empty($_SESSION['admin_2fa_pending']);
}

function getAdminTwoFactorEmail(): string
{
    $configuredEmail = trim((string) ($_ENV['ADMIN_2FA_EMAIL'] ?? ''));

    if ($configuredEmail !== '') {
        return $configuredEmail;
    }

    return trim((string) ($_ENV['MAIL_TO_EMAIL'] ?? ''));
}

function getAdminBackupTwoFactorEmail(): string
{
    return trim((string) ($_ENV['ADMIN_2FA_BACKUP_EMAIL'] ?? ''));
}

function hasAdminBackupTwoFactorEmail(): bool
{
    $email = getAdminBackupTwoFactorEmail();

    return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function getAdminTwoFactorRecipients(): array
{
    $recipients = array_values(array_unique(array_filter([
        getAdminTwoFactorEmail(),
        getAdminBackupTwoFactorEmail(),
    ], static function ($email) {
        return is_string($email) && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
    })));

    return $recipients;
}

function getMaskedAdminTwoFactorRecipients(): string
{
    $masked = array_map('maskEmailAddress', getAdminTwoFactorRecipients());

    return implode(', ', $masked);
}

function getMaskedPrimaryAdminTwoFactorEmail(): string
{
    return maskEmailAddress(getAdminTwoFactorEmail());
}

function maskEmailAddress(string $email): string
{
    $email = trim($email);

    if ($email === '' || !str_contains($email, '@')) {
        return $email;
    }

    [$localPart, $domainPart] = explode('@', $email, 2);
    $maskedLocal = strlen($localPart) <= 2
        ? substr($localPart, 0, 1) . str_repeat('*', max(strlen($localPart) - 1, 1))
        : substr($localPart, 0, 2) . str_repeat('*', max(strlen($localPart) - 4, 2)) . substr($localPart, -2);

    $domainSegments = explode('.', $domainPart);
    $domainName = array_shift($domainSegments) ?? '';
    $domainSuffix = implode('.', $domainSegments);
    $maskedDomainName = strlen($domainName) <= 2
        ? substr($domainName, 0, 1) . str_repeat('*', max(strlen($domainName) - 1, 1))
        : substr($domainName, 0, 1) . str_repeat('*', max(strlen($domainName) - 2, 3)) . substr($domainName, -1);

    return $maskedLocal . '@' . $maskedDomainName . ($domainSuffix !== '' ? '.' . $domainSuffix : '');
}

function getAdminIdentityEmail(): string
{
    $email = getAdminTwoFactorEmail();

    return $email !== '' ? $email : 'admin';
}

function getAdminIdentityDisplayName(): string
{
    $name = trim((string) ($_ENV['ADMIN_DISPLAY_NAME'] ?? 'Admin'));

    return $name !== '' ? $name : 'Admin';
}

function configureAuthMailer(): \PHPMailer\PHPMailer\PHPMailer
{
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $_ENV['MAIL_HOST'] ?? '';
    $mail->SMTPAuth = true;
    $mail->Username = $_ENV['MAIL_USERNAME'] ?? '';
    $mail->Password = $_ENV['MAIL_PASSWORD'] ?? '';
    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = (int) ($_ENV['MAIL_PORT'] ?? 587);
    $mail->CharSet = 'UTF-8';

    return $mail;
}

function sendAdminVerificationCodeToRecipient(string $toEmail, string $code): bool
{
    $fromEmail = $_ENV['MAIL_FROM_EMAIL'] ?? ($_ENV['MAIL_USERNAME'] ?? '');
    $fromName = $_ENV['MAIL_FROM_NAME'] ?? 'CLeMexique';

    if ($toEmail === '') {
        return false;
    }

    try {
        $mail = configureAuthMailer();
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail);
        $mail->Subject = authTrans('admin.2fa_mail_subject');
        $mail->Body = authTrans('admin.2fa_mail_intro') . "\n\n"
            . authTrans('admin.verification_code') . ': ' . $code . "\n\n"
            . authTrans('admin.2fa_mail_expiry');
        $mail->send();

        return true;
    } catch (\PHPMailer\PHPMailer\Exception $exception) {
        return false;
    }
}

function sendAdminVerificationCode(string $code): bool
{
    $primaryEmail = getAdminTwoFactorEmail();

    if ($primaryEmail === '' || filter_var($primaryEmail, FILTER_VALIDATE_EMAIL) === false) {
        $_SESSION['auth_error'] = authTrans('auth.admin_2fa_not_configured');

        return false;
    }

    if (!sendAdminVerificationCodeToRecipient($primaryEmail, $code)) {
        $_SESSION['auth_error'] = authTrans('auth.admin_2fa_send_failed');

        return false;
    }

    return true;
}

function resendPendingAdminVerificationCodeToBackup(): bool
{
    if (!isAdminVerificationPending()) {
        $_SESSION['auth_error'] = authTrans('auth.admin_2fa_session_missing');

        return false;
    }

    if (!hasAdminBackupTwoFactorEmail()) {
        $_SESSION['auth_error'] = authTrans('auth.admin_2fa_not_configured');

        return false;
    }

    $expiresAt = (int) ($_SESSION['admin_2fa_expires_at'] ?? 0);

    if ($expiresAt < time()) {
        clearAdminTwoFactorPending();
        $_SESSION['auth_error'] = authTrans('auth.admin_2fa_code_expired');

        return false;
    }

    $code = (string) ($_SESSION['admin_2fa_code'] ?? '');

    if ($code === '') {
        $_SESSION['auth_error'] = authTrans('auth.admin_2fa_session_missing');

        return false;
    }

    if (!sendAdminVerificationCodeToRecipient(getAdminBackupTwoFactorEmail(), $code)) {
        $_SESSION['auth_error'] = authTrans('auth.admin_2fa_send_failed');

        return false;
    }

    $_SESSION['auth_success'] = authTrans('admin.verify_backup_sent');

    return true;
}

function startAdminEmailVerificationSession(): bool
{
    $identityEmail = getAdminIdentityEmail();
    $identityDisplayName = getAdminIdentityDisplayName();
    $verificationCode = (string) random_int(100000, 999999);

    if (!sendAdminVerificationCode($verificationCode)) {
        return false;
    }

    $_SESSION['admin_2fa_pending'] = true;
    $_SESSION['admin_2fa_username'] = $identityEmail;
    $_SESSION['admin_2fa_display_name'] = $identityDisplayName;
    $_SESSION['admin_2fa_code'] = $verificationCode;
    $_SESSION['admin_2fa_expires_at'] = time() + 600;

    clearAuthFlash();

    return true;
}

function updateEnvSetting(string $filePath, string $key, string $value): bool
{
    $value = trim($value);

    if ($value === '') {
        return false;
    }

    $contents = file_exists($filePath) ? (string) file_get_contents($filePath) : '';
    $escapedKey = preg_quote($key, '/');
    $replacementLine = $key . '=' . $value;

    if (preg_match('/^' . $escapedKey . '=.*$/m', $contents) === 1) {
        $updatedContents = preg_replace('/^' . $escapedKey . '=.*$/m', $replacementLine, $contents, 1);
    } else {
        $updatedContents = rtrim($contents);
        $updatedContents .= ($updatedContents === '' ? '' : PHP_EOL) . $replacementLine . PHP_EOL;
    }

    if (!is_string($updatedContents)) {
        return false;
    }

    return file_put_contents($filePath, $updatedContents) !== false;
}

function isAdminEmailChangePending(): bool
{
    return !empty($_SESSION['admin_email_change_pending']);
}

function clearAdminEmailChangePending(): void
{
    unset(
        $_SESSION['admin_email_change_pending'],
        $_SESSION['admin_email_change_new'],
        $_SESSION['admin_email_change_current'],
        $_SESSION['admin_email_change_current_code'],
        $_SESSION['admin_email_change_new_code'],
        $_SESSION['admin_email_change_expires_at']
    );
}

function startAdminEmailChangeVerification(string $newEmail): bool
{
    $currentEmail = getAdminTwoFactorEmail();
    $currentRecipients = getAdminTwoFactorRecipients();

    if ($currentRecipients === []) {
        $_SESSION['auth_error'] = authTrans('auth.admin_2fa_not_configured');

        return false;
    }

    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['auth_error'] = authTrans('auth.register_invalid_email');

        return false;
    }

    if (strcasecmp($currentEmail, $newEmail) === 0) {
        $_SESSION['auth_error'] = authTrans('admin.change_email_same');

        return false;
    }

    $currentCode = (string) random_int(100000, 999999);
    $newCode = (string) random_int(100000, 999999);

    $sentCurrentCode = false;

    foreach ($currentRecipients as $recipient) {
        $sentCurrentCode = sendAdminVerificationCodeToRecipient($recipient, $currentCode) || $sentCurrentCode;
    }

    if (!$sentCurrentCode) {
        $_SESSION['auth_error'] = authTrans('auth.admin_2fa_send_failed');

        return false;
    }

    if (!sendAdminVerificationCodeToRecipient($newEmail, $newCode)) {
        $_SESSION['auth_error'] = authTrans('admin.change_email_new_send_failed');

        return false;
    }

    $_SESSION['admin_email_change_pending'] = true;
    $_SESSION['admin_email_change_new'] = $newEmail;
    $_SESSION['admin_email_change_current'] = $currentEmail;
    $_SESSION['admin_email_change_current_code'] = $currentCode;
    $_SESSION['admin_email_change_new_code'] = $newCode;
    $_SESSION['admin_email_change_expires_at'] = time() + 600;

    clearAuthFlash();

    return true;
}

function attemptAdminEmailChangeVerification(array $data): bool
{
    $currentCode = trim((string) ($data['current_code'] ?? ''));
    $newCode = trim((string) ($data['new_code'] ?? ''));
    $expectedCurrentCode = (string) ($_SESSION['admin_email_change_current_code'] ?? '');
    $expectedNewCode = (string) ($_SESSION['admin_email_change_new_code'] ?? '');
    $newEmail = trim((string) ($_SESSION['admin_email_change_new'] ?? ''));
    $expiresAt = (int) ($_SESSION['admin_email_change_expires_at'] ?? 0);

    if (!isAdminAuthenticated()) {
        $_SESSION['auth_error'] = authTrans('auth.admin_2fa_session_missing');

        return false;
    }

    if (!isAdminEmailChangePending() || $expectedCurrentCode === '' || $expectedNewCode === '' || $newEmail === '') {
        $_SESSION['auth_error'] = authTrans('admin.change_email_session_missing');

        return false;
    }

    if ($expiresAt < time()) {
        clearAdminEmailChangePending();
        $_SESSION['auth_error'] = authTrans('admin.change_email_code_expired');

        return false;
    }

    if (!hash_equals($expectedCurrentCode, $currentCode) || !hash_equals($expectedNewCode, $newCode)) {
        $_SESSION['auth_error'] = authTrans('admin.change_email_invalid_codes');

        return false;
    }

    $envPath = __DIR__ . '/../../.env';

    if (!updateEnvSetting($envPath, 'ADMIN_2FA_EMAIL', $newEmail)) {
        $_SESSION['auth_error'] = authTrans('admin.change_email_failed');

        return false;
    }

    $_ENV['ADMIN_2FA_EMAIL'] = $newEmail;
    $_SESSION['admin_username'] = getAdminIdentityEmail();

    clearAdminEmailChangePending();
    $_SESSION['auth_success'] = authTrans('admin.change_email_success');

    return true;
}

function attemptAdminLogin(array $data): bool
{
    $pin = trim((string) ($data['pin'] ?? ''));
    $configuredPin = trim((string) ($_ENV['ADMIN_PIN'] ?? '1690'));

    if (!hash_equals($configuredPin, $pin)) {
        $_SESSION['auth_error'] = authTrans('auth.invalid_admin_pin');

        return false;
    }

    return startAdminEmailVerificationSession();
}

function attemptAdminTwoFactorVerification(array $data): bool
{
    $code = trim((string) ($data['code'] ?? ''));
    $expectedCode = (string) ($_SESSION['admin_2fa_code'] ?? '');
    $expiresAt = (int) ($_SESSION['admin_2fa_expires_at'] ?? 0);
    $pendingUsername = (string) ($_SESSION['admin_2fa_username'] ?? '');
    $pendingDisplayName = (string) ($_SESSION['admin_2fa_display_name'] ?? $pendingUsername);

    if (!isAdminVerificationPending() || $expectedCode === '' || $pendingUsername === '') {
        $_SESSION['auth_error'] = authTrans('auth.admin_2fa_session_missing');

        return false;
    }

    if ($expiresAt < time()) {
        clearAdminTwoFactorPending();
        $_SESSION['auth_error'] = authTrans('auth.admin_2fa_code_expired');

        return false;
    }

    if (!hash_equals($expectedCode, $code)) {
        $_SESSION['auth_error'] = authTrans('auth.invalid_admin_code');

        return false;
    }

    $_SESSION['admin_authenticated'] = true;
    $_SESSION['admin_username'] = $pendingUsername;
    $_SESSION['admin_display_name'] = $pendingDisplayName;

    clearAdminTwoFactorPending();
    clearAuthFlash();

    return true;
}

function clearAdminTwoFactorPending(): void
{
    unset(
        $_SESSION['admin_2fa_pending'],
        $_SESSION['admin_2fa_username'],
        $_SESSION['admin_2fa_display_name'],
        $_SESSION['admin_2fa_code'],
        $_SESSION['admin_2fa_expires_at']
    );
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
    clearAdminTwoFactorPending();
    clearAdminEmailChangePending();
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
        $_SESSION['user_auth_error'] = authTrans('auth.user_missing_credentials');

        return false;
    }

    $user = $userModel->verifyCredentials($email, $password);

    if ($user === null) {
        $_SESSION['user_auth_error'] = authTrans('auth.user_invalid_credentials');

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
        $_SESSION['user_auth_error'] = authTrans('auth.register_missing_fields');

        return false;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['user_auth_error'] = authTrans('auth.register_invalid_email');

        return false;
    }

    if (strlen($password) < 8) {
        $_SESSION['user_auth_error'] = authTrans('auth.register_password_length');

        return false;
    }

    if (!hash_equals($password, $confirmPassword)) {
        $_SESSION['user_auth_error'] = authTrans('auth.register_password_match');

        return false;
    }

    if ($userModel->findByEmail($email) !== null) {
        $_SESSION['user_auth_error'] = authTrans('auth.register_email_exists');

        return false;
    }

    $userId = $userModel->create($name, $email, $password);
    $user = $userModel->findById($userId);

    if ($user === null) {
        $_SESSION['user_auth_error'] = authTrans('auth.register_create_failed');

        return false;
    }

    loginUserSession($user);
    $_SESSION['user_profile_success'] = authTrans('auth.register_success');

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
