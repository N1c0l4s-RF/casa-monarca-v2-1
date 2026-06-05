<?php
/**
 * POST /api/documentos-solicitar-firma.php
 * Paso 1: calcula el hash a firmar y lo guarda en firma_sessions (DB).
 *
 * Si el documento tiene firmantes asignados (modo multi-firma), solo
 * puede solicitar el hash el firmante actual (el de menor orden con
 * estado pendiente), y el contenido a firmar encadena con la firma
 * del turno anterior.
 *
 * Body: { "id": 42 }
 * Response: { "hash": "...", "session_id": N, "orden": K }
 */
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../modules/bitacora.php';
require_once __DIR__ . '/../modules/firmantes.php';

setSecurityHeaders();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
$user = requireAuth();
requireRole($user, ['administrador', 'supervisor', 'emisor']);

$body = getJsonBody();
$docId = (int)($body['id'] ?? 0);
if (!$docId) jsonError('id es requerido');

$pdo  = getDB();
$stmt = $pdo->prepare('SELECT id, folio, contenido, estado, creado_por, ruta_archivo, hash_sha256 FROM documentos WHERE id = ?');
$stmt->execute([$docId]);
$doc = $stmt->fetch();

if (!$doc) jsonError('Documento no encontrado', 404);

// Verificar que el usuario tiene clave pública registrada
$clave = $pdo->prepare('SELECT fingerprint FROM usuario_claves WHERE usuario_id = ? AND activo = 1');
$clave->execute([$user['id']]);
if (!$clave->fetch()) {
    jsonError('No tienes una clave pública registrada. Ve a Gestión de Llaves primero.', 422);
}

$multifirma = tieneFirmantes($docId);
$orden = 1;
$firmaPrevia = null;

if ($multifirma) {
    if ($doc['estado'] !== 'en_firma') {
        jsonError('Este documento no está en proceso de firma', 400);
    }
    $actual = obtenerFirmanteActual($docId);
    if (!$actual) jsonError('No quedan firmas pendientes', 400);
    if ((int)$actual['usuario_id'] !== (int)$user['id']) {
        jsonError('No es tu turno de firmar. Espera a que firmen los firmantes anteriores.', 403);
    }
    $orden = (int)$actual['orden'];
    $firmaPrevia = obtenerFirmaPrevia($docId, $orden);
    $contenidoAFirmar = construirContenidoAFirmar($doc, $orden, $firmaPrevia);
} else {
    // Legacy: una sola firma, debe ser distinta al creador
    if ($doc['estado'] !== 'borrador') jsonError('Solo se pueden emitir documentos en borrador', 400);
    if ((int)$doc['creado_por'] === (int)$user['id'] && $user['rol'] !== 'administrador') {
        jsonError('No puedes firmar tu propio documento. Debe ser firmado por un usuario diferente al creador.', 403);
    }
    $contenidoAFirmar = !empty($doc['ruta_archivo']) && !empty($doc['hash_sha256'])
        ? $doc['folio'] . '|' . $doc['hash_sha256']
        : $doc['folio'] . '|' . ($doc['contenido'] ?? '');
}

$hashHex = hash('sha256', $contenidoAFirmar);

// Limpiar sesiones previas del mismo usuario+documento
$pdo->prepare('DELETE FROM firma_sessions WHERE usuario_id = ? AND documento_id = ?')
    ->execute([$user['id'], $docId]);

$ins = $pdo->prepare('
    INSERT INTO firma_sessions (usuario_id, documento_id, hash_hex, expires_at)
    VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))
');
$ins->execute([$user['id'], $docId, $hashHex]);
$sessionId = $pdo->lastInsertId();

jsonSuccess('Hash listo para firmar', [
    'hash'         => $hashHex,
    'session_id'   => (int)$sessionId,
    'folio'        => $doc['folio'],
    'algoritmo'    => 'ECDSA P-256 + SHA-256',
    'es_multifirma'=> $multifirma,
    'orden'        => $orden,
]);
