<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../modules/documentos.php';
setSecurityHeaders();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
$user = requireAuth();
requireRole($user, ['administrador', 'emisor']);
$body = getJsonBody();
if (empty($body['id']) || empty($body['titulo'])) jsonError('id y titulo son requeridos');
try {
    actualizarDocumento((int)$body['id'], (int)$user['id'], $body);
    jsonSuccess('Documento actualizado');
} catch (\RuntimeException $e) {
    jsonError($e->getMessage(), (int)($e->getCode() ?: 400));
}
