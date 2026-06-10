-- Migration 014: Claves SUNAT en tabla configuracion
-- Permite gestionar credenciales SOL y modo desde la BD sin tocar código.
-- IMPORTANTE: Actualizar sunat_usuario_sol, sunat_clave_sol, ubigeo y distrito
--             con los valores reales de la empresa antes de enviar a SUNAT.

INSERT IGNORE INTO configuracion (clave, valor, grupo) VALUES
  ('sunat_modo',        'produccion', 'sunat'),
  ('sunat_usuario_sol', 'MODDATOS',   'sunat'),
  ('sunat_clave_sol',   'MODDATOS',   'sunat'),
  ('sunat_ubigeo',      '100109',     'sunat'),
  ('sunat_distrito',    'PILLCO MARCA', 'sunat'),
  ('sunat_provincia',   'HUANUCO',    'sunat'),
  ('sunat_departamento','HUANUCO',    'sunat'),
  ('serie_factura',     'F001',       'sunat'),
  ('serie_boleta',      'B001',       'sunat');
