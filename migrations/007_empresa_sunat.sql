-- 007_empresa_sunat.sql
-- Agrega configuración SUNAT a la tabla empresa: credenciales SOL,
-- URL del API Laravel firmador, y flag de certificado .pem cargado.

ALTER TABLE `empresa`
    ADD COLUMN `sunat_usuario_sol`  VARCHAR(45)  NULL DEFAULT NULL AFTER `modo`,
    ADD COLUMN `sunat_clave_sol`    VARCHAR(45)  NULL DEFAULT NULL AFTER `sunat_usuario_sol`,
    ADD COLUMN `sunat_api_url`      VARCHAR(255) NULL DEFAULT NULL AFTER `sunat_clave_sol`,
    ADD COLUMN `certificado_subido` TINYINT(1)   NOT NULL DEFAULT 0 AFTER `sunat_api_url`,
    ADD COLUMN `certificado_fecha`  DATETIME     NULL DEFAULT NULL AFTER `certificado_subido`;
