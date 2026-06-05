<?php
/**
 * GET /api/usuarios-firmables.php
 * Devuelve usuarios activos con clave pública registrada — candidatos
 * para ser firmantes de un documento. Disponible para todo usuario
 * autenticado (un emisor debe poder armar la cadena de firmantes sin
 * tener permisos de gestión de usuarios).
 */
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../config/db.php';

setSecurityHeaders();
$user = requireAuth();

$stmt = getDB()->prepare("
    SELECT u.id, u.nombre, u.email, u.rol
    FROM usuarios u
    JOIN usuario_claves k ON k.usuario_id = u.id AND k.activo = 1
    WHERE u.activo = 1
      AND u.rol IN ('administrador','supervisor','emisor')
    ORDER BY u.nombre ASC
");
$stmt->execute();
jsonSuccess('OK', ['usuarios' => $stmt->fetchAll()]);
