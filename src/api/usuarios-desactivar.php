<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../modules/usuarios.php';
setSecurityHeaders();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
$user = requireAuth();
requireRole($user, ['administrador']);
$body = getJsonBody();
if (empty($body['usuario_id'])) jsonError('usuario_id es requerido');
try {
    desactivarUsuario((int)$body['usuario_id'], (int)$user['id']);
    jsonSuccess('Usuario desactivado');
} catch (\RuntimeException $e) {
    jsonError($e->getMessage(), (int)($e->getCode() ?: 400));
}
