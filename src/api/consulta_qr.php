<?php
/**
 * GET /api/consulta_qr.php?token=FOLIO
 * Validación pública de documentos — sin autenticación.
 *
 * Soporta firma única (legacy) y firmas múltiples encadenadas: en este
 * último caso se verifica cada firma en orden y solo se considera
 * válido si TODAS las firmas pasan.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../modules/claves.php';
require_once __DIR__ . '/../modules/firmantes.php';

setSecurityHeaders();

$folio = trim($_GET['token'] ?? '');
if (empty($folio)) jsonError('Folio requerido. Usa ?token=DOC-XXXXXXXX');

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
if (!$doc) jsonError('Documento no encontrado', 404);

$firmaValida  = false;
$mensajeFirma = 'Documento no emitido';
$detalleFirmas = [];

$firmantes = obtenerFirmantes((int)$doc['id']);
$esMultifirma = count($firmantes) > 0;

if ($doc['estado'] === 'emitido') {
    if ($esMultifirma) {
        // Validar cadena encadenada
        $todasOk = true;
        $firmaPrevia = null;
        foreach ($firmantes as $f) {
            $pubKey = obtenerClavePublica((int)$f['usuario_id']);
            $contenido = construirContenidoAFirmar($doc, (int)$f['orden'], $firmaPrevia);
            $ok = $pubKey && verificarFirmaECDSA($contenido, (string)$f['firma'], $pubKey);
            $detalleFirmas[] = [
                'orden'        => (int)$f['orden'],
                'firmante'     => $f['nombre'],
                'rol'          => $f['rol'],
                'fecha_firma'  => $f['fecha_firma'],
                'firma_valida' => (bool)$ok,
            ];
            if (!$ok) { $todasOk = false; break; }
            $firmaPrevia = $f['firma'];
        }
        $firmaValida  = $todasOk;
        $mensajeFirma = $todasOk
            ? 'Cadena de ' . count($firmantes) . ' firmas válidas ✓'
            : 'La cadena de firmas no es válida ✗';
    } elseif ($doc['firmado_por_usuario_id']) {
        $pubKey = obtenerClavePublica((int)$doc['firmado_por_usuario_id']);
        $contenido = !empty($doc['ruta_archivo']) && !empty($doc['hash_sha256'])
            ? $doc['folio'] . '|' . $doc['hash_sha256']
            : $doc['folio'] . '|' . ($doc['contenido'] ?? '');
        // Soporta ECDSA (hex) y RSA legacy (base64 PEM) según formato detectado
        if (ctype_xdigit((string)$doc['firma']) && ctype_xdigit($pubKey)) {
            $firmaValida = verificarFirmaECDSA($contenido, $doc['firma'], $pubKey);
        } else {
            $firmaValida = $pubKey && verificarFirma($contenido, $doc['firma'], $pubKey);
        }
        $mensajeFirma = $firmaValida ? 'Firma digital válida ✓' : 'Firma digital inválida ✗';
    }
}

jsonSuccess('Consulta exitosa', [
    'documento' => [
        'folio'             => $doc['folio'],
        'titulo'            => $doc['titulo'],
        'estado'            => $doc['estado'],
        'creador'           => $doc['creador_nombre'],
        'firmante'          => $doc['firmante_nombre'],
        'fecha_creacion'    => $doc['fecha_creacion'],
        'fecha_emision'     => $doc['fecha_emision'],
        'fecha_revocacion'  => $doc['fecha_revocacion'],
        'motivo_revocacion' => $doc['motivo_revocacion'],
        'es_multifirma'     => $esMultifirma,
    ],
    'verificacion' => [
        'firma_valida'  => $firmaValida,
        'mensaje'       => $mensajeFirma,
        'firmas'        => $detalleFirmas,
    ],
]);
