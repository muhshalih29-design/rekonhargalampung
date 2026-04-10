<?php
if (session_status() === PHP_SESSION_NONE) {
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
