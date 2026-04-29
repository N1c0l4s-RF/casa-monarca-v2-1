<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../modules/documentos.php';
setSecurityHeaders();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
$user = requireAuth();
requireRole($user, ['administrador', 'emisor']);
$body = getJsonBody();
if (empty($body['titulo'])) jsonError('El título es requerido');
try {
    $id = crearDocumento((int)$user['id'], $body);
    jsonSuccess('Documento creado', ['documento_id' => $id], 201);
} catch (\RuntimeException $e) {
    jsonError($e->getMessage(), (int)($e->getCode() ?: 400));
}
