<?php
/**
 * DentalSys — Configuración SUNAT
 * ───────────────────────────────
 * Lee datos de emisor desde la tabla `empresa` y series desde `documentos_empresa`.
 * LOCAL: fuerza beta + RUC de prueba.
 */

$__host    = $_SERVER['HTTP_HOST'] ?? gethostname();
$__isLocal = (
    str_contains($__host, 'localhost') ||
    str_contains($__host, '127.0.0.1') ||
    str_contains($__host, '.test')     ||
    str_contains($__host, '.local')
);

if ($__isLocal) {
    define('SUNAT_API_URL', 'http://api-sunat-laravel.test/api/v1');
} else {
    define('SUNAT_API_URL', 'https://magus-qa.com/api-sunat-laravel/api/v1');
}

define('SUNAT_API_TIMEOUT', 60);

// ─── Leer datos del emisor desde BD ─────────────────────────────
function loadSunatConfigFromDB(): array {
    if (!isset($GLOBALS['__sunat_cfg_loaded'])) {
        $GLOBALS['__sunat_cfg_loaded'] = true;
        try {
            $emp = db()->query("SELECT * FROM empresa LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $series = db()->query(
                "SELECT tipo, serie FROM documentos_empresa WHERE activo = 1"
            )->fetchAll(PDO::FETCH_KEY_PAIR);
            $GLOBALS['__sunat_cfg_db']     = $emp    ?: [];
            $GLOBALS['__sunat_cfg_series'] = $series ?: [];
        } catch (Exception $e) {
            $GLOBALS['__sunat_cfg_db']     = [];
            $GLOBALS['__sunat_cfg_series'] = [];
        }
    }
    return $GLOBALS['__sunat_cfg_db'] ?? [];
}

$__emp    = loadSunatConfigFromDB();
$__series = $GLOBALS['__sunat_cfg_series'] ?? [];

// ─── Modo y credenciales SOL ─────────────────────────────────────
if ($__isLocal) {
    define('SUNAT_ENDPOINT',    'beta');
    define('SUNAT_RUC',         '20000000001');
    define('SUNAT_USUARIO_SOL', 'MODDATOS');
    define('SUNAT_CLAVE_SOL',   'MODDATOS');
} else {
    define('SUNAT_ENDPOINT',    $__emp['modo']              ?? 'produccion');
    define('SUNAT_RUC',         $__emp['ruc']               ?? '20000000001');
    define('SUNAT_USUARIO_SOL', $__emp['sunat_usuario_sol'] ?? 'MODDATOS');
    define('SUNAT_CLAVE_SOL',   $__emp['sunat_clave_sol']   ?? 'MODDATOS');
}

// ─── Datos del emisor ─────────────────────────────────────────────
define('SUNAT_RAZON_SOCIAL',     $__emp['razon_social']     ?? 'EMPRESA DE PRUEBAS S.A.C.');
define('SUNAT_NOMBRE_COMERCIAL', $__emp['nombre_comercial'] ?? ($__emp['razon_social'] ?? 'DentalSys'));
define('SUNAT_DIRECCION',        $__emp['direccion']        ?? 'AV. PRUEBA 123');
define('SUNAT_UBIGEO',           $__emp['ubigeo']           ?? '150101');
define('SUNAT_DISTRITO',         $__emp['distrito']         ?? 'LIMA');
define('SUNAT_PROVINCIA',        $__emp['provincia']        ?? 'LIMA');
define('SUNAT_DEPARTAMENTO',     $__emp['departamento']     ?? 'LIMA');

// ─── Series ───────────────────────────────────────────────────────
define('SUNAT_SERIE_FACTURA', $__series['factura'] ?? 'F001');
define('SUNAT_SERIE_BOLETA',  $__series['boleta']  ?? 'B001');

define('SUNAT_SERIE_NC_FACTURA', 'FC01');
define('SUNAT_SERIE_NC_BOLETA',  'BC01');
define('SUNAT_SERIE_ND_FACTURA', 'FD01');
define('SUNAT_SERIE_ND_BOLETA',  'BD01');
