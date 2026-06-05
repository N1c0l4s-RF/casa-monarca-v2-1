<?php
/**
 * POST /api/documentos-create.php
 *
 * Acepta multipart/form-data con los campos:
 *   - titulo       (string, requerido)
 *   - descripcion  (string, opcional)
 *   - archivo      (file PDF, requerido)
 *
 * El archivo se guarda en /var/uploads/{folio}.pdf y se almacena
 * su SHA-256 en la columna hash_sha256. Al firmar, ese hash es
 * lo que queda criptográficamente comprometido en la firma.
 */
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../modules/documentos.php';
require_once __DIR__ . '/../modules/bitacora.php';

setSecurityHeaders();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
$user = requireAuth();
requireRole($user, ['administrador', 'emisor']);

$titulo      = trim((string)($_POST['titulo']      ?? ''));
$descripcion = trim((string)($_POST['descripcion'] ?? ''));

if ($titulo === '') jsonError('El título es requerido');

// Validar archivo
if (empty($_FILES['archivo']) || $_FILES['archivo']['error'] === UPLOAD_ERR_NO_FILE) {
    jsonError('Debes subir un archivo PDF', 400);
}
$file = $_FILES['archivo'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    $msg = match ($file['error']) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño máximo permitido',
        UPLOAD_ERR_PARTIAL    => 'La subida quedó incompleta',
        UPLOAD_ERR_NO_TMP_DIR => 'Error de configuración del servidor (no temp dir)',
        UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir el archivo en disco',
        default               => 'Error desconocido subiendo el archivo',
    };
    jsonError($msg, 400);
}

if ($file['size'] > 25 * 1024 * 1024) {
    jsonError('El archivo excede 25 MB', 413);
}
if ($file['size'] < 100) {
    jsonError('El archivo parece estar vacío o corrupto', 400);
}

// Validar MIME real (no confiar en lo que dice el cliente)
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
if ($mime !== 'application/pdf') {
    jsonError("Solo se permiten archivos PDF (recibido: {$mime})", 400);
}

// Verificar magic number adicional (%PDF-)
$fh = fopen($file['tmp_name'], 'rb');
$header = $fh ? fread($fh, 5) : '';
if ($fh) fclose($fh);
if ($header !== '%PDF-') {
    jsonError('El archivo no es un PDF válido', 400);
}

// Generar folio y mover archivo de forma atómica
$pdo   = getDB();
$folio = generarFolio();
$dest  = '/var/uploads/' . $folio . '.pdf';

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    jsonError('No se pudo guardar el archivo en el servidor', 500);
}
@chmod($dest, 0640);

$hash_sha256 = hash_file('sha256', $dest);
$tamanio     = filesize($dest);

// Insertar documento
try {
    $stmt = $pdo->prepare('
        INSERT INTO documentos
            (folio, titulo, ruta_archivo, descripcion, hash_sha256, creado_por)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $folio,
        $titulo,
        $dest,
        $descripcion !== '' ? $descripcion : null,
        $hash_sha256,
        (int)$user['id'],
    ]);
    $docId = (int)$pdo->lastInsertId();
} catch (\Throwable $e) {
    @unlink($dest);
    jsonError('Error guardando documento: ' . $e->getMessage(), 500);
}

registrarBitacora(
    (int)$user['id'], 'created', 'documentos', $docId, $folio,
    "Documento creado con PDF '{$file['name']}' (" . round($tamanio/1024,1) . " KB) — SHA256: {$hash_sha256}"
);

jsonSuccess('Documento creado con PDF adjunto', [
    'documento_id' => $docId,
    'folio'        => $folio,
    'hash_sha256'  => $hash_sha256,
    'tamanio'      => $tamanio,
], 201);
