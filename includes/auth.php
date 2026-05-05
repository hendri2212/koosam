<?php
declare(strict_types=1);

require_once __DIR__ . '/masook_api.php';

function start_app_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function current_masook_session(): ?array
{
    start_app_session();

    if (!empty($_SESSION['masook_logged_out'])) {
        return null;
    }

    $session = null;
    $sessionUsername = (string) ($_SESSION['masook_username'] ?? '');
    $sessionUserId = (string) ($_SESSION['masook_user_id'] ?? '');

    if ($sessionUserId !== '') {
        $session = find_masook_session_by_user_id($sessionUserId);
    }

    if ($session === null && $sessionUsername !== '') {
        $session = find_masook_session_by_username($sessionUsername);
    }

    if ($session === null) {
        $session = find_latest_masook_session();
    }

    return $session;
}

function sync_masook_session_to_php(array $session): void
{
    start_app_session();

    $_SESSION['masook_username'] = (string) ($session['username'] ?? '');
    $_SESSION['masook_user_id'] = (string) ($session['user_id'] ?? '');
}

function valid_masook_session(): ?array
{
    $session = current_masook_session();
    if ($session === null) {
        return null;
    }

    if (masook_session_is_expired($session)) {
        return null;
    }

    sync_masook_session_to_php($session);

    return $session;
}

function require_masook_login(): array
{
    $session = valid_masook_session();
    if ($session !== null) {
        return $session;
    }

    $next = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    header('Location: login.php?next=' . rawurlencode($next));
    exit;
}

function redirect_if_masook_logged_in(): void
{
    if (valid_masook_session() !== null) {
        header('Location: index.php');
        exit;
    }
}

function logout_masook_session(): void
{
    start_app_session();
    unset($_SESSION['masook_username'], $_SESSION['masook_user_id']);
    $_SESSION['masook_logged_out'] = true;
}
