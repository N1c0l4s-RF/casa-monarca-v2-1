<?php
/**
 * GET /api/documentos-archivo.php?folio=DOC-XXXX[&download=1]
 *
 * Sirve el PDF asociado a un documento.
 *
 * Reglas de acceso:
 *   - Documento `emitido` o `revocado`  → PÚBLICO (cualquiera con el folio).
 *   - Documento `borrador`              → solo creador, admin o supervisor.
 *
 * El archivo se transmite por PHP (no se expone /var/uploads
 * directamente) para poder aplicar control de acceso.
 */
require_once __DIR__ . '/../auth/middleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    setSecurityHeaders();
    jsonError('Método no permitido', 405);
}

$folio = trim((string)($_GET['folio'] ?? ''));
$forceDownload = !empty($_GET['download']);

if ($folio === '' || !preg_match('/^DOC-[0-9]{8}-[A-Fa-f0-9]{6}$/', $folio)) {
    setSecurityHeaders();
    jsonError('Folio inválido', 400);
}

$pdo  = getDB();
$stmt = $pdo->prepare('SELECT id, folio, titulo, ruta_archivo, estado, creado_por FROM documentos WHERE folio = ?');
$stmt->execute([$folio]);
$doc  = $stmt->fetch();

if (!$doc) {
    setSecurityHeaders();
    jsonError('Documento no encontrado', 404);
}
if (empty($doc['ruta_archivo'])) {
    setSecurityHeaders();
    jsonError('Este documento no tiene archivo adjunto', 404);
}

// Control de acceso
if ($doc['estado'] === 'borrador') {
    startSecureSession();
    if (empty($_SESSION['usuario_id'])) {
        setSecurityHeaders();
        jsonError('Los borradores requieren autenticación', 401);
    }
    $u = $pdo->prepare('SELECT id, rol FROM usuarios WHERE id = ? AND activo = 1');
    $u->execute([$_SESSION['usuario_id']]);
    $user = $u->fetch();
    if (!$user) {
        setSecurityHeaders();
        jsonError('Sesión inválida', 401);
    }
    $esCreador = ((int)$user['id'] === (int)$doc['creado_por']);
    if (!$esCreador && !in_array($user['rol'], ['administrador','supervisor'], true)) {
        setSecurityHeaders();
        jsonError('Sin permisos para ver este borrador', 403);
    }
}

$ruta = $doc['ruta_archivo'];
if (!is_readable($ruta)) {
    setSecurityHeaders();
    jsonError('Archivo no encontrado en disco', 410);
}

// Nombre seguro para Content-Disposition
$titulo = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $doc['titulo']);
$titulo = substr($titulo ?: $doc['folio'], 0, 80);
$filename = "{$doc['folio']}_{$titulo}.pdf";

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($ruta));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
header('Cache-Control: private, no-cache, must-revalidate');
header('X-Document-Folio: ' . $doc['folio']);
header('X-Document-Estado: ' . $doc['estado']);

readfile($ruta);
exit;
