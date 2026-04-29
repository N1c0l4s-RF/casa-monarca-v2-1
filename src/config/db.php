<?php
/**
 * Casa Monarca v2 - Conexión a base de datos
 */

function getEnv(string $key, string $default = ''): string {
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $host = getEnv('DB_HOST', 'db');
    $port = getEnv('DB_PORT', '3306');
    $name = getEnv('DB_NAME', 'casa_monarca');
    $user = getEnv('DB_USER', 'app_user');
    $pass = getEnv('DB_PASS', 'secure_password_123');

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error de conexión a la base de datos']);
        exit;
    }

    return $pdo;
}
