<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../modules/documentos.php';
setSecurityHeaders();
$user = requireAuth();
$docs = listarDocumentos((int)$user['id'], $user['rol']);
jsonSuccess('Documentos obtenidos', ['documentos' => $docs]);
