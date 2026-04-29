<?php
/**
 * Casa Monarca v2 - Módulo de gestión de usuarios
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/bitacora.php';

const ROLES_VALIDOS = ['administrador', 'supervisor', 'emisor', 'verificador', 'consultor'];

function listarUsuarios(): array {
    $pdo  = getDB();
    $stmt = $pdo->query('SELECT id, nombre, email, rol, activo, fecha_creacion, ultimo_login FROM usuarios ORDER BY fecha_creacion DESC');
    return $stmt->fetchAll();
}

function cambiarRol(int $targetId, string $nuevoRol, int $adminId): void {
    if (!in_array($nuevoRol, ROLES_VALIDOS, true)) {
        throw new \RuntimeException('Rol inválido', 400);
    }

    $pdo  = getDB();
    $stmt = $pdo->prepare('SELECT id, nombre, rol FROM usuarios WHERE id = ?');
    $stmt->execute([$targetId]);
    $user = $stmt->fetch();

    if (!$user) throw new \RuntimeException('Usuario no encontrado', 404);

    $pdo->prepare('UPDATE usuarios SET rol = ? WHERE id = ?')->execute([$nuevoRol, $targetId]);
    registrarBitacora($adminId, 'role_changed', 'usuarios', null, null,
        "Rol de usuario #{$targetId} ({$user['nombre']}) cambiado de {$user['rol']} a {$nuevoRol}");
}

function desactivarUsuario(int $targetId, int $adminId): void {
    $pdo  = getDB();
    $stmt = $pdo->prepare('SELECT id, nombre FROM usuarios WHERE id = ?');
    $stmt->execute([$targetId]);
    $user = $stmt->fetch();

    if (!$user) throw new \RuntimeException('Usuario no encontrado', 404);
    if ($targetId === $adminId) throw new \RuntimeException('No puedes desactivarte a ti mismo', 400);

    $pdo->prepare('UPDATE usuarios SET activo = 0 WHERE id = ?')->execute([$targetId]);
    registrarBitacora($adminId, 'deactivated', 'usuarios', null, null, "Usuario #{$targetId} ({$user['nombre']}) desactivado");
}
