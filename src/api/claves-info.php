<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../modules/claves.php';
setSecurityHeaders();
$user = requireAuth();
requireRole($user, ['administrador', 'supervisor']);
$info = obtenerInfoClave((int)$user['id']);
if (!$info) jsonError('No tienes llaves RSA generadas. Contacta al administrador.', 404);
jsonSuccess('Información de llave', ['clave' => $info]);
