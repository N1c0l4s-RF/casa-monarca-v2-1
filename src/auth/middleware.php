<?php
/**
 * Casa Monarca v2 - Middleware de autenticación y seguridad
 */

require_once __DIR__ . '/../config/db.php';

function setSecurityHeaders(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (!empty($_SERVER['HTTPS'])) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $lifetime = (int) getEnv('SESSION_LIFETIME', '3600');
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function requireAuth(): array {
    startSecureSession();
    if (empty($_SESSION['usuario_id'])) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'No autenticado']);
        exit;
    }
    // Verificar que el usuario siga activo en BD
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT id, nombre, email, rol, activo FROM usuarios WHERE id = ? AND activo = 1');
    $stmt->execute([$_SESSION['usuario_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        session_destroy();
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Sesión inválida o usuario desactivado']);
        exit;
    }
    return $user;
}

function requireRole(array $user, array $rolesPermitidos): void {
    if (!in_array($user['rol'], $rolesPermitidos, true)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Sin permisos para esta operación']);
        exit;
    }
}

function getClientIP(): string {
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            return explode(',', $_SERVER[$key])[0];
        }
    }
    return '0.0.0.0';
}

function getUserAgent(): string {
    return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
}

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $message, int $code = 400): void {
    jsonResponse(['status' => 'error', 'message' => $message], $code);
}

function jsonSuccess(string $message, array $data = [], int $code = 200): void {
    jsonResponse(array_merge(['status' => 'success', 'message' => $message], $data ? ['data' => $data] : []), $code);
}

function getJsonBody(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw)) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
