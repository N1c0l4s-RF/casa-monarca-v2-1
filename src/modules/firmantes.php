<?php
/**
 * Módulo de firmas múltiples con orden estricto.
 *
 * Modelo:
 *   - Cada documento puede tener 0..N filas en documento_firmantes.
 *   - Si hay 0, el doc usa el flujo legacy de firma única.
 *   - Si hay >=1, el doc se mueve a estado 'en_firma' y solo el firmante
 *     con menor `orden` y estado 'pendiente' puede firmar.
 *   - Cuando el último firmante completa, el doc pasa a 'emitido'.
 *
 * Encadenamiento criptográfico:
 *   contenidoAFirmar(N) = folio | hash_doc | orden=N | firma_de_orden_(N-1)
 *   (firma_anterior vacía cuando N=1)
 *   Esto sella el orden: para producir una firma válida del orden 3 hace
 *   falta conocer la firma válida del orden 2, etc.
 */

require_once __DIR__ . '/../config/db.php';

function asignarFirmantes(int $docId, array $usuarioIds, int $creadoPor): void {
    $pdo = getDB();

    if (count($usuarioIds) < 1) {
        throw new \RuntimeException('Debes asignar al menos un firmante', 400);
    }
    if (count($usuarioIds) > 10) {
        throw new \RuntimeException('Máximo 10 firmantes por documento', 400);
    }
    if (count(array_unique($usuarioIds)) !== count($usuarioIds)) {
        throw new \RuntimeException('No puedes repetir firmantes', 400);
    }

    // El creador no puede firmar, EXCEPTO si es administrador y firma al final.
    if (in_array($creadoPor, $usuarioIds, true)) {
        $stmt = $pdo->prepare('SELECT rol FROM usuarios WHERE id = ?');
        $stmt->execute([$creadoPor]);
        $rolCreador = $stmt->fetchColumn();
        $esUltimo = end($usuarioIds) === $creadoPor;
        if ($rolCreador !== 'administrador' || !$esUltimo) {
            throw new \RuntimeException('El creador del documento no puede ser firmante (excepción: administrador firmando al final)', 400);
        }
    }

    // Validar que el doc está en borrador y aún no tiene firmantes
    $stmt = $pdo->prepare('SELECT estado FROM documentos WHERE id = ?');
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();
    if (!$doc) throw new \RuntimeException('Documento no encontrado', 404);
    if ($doc['estado'] !== 'borrador') {
        throw new \RuntimeException('Solo se pueden asignar firmantes a documentos en borrador', 400);
    }

    // Validar que cada usuario existe, está activo y tiene clave pública
    $placeholders = implode(',', array_fill(0, count($usuarioIds), '?'));
    $stmt = $pdo->prepare("
        SELECT u.id, u.nombre, u.activo, k.fingerprint
        FROM usuarios u
        LEFT JOIN usuario_claves k ON k.usuario_id = u.id AND k.activo = 1
        WHERE u.id IN ($placeholders)
    ");
    $stmt->execute($usuarioIds);
    $users = $stmt->fetchAll();
    if (count($users) !== count($usuarioIds)) {
        throw new \RuntimeException('Uno o más firmantes no existen', 400);
    }
    foreach ($users as $u) {
        if (!$u['activo']) throw new \RuntimeException("El firmante {$u['nombre']} está inactivo", 400);
        if (!$u['fingerprint']) throw new \RuntimeException("El firmante {$u['nombre']} no tiene clave pública registrada", 400);
    }

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('INSERT INTO documento_firmantes (documento_id, usuario_id, orden) VALUES (?, ?, ?)');
        foreach ($usuarioIds as $i => $uid) {
            $ins->execute([$docId, (int)$uid, $i + 1]);
        }
        $pdo->prepare('UPDATE documentos SET estado = "en_firma" WHERE id = ?')->execute([$docId]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function obtenerFirmantes(int $docId): array {
    $stmt = getDB()->prepare('
        SELECT f.id, f.usuario_id, f.orden, f.estado, f.firma, f.fecha_firma,
               u.nombre, u.email, u.rol
        FROM documento_firmantes f
        JOIN usuarios u ON u.id = f.usuario_id
        WHERE f.documento_id = ?
        ORDER BY f.orden ASC
    ');
    $stmt->execute([$docId]);
    return $stmt->fetchAll();
}

function tieneFirmantes(int $docId): bool {
    $stmt = getDB()->prepare('SELECT 1 FROM documento_firmantes WHERE documento_id = ? LIMIT 1');
    $stmt->execute([$docId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Devuelve la fila del siguiente firmante en turno, o null si no quedan.
 */
function obtenerFirmanteActual(int $docId): ?array {
    $stmt = getDB()->prepare('
        SELECT id, usuario_id, orden
        FROM documento_firmantes
        WHERE documento_id = ? AND estado = "pendiente"
        ORDER BY orden ASC LIMIT 1
    ');
    $stmt->execute([$docId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Construye el contenido exacto a firmar para un turno dado.
 * Encadena con la firma anterior si orden > 1.
 *
 *   contenidoAFirmar = folio | base_hash | orden | firma_prev
 *
 * base_hash es:
 *   - hash_sha256 del PDF si el doc tiene archivo
 *   - sha256(contenido) para docs legacy texto
 */
function construirContenidoAFirmar(array $doc, int $orden, ?string $firmaPrevia): string {
    $base = !empty($doc['ruta_archivo']) && !empty($doc['hash_sha256'])
        ? $doc['hash_sha256']
        : hash('sha256', (string)($doc['contenido'] ?? ''));
    return $doc['folio'] . '|' . $base . '|' . $orden . '|' . ($firmaPrevia ?? '');
}

/**
 * Devuelve la firma del orden anterior (necesaria para encadenar).
 * Retorna null si orden==1.
 */
function obtenerFirmaPrevia(int $docId, int $orden): ?string {
    if ($orden <= 1) return null;
    $stmt = getDB()->prepare('
        SELECT firma FROM documento_firmantes
        WHERE documento_id = ? AND orden = ? AND estado = "firmado"
    ');
    $stmt->execute([$docId, $orden - 1]);
    $f = $stmt->fetchColumn();
    return $f ?: null;
}
