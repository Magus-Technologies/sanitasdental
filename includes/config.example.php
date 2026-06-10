<?php
/**
 * config.example.php — PLANTILLA de configuración.
 *
 * Cópialo a `includes/config.php` y ajusta las contraseñas según tu entorno.
 * El archivo `config.php` real NO se versiona en git (ver .gitignore).
 *
 *   cp includes/config.example.php includes/config.php
 *   nano includes/config.php   # editar credenciales
 */

// Auto-detecta entorno (LOCAL vs PRODUCCIÓN) por hostname.
$__host  = $_SERVER['HTTP_HOST'] ?? gethostname();
$__isCli = PHP_SAPI === 'cli';
$__dir   = strtolower(str_replace('\\', '/', __DIR__));
$__isLocal = (
    str_contains($__host, 'localhost') ||
    str_contains($__host, '127.0.0.1') ||
    str_contains($__host, '.test')     ||
    str_contains($__host, '.local')    ||
    ($__isCli && (
        str_contains($__dir, '/laragon/') ||
        str_contains($__dir, '/xampp/')   ||
        str_contains($__dir, '/wamp')     ||
        str_contains($__dir, '/mamp/')
    ))
);

if ($__isLocal) {
    // ════════ LOCAL (Laragon / XAMPP) ════════
    define('DB_HOST',  'localhost');
    define('DB_NAME',  'dental');
    define('DB_USER',  'root');
    define('DB_PASS',  '');                              // ← cambia si usas contraseña en local
    define('BASE_URL', '/DentalSys');                     // ← carpeta bajo www
    define('APP_ENV',  'development');
    define('MIGRATIONS_TOKEN', 'dev_local_token_no_importa');
} else {
    // ════════ PRODUCCIÓN ════════
    define('DB_HOST',  'localhost');
    define('DB_NAME',  'dental');
    define('DB_USER',  'TU_USUARIO_DB');                  // ← cambia
    define('DB_PASS',  'TU_PASSWORD_DB');                 // ← cambia
    define('BASE_URL', '/dental');                        // ← path bajo el dominio
    define('APP_ENV',  'production');
    define('MIGRATIONS_TOKEN', 'GENERA_UN_TOKEN_LARGO_Y_ALEATORIO');
}

define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('APP_NAME',   'DentalSys');
date_default_timezone_set('America/Lima');

// ── BASE DE DATOS ──────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if (!$pdo) $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES=>false]
    );
    return $pdo;
}

// ── SESIÓN ─────────────────────────────────────────────
function sesion(): void {
    if (session_status()===PHP_SESSION_NONE) {
        session_set_cookie_params(['lifetime'=>28800,'path'=>'/','httponly'=>true,'samesite'=>'Lax']);
        session_start();
    }
}
function estaLogueado(): bool { sesion(); return isset($_SESSION['uid']); }
function getUsr(): array       { return $_SESSION['usr'] ?? []; }
function getRol(): string      { return $_SESSION['rol'] ?? ''; }
function esRol(string ...$r): bool { return in_array(getRol(),$r); }
function requiereLogin(): void { if(!estaLogueado()){ header('Location:'.BASE_URL.'/login.php'); exit; } }
function requiereRol(string ...$r): void { requiereLogin(); if(!esRol(...$r)){ header('Location:'.BASE_URL.'/sin-permiso.php'); exit; } }

// ── HELPERS ────────────────────────────────────────────
function e(string $s): string { return htmlspecialchars($s,ENT_QUOTES,'UTF-8'); }
function mon(float $m): string { return getCfg('moneda','S/').' '.number_format($m,2); }
function fDate(?string $d): string { return $d ? date('d/m/Y',strtotime($d)) : '—'; }
function fDT(?string $d): string   { return $d ? date('d/m/Y H:i',strtotime($d)) : '—'; }
function edad(?string $f): string  { return $f ? (new DateTime($f))->diff(new DateTime())->y.' años' : '—'; }
function go(string $url): void     { header('Location:'.BASE_URL.'/'.ltrim($url,'/')); exit; }

