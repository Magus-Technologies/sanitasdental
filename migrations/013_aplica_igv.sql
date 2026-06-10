-- 013_aplica_igv.sql
-- Agrega columna aplica_igv a pagos para permitir comprobantes exonerados/inafectos de IGV.

ALTER TABLE `pagos`
    ADD COLUMN `aplica_igv` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=IGV incluido, 0=exonerado/inafecto' AFTER `descuento`;
