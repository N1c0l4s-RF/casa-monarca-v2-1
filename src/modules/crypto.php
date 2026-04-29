<?php
/**
 * Casa Monarca v2 - Módulo de criptografía
 * AES-256-CBC, PBKDF2, SHA-256
 */

function derivarClave(int $usuarioId, string $salt): string {
    // PBKDF2 con 100k iteraciones, SHA-256, clave de 32 bytes
    return hash_pbkdf2('sha256', (string)$usuarioId, $salt, 100000, 32, true);
}

function encriptarPrivada(string $privatePem, int $usuarioId): array {
    $salt      = random_bytes(16);
    $iv        = random_bytes(16);
    $clave     = derivarClave($usuarioId, $salt);
    $encrypted = openssl_encrypt($privatePem, 'AES-256-CBC', $clave, OPENSSL_RAW_DATA, $iv);

    if ($encrypted === false) {
        throw new \RuntimeException('Error al encriptar la clave privada');
    }

    return [
        'encrypted' => $encrypted,
        'iv'        => base64_encode($iv),
        'salt'      => base64_encode($salt),
    ];
}

function desencriptarPrivada(string $encryptedBlob, string $ivB64, string $saltB64, int $usuarioId): string {
    $iv    = base64_decode($ivB64);
    $salt  = base64_decode($saltB64);
    $clave = derivarClave($usuarioId, $salt);
    $pem   = openssl_decrypt($encryptedBlob, 'AES-256-CBC', $clave, OPENSSL_RAW_DATA, $iv);

    if ($pem === false) {
        throw new \RuntimeException('Error al desencriptar la clave privada');
    }

    return $pem;
}

function hashSHA256(string $data): string {
    return hash('sha256', $data);
}

function generarTokenSeguro(): string {
    return bin2hex(random_bytes(32));
}

function hashToken(string $token): string {
    return hash('sha256', $token);
}
