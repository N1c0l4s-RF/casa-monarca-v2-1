<?php
/**
 * POST /api/documentos-firmantes-asignar.php
 * Body: { "id": 42, "firmantes": [3, 7, 5] }
 *
 * Asigna la cadena ordenada de firmantes a un documento en borrador.
 * El orden del array es el orden de firma (firmantes[0] firma primero).
 */
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../modules/firmantes.php';
require_once __DIR__ . '/../modules/bitacora.php';

setSecurityHeaders();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
$user = requireAuth();
requireRole($user, ['administrador', 'emisor']);

$body = getJsonBody();
$docId = (int)($body['id'] ?? 0);
$firmantes = $body['firmantes'] ?? null;

if (!$docId) jsonError('id es requerido');
if (!is_array($firmantes) || !$firmantes) jsonError('firmantes debe ser un arreglo no vacío');

$ids = array_map('intval', $firmantes);

// Solo el creador del doc (o admin) puede asignar firmantes
$pdo = getDB();
$stmt = $pdo->prepare('SELECT creado_por, folio FROM documentos WHERE id = ?');
$stmt->execute([$docId]);
$doc = $stmt->fetch();
if (!$doc) jsonError('Documento no encontrado', 404);
if ((int)$doc['creado_por'] !== (int)$user['id'] && $user['rol'] !== 'administrador') {
    jsonError('Solo el creador del documento puede asignar firmantes', 403);
}

try {
    asignarFirmantes($docId, $ids, (int)$doc['creado_por']);
} catch (\RuntimeException $e) {
    jsonError($e->getMessage(), $e->getCode() ?: 400);
}

registrarBitacora(
    (int)$user['id'], 'firmantes_asignados', 'documentos', $docId, $doc['folio'],
    'Asignados ' . count($ids) . ' firmantes en orden: ' . implode(' → ', $ids)
);

jsonSuccess('Firmantes asignados', ['firmantes' => obtenerFirmantes($docId)]);
