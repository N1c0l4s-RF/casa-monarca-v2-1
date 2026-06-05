<?php
/**
 * GET /api/documentos-firmantes-list.php?id=42
 * Devuelve la cadena de firmantes con su estado y a quién le toca firmar.
 */
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../modules/firmantes.php';

setSecurityHeaders();
$user = requireAuth();

$docId = (int)($_GET['id'] ?? 0);
if (!$docId) jsonError('id es requerido');

$firmantes = obtenerFirmantes($docId);
$actual = obtenerFirmanteActual($docId);

jsonSuccess('OK', [
    'firmantes'              => $firmantes,
    'siguiente_usuario_id'   => $actual['usuario_id'] ?? null,
    'siguiente_orden'        => $actual['orden'] ?? null,
    'es_multifirma'          => count($firmantes) > 0,
    'total_firmantes'        => count($firmantes),
    'firmas_completadas'     => count(array_filter($firmantes, fn($f) => $f['estado'] === 'firmado')),
]);
