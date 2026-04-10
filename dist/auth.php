<?php
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    $params = session_get_cookie_params();
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => $is_https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));

    $session_storage_ready = false;
    try {
        $pdo = db();
        $pdo->exec('CREATE TABLE IF NOT EXISTS app_sessions (id VARCHAR(128) PRIMARY KEY, data TEXT NOT NULL, expires BIGINT NOT NULL)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS app_sessions_expires_idx ON app_sessions (expires)');

        session_set_save_handler(
            function () { return true; },
            function () { return true; },
            function ($id) use ($pdo) {
                $now = time();
                $stmt = $pdo->prepare('SELECT data FROM app_sessions WHERE id = ? AND expires >= ?');
                $stmt->execute([$id, $now]);
                $row = $stmt->fetch();
                return $row ? $row['data'] : '';
            },
            function ($id, $data) use ($pdo) {
                $lifetime = (int)ini_get('session.gc_maxlifetime');
                $expires = time() + $lifetime;
                $stmt = $pdo->prepare('INSERT INTO app_sessions (id, data, expires) VALUES (?, ?, ?) ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, expires = EXCLUDED.expires');
                return $stmt->execute([$id, $data, $expires]);
            },
            function ($id) use ($pdo) {
                $stmt = $pdo->prepare('DELETE FROM app_sessions WHERE id = ?');
                return $stmt->execute([$id]);
            },
            function ($maxlifetime) use ($pdo) {
                $cutoff = time() - (int)$maxlifetime;
                $stmt = $pdo->prepare('DELETE FROM app_sessions WHERE expires < ?');
                return $stmt->execute([$cutoff]);
            }
        );
        $session_storage_ready = true;
    } catch (Throwable $e) {
        $session_storage_ready = false;
    }

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
