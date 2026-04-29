<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../modules/documentos.php';
setSecurityHeaders();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
$user = requireAuth();
$body = getJsonBody();
if (empty($body['id'])) jsonError('id es requerido');
try {
    eliminarDocumento((int)$body['id'], (int)$user['id']);
    jsonSuccess('Documento eliminado');
} catch (\RuntimeException $e) {
    jsonError($e->getMessage(), (int)($e->getCode() ?: 400));
}
