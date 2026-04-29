<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../modules/key_tokens.php';
require_once __DIR__ . '/../modules/bitacora.php';
setSecurityHeaders();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
$user = requireAuth();
requireRole($user, ['administrador', 'supervisor']);
$body = getJsonBody();
$tipo = in_array($body['tipo'] ?? 'key', ['key','cer']) ? ($body['tipo'] ?? 'key') : 'key';
try {
    $token = generarToken((int)$user['id'], $tipo);
    registrarBitacora((int)$user['id'], 'token_generated', 'claves', null, null, "Token temporal generado (tipo: {$tipo})");
    jsonSuccess('Token generado (válido 10 minutos)', ['token' => $token, 'tipo' => $tipo, 'expires_in' => 600]);
} catch (\RuntimeException $e) {
    jsonError($e->getMessage(), 500);
}
