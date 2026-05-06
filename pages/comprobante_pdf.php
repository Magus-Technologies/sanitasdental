<?php
/**
 * Ruta pública para descargar/visualizar el PDF de un comprobante.
 * Acceso por token único (no requiere login).
 *
 *   /pages/comprobante_pdf.php?token=XXX                 → pantalla con botones A4 / Ticket
 *   /pages/comprobante_pdf.php?token=XXX&fmt=a4          → PDF A4 inline
 *   /pages/comprobante_pdf.php?token=XXX&fmt=a4&dl=1     → fuerza descarga A4
 *   /pages/comprobante_pdf.php?token=XXX&fmt=ticket      → PDF Ticket 80mm inline
 */
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

$token = preg_replace('/[^a-f0-9]/i', '', $_GET['token'] ?? '');
if (strlen($token) !== 40) {
    http_response_code(404);
    exit('Comprobante no encontrado.');
}

$st = db()->prepare("
    SELECT p.*,
           CONCAT(pa.nombres,' ',pa.apellido_paterno,' ',COALESCE(pa.apellido_materno,'')) AS pac,
           pa.dni, pa.ruc, pa.telefono, pa.email, pa.direccion, pa.distrito
    FROM pagos p JOIN pacientes pa ON p.paciente_id = pa.id
    WHERE p.pdf_token = ? AND p.tipo_comprobante IN('boleta','factura','nota_venta')
    LIMIT 1
");
$st->execute([$token]);
$pago = $st->fetch();

if (!$pago) {
    http_response_code(404);
    exit('Comprobante no encontrado o link inválido.');
}

$dets = db()->prepare("
    SELECT pd.*, i.nombre AS inv_nombre, i.codigo AS inv_codigo
    FROM pago_detalles pd LEFT JOIN inventario i ON pd.inventario_id = i.id
    WHERE pd.pago_id = ? ORDER BY pd.id
");
$dets->execute([$pago['id']]);
$dets = $dets->fetchAll();

// ── Datos derivados ────────────────────────────────────────────────
$tc       = $pago['tipo_comprobante'];
$serieNum = $pago['serie'].'-'.str_pad((string)$pago['numero'], 8, '0', STR_PAD_LEFT);
$tipoLbl  = ['boleta'=>'BOLETA DE VENTA ELECTRÓNICA','factura'=>'FACTURA ELECTRÓNICA','nota_venta'=>'NOTA DE VENTA'];
$titDoc   = $tipoLbl[$tc] ?? strtoupper(str_replace('_',' ',$tc));

$E        = empresa() ?: [];
$emp_rs   = $E['razon_social']     ?? getCfg('clinica_nombre',  'DentalSys');
$emp_com  = $E['nombre_comercial'] ?? '';
$emp_ruc  = $E['ruc']              ?? getCfg('clinica_ruc',      '');
$emp_dir  = $E['direccion']        ?? getCfg('clinica_direccion','');
$emp_dist = $E['distrito']         ?? '';
$emp_prov = $E['provincia']        ?? '';
$emp_dpto = $E['departamento']     ?? '';
$emp_tel  = $E['telefono']         ?? getCfg('clinica_telefono', '');
$emp_mail = $E['email']            ?? '';
$emp_web  = $E['web']              ?? '';
$emp_prop = $E['propaganda']       ?? '';
$emp_pie  = $E['pie_pagina']       ?? '';
$emp_color= $E['color_primario']   ?? '#1a1a1a';
$emp_mon  = $E['moneda']           ?? 'S/';

$logoSrc = '';
if (!empty($E['logo'])) {
    $logoPath = UPLOAD_PATH . ltrim($E['logo'], '/');
    if (is_file($logoPath)) {
        $mime    = mime_content_type($logoPath) ?: 'image/png';
        $logoSrc = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoPath));
    }
}

$ec = ['pagado'=>'#2ecc71','pendiente'=>'#f39c12','anulado'=>'#e74c3c'];
$estadoColor = $ec[$pago['estado']] ?? '#777';

