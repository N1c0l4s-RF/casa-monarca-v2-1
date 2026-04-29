<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../modules/usuarios.php';
setSecurityHeaders();
$user = requireAuth();
requireRole($user, ['administrador', 'supervisor']);
jsonSuccess('Usuarios obtenidos', ['usuarios' => listarUsuarios()]);
