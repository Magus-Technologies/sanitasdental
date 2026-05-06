-- 004_empresa.sql
-- Tabla de empresa (datos de la clínica) con logo y datos para SUNAT/PDF.
-- Single-tenant: un solo registro (id=1). Extensible a multi en el futuro.

CREATE TABLE IF NOT EXISTS `empresa` (
    `id`               INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `ruc`              VARCHAR(11)    NOT NULL,
    `razon_social`     VARCHAR(255)   NOT NULL,
    `nombre_comercial` VARCHAR(255)   NULL,
    `direccion`        VARCHAR(255)   NULL,
    `ubigeo`           VARCHAR(6)     NULL,
    `distrito`         VARCHAR(100)   NULL,
    `provincia`        VARCHAR(100)   NULL,
    `departamento`     VARCHAR(100)   NULL,
    `telefono`         VARCHAR(30)    NULL,
    `telefono2`        VARCHAR(30)    NULL,
    `email`            VARCHAR(150)   NULL,
    `web`              VARCHAR(150)   NULL,
    `logo`             VARCHAR(255)   NULL COMMENT 'Ruta relativa dentro de uploads/',
    `igv`              DECIMAL(5,2)   NOT NULL DEFAULT 18.00,
    `moneda`           VARCHAR(10)    NOT NULL DEFAULT 'S/',
    `color_primario`   VARCHAR(7)     NULL DEFAULT '#00d4ee',
    `propaganda`       VARCHAR(255)   NULL COMMENT 'Texto promocional al pie del PDF',
    `pie_pagina`       TEXT           NULL,
    `modo`             ENUM('produccion','beta') NOT NULL DEFAULT 'beta',
    `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_empresa_ruc` (`ruc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro inicial (placeholder editable desde el módulo)
INSERT INTO `empresa` (`id`, `ruc`, `razon_social`, `nombre_comercial`)
VALUES (1, '00000000000', 'MI CLÍNICA DENTAL', 'DentalSys')
ON DUPLICATE KEY UPDATE `id` = `id`;
