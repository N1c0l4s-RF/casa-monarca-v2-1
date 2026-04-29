<?php
/**
 * POST /auth/login
 */
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../modules/bitacora.php';

setSecurityHeaders();
startSecureSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Método no permitido', 405);
}

$body  = getJsonBody();
$email = trim($body['email'] ?? '');
$pass  = $body['password'] ?? '';

if (empty($email) || empty($pass)) {
    jsonError('Email y contraseña son requeridos');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonError('Email inválido');
}

$pdo  = getDB();
$stmt = $pdo->prepare('SELECT id, nombre, email, password_hash, rol, activo FROM usuarios WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($pass, $user['password_hash'])) {
    registrarBitacora(null, 'login', 'auth', null, null, 'Intento de login fallido: ' . $email, 'failed', 'Credenciales incorrectas');
    jsonError('Credenciales incorrectas', 401);
}

if (!$user['activo']) {
    jsonError('Usuario desactivado. Contacte al administrador.', 403);
}

// Regenerar sesión para prevenir fixation
session_regenerate_id(true);
$_SESSION['usuario_id'] = $user['id'];
$_SESSION['rol']        = $user['rol'];

// Actualizar último login
$pdo->prepare('UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?')->execute([$user['id']]);

registrarBitacora($user['id'], 'login', 'auth', null, null, 'Login exitoso');

jsonSuccess('Login exitoso', [
    'usuario' => [
        'id'     => $user['id'],
        'nombre' => $user['nombre'],
        'email'  => $user['email'],
        'rol'    => $user['rol'],
    ]
]);
