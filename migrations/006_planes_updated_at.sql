-- 006_planes_updated_at.sql
-- Agrega columna updated_at a planes_tratamiento para registrar última modificación.

ALTER TABLE `planes_tratamiento`
    ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT NULL
        ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;
