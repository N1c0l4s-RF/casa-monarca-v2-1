-- ============================================================
-- Migration 005: Soporte de firmas múltiples con orden estricto
--
-- Cambios:
--   1. documentos.estado: agrega 'en_firma' al ENUM
--      (entre 'borrador' y 'emitido' cuando hay >=1 firmante asignado
--       y todavía faltan firmas)
--
--   2. Nueva tabla documento_firmantes: lista ordenada de firmantes
--      por documento. Cada fila representa un turno de firma. El
--      siguiente firmante en poder firmar es siempre el de menor
--      'orden' con estado='pendiente'.
--
--   3. Encadenamiento: cada firma incluye en el contenido firmado el
--      orden y la firma anterior (folio|hash_sha256|orden|firma_prev),
--      de modo que el orden queda sellado criptográficamente y no se
--      pueden insertar firmas fuera de secuencia.
--
-- Compatibilidad: documentos creados sin firmantes asignados siguen
-- funcionando con el flujo de una sola firma (documentos.firma /
-- documentos.firmado_por_usuario_id).
-- ============================================================

USE casa_monarca;

-- 1. Estado en_firma
ALTER TABLE documentos
    MODIFY COLUMN estado ENUM('borrador','en_firma','emitido','revocado')
    NOT NULL DEFAULT 'borrador';

-- 2. Tabla de firmantes ordenados
CREATE TABLE IF NOT EXISTS documento_firmantes (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    documento_id   INT UNSIGNED NOT NULL,
    usuario_id     INT UNSIGNED NOT NULL,
    orden          TINYINT UNSIGNED NOT NULL,
    estado         ENUM('pendiente','firmado') NOT NULL DEFAULT 'pendiente',
    firma          VARCHAR(255) NULL,
    hash_firmado   VARCHAR(64) NULL,
    fecha_firma    DATETIME NULL,
    creado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_doc_orden   (documento_id, orden),
    UNIQUE KEY uq_doc_usuario (documento_id, usuario_id),
    KEY idx_doc_estado (documento_id, estado),

    FOREIGN KEY (documento_id) REFERENCES documentos(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id)   REFERENCES usuarios(id)   ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
