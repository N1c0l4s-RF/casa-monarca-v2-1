<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../modules/usuarios.php';
setSecurityHeaders();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
$user = requireAuth();
requireRole($user, ['administrador']);
$body = getJsonBody();
if (empty($body['usuario_id']) || empty($body['rol'])) jsonError('usuario_id y rol son requeridos');
try {
    cambiarRol((int)$body['usuario_id'], $body['rol'], (int)$user['id']);
    jsonSuccess('Rol actualizado');
} catch (\RuntimeException $e) {
    jsonError($e->getMessage(), (int)($e->getCode() ?: 400));
}
