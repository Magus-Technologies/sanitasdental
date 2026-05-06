-- 005_documentos_empresa.sql
-- Series y correlativos por tipo de documento (boleta/factura/nota_venta)
-- + extender ENUM de tipo_comprobante en `pagos` para soportar nota_venta.

CREATE TABLE IF NOT EXISTS `documentos_empresa` (
    `id`         INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `empresa_id` INT UNSIGNED   NOT NULL DEFAULT 1,
    `tipo`       ENUM('boleta','factura','nota_venta','nota_credito','ticket') NOT NULL,
    `serie`      VARCHAR(4)     NOT NULL,
    `numero`     INT UNSIGNED   NOT NULL DEFAULT 0 COMMENT 'Último número emitido',
    `descripcion` VARCHAR(100)  NULL,
    `activo`     TINYINT(1)     NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_emp_tipo_serie` (`empresa_id`, `tipo`, `serie`),
    KEY `idx_tipo` (`tipo`),
    KEY `idx_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Series por defecto (puedes editarlas desde Admin → Documentos)
INSERT INTO `documentos_empresa` (`empresa_id`, `tipo`, `serie`, `numero`, `descripcion`) VALUES
    (1, 'boleta',     'B001', 0, 'Boleta electrónica'),
    (1, 'factura',    'F001', 0, 'Factura electrónica'),
    (1, 'nota_venta', 'NV01', 0, 'Nota de venta interna (no SUNAT)')
ON DUPLICATE KEY UPDATE `id` = `id`;

-- Extender el ENUM en pagos para incluir nota_venta y nota_credito
ALTER TABLE `pagos`
    MODIFY COLUMN `tipo_comprobante`
        ENUM('boleta','factura','ticket','nota_venta','nota_credito') NULL DEFAULT 'ticket';
