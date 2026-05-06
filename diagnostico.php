<?php
/**
 * diagnostico.php — Diagnóstico DentalSys
 * Subir a /var/www/html/dental/diagnostico.php
 * Abrir: https://magus-ecommerce.com/dental/diagnostico.php
 * BORRAR después de usar
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo '<style>body{font-family:monospace;background:#111A26;color:#E8EDF2;padding:24px;font-size:14px;line-height:1.8}
.ok{color:#2ECC8E;font-weight:bold}.fail{color:#E05252;font-weight:bold}.warn{color:#F5A623}
pre{background:#0E1621;padding:12px;border-radius:6px;border:1px solid rgba(0,212,238,.15);white-space:pre-wrap;overflow-x:auto}
h2{color:#00D4EE;margin:20px 0 6px;font-size:15px}hr{border-color:rgba(0,212,238,.15);margin:16px 0}</style>';
echo '<h1 style="color:#fff;font-size:20px">🔧 Diagnóstico DentalSys</h1>';

// 1. PHP
echo '<h2>1. PHP</h2>';
echo 'PHP: <span class="ok">'.PHP_VERSION.'</span><br>';
echo 'OS: '.PHP_OS.'<br>';
echo 'SCRIPT_FILENAME: '.($_SERVER['SCRIPT_FILENAME']??'N/A').'<br>';
echo 'DOCUMENT_ROOT: '.($_SERVER['DOCUMENT_ROOT']??'N/A').'<br>';

// 2. Extensiones
echo '<h2>2. Extensiones PHP</h2>';
$exts = ['pdo','pdo_mysql','json','mbstring','fileinfo','session'];
foreach($exts as $e) {
    echo extension_loaded($e)
        ? "<span class='ok'>✓ $e</span><br>"
        : "<span class='fail'>✗ $e — FALTA</span><br>";
}

// 3. Sesiones
echo '<h2>3. Sesiones</h2>';
session_start();
echo '<span class="ok">✓ session_start() OK</span><br>';
echo 'session_save_path: '.session_save_path().'<br>';
$_SESSION['test'] = 'ok';
echo 'Session write: <span class="'.($_SESSION['test']==='ok'?'ok':'fail').'">'.($_SESSION['test']==='ok'?'✓ OK':'✗ FALLO').'</span><br>';

// 4. DB connection
echo '<h2>4. Conexión a la base de datos</h2>';
// Try to read config
$cfgFile = __DIR__.'/includes/config.php';
if(!file_exists($cfgFile)) {
    echo '<span class="fail">✗ includes/config.php no encontrado</span><br>';
} else {
    // Extract constants without requiring the file (which starts session)
    $cfgContent = file_get_contents($cfgFile);
    preg_match("/define\('DB_HOST',\s*'([^']*)'\)/", $cfgContent, $host);
    preg_match("/define\('DB_NAME',\s*'([^']*)'\)/", $cfgContent, $name);
    preg_match("/define\('DB_USER',\s*'([^']*)'\)/", $cfgContent, $user);
    preg_match("/define\('DB_PASS',\s*'([^']*)'\)/", $cfgContent, $pass);
    $dbHost = $host[1]??'localhost';
    $dbName = $name[1]??'dental';
    $dbUser = $user[1]??'root';
    $dbPass = $pass[1]??'';
    echo "Host: <strong>$dbHost</strong> | DB: <strong>$dbName</strong> | User: <strong>$dbUser</strong><br>";
    try {
        $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        echo '<span class="ok">✓ Conexión exitosa</span><br>';
        // Check tables
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        echo 'Tablas encontradas: <strong>'.count($tables).'</strong><br>';
        $required = ['usuarios','pacientes','citas','historias_clinicas','odontogramas','pagos','inventario','configuracion','auditoria'];
        foreach($required as $t) {
            echo in_array($t,$tables)
                ? "<span class='ok'>  ✓ $t</span><br>"
                : "<span class='fail'>  ✗ $t — FALTA</span><br>";
        }
        // Check users
        $users = $pdo->query("SELECT id,email,rol_id,activo FROM usuarios")->fetchAll();
        echo '<br>Usuarios registrados: <strong>'.count($users).'</strong><br>';
        foreach($users as $u) {
            echo "  ID:{$u['id']} | {$u['email']} | rol:{$u['rol_id']} | activo:{$u['activo']}<br>";
        }
        // Test password
        if($users) {
            $u = $pdo->query("SELECT password FROM usuarios WHERE email='admin@dental.com' LIMIT 1")->fetchColumn();
            $ok = password_verify('password', $u);
            echo '<br>Test contraseña admin (password): <span class="'.($ok?'ok':'fail').'">'.($ok?'✓ OK':'✗ FALLO — hash incorrecto').'</span><br>';
            if(!$ok) {
                $newHash = password_hash('password', PASSWORD_BCRYPT);
                echo '<span class="warn">Ejecuta este SQL para arreglar:<br>';
                echo "<pre>UPDATE usuarios SET password='$newHash' WHERE email IN('admin@dental.com','doctor@dental.com','recepcion@dental.com');</pre></span>";
            }
        }
    } catch(PDOException $e) {
        echo '<span class="fail">✗ Error de conexión: '.$e->getMessage().'</span><br>';
        echo '<span class="warn">Verifica DB_HOST, DB_NAME, DB_USER, DB_PASS en includes/config.php</span><br>';
    }
}

// 5. Uploads writable
echo '<h2>5. Permisos de uploads</h2>';
$dirs = ['uploads','uploads/radiografias','uploads/fotos','uploads/docs','uploads/consentimientos'];
foreach($dirs as $d) {
    $full = __DIR__.'/'.$d;
    if(!is_dir($full)) { mkdir($full,0755,true); echo "<span class='warn'>⚠ Creado: $d</span><br>"; continue; }
    echo is_writable($full)
        ? "<span class='ok'>✓ Escribible: $d</span><br>"
        : "<span class='fail'>✗ Sin permisos: $d</span><br>";
}

// 6. BASE_URL
echo '<h2>6. BASE_URL y rutas</h2>';
$cfgContent = file_get_contents(__DIR__.'/includes/config.php');
preg_match("/define\('BASE_URL',\s*'([^']*)'\)/", $cfgContent, $burl);
echo 'BASE_URL configurado: <strong>'.($burl[1]??'NO ENCONTRADO').'</strong><br>';
echo 'URL actual: <strong>'.$_SERVER['REQUEST_URI'].'</strong><br>';
$expected = dirname($_SERVER['REQUEST_URI']);
echo 'Debería ser: <strong>/dental</strong><br>';
if(($burl[1]??'')!=='/dental') {
    echo '<span class="warn">⚠ Si el proyecto no está en /dental, actualiza BASE_URL en config.php</span><br>';
}

// 7. Error log location
echo '<h2>7. PHP Error Log</h2>';
echo 'error_log: '.ini_get('error_log').'<br>';
echo 'display_errors: '.ini_get('display_errors').'<br>';
echo 'log_errors: '.ini_get('log_errors').'<br>';
$logFile = ini_get('error_log');
if($logFile && file_exists($logFile)) {
    $lines = array_slice(file($logFile), -15);
    echo '<br>Últimas líneas del error log:<br>';
    echo '<pre style="font-size:11px">'.htmlspecialchars(implode('',$lines)).'</pre>';
} else {
    // Try Apache log
    $apacheLogs = ['/var/log/httpd/error_log','/var/log/apache2/error.log','/var/log/httpd/error.log'];
    foreach($apacheLogs as $lg) {
        if(file_exists($lg) && is_readable($lg)) {
            $lines = array_slice(file($lg), -10);
            echo "Apache log ($lg):<br><pre style='font-size:11px'>".htmlspecialchars(implode('',$lines)).'</pre>';
            break;
        }
    }
}

echo '<hr><p style="color:rgba(160,176,192,.4);font-size:11px">⚠ BORRAR este archivo después del diagnóstico</p>';
?>
