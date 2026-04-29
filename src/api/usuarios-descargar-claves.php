<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../modules/claves.php';
require_once __DIR__ . '/../modules/bitacora.php';
setSecurityHeaders();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
$user = requireAuth();
requireRole($user, ['administrador']);
$body = getJsonBody();
if (empty($body['usuario_id'])) jsonError('usuario_id es requerido');
$targetId = (int)$body['usuario_id'];
try {
    $result = generarLlavesRSA($targetId);
    registrarBitacora((int)$user['id'], 'generated', 'claves', null, null, "Llaves RSA generadas para usuario #{$targetId}");
    jsonSuccess('Llaves RSA generadas', $result);
} catch (\RuntimeException $e) {
    jsonError($e->getMessage(), 500);
}