// ── QR del comprobante ────────────────────────────────────────────
// Boleta/Factura → formato SUNAT estándar (separado por |):
//   RUC|TIPO|SERIE|NUMERO|IGV|TOTAL|FECHA|TIPO_DOC_CLIENTE|NUM_DOC_CLIENTE|HASH
// Nota de venta → URL pública del PDF
$aplica_igv = !isset($pago['aplica_igv']) || $pago['aplica_igv'];
$codSunat = ['boleta'=>'03','factura'=>'01'];
if (isset($codSunat[$tc])) {
    $igv = $aplica_igv
        ? round((float)$pago['total'] - ((float)$pago['total'] / 1.18), 2)
        : 0;
    $tipoDocCli = $tc === 'factura' ? '6' : '1';   // 1=DNI, 6=RUC
    $numDocCli  = $tc === 'factura' ? ($pago['ruc'] ?: '-') : ($pago['dni'] ?: '-');
    $qrParts = [
        $emp_ruc ?: '00000000000',
        $codSunat[$tc],
        $pago['serie'],
        str_pad((string)$pago['numero'], 8, '0', STR_PAD_LEFT),
        number_format($igv, 2, '.', ''),
        number_format((float)$pago['total'], 2, '.', ''),
        date('Y-m-d', strtotime($pago['fecha'])),
        $tipoDocCli,
        $numDocCli,
    ];
    if (!empty($pago['sunat_hash'])) $qrParts[] = $pago['sunat_hash'];
    $qrData = implode('|', $qrParts);
} else {
    $proto   = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || (($_SERVER['SERVER_PORT']??'')=='443')) ? 'https' : 'http';
    $hostUri = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $qrData  = $proto.'://'.$hostUri.BASE_URL.'/pages/comprobante_pdf.php?token='.$token;
}

// Genera QR en base64 (data URI) listo para <img src>
function qrDataUri(string $data, int $size = 180): string {
    try {
        $builder = new Builder(
            writer: new PngWriter(),
            data: $data,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 6,
        );
        return $builder->build()->getDataUri();
    } catch (Throwable $e) {
        return '';
    }
}

// Convierte número a letras (usado en A4)
function numeroALetras(float $n): string {
    $entero = (int)floor($n);
    $cent   = (int)round(($n - $entero) * 100);
    $unidades = ['','UNO','DOS','TRES','CUATRO','CINCO','SEIS','SIETE','OCHO','NUEVE','DIEZ','ONCE','DOCE','TRECE','CATORCE','QUINCE','DIECISÉIS','DIECISIETE','DIECIOCHO','DIECINUEVE','VEINTE'];
    $decenas  = ['','','VEINTI','TREINTA','CUARENTA','CINCUENTA','SESENTA','SETENTA','OCHENTA','NOVENTA'];
    $centenas = ['','CIENTO','DOSCIENTOS','TRESCIENTOS','CUATROCIENTOS','QUINIENTOS','SEISCIENTOS','SETECIENTOS','OCHOCIENTOS','NOVECIENTOS'];
    $cnv = function($num) use (&$cnv,$unidades,$decenas,$centenas) {
        if ($num <= 20) return $unidades[$num];
        if ($num < 100) {
            $d = (int)($num/10); $u = $num % 10;
            if ($d === 2) return $u ? 'VEINTI'.$unidades[$u] : 'VEINTE';
            return $decenas[$d].($u ? ' Y '.$unidades[$u] : '');
        }
        if ($num === 100) return 'CIEN';
        if ($num < 1000) {
            $c = (int)($num/100); $r = $num % 100;
            return $centenas[$c].($r ? ' '.$cnv($r) : '');
        }
        if ($num < 1000000) {
            $m = (int)($num/1000); $r = $num % 1000;
            $pre = $m === 1 ? 'MIL' : $cnv($m).' MIL';
            return $pre.($r ? ' '.$cnv($r) : '');
        }
        return (string)$num;
    };
    $palabras = $entero === 0 ? 'CERO' : $cnv($entero);
    return $palabras.' CON '.str_pad((string)$cent, 2, '0', STR_PAD_LEFT).'/100 SOLES';
}

$fmt = $_GET['fmt'] ?? '';

