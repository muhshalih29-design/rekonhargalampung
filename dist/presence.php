<?php
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$user = current_user();
if (!$user) {
    http_response_code(401);
    exit;
}

try {
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE users SET last_seen = NOW() WHERE id = ?');
    $stmt->execute([$user['id'] ?? 0]);
} catch (Throwable $e) {
    // noop
}

echo 'OK';
