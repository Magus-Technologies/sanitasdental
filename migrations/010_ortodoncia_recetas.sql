-- Migración 010: Tablas para Ortodoncia y Recetarios
-- Fecha: 2026-05-04

-- Tabla para controles de ortodoncia
CREATE TABLE IF NOT EXISTS `ortodoncias` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `paciente_id` int(10) unsigned NOT NULL,
  `hc_id` int(10) unsigned DEFAULT NULL,
  `tipo` enum('instalacion','control') NOT NULL DEFAULT 'control',
  `fecha_atencion` date NOT NULL,
  `fecha_referencia` date DEFAULT NULL,
  `tipo_arco` text DEFAULT NULL COMMENT 'JSON con tipos de arco seleccionados',
  `observaciones` text DEFAULT NULL,
  `procedimientos` text DEFAULT NULL,
  `proximo_control` date DEFAULT NULL,
  `doctor_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_paciente` (`paciente_id`),
  KEY `idx_hc` (`hc_id`),
  KEY `idx_fecha` (`fecha_atencion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabla para recetarios
CREATE TABLE IF NOT EXISTS `recetas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `paciente_id` int(10) unsigned NOT NULL,
  `hc_id` int(10) unsigned DEFAULT NULL,
  `codigo` varchar(20) NOT NULL,
  `fecha_prescripcion` date NOT NULL,
  `valido_hasta` date DEFAULT NULL,
  `indicaciones_generales` text DEFAULT NULL,
  `doctor_id` int(10) unsigned DEFAULT NULL,
  `estado` enum('activa','vencida','anulada') DEFAULT 'activa',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`),
  KEY `idx_paciente` (`paciente_id`),
  KEY `idx_hc` (`hc_id`),
  KEY `idx_fecha` (`fecha_prescripcion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabla para detalles de medicamentos en recetas
CREATE TABLE IF NOT EXISTS `receta_medicamentos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `receta_id` int(10) unsigned NOT NULL,
  `medicamento` varchar(200) NOT NULL,
  `numero_tomas` int(11) DEFAULT 1,
  `frecuencia` varchar(100) DEFAULT NULL,
  `hora_sugerida` varchar(50) DEFAULT NULL,
  `indicaciones` text DEFAULT NULL,
  `orden` tinyint(4) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_receta` (`receta_id`),
  CONSTRAINT `fk_receta_med_receta` FOREIGN KEY (`receta_id`) REFERENCES `recetas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Agregar campo foto_perfil a pacientes (si no existe)
ALTER TABLE `pacientes` 
ADD COLUMN `foto_perfil` varchar(255) DEFAULT NULL COMMENT 'Ruta de la foto de perfil' 
AFTER `contacto_parentesco`;