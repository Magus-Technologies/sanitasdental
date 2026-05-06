-- 008_quitar_sunat_api_url.sql
-- La URL del API SUNAT está auto-detectada en config_sunat.php (local vs producción).
-- No tiene sentido configurarla por empresa: la quitamos.

ALTER TABLE `empresa` DROP COLUMN `sunat_api_url`;
