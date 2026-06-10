<?php
/**
 * DentalSys — Configuración SUNAT
 * ───────────────────────────────
 * Auto-detecta entorno (LOCAL vs PRODUCCIÓN) por hostname.
 */

$__host = $_SERVER['HTTP_HOST'] ?? gethostname();
$__isLocal = (
    str_contains($__host, 'localhost') ||
    str_contains($__host, '127.0.0.1') ||
    str_contains($__host, '.test')     ||
    str_contains($__host, '.local')
);

if ($__isLocal) {
    // C:\laragon\www\api-sunat-laravel
    define('SUNAT_API_URL', 'http://api-sunat-laravel.test/api/v1');
} else {
    // ↳ Cambia a la URL pública de tu API Laravel
   define('SUNAT_API_URL', 'https://magus-qa.com/api-sunat-laravel/api/v1');

}

define('SUNAT_API_TIMEOUT', 60);

// ─── Leer configuración de BD ────────────────────────────────
function loadSunatConfigFromDB(): array {
    if (!isset($GLOBALS['__sunat_cfg_loaded'])) {
        $GLOBALS['__sunat_cfg_loaded'] = true;
        try {
            $rows = db()->query("SELECT clave, valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);
            $GLOBALS['__sunat_cfg_db'] = $rows;
        } catch (Exception $e) {
            $GLOBALS['__sunat_cfg_db'] = [];
        }
    }
    return $GLOBALS['__sunat_cfg_db'] ?? [];
}

$__cfg = loadSunatConfigFromDB();

// ─── Modo y credenciales SOL ─────────────────────────────────
// LOCAL: siempre beta + credenciales de prueba SUNAT, sin importar la BD.
// PRODUCCIÓN: lee de la tabla configuracion.
if ($__isLocal) {
    define('SUNAT_ENDPOINT',    'beta');
    define('SUNAT_RUC',         '20000000001');
    define('SUNAT_USUARIO_SOL', 'MODDATOS');
    define('SUNAT_CLAVE_SOL',   'MODDATOS');
} else {
    define('SUNAT_ENDPOINT',    $__cfg['sunat_modo']        ?? 'produccion');
    define('SUNAT_RUC',         $__cfg['clinica_ruc']       ?? '20000000001');
    define('SUNAT_USUARIO_SOL', $__cfg['sunat_usuario_sol'] ?? 'MODDATOS');
    define('SUNAT_CLAVE_SOL',   $__cfg['sunat_clave_sol']   ?? 'MODDATOS');
}

// ─── Datos del emisor ────────────────────────────────────────
define('SUNAT_RAZON_SOCIAL',     $__cfg['clinica_nombre']    ?? 'EMPRESA DE PRUEBAS S.A.C.');
define('SUNAT_NOMBRE_COMERCIAL', $__cfg['clinica_nombre']    ?? 'DentalSys');
define('SUNAT_DIRECCION',        $__cfg['clinica_direccion'] ?? 'AV. PRUEBA 123');
define('SUNAT_UBIGEO',           $__cfg['sunat_ubigeo']      ?? '150101');
define('SUNAT_DISTRITO',         $__cfg['sunat_distrito']    ?? 'LIMA');
define('SUNAT_PROVINCIA',        $__cfg['sunat_provincia']   ?? 'LIMA');
define('SUNAT_DEPARTAMENTO',     $__cfg['sunat_departamento']?? 'LIMA');

// ─── Series ──────────────────────────────────────────────────
define('SUNAT_SERIE_FACTURA', $__cfg['serie_factura'] ?? 'F001');
define('SUNAT_SERIE_BOLETA',  $__cfg['serie_boleta']  ?? 'B001');

define('SUNAT_SERIE_NC_FACTURA', 'FC01');
define('SUNAT_SERIE_NC_BOLETA',  'BC01');
define('SUNAT_SERIE_ND_FACTURA', 'FD01');
define('SUNAT_SERIE_ND_BOLETA',  'BD01');
