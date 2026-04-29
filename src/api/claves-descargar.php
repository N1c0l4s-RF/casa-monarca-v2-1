<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../modules/claves.php';
require_once __DIR__ . '/../modules/key_tokens.php';
require_once __DIR__ . '/../modules/bitacora.php';
setSecurityHeaders();
$user = requireAuth();
requireRole($user, ['administrador', 'supervisor']);
$token = $_GET['token'] ?? '';
$tipo  = $_GET['tipo'] ?? 'key';
if (empty($token)) jsonError('Token requerido');
$tokenData = consumirToken($token, (int)$user['id']);
if (!$tokenData) {
    registrarBitacora((int)$user['id'], 'download_failed', 'claves', null, null, 'Token inválido, expirado o ya usado', 'failed', 'Token inválido');
    jsonError('Token inválido, expirado o ya usado', 401);
}
try {
    if ($tipo === 'cer') {
        $publicKey = obtenerClavePublica((int)$user['id']);
        registrarBitacora((int)$user['id'], 'downloaded', 'claves', null, null, 'Clave pública descargada');
        header('Content-Type: application/x-pem-file');
        header('Content-Disposition: attachment; filename="public_' . $user['id'] . '.cer"');
        echo $publicKey;
    } else {
        $privateKey = obtenerClavePrivadaDesencriptada((int)$user['id']);
        registrarDescargaClave((int)$user['id']);
        registrarBitacora((int)$user['id'], 'downloaded', 'claves', null, null, 'Clave privada descargada');
        header('Content-Type: application/x-pem-file');
        header('Content-Disposition: attachment; filename="private_' . $user['id'] . '.key"');
        echo $privateKey;
        // Limpiar de memoria
        $privateKey = str_repeat('0', strlen($privateKey));
    }
    exit;
} catch (\RuntimeException $e) {
    registrarBitacora((int)$user['id'], 'download_failed', 'claves', null, null, $e->getMessage(), 'failed', $e->getMessage());
    jsonError($e->getMessage(), 500);
}
