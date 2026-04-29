-- Casa Monarca v2 - Schema completo
-- MySQL 8.0+

SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS casa_monarca CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE casa_monarca;

-- -------------------------------------------------------
-- Tabla: usuarios
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    email VARCHAR(180) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    rol ENUM('administrador','supervisor','emisor','verificador','consultor') NOT NULL DEFAULT 'consultor',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultimo_login DATETIME NULL,
    INDEX idx_email (email),
    INDEX idx_rol (rol),
    INDEX idx_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Tabla: documentos
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS documentos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    folio VARCHAR(30) NOT NULL UNIQUE,
    titulo VARCHAR(255) NOT NULL,
    ruta_archivo VARCHAR(500) NULL,
    descripcion TEXT NULL,
    contenido LONGTEXT NULL,
    hash_sha256 VARCHAR(64) NULL,
    estado ENUM('borrador','emitido','revocado') NOT NULL DEFAULT 'borrador',
    creado_por INT UNSIGNED NOT NULL,
    firmado_por_usuario_id INT UNSIGNED NULL,
    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fecha_emision DATETIME NULL,
    fecha_revocacion DATETIME NULL,
    motivo_revocacion TEXT NULL,
    FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE RESTRICT,
    FOREIGN KEY (firmado_por_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_estado (estado),
    INDEX idx_creado_por (creado_por),
    INDEX idx_folio (folio),
    INDEX idx_fecha_creacion (fecha_creacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Tabla: usuario_claves (RSA-3072)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuario_claves (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL UNIQUE,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    clave_privada_encriptada BLOB NOT NULL,
    iv_encriptacion VARCHAR(64) NOT NULL,
    certificado_publico LONGTEXT NOT NULL,
    fingerprint VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revocada_en DATETIME NULL,
    download_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_downloaded_at DATETIME NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_usuario_id (usuario_id),
    INDEX idx_fingerprint (fingerprint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Tabla: key_download_tokens
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS key_download_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    tipo ENUM('key','cer') NOT NULL DEFAULT 'key',
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_token_hash (token_hash),
    INDEX idx_usuario_id (usuario_id),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Tabla: bitacora (auditoría)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS bitacora (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NULL,
    accion VARCHAR(60) NOT NULL,
    modulo VARCHAR(60) NOT NULL,
    documento_id INT UNSIGNED NULL,
    documento_folio VARCHAR(30) NULL,
    descripcion TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    resultado ENUM('success','failed') NOT NULL DEFAULT 'success',
    motivo_fallo TEXT NULL,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_usuario_id (usuario_id),
    INDEX idx_accion (accion),
    INDEX idx_modulo (modulo),
    INDEX idx_fecha (fecha),
    INDEX idx_resultado (resultado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
