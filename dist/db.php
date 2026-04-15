<?php
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $url = getenv('DATABASE_URL');
    if ($url) {
        $parts = parse_url($url);
        $host = $parts['host'] ?? 'localhost';
        $port = $parts['port'] ?? 5432;
        $user = $parts['user'] ?? 'postgres';
        $pass = $parts['pass'] ?? '';
        $db   = ltrim($parts['path'] ?? '/postgres', '/');
    } else {
        $host = getenv('PGHOST') ?: 'localhost';
        $port = getenv('PGPORT') ?: 5432;
        $db   = getenv('PGDATABASE') ?: 'postgres';
        $user = getenv('PGUSER') ?: 'postgres';
        $pass = getenv('PGPASSWORD') ?: '';
    }

    $host_lower = strtolower((string)$host);
    $is_supabase_pooler = str_contains($host_lower, '.pooler.supabase.com');
    $is_supabase_direct = str_contains($host_lower, '.supabase.co');
    $can_use_hostaddr = !$is_supabase_pooler && !$is_supabase_direct;

    if ($can_use_hostaddr) {
        $hostaddr = gethostbyname($host);
    } else {
        $hostaddr = false;
    }

    if ($hostaddr && $hostaddr !== $host) {
        $dsn = sprintf(
            'pgsql:host=%s;hostaddr=%s;port=%s;dbname=%s;sslmode=require;connect_timeout=12',
            $host,
            $hostaddr,
            $port,
            $db
        );
    } else {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=require;connect_timeout=12',
            $host,
            $port,
            $db
        );
    }

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 12,
    ]);
    return $pdo;
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT to_regclass('public.' || ?) AS reg");
    $stmt->execute([$table]);
    $row = $stmt->fetch();
    return !empty($row['reg']);
}
