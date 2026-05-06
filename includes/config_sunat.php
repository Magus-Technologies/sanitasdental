<?php
/**
 * DentalSys — Configuración SUNAT
 * -------------------------------
 * Auto-detecta entorno (LOCAL vs PRODUCCIÓN) por hostname.
 * Los datos del emisor se leen de la tabla `empresa` (id=1) para
 * reflejar siempre el modo (beta/produccion) y credenciales reales.
 */

$__host = $_SERVER['HTTP_HOST'] ?? gethostname();
$__isLocal = (
    str_contains($__host, 'localhost') ||
    str_contains($__host, '127.0.0.1') ||
    str_contains($__host, '.test')     ||
    str_contains($__host, '.local')
);

if ($__isLocal) {
    define('SUNAT_API_URL', 'http://api-sunat-laravel.test/api/v1');
} else {
    define('SUNAT_API_URL', 'http://84.247.162.204/api-sunat-laravel/api/v1');
}

define('SUNAT_API_TIMEOUT', 60);

// --- Leer empresa desde BD (sin session/DB si es muy temprano) --
// Si db() no está disponible aún, definimos valores por defecto
// que serán sobrescritos al llamar a SunatBuilder/SunatService.
$__emp = [];
if (function_exists('db')) {
    try {
        $st = db()->prepare("SELECT * FROM empresa WHERE id=1 LIMIT 1");
        $st->execute();
        $__emp = $st->fetch() ?: [];
    } catch (Throwable $e) {
        $__emp = [];
    }
}

// 'beta' | 'produccion' — leído del modo de la empresa
$__modo = ($__emp['modo'] ?? 'beta') === 'produccion' ? 'produccion' : 'beta';
define('SUNAT_ENDPOINT', $__modo);

// --- Credenciales SOL desde la empresa -----------------------
define('SUNAT_RUC',         $__emp['ruc']              ?? '20000000001');
define('SUNAT_USUARIO_SOL', $__emp['sunat_usuario_sol'] ?? 'MODDATOS');
define('SUNAT_CLAVE_SOL',   $__emp['sunat_clave_sol']   ?? 'MODDATOS');

// --- Datos del emisor desde la empresa ----------------------
define('SUNAT_RAZON_SOCIAL',     $__emp['razon_social']      ?? 'EMPRESA DE PRUEBAS S.A.C.');
define('SUNAT_NOMBRE_COMERCIAL', $__emp['nombre_comercial']  ?? $__emp['razon_social'] ?? 'DentalSys');
define('SUNAT_DIRECCION',        $__emp['direccion']         ?? 'AV. PRUEBA 123');
define('SUNAT_UBIGEO',           $__emp['ubigeo']            ?? '150101');
define('SUNAT_DISTRITO',         $__emp['distrito']          ?? 'LIMA');
define('SUNAT_PROVINCIA',        $__emp['provincia']         ?? 'LIMA');
define('SUNAT_DEPARTAMENTO',     $__emp['departamento']      ?? 'LIMA');

// --- Series (reserva por defecto, el correlativo real usa documentos_empresa) -
define('SUNAT_SERIE_FACTURA', 'F001');
define('SUNAT_SERIE_BOLETA',  'B001');