// ════════════════════════════════════════════════════════════════════
// Pantalla de selección de formato (cuando no se especifica fmt)
// ════════════════════════════════════════════════════════════════════
if ($fmt === '') {
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($serieNum)?> · <?=e($emp_rs)?></title>
<style>
 *{box-sizing:border-box;margin:0;padding:0}
 body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0a0e14;color:#e6e6e6;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
 .wrap{max-width:520px;width:100%;text-align:center}
 .doc-card{background:#11161e;border:1px solid #222a36;border-radius:14px;padding:36px 28px;box-shadow:0 8px 32px rgba(0,0,0,.4)}
 .logo{max-width:100px;max-height:80px;margin:0 auto 14px;display:block}
 h1{font-size:20px;font-weight:700;color:<?=e($emp_color)?>;margin-bottom:4px}
 .sub{color:#999;font-size:13px;margin-bottom:24px}
 .meta{background:#1a212d;border-radius:8px;padding:12px 16px;margin-bottom:28px;text-align:left}
 .meta-row{display:flex;justify-content:space-between;padding:4px 0;font-size:13px}
 .meta-row .lbl{color:#888}
 .meta-row .val{font-weight:600}
 .total-row{border-top:1px solid #2a3442;margin-top:8px;padding-top:8px}
 .total-row .val{font-size:20px;color:<?=e($emp_color)?>}
 h2{font-size:14px;color:#aaa;margin-bottom:14px;font-weight:500}
 .options{display:grid;grid-template-columns:1fr 1fr;gap:14px}
 .opt{display:block;padding:24px 16px;background:#1a212d;border:2px solid #2a3442;border-radius:10px;color:#e6e6e6;text-decoration:none;transition:all .15s}
 .opt:hover{border-color:<?=e($emp_color)?>;background:#1f2a3a;transform:translateY(-2px)}
 .opt .ico{font-size:38px;margin-bottom:8px;display:block}
 .opt .t{font-weight:700;font-size:15px;margin-bottom:2px}
 .opt .d{color:#888;font-size:11px}
 .foot{margin-top:20px;color:#555;font-size:11px}
</style>
</head><body>
<div class="wrap">
 <div class="doc-card">
  <?php if($logoSrc): ?><img src="<?=$logoSrc?>" class="logo" alt="Logo"><?php endif; ?>
  <h1><?=e($emp_rs)?></h1>
  <div class="sub"><?=e($titDoc)?> · <strong><?=e($serieNum)?></strong></div>

  <div class="meta">
   <div class="meta-row"><span class="lbl">Cliente</span><span class="val"><?=e($pago['pac'])?></span></div>
   <div class="meta-row"><span class="lbl"><?=$tc==='factura'?'RUC':'DNI'?></span><span class="val"><?=e($tc==='factura'?($pago['ruc']?:'—'):($pago['dni']?:'—'))?></span></div>
   <div class="meta-row"><span class="lbl">Fecha</span><span class="val"><?=fDT($pago['fecha'])?></span></div>
   <div class="meta-row total-row"><span class="lbl">TOTAL</span><span class="val"><?=e($emp_mon)?> <?=number_format((float)$pago['total'],2)?></span></div>
  </div>

  <h2>📄 ¿En qué formato quieres el PDF?</h2>
  <div class="options">
   <a href="?token=<?=e($token)?>&fmt=a4" class="opt">
    <span class="ico">📄</span>
    <div class="t">A4</div>
    <div class="d">Tamaño carta · Ideal para enviar/archivar</div>
   </a>
   <a href="?token=<?=e($token)?>&fmt=ticket" class="opt">
    <span class="ico">🧾</span>
    <div class="t">Ticket 80mm</div>
    <div class="d">Impresora térmica · Compacto</div>
   </a>
  </div>
  <div class="foot">Generado por <?=e($emp_rs)?></div>
 </div>
</div>
</body></html><?php
    exit;
}

// ════════════════════════════════════════════════════════════════════
// Generación del PDF (A4 o Ticket 80mm)
// ════════════════════════════════════════════════════════════════════
ob_start();

$qrSrc = qrDataUri($qrData, $fmt === 'ticket' ? 160 : 200);

if ($fmt === 'ticket') {
    // ─── TICKET 80mm ─────────────────────────────────────────────────
    ?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><style>
 *{box-sizing:border-box;margin:0;padding:0}
 body{font-family:DejaVu Sans Mono, monospace;font-size:9px;color:#000;line-height:1.35;padding:3mm 4mm}
 .c{text-align:center}
 .b{font-weight:bold}
 .lg{font-size:11px}
 .xl{font-size:13px}
 .sep{border-top:1px dashed #000;margin:5px 0}
 .lbl{color:#444;font-size:8px}
 table{width:100%;border-collapse:collapse}
 .it th{border-bottom:1px solid #000;padding:2px 0;font-size:8px;text-align:left}
 .it td{padding:2px 0;font-size:9px;vertical-align:top}
 .it td.r{text-align:right}
 .it td.c{text-align:center}
 .tot td{padding:2px 0}
 .tot td.r{text-align:right}
 .tot tr.big td{font-size:11px;font-weight:bold;border-top:1px solid #000;padding-top:4px}
</style></head><body>
<?php if($logoSrc): ?>
<div class="c"><img src="<?=$logoSrc?>" style="max-width:50mm;max-height:18mm"></div>
<?php endif; ?>
<div class="c b xl"><?=e($emp_com ?: $emp_rs)?></div>
<?php if($emp_com && $emp_com !== $emp_rs): ?><div class="c lbl"><?=e($emp_rs)?></div><?php endif; ?>
<?php if($emp_ruc): ?><div class="c b">RUC: <?=e($emp_ruc)?></div><?php endif; ?>
<?php if($emp_dir): ?><div class="c"><?=e($emp_dir)?></div><?php endif; ?>
<?php if($emp_dist): ?><div class="c"><?=e($emp_dist)?><?=$emp_prov?' - '.e($emp_prov):''?></div><?php endif; ?>
<?php if($emp_tel): ?><div class="c">Tel: <?=e($emp_tel)?></div><?php endif; ?>
<div class="sep"></div>
<div class="c b lg"><?=e($titDoc)?></div>
<div class="c b xl"><?=e($serieNum)?></div>
<div class="sep"></div>
<div><span class="lbl">Fecha:</span> <?=fDT($pago['fecha'])?></div>
<div><span class="lbl">Cliente:</span> <?=e($pago['pac'])?></div>
<div><span class="lbl"><?=$tc==='factura'?'RUC':'DNI'?>:</span> <?=e($tc==='factura'?($pago['ruc']?:'—'):($pago['dni']?:'—'))?></div>
<?php if($pago['direccion']): ?><div class="lbl">Dir: <?=e($pago['direccion'])?></div><?php endif; ?>
<div class="sep"></div>
<table class="it">
 <thead><tr><th>Descripción</th><th class="r" style="width:14mm">Importe</th></tr></thead>
 <tbody>
 <?php foreach($dets as $d):
  $cant = rtrim(rtrim((string)$d['cantidad'],'0'),'.');
 ?>
  <tr>
   <td colspan="2"><?=e($d['concepto'])?></td>
  </tr>
  <tr>
   <td><?=$cant?> x <?=number_format((float)$d['precio'],2)?></td>
   <td class="r"><?=number_format((float)$d['subtotal'],2)?></td>
  </tr>
 <?php endforeach; ?>
 </tbody>
</table>
<div class="sep"></div>
<?php
 if ($aplica_igv):
  $tkGrav = round((float)$pago['total'] / 1.18, 2);
  $tkIgv  = round((float)$pago['total'] - $tkGrav, 2);
?>
<table class="tot">
 <tr><td>Op. Gravada</td><td class="r"><?=e($emp_mon)?> <?=number_format($tkGrav,2)?></td></tr>
 <tr><td>IGV (18%)</td><td class="r"><?=e($emp_mon)?> <?=number_format($tkIgv,2)?></td></tr>
<?php else: ?>
<table class="tot">
 <tr><td>Op. Inafecta / Exonerada</td><td class="r"><?=e($emp_mon)?> <?=number_format((float)$pago['total'],2)?></td></tr>
<?php endif; ?>
 <?php if((float)$pago['descuento']>0): ?>
 <tr><td>Descuento</td><td class="r">-<?=e($emp_mon)?> <?=number_format((float)$pago['descuento'],2)?></td></tr>
 <?php endif; ?>
 <tr class="big"><td>TOTAL</td><td class="r"><?=e($emp_mon)?> <?=number_format((float)$pago['total'],2)?></td></tr>
</table>
<div class="sep"></div>
<div class="lbl">Método: <span class="b"><?=strtoupper($pago['metodo'])?></span></div>
<?php if($pago['referencia']): ?><div class="lbl">Ref: <?=e($pago['referencia'])?></div><?php endif; ?>
<?php if(!empty($pago['sunat_estado'])): ?>
 <div class="lbl">SUNAT: <span class="b"><?=strtoupper($pago['sunat_estado'])?></span></div>
<?php endif; ?>
<div class="sep"></div>
<?php if($qrSrc): ?>
<div class="c" style="margin:6px 0"><img src="<?=$qrSrc?>" style="width:30mm;height:30mm"></div>
<?php endif; ?>
<?php if($emp_prop): ?><div class="c b"><?=e($emp_prop)?></div><?php endif; ?>
<div class="c lbl" style="margin-top:4px">¡Gracias por su preferencia!</div>
<div class="c lbl"><?=date('d/m/Y H:i')?></div>
<?php if($emp_pie): ?><div class="c lbl" style="margin-top:3px"><?=nl2br(e($emp_pie))?></div><?php endif; ?>
</body></html><?php
} else {
    // ─── A4 (estilo ilisava: tabla con bordes reales, cajas profesionales) ─
    if ($aplica_igv) {
        $totalGrav    = round((float)$pago['total'] / 1.18, 2);
        $igvCalc      = round((float)$pago['total'] - $totalGrav, 2);
    } else {
        $totalGrav    = (float)$pago['total'];
        $igvCalc      = 0;
    }
    ?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8">
<style>
 *{box-sizing:border-box;margin:0;padding:0}
 body{font-family:DejaVu Sans, sans-serif;font-size:9pt;color:#333;padding:14mm 12mm}
 p,div,span,table,td,th,tr{margin:0;padding:0}
 .text-c{text-align:center}
 .text-r{text-align:right}
 .text-l{text-align:left}
 .b{font-weight:bold}

 /* HEADER */
 .head-tbl{width:100%;border-collapse:collapse;margin-bottom:14px}
 .head-tbl > tbody > tr > td{vertical-align:top;padding:0}
 .emp-name{font-size:14pt;font-weight:bold;color:<?=e($emp_color)?>;line-height:1.1;text-transform:uppercase}
 .emp-com{font-size:8pt;color:#444;margin-top:3px;line-height:1.3}
 .emp-line{font-size:8pt;color:#000;margin-top:2px}

 .doc-box{border:2px solid <?=e($emp_color)?>;border-radius:8px;width:100%}
 .doc-box .ruc{padding:6px 10px;font-size:10pt;font-weight:bold;text-align:center}
 .doc-box .tipo{background:<?=e($emp_color)?>;color:#fff;padding:6px 8px;font-size:10pt;font-weight:bold;text-align:center;line-height:1.2}
 .doc-box .num{padding:7px 10px;font-size:13pt;font-weight:bold;text-align:center;font-family:DejaVu Sans Mono, monospace}
 .doc-box .est{padding:0 10px 5px;font-size:8pt;font-weight:bold;text-align:center;color:<?=e($estadoColor)?>;letter-spacing:.4px}

 /* CLIENTE / FECHA – cajas redondeadas pareadas */
 .client-tbl{width:100%;border-collapse:separate;border-spacing:8px 0;margin-left:-8px;margin-bottom:10px}
 .client-tbl > tbody > tr > td{border:1.2px solid #777;border-radius:8px;padding:8px 10px;vertical-align:top;font-size:8pt}
 .client-tbl b{font-weight:bold}

 /* TABLA DE PRODUCTOS */
 .products-table{width:100%;border-collapse:collapse;margin-bottom:6px;border:2px solid <?=e($emp_color)?>;border-radius:6px}
 .products-table thead{background:<?=e($emp_color)?>;color:#fff}
 .products-table th{padding:6px 4px;font-size:7.5pt;font-weight:bold;border:1px solid <?=e($emp_color)?>;text-align:center}
 .products-table td{padding:6px 4px;font-size:8pt;border-left:1px solid #ccc;border-right:1px solid #ccc;vertical-align:top}
 .products-table tbody tr:last-child td{border-bottom:1px solid #ccc}
 .item-extra{color:#666;font-size:7pt;margin-top:2px;font-style:italic}

 /* SON: ... */
 .son{width:100%;border-collapse:collapse;margin-bottom:6px;border:2px solid #999;border-radius:6px}
 .son td{padding:6px 10px;font-size:9.5pt;font-weight:bold;font-style:italic;text-align:center;text-transform:uppercase}

 /* SECCIÓN INFERIOR (QR + Totales) */
 .bottom-tbl{width:100%;border-collapse:collapse;margin-top:8px}
 .bottom-tbl > tbody > tr > td{vertical-align:top}

 .info-box{border:1.5px solid #888;border-radius:6px;padding:8px 10px;font-size:8pt}
 .info-box b{font-weight:bold}
 .info-box .row{margin:2px 0}

 .totals-up{width:100%;border-collapse:separate;border-spacing:0;border:2px solid #999;border-radius:6px;margin-bottom:5px}
 .totals-up td{padding:3px 10px;font-size:8.5pt}
 .totals-up td.lbl{text-align:right;width:65%}
 .totals-up td.val{text-align:right;font-family:DejaVu Sans Mono, monospace;width:35%}

 .totals-down{width:100%;border-collapse:separate;border-spacing:0;border:2px solid <?=e($emp_color)?>;border-radius:6px;background:<?=e($emp_color)?>}
 .totals-down td{padding:7px 10px;font-size:13pt;font-weight:bold;color:#fff}
 .totals-down td.lbl{text-align:right;width:60%}
 .totals-down td.val{text-align:right;font-family:DejaVu Sans Mono, monospace;width:40%}

 /* FOOTER */
 .prop{margin-top:14px;text-align:center;font-size:9pt;font-weight:bold;font-style:italic;color:<?=e($emp_color)?>}
 .foot{margin-top:10px;padding-top:8px;border-top:1px solid #ddd;font-size:7.5pt;color:#666;text-align:center;line-height:1.5}
 .hash{font-family:DejaVu Sans Mono, monospace;font-size:6.5pt;color:#999;word-break:break-all;margin-top:3px}
</style>
</head><body>

<!-- HEADER: empresa | cuadro comprobante -->
<table class="head-tbl"><tr>
 <td style="width:63%;padding-right:14px">
  <table style="border-collapse:collapse"><tr>
   <?php if($logoSrc): ?>
    <td style="vertical-align:middle;padding-right:10px"><img src="<?=$logoSrc?>" alt="Logo" style="height:80px;width:auto"></td>
   <?php endif; ?>
   <td style="vertical-align:middle">
    <div class="emp-name"><?=e($emp_rs)?></div>
    <?php if($emp_com && $emp_com !== $emp_rs): ?>
     <div class="emp-com"><?=e($emp_com)?></div>
    <?php endif; ?>
   </td>
  </tr></table>
 </td>
 <td style="width:37%">
  <div class="doc-box">
   <?php if($emp_ruc): ?><div class="ruc">R.U.C. <?=e($emp_ruc)?></div><?php endif; ?>
   <div class="tipo"><?=e($titDoc)?></div>
   <div class="num"><?=e($serieNum)?></div>
   <?php if(!empty($pago['sunat_estado'])): ?>
    <div class="est">SUNAT · <?=strtoupper($pago['sunat_estado'])?></div>
   <?php endif; ?>
  </div>
 </td>
</tr></table>

<!-- Datos de empresa (full width) -->
<div style="margin-bottom:8px">
 <?php if($emp_dir): ?>
  <div class="emp-line"><b>DIRECCIÓN:</b> <?=e($emp_dir)?><?php
   $loc = trim(($emp_dist?$emp_dist.($emp_prov?' - '.$emp_prov:''):'').($emp_dpto?', '.$emp_dpto:''));
   if ($loc) echo ', '.e($loc);
  ?></div>
 <?php endif; ?>
 <?php if($emp_tel): ?><div class="emp-line"><b>TELÉF.:</b> <?=e($emp_tel)?><?=$emp_mail?'   <b>CORREO:</b> '.e($emp_mail):''?></div><?php endif; ?>
 <?php if($emp_web && !$emp_mail): ?><div class="emp-line"><b>WEB:</b> <?=e($emp_web)?></div><?php endif; ?>
</div>

<!-- CLIENTE / FECHA -->
<table class="client-tbl"><tr>
 <td style="width:50%">
  <span class="b">CLIENTE:</span> <?=e($pago['pac'])?><br>
  <span class="b"><?=$tc==='factura'?'RUC':'DNI'?>:</span> <?=e($tc==='factura'?($pago['ruc']?:'—'):($pago['dni']?:'—'))?><br>
  <span class="b">DIRECCIÓN:</span> <?=e($pago['direccion']?:'—')?><?=$pago['distrito']?', '.e($pago['distrito']):''?>
 </td>
 <td style="width:50%">
  <span class="b">FECHA EMISIÓN:</span> <?=fDT($pago['fecha'])?><br>
  <span class="b">MONEDA:</span> SOLES (PEN)<br>
  <span class="b">ESTADO:</span> <span style="color:<?=e($estadoColor)?>;font-weight:bold"><?=strtoupper($pago['estado'])?></span><br>
  <span class="b">CÓD. INTERNO:</span> <?=e($pago['codigo'])?>
 </td>
</tr></table>

<!-- TABLA DE PRODUCTOS -->
<table class="products-table">
 <thead>
  <tr>
   <th width="5%">N°</th>
   <th width="9%">CANT.</th>
   <th width="14%">CÓDIGO</th>
   <th width="46%" style="text-align:left;padding-left:5px">DESCRIPCIÓN</th>
    <th width="12%">P. UNIT.<br><span style="font-size:6.5pt;font-weight:normal"><?=$aplica_igv?'(c/IGV)':'(s/IGV)'?></span></th>
    <th width="14%">IMPORTE<br><span style="font-size:6.5pt;font-weight:normal"><?=$aplica_igv?'(c/IGV)':'(s/IGV)'?></span></th>
  </tr>
 </thead>
 <tbody>
  <?php foreach($dets as $i => $d): ?>
  <tr>
   <td class="text-c"><?=$i+1?></td>
   <td class="text-c"><?=rtrim(rtrim((string)$d['cantidad'],'0'),'.')?></td>
   <td class="text-c"><?=e($d['inv_codigo'] ?: '—')?></td>
   <td style="padding-left:5px">
    <?=e($d['concepto'])?>
    <?php if(!empty($d['inv_nombre'])): ?>
     <div class="item-extra"><?=e($d['inv_nombre'])?></div>
    <?php endif; ?>
   </td>
   <td class="text-r" style="font-family:DejaVu Sans Mono, monospace"><?=number_format((float)$d['precio'],2)?></td>
   <td class="text-r" style="font-family:DejaVu Sans Mono, monospace"><b><?=number_format((float)$d['subtotal'],2)?></b></td>
  </tr>
  <?php endforeach; ?>
 </tbody>
</table>

<!-- SON: -->
<table class="son"><tr>
 <td>SON: <?=e(numeroALetras((float)$pago['total']))?></td>
</tr></table>

<!-- BOTTOM: QR/Info | Totales -->
<table class="bottom-tbl"><tr>
 <td style="width:55%;padding-right:10px">
  <table style="border-collapse:collapse;width:100%"><tr>
   <?php if($qrSrc): ?>
   <td style="width:115px;vertical-align:top;padding-right:8px">
    <div style="border:1.5px solid #888;border-radius:6px;padding:5px;text-align:center;background:#fff">
     <img src="<?=$qrSrc?>" style="width:100px;height:100px">
     <div style="font-size:6.5pt;color:#777;margin-top:2px">CONSULTA / VALIDACIÓN</div>
    </div>
   </td>
   <?php endif; ?>
   <td style="vertical-align:top">
    <div class="info-box">
     <div class="row"><b>MÉTODO PAGO:</b> <?=strtoupper($pago['metodo'])?></div>
     <?php if($pago['referencia']): ?><div class="row"><b>REFERENCIA:</b> <?=e($pago['referencia'])?></div><?php endif; ?>
     <?php if($pago['notas']): ?><div class="row"><b>NOTAS:</b> <?=e($pago['notas'])?></div><?php endif; ?>
     <?php if($emp_pie): ?><div class="row" style="color:#666;font-size:7.5pt;margin-top:6px"><?=nl2br(e($emp_pie))?></div><?php endif; ?>
    </div>
   </td>
  </tr></table>
 </td>
  <td style="width:45%">
   <!-- Caja superior: desglose IGV -->
   <table class="totals-up">
    <?php if ($aplica_igv): ?>
    <tr><td class="lbl">OP. GRAVADAS: <?=e($emp_mon)?></td><td class="val"><?=number_format($totalGrav,2)?></td></tr>
    <tr><td class="lbl">IGV (18%): <?=e($emp_mon)?></td><td class="val"><?=number_format($igvCalc,2)?></td></tr>
    <?php else: ?>
    <tr><td class="lbl">OP. INAFECTA / EXONERADA: <?=e($emp_mon)?></td><td class="val"><?=number_format($totalGrav,2)?></td></tr>
    <tr><td class="lbl">IGV: <?=e($emp_mon)?></td><td class="val">0.00</td></tr>
    <?php endif; ?>
    <?php if((float)$pago['descuento']>0): ?>
    <tr><td class="lbl">DESCUENTO: <?=e($emp_mon)?></td><td class="val">-<?=number_format((float)$pago['descuento'],2)?></td></tr>
    <?php endif; ?>
   </table>
  <!-- Caja inferior: TOTAL -->
  <table class="totals-down"><tr>
   <td class="lbl">TOTAL: <?=e($emp_mon)?></td>
   <td class="val"><?=number_format((float)$pago['total'],2)?></td>
  </tr></table>
 </td>
</tr></table>

<?php if($emp_prop): ?>
<div class="prop"><?=e($emp_prop)?></div>
<?php endif; ?>

<div class="foot">
 <?php if($tc !== 'nota_venta'): ?>
  Representación impresa del comprobante electrónico. Operación sujeta a las normas vigentes de SUNAT.
 <?php else: ?>
  Documento interno · No constituye comprobante de pago electrónico SUNAT.
 <?php endif; ?>
 <br>Generado el <?=date('d/m/Y H:i')?>
 <?php if(!empty($pago['sunat_hash'])): ?>
  <div class="hash">Hash: <?=e($pago['sunat_hash'])?></div>
 <?php endif; ?>
</div>

</body></html><?php
}

$html = ob_get_clean();

$opts = new Options();
$opts->set('isHtml5ParserEnabled', true);
$opts->set('isRemoteEnabled',      false);
$opts->set('defaultFont',          'DejaVu Sans');
$opts->set('chroot',               UPLOAD_PATH);

$dompdf = new Dompdf($opts);
$dompdf->loadHtml($html, 'UTF-8');

if ($fmt === 'ticket') {
    // 80mm x 297mm = 226.77pt x 841.89pt (1mm = 2.8346pt)
    $dompdf->setPaper([0, 0, 226.77, 841.89]);
} else {
    $dompdf->setPaper('A4', 'portrait');
}

$dompdf->render();

$prefix   = $fmt === 'ticket' ? 'Ticket-' : '';
$filename = $prefix.$serieNum.'-'.preg_replace('/[^A-Za-z0-9]/','_', $pago['pac']).'.pdf';
$attach   = !empty($_GET['dl']);
$dompdf->stream($filename, ['Attachment' => $attach]);
exit;
