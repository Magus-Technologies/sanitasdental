-- Migración 012: Agregar campo dientes_json a ortodoncias
-- Fecha: 2026-05-04

ALTER TABLE `ortodoncias` 
ADD COLUMN `dientes_json` TEXT DEFAULT NULL COMMENT 'JSON con números de dientes que tienen brackets' 
AFTER `tipo_arco`;
