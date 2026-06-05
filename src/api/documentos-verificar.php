<?php
/**
 * GET /api/documentos-verificar.php?folio=DOC-XXXXXXXX-XXXXXX
 * Verificación pública de documentos — no requiere autenticación.
 * Devuelve estado, emisor, fecha y firma si el documento existe y está emitido.
 */
require_once __DIR__ . '/../auth/middleware.php';

setSecurityHeaders();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonError('Método no permitido', 405);

$folio = trim($_GET['folio'] ?? '');
if (!$folio) jsonError('folio es requerido', 400);

$pdo  = getDB();
$stmt = $pdo->prepare('
    SELECT d.folio, d.titulo, d.estado, d.fecha_emision, d.firma,
           d.creado_por, d.firmado_por_usuario_id,
           d.ruta_archivo, d.hash_sha256,
           uc.nombre AS creador_nombre,
           uf.nombre AS firmante_nombre
    FROM documentos d
    JOIN usuarios uc ON uc.id = d.creado_por
    LEFT JOIN usuarios uf ON uf.id = d.firmado_por_usuario_id
    WHERE d.folio = ?
    LIMIT 1
');
$stmt->execute([$folio]);
$doc = $stmt->fetch();

if (!$doc) {
    jsonSuccess('Documento no encontrado', [
        'valido'  => false,
        'mensaje' => 'El folio ingresado no corresponde a ningún documento registrado.',
    ]);
}

if ($doc['estado'] !== 'emitido') {
    jsonSuccess('Documento no emitido', [
        'valido'  => false,
        'mensaje' => "El documento existe pero su estado es \"{$doc['estado']}\". Solo los documentos emitidos pueden verificarse.",
    ]);
}

jsonSuccess('Documento verificado', [
    'valido'    => true,
    'documento' => [
        'folio'        => $doc['folio'],
        'titulo'       => $doc['titulo'],
        'estado'       => $doc['estado'],
        'creador'      => $doc['creador_nombre'],
        'emisor'       => $doc['firmante_nombre'],
        'fecha_emision'=> $doc['fecha_emision'],
        'firma'        => $doc['firma'],
        'algoritmo'    => 'ECDSA P-256',
        'tiene_pdf'    => !empty($doc['ruta_archivo']),
        'hash_sha256'  => $doc['hash_sha256'] ?: null,
    ],
]);
