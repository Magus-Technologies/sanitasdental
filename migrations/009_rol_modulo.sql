-- 009_rol_modulo.sql
-- Permisos por rol: cada rol puede tener acceso a uno o varios módulos.
-- El rol "admin" SIEMPRE ve todo (bypass en código), no requiere registros aquí.

CREATE TABLE IF NOT EXISTS `rol_modulo` (
    `rol_id`     TINYINT UNSIGNED NOT NULL,
    `modulo`     VARCHAR(40)      NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`rol_id`, `modulo`),
    KEY `idx_modulo` (`modulo`),
    CONSTRAINT `fk_rolmod_rol` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Permisos por defecto ─────────────────────────────────────────
-- 1=admin (no se siembra, ve todo)
-- 2=doctor    : pacientes, citas, hc, odontograma, tratamientos, reportes
-- 3=recepcion : pacientes, citas, facturacion, inventario, notificaciones, turnos, reportes
-- 4=contador  : facturacion, reportes, documentos
-- 5=paciente  : (sin acceso al sistema, no se siembra)

INSERT INTO `rol_modulo` (`rol_id`, `modulo`) VALUES
    (2, 'dashboard'),
    (2, 'pacientes'),
    (2, 'citas'),
    (2, 'historia_clinica'),
    (2, 'odontograma'),
    (2, 'tratamientos'),
    (2, 'reportes'),

    (3, 'dashboard'),
    (3, 'pacientes'),
    (3, 'citas'),
    (3, 'tratamientos'),
    (3, 'facturacion'),
    (3, 'inventario'),
    (3, 'notificaciones'),
    (3, 'turnos'),
    (3, 'reportes'),

    (4, 'dashboard'),
    (4, 'facturacion'),
    (4, 'documentos'),
    (4, 'inventario'),
    (4, 'reportes')
ON DUPLICATE KEY UPDATE `rol_id` = `rol_id`;
