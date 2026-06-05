-- ============================================================
-- Migration 004 (reemplazo): elimina la feature de auto-registro.
--
-- Reemplaza la migration 004 original, que creaba `usuarios_pendientes`.
-- Ahora el alta de usuarios la hace exclusivamente un administrador
-- desde el panel, por lo que la tabla y todo su flujo se eliminan.
--
-- Idempotente: seguro de correr en envs frescos o en envs que ya
-- habían ejecutado la 004 original.
-- ============================================================

USE casa_monarca;

DROP TABLE IF EXISTS usuarios_pendientes;
