<?php
/**
 * Casa Monarca v2 - Módulo de llaves RSA-3072
 */

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/../config/db.php';

function generarLlavesRSA(int $usuarioId): array {
    // Generar par RSA-3072
    $config = [
        'private_key_bits' => 3072,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ];

    $resource = openssl_pkey_new($config);
    if (!$resource) {
        throw new \RuntimeException('Error generando par RSA: ' . openssl_error_string());
    }

    // Extraer clave privada en PEM
    openssl_pkey_export($resource, $privatePem);

    // Extraer clave pública en PEM
    $details   = openssl_pkey_get_details($resource);
    $publicPem = $details['key'];

    // Fingerprint SHA-256 de la clave pública
    $fingerprint = hashSHA256($publicPem);

    // Encriptar clave privada
    $encrypted = encriptarPrivada($privatePem, $usuarioId);

    // Guardar en BD
    $pdo = getDB();
    $exists = $pdo->prepare('SELECT id FROM usuario_claves WHERE usuario_id = ?');
    $exists->execute([$usuarioId]);

    if ($exists->fetch()) {
        $stmt = $pdo->prepare('
            UPDATE usuario_claves
            SET version = version + 1,
                activo = 1,
                clave_privada_encriptada = ?,
                iv_encriptacion = ?,
                certificado_publico = ?,
                fingerprint = ?,
                created_at = NOW(),
                revocada_en = NULL,
                download_count = 0,
                last_downloaded_at = NULL
            WHERE usuario_id = ?
        ');
        $stmt->execute([
            $encrypted['encrypted'] . '::' . $encrypted['salt'],
            $encrypted['iv'],
            $publicPem,
            $fingerprint,
            $usuarioId,
        ]);
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO usuario_claves
                (usuario_id, clave_privada_encriptada, iv_encriptacion, certificado_publico, fingerprint)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $encrypted['encrypted'] . '::' . $encrypted['salt'],
            $encrypted['iv'],
            $publicPem,
            $fingerprint,
        ]);
    }

    // Retornar clave pública (la privada nunca se devuelve directamente)
    return [
        'fingerprint' => $fingerprint,
        'public_key'  => $publicPem,
    ];
}

function obtenerInfoClave(int $usuarioId): ?array {
    $pdo  = getDB();
    $stmt = $pdo->prepare('
        SELECT version, activo, fingerprint, created_at, download_count, last_downloaded_at
        FROM usuario_claves WHERE usuario_id = ?
    ');
    $stmt->execute([$usuarioId]);
    return $stmt->fetch() ?: null;
}

function obtenerClavePublica(int $usuarioId): ?string {
    $pdo  = getDB();
    $stmt = $pdo->prepare('SELECT certificado_publico FROM usuario_claves WHERE usuario_id = ? AND activo = 1');
    $stmt->execute([$usuarioId]);
    $row = $stmt->fetch();
    return $row ? $row['certificado_publico'] : null;
}

function obtenerClavePrivadaDesencriptada(int $usuarioId): string {
    $pdo  = getDB();
    $stmt = $pdo->prepare('SELECT clave_privada_encriptada, iv_encriptacion FROM usuario_claves WHERE usuario_id = ? AND activo = 1');
    $stmt->execute([$usuarioId]);
    $row = $stmt->fetch();

    if (!$row) {
        throw new \RuntimeException('No se encontró clave privada para el usuario');
    }

    // Separar encrypted y salt
    [$encrypted, $saltB64] = explode('::', $row['clave_privada_encriptada'], 2);

    return desencriptarPrivada($encrypted, $row['iv_encriptacion'], $saltB64, $usuarioId);
}

function registrarDescargaClave(int $usuarioId): void {
    $pdo = getDB();
    $pdo->prepare('UPDATE usuario_claves SET download_count = download_count + 1, last_downloaded_at = NOW() WHERE usuario_id = ?')
        ->execute([$usuarioId]);
}

function firmarContenido(string $contenido, int $usuarioId): string {
    $privatePem = obtenerClavePrivadaDesencriptada($usuarioId);
    $privateKey = openssl_pkey_get_private($privatePem);
    if (!$privateKey) {
        throw new \RuntimeException('Error cargando clave privada para firma');
    }
    openssl_sign($contenido, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    return base64_encode($signature);
}

function verificarFirma(string $contenido, string $signatureB64, string $publicPem): bool {
    $publicKey = openssl_pkey_get_public($publicPem);
    if (!$publicKey) return false;
    $signature = base64_decode($signatureB64);
    $result    = openssl_verify($contenido, $signature, $publicKey, OPENSSL_ALGO_SHA256);
    return $result === 1;
}
