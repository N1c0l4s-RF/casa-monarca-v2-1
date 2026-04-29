<?php
/**
 * Casa Monarca v2 - Módulo de documentos
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/claves.php';
require_once __DIR__ . '/bitacora.php';

function generarFolio(): string {
    $fecha = date('Ymd');
    $random = strtoupper(bin2hex(random_bytes(3)));
    return "DOC-{$fecha}-{$random}";
}

function crearDocumento(int $usuarioId, array $data): int {
    $pdo   = getDB();
    $folio = generarFolio();

    $stmt = $pdo->prepare('
        INSERT INTO documentos (folio, titulo, ruta_archivo, descripcion, contenido, hash_sha256, creado_por)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $folio,
        $data['titulo'],
        $data['ruta_archivo'] ?? null,
        $data['descripcion'] ?? null,
        $data['contenido'] ?? null,
        isset($data['contenido']) ? hashSHA256($data['contenido']) : null,
        $usuarioId,
    ]);

    $docId = (int) $pdo->lastInsertId();
    registrarBitacora($usuarioId, 'created', 'documentos', $docId, $folio, "Documento creado: {$data['titulo']}");

    return $docId;
}

function listarDocumentos(int $usuarioId, string $rol): array {
    $pdo = getDB();

    if (in_array($rol, ['administrador', 'supervisor', 'verificador'], true)) {
        $stmt = $pdo->prepare('
            SELECT d.*, u.nombre as creador_nombre
            FROM documentos d
            JOIN usuarios u ON u.id = d.creado_por
            ORDER BY d.fecha_creacion DESC
        ');
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare('
            SELECT d.*, u.nombre as creador_nombre
            FROM documentos d
            JOIN usuarios u ON u.id = d.creado_por
            WHERE d.creado_por = ?
            ORDER BY d.fecha_creacion DESC
        ');
        $stmt->execute([$usuarioId]);
    }

    return $stmt->fetchAll();
}

function actualizarDocumento(int $docId, int $usuarioId, array $data): void {
    $pdo = getDB();

    // Validar que existe, está en borrador y es del usuario
    $stmt = $pdo->prepare('SELECT id, folio, estado, creado_por FROM documentos WHERE id = ?');
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();

    if (!$doc) throw new \RuntimeException('Documento no encontrado', 404);
    if ($doc['creado_por'] != $usuarioId) throw new \RuntimeException('Sin permisos sobre este documento', 403);
    if ($doc['estado'] !== 'borrador') throw new \RuntimeException('Solo se pueden editar documentos en borrador', 400);

    $contenido = $data['contenido'] ?? null;
    $pdo->prepare('
        UPDATE documentos SET titulo = ?, descripcion = ?, contenido = ?, hash_sha256 = ?
        WHERE id = ?
    ')->execute([
        $data['titulo'],
        $data['descripcion'] ?? null,
        $contenido,
        $contenido ? hashSHA256($contenido) : null,
        $docId,
    ]);

    registrarBitacora($usuarioId, 'updated', 'documentos', $docId, $doc['folio'], 'Documento actualizado');
}

function eliminarDocumento(int $docId, int $usuarioId): void {
    $pdo  = getDB();
    $stmt = $pdo->prepare('SELECT id, folio, estado, creado_por FROM documentos WHERE id = ?');
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();

    if (!$doc) throw new \RuntimeException('Documento no encontrado', 404);
    if ($doc['creado_por'] != $usuarioId) throw new \RuntimeException('Sin permisos', 403);
    if ($doc['estado'] !== 'borrador') throw new \RuntimeException('Solo se pueden eliminar documentos en borrador', 400);

    $pdo->prepare('DELETE FROM documentos WHERE id = ?')->execute([$docId]);
    registrarBitacora($usuarioId, 'deleted', 'documentos', $docId, $doc['folio'], 'Documento eliminado');
}

function emitirDocumento(int $docId, int $usuarioId): void {
    $pdo  = getDB();
    $stmt = $pdo->prepare('SELECT id, folio, estado, contenido, creado_por FROM documentos WHERE id = ?');
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();

    if (!$doc) throw new \RuntimeException('Documento no encontrado', 404);
    if ($doc['estado'] !== 'borrador') throw new \RuntimeException('Solo se pueden emitir borradores', 400);

    // Firmar contenido
    $contenidoFirmar = $doc['folio'] . '|' . $doc['contenido'];
    $firma = firmarContenido($contenidoFirmar, $usuarioId);

    $pdo->prepare('
        UPDATE documentos
        SET estado = "emitido", firmado_por_usuario_id = ?, fecha_emision = NOW(), hash_sha256 = ?
        WHERE id = ?
    ')->execute([$usuarioId, $firma, $docId]);

    registrarBitacora($usuarioId, 'emitted', 'documentos', $docId, $doc['folio'], 'Documento emitido y firmado');
}

function revocarDocumento(int $docId, int $usuarioId, string $motivo): void {
    $pdo  = getDB();
    $stmt = $pdo->prepare('SELECT id, folio, estado FROM documentos WHERE id = ?');
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();

    if (!$doc) throw new \RuntimeException('Documento no encontrado', 404);
    if ($doc['estado'] === 'revocado') throw new \RuntimeException('El documento ya está revocado', 400);

    $pdo->prepare('
        UPDATE documentos
        SET estado = "revocado", motivo_revocacion = ?, fecha_revocacion = NOW()
        WHERE id = ?
    ')->execute([$motivo, $docId]);

    registrarBitacora($usuarioId, 'revoked', 'documentos', $docId, $doc['folio'], "Revocado: {$motivo}");
}
