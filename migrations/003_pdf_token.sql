-- 003_pdf_token.sql
-- Token público para compartir el PDF de un comprobante por link sin login.
ALTER TABLE pagos
    ADD COLUMN pdf_token CHAR(40) NULL UNIQUE AFTER sunat_fecha;
