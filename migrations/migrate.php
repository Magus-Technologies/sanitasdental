<?php
/**
 * Migrador casero para DentalSys.
 *
 *   USO (CLI):  php migrations/migrate.php
 *   USO (web):  http://localhost/dental/migrations/migrate.php?token=...
 *
 * Las migraciones DEBEN llamarse `NNN_descripcion.sql`.
 */
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: text/plain; charset=utf-8');

// Token simple: en local sin restricción, en cualquier otro host se exige.
$esCli = (PHP_SAPI === 'cli');
$host  = $_SERVER['HTTP_HOST'] ?? gethostname();
$esLoc = str_contains($host, 'localhost') || str_contains($host, '127.0.0.1') || str_contains($host, '.test');
if (!$esCli && !$esLoc) {
    $token   = $_GET['token'] ?? '';
    $esperado = defined('MIGRATIONS_TOKEN') ? MIGRATIONS_TOKEN : '';
    if ($esperado === '' || !hash_equals($esperado, $token)) {
        http_response_code(403);
        echo "403 — Acceso denegado.\n";
        exit;
    }
}

$db = db();
$db->exec("CREATE TABLE IF NOT EXISTS _migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    archivo VARCHAR(255) NOT NULL UNIQUE,
    ejecutada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$ya = array_flip($db->query("SELECT archivo FROM _migrations")->fetchAll(PDO::FETCH_COLUMN));
$archivos = glob(__DIR__ . '/[0-9]*.sql');
sort($archivos, SORT_STRING);

if (!$archivos) { echo "No hay archivos .sql en /migrations\n"; exit; }

$n = 0;
foreach ($archivos as $ruta) {
    $nombre = basename($ruta);
    if (isset($ya[$nombre])) { echo "•  $nombre   (ya aplicada)\n"; continue; }
    try {
        $db->exec(file_get_contents($ruta));
        $db->prepare("INSERT INTO _migrations (archivo) VALUES (?)")->execute([$nombre]);
        echo "OK $nombre\n";
        $n++;
    } catch (Throwable $e) {
        echo "ERR $nombre — " . $e->getMessage() . "\n";
        exit(1);
    }
}
echo "\n$n migración(es) aplicada(s).\n";
