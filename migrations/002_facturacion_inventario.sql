-- 002_facturacion_inventario.sql
-- Vincula items de facturación al inventario para descontar stock al emitir.

ALTER TABLE pago_detalles
  ADD COLUMN inventario_id INT UNSIGNED NULL AFTER pago_id,
  ADD INDEX idx_pd_inv (inventario_id);
