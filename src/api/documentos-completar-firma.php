<?php
/**
 * POST /api/documentos-completar-firma.php
 * Paso 2: recibe firma ECDSA, verifica contra clave pública, emite documento.
 * Body: { "id": 42, "firma": "hex_firma_ecdsa...", "session_id": N }
 */
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../modules/bitacora.php';
require_once __DIR__ . '/../modules/firmantes.php';
require_once __DIR__ . '/../modules/claves.php';

setSecurityHeaders();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
$user = requireAuth();
requireRole($user, ['administrador', 'supervisor', 'emisor']);

$body      = getJsonBody();
$docId     = (int)($body['id'] ?? 0);
$firma     = trim($body['firma'] ?? '');
$sessionId = (int)($body['session_id'] ?? 0);

if (!$docId) jsonError('id es requerido');
if (!$firma) jsonError('firma es requerida');
if (!preg_match('/^[0-9a-f]+$/i', $firma)) jsonError('Formato de firma inválido');

// ── Validar firma_session en DB ───────────────────────────────────────────
$pdo = getDB();
$stmt = $pdo->prepare('
    SELECT id, hash_hex, expires_at, usado_en
    FROM firma_sessions
    WHERE usuario_id = ? AND documento_id = ? AND usado_en IS NULL
    ORDER BY id DESC LIMIT 1
');
$stmt->execute([$user['id'], $docId]);
$session = $stmt->fetch();

if (!$session) {
    jsonError('No hay firma pendiente. Solicita el hash primero.', 422);
}
if (new DateTime() > new DateTime($session['expires_at'])) {
    jsonError('La solicitud de firma expiró (10 minutos). Vuelve a intentarlo.', 422);
}

$hashHex = $session['hash_hex'];

// Marcar como usada (one-shot)
$pdo->prepare('UPDATE firma_sessions SET usado_en = NOW() WHERE id = ?')
    ->execute([$session['id']]);

// ── Obtener documento y reconstruir contenido a firmar ────────────────────
$stmt = $pdo->prepare('SELECT id, folio, contenido, estado, creado_por, ruta_archivo, hash_sha256 FROM documentos WHERE id = ?');
$stmt->execute([$docId]);
$doc = $stmt->fetch();

if (!$doc) jsonError('Documento no encontrado', 404);

// Verificar integridad del PDF antes de firmar
if (!empty($doc['ruta_archivo']) && !empty($doc['hash_sha256'])) {
    if (!is_readable($doc['ruta_archivo'])) {
        jsonError('Archivo PDF no encontrado en el servidor', 500);
    }
    $hashActual = hash_file('sha256', $doc['ruta_archivo']);
    if ($hashActual !== $doc['hash_sha256']) {
        jsonError('El archivo PDF fue modificado después de su subida. Por integridad, no se puede firmar.', 422);
    }
}

$multifirma = tieneFirmantes((int)$doc['id']);
$ordenActual = 1;
$firmanteRow = null;

if ($multifirma) {
    if ($doc['estado'] !== 'en_firma') jsonError('El documento no está en proceso de firma', 400);
    $firmanteRow = obtenerFirmanteActual((int)$doc['id']);
    if (!$firmanteRow) jsonError('No quedan firmas pendientes', 400);
    if ((int)$firmanteRow['usuario_id'] !== (int)$user['id']) {
        jsonError('No es tu turno de firmar', 403);
    }
    $ordenActual = (int)$firmanteRow['orden'];
    $firmaPrevia = obtenerFirmaPrevia((int)$doc['id'], $ordenActual);
    $contenidoAFirmar = construirContenidoAFirmar($doc, $ordenActual, $firmaPrevia);
} else {
    if ($doc['estado'] !== 'borrador') jsonError('Solo se pueden emitir borradores', 400);
    if ((int)$doc['creado_por'] === (int)$user['id'] && $user['rol'] !== 'administrador') {
        jsonError('No puedes firmar tu propio documento. Se requiere un firmante diferente al creador.', 403);
    }
    $contenidoAFirmar = !empty($doc['ruta_archivo']) && !empty($doc['hash_sha256'])
        ? $doc['folio'] . '|' . $doc['hash_sha256']
        : $doc['folio'] . '|' . ($doc['contenido'] ?? '');
}

// Validar que el hash en DB corresponde al documento actual
if (hash('sha256', $contenidoAFirmar) !== $hashHex) {
    jsonError('El contenido del documento fue modificado. Vuelve a solicitar la firma.', 422);
}

// ── Obtener clave pública del usuario ─────────────────────────────────────
$stmt = $pdo->prepare('SELECT certificado_publico FROM usuario_claves WHERE usuario_id = ? AND activo = 1');
$stmt->execute([$user['id']]);
$claveRow = $stmt->fetch();
if (!$claveRow) jsonError('No tienes una clave pública registrada.', 422);

$publicKeyHex = $claveRow['certificado_publico'];

// Verificar firma ECDSA P-256 (helper en modules/claves.php)
$firmaValida = verificarFirmaECDSA($contenidoAFirmar, $firma, $publicKeyHex);

if (!$firmaValida) {
    registrarBitacora($user['id'], 'firma_invalida', 'documentos', $docId, null,
        'Firma ECDSA inválida recibida', 'failed', 'La firma no corresponde a la clave pública registrada');
    jsonError('Firma inválida. La firma no corresponde a tu clave pública.', 422);
}

// ── Persistir firma ───────────────────────────────────────────────────────
$firmaHex = strtolower($firma);

if ($multifirma) {
    // Marcar la fila del firmante actual como firmada
    $pdo->prepare('
        UPDATE documento_firmantes
        SET estado = "firmado", firma = ?, hash_firmado = ?, fecha_firma = NOW()
        WHERE id = ?
    ')->execute([$firmaHex, $hashHex, $firmanteRow['id']]);

    // Si ya no quedan pendientes, emitir el documento
    $quedaPendiente = obtenerFirmanteActual($docId);
    if (!$quedaPendiente) {
        $pdo->prepare('
            UPDATE documentos SET
                estado = "emitido",
                firmado_por_usuario_id = ?,
                fecha_emision = NOW(),
                firma = ?
            WHERE id = ?
        ')->execute([$user['id'], $firmaHex, $docId]);
        registrarBitacora($user['id'], 'emitted', 'documentos', $docId, $doc['folio'],
            "Documento emitido. Firma final (orden {$ordenActual}) completó la cadena.");
        jsonSuccess('Última firma registrada. Documento emitido.', [
            'folio' => $doc['folio'], 'orden' => $ordenActual, 'completado' => true,
        ]);
    } else {
        registrarBitacora($user['id'], 'firma_parcial', 'documentos', $docId, $doc['folio'],
            "Firma del orden {$ordenActual} registrada. Pendiente: orden {$quedaPendiente['orden']}.");
        jsonSuccess('Firma registrada. Aún faltan firmas.', [
            'folio' => $doc['folio'], 'orden' => $ordenActual, 'completado' => false,
            'siguiente_usuario_id' => (int)$quedaPendiente['usuario_id'],
            'siguiente_orden'      => (int)$quedaPendiente['orden'],
        ]);
    }
} else {
    $pdo->prepare('
        UPDATE documentos SET
            estado = "emitido",
            firmado_por_usuario_id = ?,
            fecha_emision = NOW(),
            firma = ?
        WHERE id = ?
    ')->execute([$user['id'], $firmaHex, $docId]);

    registrarBitacora($user['id'], 'emitted', 'documentos', $docId, $doc['folio'],
        'Documento emitido con firma ECDSA P-256 client-side');

    jsonSuccess('Documento emitido y firmado correctamente', [
        'folio'     => $doc['folio'],
        'algoritmo' => 'ECDSA P-256',
    ]);
}

