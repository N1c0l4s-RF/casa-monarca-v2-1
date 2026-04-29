<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../modules/documentos.php';
setSecurityHeaders();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
$user = requireAuth();
requireRole($user, ['administrador', 'supervisor']);
$body = getJsonBody();
if (empty($body['id']) || empty($body['motivo'])) jsonError('id y motivo son requeridos');
try {
    revocarDocumento((int)$body['id'], (int)$user['id'], $body['motivo']);
    jsonSuccess('Documento revocado');
} catch (\RuntimeException $e) {
    jsonError($e->getMessage(), (int)($e->getCode() ?: 400));
}
