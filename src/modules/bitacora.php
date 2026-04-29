<?php
/**
 * Casa Monarca v2 - Módulo de auditoría (bitácora)
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/middleware.php';

function registrarBitacora(
    ?int    $usuarioId,
    string  $accion,
    string  $modulo,
    ?int    $documentoId = null,
    ?string $documentoFolio = null,
    ?string $descripcion = null,
    string  $resultado = 'success',
    ?string $motivoFallo = null
): void {
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare('
            INSERT INTO bitacora
                (usuario_id, accion, modulo, documento_id, documento_folio, descripcion, ip_address, user_agent, resultado, motivo_fallo)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $usuarioId,
            $accion,
            $modulo,
            $documentoId,
            $documentoFolio,
            $descripcion,
            getClientIP(),
            getUserAgent(),
            $resultado,
            $motivoFallo,
        ]);
    } catch (\Throwable $e) {
        // Silencioso — nunca interrumpir el flujo por un fallo de auditoría
        error_log('Bitacora error: ' . $e->getMessage());
    }
}
