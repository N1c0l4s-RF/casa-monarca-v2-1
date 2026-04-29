<?php
/**
 * GET /api/consulta_qr.php?token=FOLIO
 * Validación pública de documentos — sin autenticación
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../modules/claves.php';

setSecurityHeaders();

$folio = trim($_GET['token'] ?? '');

if (empty($folio)) {
    jsonError('Folio requerido. Usa ?token=DOC-XXXXXXXX');
}

$pdo  = getDB();
$stmt = $pdo->prepare('
    SELECT d.*, u.nombre as creador_nombre, f.nombre as firmante_nombre
    FROM documentos d
    JOIN usuarios u ON u.id = d.creado_por
    LEFT JOIN usuarios f ON f.id = d.firmado_por_usuario_id
    WHERE d.folio = ?
');
$stmt->execute([$folio]);
$doc = $stmt->fetch();

if (!$doc) {
    jsonError('Documento no encontrado', 404);
}

$firmaValida = false;
$mensajeFirma = 'Documento no emitido';

if ($doc['estado'] === 'emitido' && $doc['firmado_por_usuario_id']) {
    $publicKey = obtenerClavePublica((int)$doc['firmado_por_usuario_id']);
    if ($publicKey) {
        $contenidoFirmar = $doc['folio'] . '|' . $doc['contenido'];
        $firmaValida = verificarFirma($contenidoFirmar, $doc['hash_sha256'], $publicKey);
        $mensajeFirma = $firmaValida ? 'Firma digital válida ✓' : 'Firma digital inválida ✗';
    }
}

jsonSuccess('Consulta exitosa', [
    'documento' => [
        'folio'          => $doc['folio'],
        'titulo'         => $doc['titulo'],
        'estado'         => $doc['estado'],
        'creador'        => $doc['creador_nombre'],
        'firmante'       => $doc['firmante_nombre'],
        'fecha_creacion' => $doc['fecha_creacion'],
        'fecha_emision'  => $doc['fecha_emision'],
        'fecha_revocacion' => $doc['fecha_revocacion'],
        'motivo_revocacion' => $doc['motivo_revocacion'],
    ],
    'verificacion' => [
        'firma_valida'  => $firmaValida,
        'mensaje'       => $mensajeFirma,
    ]
]);
