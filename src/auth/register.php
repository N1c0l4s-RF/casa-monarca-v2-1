<?php
/**
 * POST /auth/register
 */
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../modules/bitacora.php';

setSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Método no permitido', 405);
}

$body   = getJsonBody();
$nombre = trim($body['nombre'] ?? '');
$email  = trim($body['email'] ?? '');
$pass   = $body['password'] ?? '';

if (empty($nombre) || empty($email) || empty($pass)) {
    jsonError('Nombre, email y contraseña son requeridos');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonError('Email inválido');
}
if (strlen($pass) < 8) {
    jsonError('La contraseña debe tener al menos 8 caracteres');
}

$pdo  = getDB();
$check = $pdo->prepare('SELECT id FROM usuarios WHERE email = ?');
$check->execute([$email]);
if ($check->fetch()) {
    jsonError('El email ya está registrado', 409);
}

$hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 10]);
$stmt = $pdo->prepare('INSERT INTO usuarios (nombre, email, password_hash, rol) VALUES (?, ?, ?, ?)');
$stmt->execute([$nombre, $email, $hash, 'consultor']);
$newId = (int) $pdo->lastInsertId();

registrarBitacora($newId, 'register', 'auth', null, null, 'Registro de nuevo usuario: ' . $email);

jsonSuccess('Usuario registrado exitosamente', ['usuario_id' => $newId], 201);
