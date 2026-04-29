<?php
/**
 * POST /auth/logout
 */
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../modules/bitacora.php';

setSecurityHeaders();
startSecureSession();

$userId = $_SESSION['usuario_id'] ?? null;
if ($userId) {
    registrarBitacora($userId, 'logout', 'auth', null, null, 'Cierre de sesión');
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'], $params['secure'], $params['httponly']
    );
}
session_destroy();

jsonSuccess('Sesión cerrada correctamente');
