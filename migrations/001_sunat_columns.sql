-- =============================================================
-- 001 — Columnas SUNAT en `pagos` + `ruc` en `pacientes`
-- Aplicar:  mysql -u root -p dental < migrations/001_sunat_columns.sql
--           o vía web: /dental/migrations/migrate.php
-- =============================================================

ALTER TABLE `pagos`
    ADD COLUMN `tipo_comprobante` ENUM('boleta','factura','ticket') NULL DEFAULT 'ticket' AFTER `referencia`,
    ADD COLUMN `serie`            VARCHAR(10)   NULL DEFAULT NULL   AFTER `tipo_comprobante`,
    ADD COLUMN `numero`           INT UNSIGNED  NULL DEFAULT NULL   AFTER `serie`,
    ADD COLUMN `sunat_estado`     ENUM('pendiente','aceptado','rechazado') NULL DEFAULT NULL AFTER `numero`,
    ADD COLUMN `sunat_hash`       VARCHAR(255)  NULL DEFAULT NULL   AFTER `sunat_estado`,
    ADD COLUMN `sunat_qr`         TEXT          NULL                AFTER `sunat_hash`,
    ADD COLUMN `sunat_xml`        LONGTEXT      NULL                AFTER `sunat_qr`,
    ADD COLUMN `sunat_cdr`        LONGTEXT      NULL                AFTER `sunat_xml`,
    ADD COLUMN `sunat_mensaje`    VARCHAR(1000) NULL                AFTER `sunat_cdr`,
    ADD COLUMN `sunat_fecha`      DATETIME      NULL                AFTER `sunat_mensaje`;

-- Necesario para emitir facturas
ALTER TABLE `pacientes`
    ADD COLUMN `ruc` VARCHAR(11) NULL DEFAULT NULL AFTER `dni`;
