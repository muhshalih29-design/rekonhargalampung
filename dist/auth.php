<?php
if (session_status() === PHP_SESSION_NONE) {
    $params = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));
    session_start();
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_auth(): array {
    $user = current_user();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    return $user;
}

function is_provinsi(array $user): bool {
    return ($user['role'] ?? '') === 'provinsi';
}

function is_kabupaten(array $user): bool {
    return ($user['role'] ?? '') === 'kabupaten';
}

function can_edit_penjelasan(array $user, string $kode_kabupaten): bool {
    if (is_provinsi($user)) return true;
    if (!is_kabupaten($user)) return false;
    return isset($user['kab_kode']) && (string)$user['kab_kode'] === (string)$kode_kabupaten;
}
