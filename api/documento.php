<?php
/**
 * Proxy a APIs Perú (RENIEC/SUNAT) para consultar DNI o RUC.
 * Se llama desde el frontend con: includes/api_documento.php?doc=XXXXXXXX
 * Mantiene el token en el servidor para no exponerlo al cliente.
 */
require_once __DIR__.'/config.php';
requiereLogin();
header('Content-Type: application/json; charset=utf-8');

const APISPERU_URL   = 'https://dniruc.apisperu.com/api/v1';
const APISPERU_TOKEN = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJlbWFpbCI6InN5c3RlbWNyYWZ0LnBlQGdtYWlsLmNvbSJ9.yuNS5hRaC0hCwymX_PjXRoSZJWLNNBeOdlLRSUGlHGA';

$doc = preg_replace('/\D/', '', $_GET['doc'] ?? '');
$len = strlen($doc);

if ($len !== 8 && $len !== 11) {
    echo json_encode(['ok'=>false, 'msg'=>'Ingrese 8 dígitos (DNI) u 11 dígitos (RUC).']);
    exit;
}

$tipo = $len === 8 ? 'dni' : 'ruc';
$url  = APISPERU_URL.'/'.$tipo.'/'.$doc.'?token='.APISPERU_TOKEN;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    CURLOPT_SSL_VERIFYPEER => false,
]);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($code !== 200 || !$res) {
    echo json_encode([
        'ok'   => false,
        'msg'  => $tipo === 'dni' ? 'DNI no encontrado en RENIEC.' : 'RUC no encontrado en SUNAT.',
        'code' => $code,
        'err'  => $err ?: null,
    ]);
    exit;
}

$d = json_decode($res, true) ?: [];

if ($tipo === 'dni') {
    echo json_encode([
        'ok'   => true,
        'tipo' => 'dni',
        'data' => [
            'nombres'          => trim($d['nombres'] ?? ''),
            'apellido_paterno' => trim($d['apellidoPaterno'] ?? ''),
            'apellido_materno' => trim($d['apellidoMaterno'] ?? ''),
        ],
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'ok'   => true,
        'tipo' => 'ruc',
        'data' => [
            'razon_social' => trim($d['razonSocial'] ?? ''),
            'direccion'    => trim($d['direccion'] ?? ''),
            'distrito'     => trim($d['distrito'] ?? ''),
            'provincia'    => trim($d['provincia'] ?? ''),
            'departamento' => trim($d['departamento'] ?? ''),
            'estado'       => trim($d['estado'] ?? ''),
            'condicion'    => trim($d['condicion'] ?? ''),
        ],
    ], JSON_UNESCAPED_UNICODE);
}
