-- Agregar columna inventario_id a receta_medicamentos
ALTER TABLE receta_medicamentos ADD COLUMN inventario_id INT(10) UNSIGNED DEFAULT NULL AFTER receta_id;

-- Agregar foreign key (opcional, para integridad referencial)
ALTER TABLE receta_medicamentos ADD CONSTRAINT fk_receta_med_inventario FOREIGN KEY (inventario_id) REFERENCES inventario(id) ON DELETE SET NULL;
