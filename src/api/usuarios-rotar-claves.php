<?php
require_once __DIR__ . '/../auth/middleware.php';
setSecurityHeaders();
$user = requireAuth();
requireRole($user, ['administrador']);
jsonError('Rotación de llaves no implementada en esta versión. Próxima fase.', 501);
