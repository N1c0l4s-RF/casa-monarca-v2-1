<?php
/**
 * Casa Monarca v2 - Tokens temporales para descarga de claves
 */

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/../config/db.php';

function generarToken(int $usuarioId, string $tipo = 'key'): string {
    $pdo = getDB();

    // Limpiar tokens expirados del usuario
    $pdo->prepare('DELETE FROM key_download_tokens WHERE usuario_id = ? AND expires_at < NOW()')
        ->execute([$usuarioId]);

    $token     = generarTokenSeguro();
    $tokenHash = hashToken($token);
    $expires   = date('Y-m-d H:i:s', time() + 600); // 10 minutos

    $stmt = $pdo->prepare('
        INSERT INTO key_download_tokens (usuario_id, token_hash, tipo, expires_at)
        VALUES (?, ?, ?, ?)
    ');
    $stmt->execute([$usuarioId, $tokenHash, $tipo, $expires]);

    return $token; // Retornar token en claro (sólo aquí)
}

function consumirToken(string $token, int $usuarioId): ?array {
    $pdo       = getDB();
    $tokenHash = hashToken($token);

    $stmt = $pdo->prepare('
        SELECT id, tipo, expires_at, used_at
        FROM key_download_tokens
        WHERE token_hash = ? AND usuario_id = ?
    ');
    $stmt->execute([$tokenHash, $usuarioId]);
    $row = $stmt->fetch();

    if (!$row) return null;
    if ($row['used_at'] !== null) return null; // Ya usado
    if (strtotime($row['expires_at']) < time()) return null; // Expirado

    // Marcar como usado (single-use)
    $pdo->prepare('UPDATE key_download_tokens SET used_at = NOW() WHERE id = ?')
        ->execute([$row['id']]);

    return $row;
}
