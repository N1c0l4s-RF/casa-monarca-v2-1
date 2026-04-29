<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../config/db.php';
setSecurityHeaders();
$user = requireAuth();
requireRole($user, ['administrador', 'supervisor']);
$pdo  = getDB();
$limit = min((int)($_GET['limit'] ?? 100), 500);
$offset = (int)($_GET['offset'] ?? 0);
$stmt = $pdo->prepare('
    SELECT b.*, u.nombre as usuario_nombre, u.email as usuario_email
    FROM bitacora b
    LEFT JOIN usuarios u ON u.id = b.usuario_id
    ORDER BY b.fecha DESC
    LIMIT ? OFFSET ?
');
$stmt->execute([$limit, $offset]);
$rows = $stmt->fetchAll();
$total = $pdo->query('SELECT COUNT(*) FROM bitacora')->fetchColumn();
jsonSuccess('Bitácora obtenida', ['registros' => $rows, 'total' => (int)$total]);
