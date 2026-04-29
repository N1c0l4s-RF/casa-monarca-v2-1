<?php
/**
 * GET /auth/session
 */
require_once __DIR__ . '/../auth/middleware.php';

setSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Método no permitido', 405);
}

$user = requireAuth();

jsonSuccess('Sesión activa', [
    'usuario' => [
        'id'     => $user['id'],
        'nombre' => $user['nombre'],
        'email'  => $user['email'],
        'rol'    => $user['rol'],
    ]
]);
