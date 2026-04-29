-- Casa Monarca v2 - Migration 001: Datos iniciales
USE casa_monarca;

-- Admin por defecto: admin@empresa.local / admin123
-- password_hash = bcrypt("admin123") con cost 10
INSERT INTO usuarios (nombre, email, password_hash, rol, activo)
VALUES (
    'Administrador',
    'admin@empresa.local',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'administrador',
    1
) ON DUPLICATE KEY UPDATE id=id;