function flash(string $t, string $m): void { sesion(); $_SESSION['flash']=compact('t','m'); }
function popFlash(): string {
    sesion(); if(!isset($_SESSION['flash'])) return '';
    ['t'=>$t,'m'=>$m] = $_SESSION['flash']; unset($_SESSION['flash']);
    $cls=['ok'=>'alert-success','error'=>'alert-danger','warn'=>'alert-warning','info'=>'alert-info'][$t]??'alert-info';
    $ico=['ok'=>'check-circle-fill','error'=>'x-circle-fill','warn'=>'exclamation-triangle-fill','info'=>'info-circle-fill'][$t]??'info-circle-fill';
    return "<div class='alert $cls alert-dismissible fade show d-flex align-items-center gap-2'><i class='bi bi-$ico'></i><span>$m</span><button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
}

function getCfg(string $k, string $d=''): string {
    static $c=[];
    if(!isset($c[$k])){$s=db()->prepare("SELECT valor FROM configuracion WHERE clave=?");$s->execute([$k]);$c[$k]=$s->fetchColumn()?:$d;}
    return $c[$k];
}

/**
 * Devuelve datos de la empresa (single-tenant: registro id=1).
 *   empresa()           → array completo
 *   empresa('ruc')      → string del campo
 *   empresa('logo',true)→ ruta absoluta web del logo (con BASE_URL); '' si no hay
 */
function empresa(?string $campo = null, bool $urlLogo = false) {
    static $row = null;
    if ($row === null) {
        try { $row = db()->query("SELECT * FROM empresa WHERE id=1 LIMIT 1")->fetch() ?: []; }
        catch (Exception $e) { $row = []; }
    }
    if ($campo === null) return $row;
    $val = $row[$campo] ?? '';
    if ($urlLogo && $campo === 'logo' && $val) return BASE_URL.'/uploads/'.ltrim($val, '/');
    return $val;
}

/**
 * Reserva el siguiente correlativo para un tipo de documento.
 * Atómico: usa SELECT ... FOR UPDATE dentro de transacción.
 */
function siguienteCorrelativo(string $tipo, int $empresaId = 1): ?array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT id, serie, numero FROM documentos_empresa
                             WHERE empresa_id=? AND tipo=? AND activo=1
                             ORDER BY id ASC LIMIT 1 FOR UPDATE");
        $st->execute([$empresaId, $tipo]);
        $row = $st->fetch();
        if (!$row) { $pdo->rollBack(); return null; }
        $next = (int)$row['numero'] + 1;
        $pdo->prepare("UPDATE documentos_empresa SET numero=? WHERE id=?")->execute([$next, $row['id']]);
        $pdo->commit();
        return [
            'serie'      => $row['serie'],
            'numero'     => $next,
            'formateado' => $row['serie'].'-'.str_pad((string)$next, 8, '0', STR_PAD_LEFT),
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function genCodigo(string $pre, string $tabla, string $campo='codigo'): string {
    $n=(int)db()->query("SELECT MAX(CAST(SUBSTRING_INDEX($campo,'-',-1) AS UNSIGNED)) FROM $tabla WHERE $campo LIKE '$pre-%'")->fetchColumn();
    return $pre.'-'.str_pad($n+1,5,'0',STR_PAD_LEFT);
}

function subirArchivo(array $f, string $dir, array $exts=['jpg','jpeg','png','pdf','webp']): ?string {
    if($f['error']!==UPLOAD_ERR_OK) return null;
    $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
    if(!in_array($ext,$exts)||$f['size']>20*1024*1024) return null;
    $name=uniqid().'_'.time().'.'.$ext;
    $path=UPLOAD_PATH.$dir.'/';
    if(!is_dir($path)) mkdir($path,0755,true);
    return move_uploaded_file($f['tmp_name'],$path.$name) ? $dir.'/'.$name : null;
}

function auditar(string $accion, string $tabla='', int $id=0, string $datos=''): void {
    if(!estaLogueado()) return;
    try { db()->prepare("INSERT INTO auditoria(usuario_id,accion,tabla,registro_id,ip,datos)VALUES(?,?,?,?,?,?)")
        ->execute([$_SESSION['uid'],$accion,$tabla,$id?:null,$_SERVER['REMOTE_ADDR']??null,$datos?:null]); }
    catch(Exception $e){}
}

function urlWA(string $tel, string $msg): string {
    $t=preg_replace('/[^0-9]/','',trim($tel));
    if(strlen($t)===9) $t='51'.$t;
    return 'https://web.whatsapp.com/send?phone='.$t.'&text='.urlencode($msg);
}
