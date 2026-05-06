<?php
/**
 * hc_pdf.php — Genera PDF de Historia Clínica Completa
 * Requiere: pip3 install reportlab --break-system-packages
 * URL: /dental/pages/hc_pdf.php?id=X
 */
ob_start();

require_once __DIR__.'/../includes/config.php';
requiereLogin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); die('HC no encontrada'); }

// ── Cargar datos completos ──────────────────────────────────
$st = db()->prepare("SELECT hc.*,
    CONCAT(p.nombres,' ',p.apellido_paterno,' ',COALESCE(p.apellido_materno,'')) AS pac_nombre,
    p.dni, p.fecha_nacimiento, p.sexo, p.telefono, p.email, p.direccion, p.distrito,
    p.tipo_seguro, p.num_seguro, p.alergias, p.enfermedades_base, p.medicacion_actual,
    p.cirugia_previa, p.embarazo, p.fuma, p.alcohol, p.contacto_nombre, p.contacto_telefono,
    CONCAT(u.nombre,' ',u.apellidos) AS doctor, u.cmp, u.especialidad
    FROM historias_clinicas hc
    JOIN pacientes p ON hc.paciente_id=p.id
    LEFT JOIN usuarios u ON hc.doctor_id=u.id
    WHERE hc.id=?");
$st->execute([$id]); $hc = $st->fetch();
if (!$hc) { http_response_code(404); die('HC no encontrada'); }

// Evoluciones
$evs = db()->prepare("SELECT e.*,CONCAT(u.nombre,' ',u.apellidos) AS dr
    FROM evoluciones e LEFT JOIN usuarios u ON e.doctor_id=u.id
    WHERE e.hc_id=? ORDER BY e.fecha ASC");
$evs->execute([$id]); $evoluciones = $evs->fetchAll();

// Plan de tratamiento
$plan = db()->prepare("SELECT * FROM planes_tratamiento WHERE hc_id=? ORDER BY created_at DESC LIMIT 1");
$plan->execute([$id]); $plan = $plan->fetch();
$plan_det = [];
if ($plan) {
    $pd = db()->prepare("SELECT * FROM plan_detalles WHERE plan_id=? ORDER BY orden");
    $pd->execute([$plan['id']]); $plan_det = $pd->fetchAll();
}

// Odontograma
$odont = db()->prepare("SELECT * FROM odontogramas WHERE hc_id=? ORDER BY fecha DESC LIMIT 1");
$odont->execute([$id]); $odont = $odont->fetch();
$dientes = [];
if ($odont) {
    $ds = db()->prepare("SELECT * FROM odontograma_dientes WHERE odontograma_id=?");
    $ds->execute([$odont['id']]);
    foreach ($ds->fetchAll() as $d) $dientes[$d['numero_diente']][] = $d;
}

// Config clínica
$clinica = getCfg('clinica_nombre','Clínica Dental');
$dir_cli = getCfg('clinica_direccion','');
$tel_cli = getCfg('clinica_telefono','');
$dir_med = getCfg('director_nombre','');
$cmp_med = getCfg('director_cmp','');

// ── Generar SVG del odontograma para el PDF ─────────────────
function buildOdontogramaSVG(array $dientes): string {
    $col_map = [
        'caries'=>'#E05252','obturado'=>'#00D4EE','ausente'=>'#F5A623',
        'endodoncia'=>'#8B5CF6','corona'=>'#F59E0B','implante'=>'#10B981',
        'fractura'=>'#EF4444','presupuesto'=>'#3B82F6','sellante'=>'#EC4899',
        'protesis'=>'#6366F1','brackets'=>'#06B6D4','sano'=>'none',
    ];
    $col_color = ['rojo'=>'#E05252','azul'=>'#00D4EE','negro'=>'#607080','verde'=>'#10B981'];

    $dx = 42; $xc = 420;
    $sup_der=[18,17,16,15,14,13,12,11]; $sup_izq=[21,22,23,24,25,26,27,28];
    $inf_der=[48,47,46,45,44,43,42,41]; $inf_izq=[31,32,33,34,35,36,37,38];
    $tip=['I'=>12,'C'=>13,'PM'=>15,'M'=>18];
    $tipo=[11=>'I',12=>'I',13=>'C',14=>'PM',15=>'PM',16=>'M',17=>'M',18=>'M',
           21=>'I',22=>'I',23=>'C',24=>'PM',25=>'PM',26=>'M',27=>'M',28=>'M',
           31=>'I',32=>'I',33=>'C',34=>'PM',35=>'PM',36=>'M',37=>'M',38=>'M',
           41=>'I',42=>'I',43=>'C',44=>'PM',45=>'PM',46=>'M',47=>'M',48=>'M'];

    function gx(int $n,int $xc,int $dx): float {
        $sd=[18,17,16,15,14,13,12,11]; $si=[21,22,23,24,25,26,27,28];
        $ii=[31,32,33,34,35,36,37,38]; $id=[48,47,46,45,44,43,42,41];
        if(in_array($n,$sd)){$i=array_search($n,array_reverse($sd));return $xc-20-$i*$dx;}
        if(in_array($n,$si)){$i=array_search($n,$si);return $xc+20+$i*$dx;}
        if(in_array($n,$ii)){$i=array_search($n,$ii);return $xc+20+$i*$dx;}
        if(in_array($n,$id)){$i=array_search($n,array_reverse($id));return $xc-20-$i*$dx;}
        return $xc;
    }

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 840 200" style="width:100%;max-width:840px">';
    $svg .= '<rect width="840" height="200" fill="white"/>';
    // Líneas referencia
    $svg .= '<line x1="420" y1="5" x2="420" y2="195" stroke="#CCCCCC" stroke-width="1" stroke-dasharray="4,3"/>';
    $svg .= '<line x1="10" y1="100" x2="830" y2="100" stroke="#EEEEEE" stroke-width="1"/>';
    // Labels cuadrantes
    $svg .= '<text x="210" y="12" text-anchor="middle" font-size="7" fill="#888" font-family="Arial">SUPERIOR DERECHO</text>';
    $svg .= '<text x="630" y="12" text-anchor="middle" font-size="7" fill="#888" font-family="Arial">SUPERIOR IZQUIERDO</text>';
    $svg .= '<text x="210" y="198" text-anchor="middle" font-size="7" fill="#888" font-family="Arial">INFERIOR DERECHO</text>';
    $svg .= '<text x="630" y="198" text-anchor="middle" font-size="7" fill="#888" font-family="Arial">INFERIOR IZQUIERDO</text>';

    $todos = array_merge($sup_der,$sup_izq,$inf_izq,$inf_der);
    foreach ($todos as $num) {
        $cx = gx($num,$xc,$dx);
        $t  = $tipo[$num] ?? 'I';
        $r  = ['I'=>11,'C'=>12,'PM'=>13,'M'=>15][$t] ?? 11;
        $inf= $num >= 30;
        $cy_circ = $inf ? 140 : 60;
        $cy_num  = $inf ? 155 : 46;

        $estados = $dientes[(string)$num] ?? [];
        $main_c = null; $main_e = null;
        foreach ($estados as $e) {
            if (in_array($e['cara'],['total','oclusal'])) {
                $main_c = $col_color[$e['color']] ?? ($col_map[$e['estado']] ?? null);
                $main_e = $e['estado']; break;
            }
        }
        if (!$main_c && $estados) {
            $main_c = $col_color[$estados[0]['color']] ?? ($col_map[$estados[0]['estado']] ?? null);
            $main_e = $estados[0]['estado'];
        }

        $cs = $main_c && $main_c !== 'none' ? $main_c : '#9BB0BC';
        $sw = $main_c && $main_c !== 'none' ? '2' : '1';
        $cf = $main_c && $main_c !== 'none' ? $main_c.'22' : '#F0F4F7';

        if ($main_e === 'ausente') {
            $svg .= "<rect x='".($cx-$r)."' y='".($cy_circ-$r)."' width='".($r*2)."' height='".($r*2)."' rx='3' fill='#FFF8E7' stroke='#F5A623' stroke-width='1.5' stroke-dasharray='3,2'/>";
            $svg .= "<line x1='".($cx-7)."' y1='".($cy_circ-7)."' x2='".($cx+7)."' y2='".($cy_circ+7)."' stroke='#F5A623' stroke-width='1.5'/>";
            $svg .= "<line x1='".($cx+7)."' y1='".($cy_circ-7)."' x2='".($cx-7)."' y2='".($cy_circ+7)."' stroke='#F5A623' stroke-width='1.5'/>";
        } else {
            // Círculo FDI
            $svg .= "<circle cx='$cx' cy='$cy_circ' r='$r' fill='$cf' stroke='$cs' stroke-width='$sw'/>";
            // Líneas de división
            $svg .= "<line x1='$cx' y1='".($cy_circ-$r)."' x2='$cx' y2='".($cy_circ+$r)."' stroke='#CCCCCC' stroke-width='0.6'/>";
            $svg .= "<line x1='".($cx-$r)."' y1='$cy_circ' x2='".($cx+$r)."' y2='$cy_circ' stroke='#CCCCCC' stroke-width='0.6'/>";
            $svg .= "<circle cx='$cx' cy='$cy_circ' r='".round($r*0.4)."' fill='none' stroke='#CCCCCC' stroke-width='0.6'/>";
            // Caras marcadas
            foreach ($estados as $e) {
                if ($e['estado'] === 'sano') continue;
                $c = $col_color[$e['color']] ?? ($col_map[$e['estado']] ?? '#E05252');
                $p2 = $r - 1;
                if ($e['cara'] === 'total') {
                    $svg .= "<circle cx='$cx' cy='$cy_circ' r='".($r-1)."' fill='$c' opacity='0.7'/>";
                } elseif ($e['cara'] === 'oclusal') {
                    $svg .= "<circle cx='$cx' cy='$cy_circ' r='".round($r*0.4)."' fill='$c' opacity='0.85'/>";
                } elseif ($e['cara'] === 'vestibular') {
                    $svg .= "<path d='M ".($cx-$p2).",".($cy_circ-$p2)." L ".($cx+$p2).",".($cy_circ-$p2)." L $cx,".($cy_circ-1)." Z' fill='$c' opacity='0.8'/>";
                } elseif ($e['cara'] === 'lingual') {
                    $svg .= "<path d='M ".($cx-$p2).",".($cy_circ+$p2)." L ".($cx+$p2).",".($cy_circ+$p2)." L $cx,".($cy_circ+1)." Z' fill='$c' opacity='0.8'/>";
                } elseif ($e['cara'] === 'mesial') {
                    $svg .= "<path d='M ".($cx-$p2).",".($cy_circ-$p2)." L ".($cx-$p2).",".($cy_circ+$p2)." L ".($cx-1).",$cy_circ Z' fill='$c' opacity='0.8'/>";
                } elseif ($e['cara'] === 'distal') {
                    $svg .= "<path d='M ".($cx+$p2).",".($cy_circ-$p2)." L ".($cx+$p2).",".($cy_circ+$p2)." L ".($cx+1).",$cy_circ Z' fill='$c' opacity='0.8'/>";
                }
            }
            // Símbolo endodoncia
            if ($main_e === 'endodoncia') {
                $svg .= "<line x1='$cx' y1='".($cy_circ-$r+2)."' x2='$cx' y2='".($cy_circ+$r-2)."' stroke='#8B5CF6' stroke-width='2' stroke-linecap='round'/>";
            }
        }
        // Número FDI
        $svg .= "<text x='$cx' y='$cy_num' text-anchor='middle' font-size='7' fill='#4A7080' font-family='Arial' font-weight='600'>$num</text>";
    }
    $svg .= '</svg>';
    return $svg;
}

$odontograma_svg = $dientes ? buildOdontogramaSVG($dientes) : '';

// ── Generar HTML para PDF (estilo clínico MINSA) ─────────────
function r(string $s): string { return nl2br(htmlspecialchars(trim($s), ENT_QUOTES, 'UTF-8')); }
function rv(?string $s, string $d='—'): string { return trim($s ?? '') ?: $d; }
function fd(?string $d): string { return $d ? date('d/m/Y', strtotime($d)) : '—'; }

ob_end_clean();

// Imprimir HTML directamente (impresión del navegador)
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Historia Clínica — <?= htmlspecialchars($hc['pac_nombre']) ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap');
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Roboto', Arial, sans-serif; font-size: 10pt; color: #1A2332; background: #fff; }

/* Botón imprimir - solo pantalla */
@media screen {
  .print-bar { position: fixed; top: 0; left: 0; right: 0; background: #1A2332; padding: 10px 24px; display: flex; align-items: center; justify-content: space-between; z-index: 1000; box-shadow: 0 2px 8px rgba(0,0,0,.3); }
  .btn-print { background: #00D4EE; color: #050E18; border: none; padding: 8px 20px; border-radius: 6px; font-weight: 700; cursor: pointer; font-size: 13px; }
  .btn-close { background: transparent; color: #A0B0C0; border: 1px solid rgba(255,255,255,.2); padding: 8px 16px; border-radius: 6px; font-size: 13px; cursor: pointer; text-decoration: none; display: inline-block; }
  body { padding-top: 52px; }
  .page { max-width: 210mm; margin: 20px auto; background: #fff; box-shadow: 0 4px 20px rgba(0,0,0,.15); padding: 20mm; }
}
@media print {
  .print-bar { display: none !important; }
  body { padding: 0; background: white; }
  .page { padding: 10mm 15mm; margin: 0; }
  .no-break { page-break-inside: avoid; }
  h2 { page-break-after: avoid; }
  .page-break { page-break-before: always; }
}

/* ESTILOS DEL DOCUMENTO */
.header-doc { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #1A2332; padding-bottom: 12px; margin-bottom: 16px; }
.header-logo { }
.header-logo .clinica-name { font-size: 15pt; font-weight: 700; color: #1A2332; }
.header-logo .clinica-sub { font-size: 8pt; color: #607080; margin-top: 2px; }
.header-logo .clinica-info { font-size: 7.5pt; color: #607080; margin-top: 3px; }
.header-hc { text-align: right; }
.header-hc .hc-num { font-size: 14pt; font-weight: 700; color: #00B8CC; }
.header-hc .hc-fecha { font-size: 8pt; color: #607080; }
.sello-minsa { font-size: 7pt; color: #888; margin-top: 4px; }

.section { margin-bottom: 14px; }
.section-title { background: #1A2332; color: white; padding: 5px 12px; font-size: 9pt; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; border-radius: 3px; margin-bottom: 8px; }
.section-title.green { background: #1B5E3A; }
.section-title.blue  { background: #1A3A6B; }
.section-title.red   { background: #6B1A1A; }
.section-title.purple{ background: #3A1A6B; }

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 16px; }
.grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px 12px; }
.field { margin-bottom: 5px; }
.field-label { font-size: 7.5pt; font-weight: 700; color: #607080; text-transform: uppercase; letter-spacing: .3px; }
.field-value { font-size: 9.5pt; color: #1A2332; border-bottom: 1px solid #E0E8EE; padding-bottom: 2px; min-height: 16px; }
.field-value.block { background: #F7FAFB; padding: 5px 8px; border-radius: 3px; border: 1px solid #E0E8EE; border-bottom: 1px solid #E0E8EE; font-size: 9pt; min-height: 30px; white-space: pre-wrap; }
.field-value.alerta { background: #FFF5F5; border-color: #E05252; color: #8B1A1A; }

/* Paciente card */
.pac-card { background: #F0F7FA; border: 1px solid #C8DDE8; border-radius: 6px; padding: 12px 16px; margin-bottom: 14px; }
.pac-nombre { font-size: 14pt; font-weight: 700; color: #1A2332; }
.pac-meta { font-size: 8.5pt; color: #607080; margin-top: 3px; }

/* Signos vitales */
.vitales { display: flex; gap: 16px; flex-wrap: wrap; margin: 8px 0; }
.vital-item { text-align: center; background: #F7FAFB; border: 1px solid #E0E8EE; border-radius: 6px; padding: 6px 12px; }
.vital-val { font-size: 13pt; font-weight: 700; color: #1A3A6B; }
.vital-label { font-size: 7pt; color: #888; text-transform: uppercase; }

/* Odontograma */
.odont-container { background: #FAFBFC; border: 1px solid #DDE6EE; border-radius: 6px; padding: 10px; margin: 6px 0; }
.odont-leyenda { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; font-size: 7.5pt; }
.ley-item { display: flex; align-items: center; gap: 3px; }
.ley-dot { width: 10px; height: 10px; border-radius: 2px; display: inline-block; }

/* Plan tratamiento */
.plan-table { width: 100%; border-collapse: collapse; font-size: 8.5pt; margin-top: 6px; }
.plan-table th { background: #2A3A4A; color: white; padding: 5px 8px; text-align: left; font-weight: 600; font-size: 8pt; }
.plan-table td { padding: 4px 8px; border-bottom: 1px solid #EEF2F5; }
.plan-table tr:nth-child(even) td { background: #F7FAFB; }

/* Evoluciones */
.evolucion-item { border-left: 3px solid #00B8CC; padding: 8px 12px; margin-bottom: 8px; background: #F7FCFD; border-radius: 0 4px 4px 0; }
.evol-header { display: flex; justify-content: space-between; margin-bottom: 4px; }
.evol-fecha { font-weight: 700; color: #1A2332; font-size: 9pt; }
.evol-dr { color: #607080; font-size: 8pt; }
.evol-body { font-size: 9pt; color: #1A2332; }

/* Firma */
.firma-section { display: flex; justify-content: space-between; margin-top: 30px; padding-top: 16px; border-top: 1px solid #DDE6EE; }
.firma-box { text-align: center; width: 45%; }
.firma-line { border-top: 1px solid #1A2332; margin-bottom: 4px; width: 80%; margin-left: auto; margin-right: auto; }
.firma-label { font-size: 8pt; color: #607080; }

/* Badges */
.badge { display: inline-block; padding: 2px 7px; border-radius: 3px; font-size: 7.5pt; font-weight: 700; }
.badge-red { background: #FEE2E2; color: #8B1A1A; }
.badge-green { background: #D1FAE5; color: #065F46; }
.badge-blue { background: #DBEAFE; color: #1E3A8A; }
.badge-gray { background: #F1F5F9; color: #475569; }
</style>
</head>
<body>

<div class="print-bar">
  <div style="color:#E8EDF2;font-size:13px;font-weight:700">🦷 Historia Clínica — <?= htmlspecialchars($hc['pac_nombre']) ?></div>
  <div style="display:flex;gap:10px">
    <a href="<?= BASE_URL ?>/pages/historia_clinica.php?id=<?= $id ?>" class="btn-close">← Volver</a>
    <button onclick="window.print()" class="btn-print">🖨️ Imprimir / Guardar PDF</button>
  </div>
</div>

<div class="page">

<!-- ── ENCABEZADO ── -->
<div class="header-doc">
  <div class="header-logo">
    <div class="clinica-name">🦷 <?= htmlspecialchars($clinica) ?></div>
    <div class="clinica-sub">Historia Clínica Odontológica</div>
    <?php if ($dir_cli): ?><div class="clinica-info">📍 <?= htmlspecialchars($dir_cli) ?></div><?php endif; ?>
    <?php if ($tel_cli): ?><div class="clinica-info">📞 <?= htmlspecialchars($tel_cli) ?></div><?php endif; ?>
    <div class="sello-minsa">NT N°022-MINSA/DGSP-V.02 | RM 593-2006/MINSA</div>
  </div>
  <div class="header-hc">
    <div class="hc-num"><?= htmlspecialchars($hc['numero_hc']) ?></div>
    <div class="hc-fecha">Fecha: <?= fd($hc['fecha_apertura']) ?></div>
    <div class="hc-fecha">Impreso: <?= date('d/m/Y H:i') ?></div>
    <?php if ($dir_med): ?><div class="hc-fecha">Dir. Médico: <?= htmlspecialchars($dir_med) ?></div><?php endif; ?>
    <?php if ($cmp_med): ?><div class="hc-fecha"><?= htmlspecialchars($cmp_med) ?></div><?php endif; ?>
  </div>
</div>

<!-- ── DATOS DEL PACIENTE ── -->
<div class="pac-card no-break">
  <div class="pac-nombre"><?= htmlspecialchars(trim($hc['pac_nombre'])) ?></div>
  <div class="pac-meta">
    DNI: <?= rv($hc['dni']) ?> &nbsp;|&nbsp;
    Nacimiento: <?= fd($hc['fecha_nacimiento']) ?> &nbsp;|&nbsp;
    <?= $hc['fecha_nacimiento'] ? (new DateTime($hc['fecha_nacimiento']))->diff(new DateTime())->y.' años' : '' ?> &nbsp;|&nbsp;
    Sexo: <?= ['M'=>'Masculino','F'=>'Femenino','O'=>'Otro'][$hc['sexo']??''] ?? '—' ?> &nbsp;|&nbsp;
    Seguro: <?= strtoupper(rv($hc['tipo_seguro'])) ?>
    <?= $hc['num_seguro'] ? '('.$hc['num_seguro'].')' : '' ?>
  </div>
  <div class="pac-meta" style="margin-top:4px">
    📞 <?= rv($hc['telefono']) ?> &nbsp;|&nbsp;
    ✉ <?= rv($hc['email']) ?> &nbsp;|&nbsp;
    📍 <?= rv($hc['direccion']) ?> <?= $hc['distrito'] ? '— '.$hc['distrito'] : '' ?>
  </div>
</div>

<!-- ── ALERTAS MÉDICAS ── -->
<?php if ($hc['alergias'] || $hc['enfermedades_base'] || $hc['medicacion_actual']): ?>
<div class="section no-break">
  <div class="section-title red">⚠ Antecedentes médicos importantes</div>
  <div class="grid-3">
    <div class="field">
      <div class="field-label">Alergias conocidas</div>
      <div class="field-value block <?= $hc['alergias'] ? 'alerta' : '' ?>"><?= r($hc['alergias'] ?: 'Sin alergias conocidas') ?></div>
    </div>
    <div class="field">
      <div class="field-label">Enfermedades de base</div>
      <div class="field-value block"><?= r($hc['enfermedades_base'] ?: 'Ninguna') ?></div>
    </div>
    <div class="field">
      <div class="field-label">Medicación actual</div>
      <div class="field-value block"><?= r($hc['medicacion_actual'] ?: 'Ninguna') ?></div>
    </div>
  </div>
  <?php if ($hc['embarazo'] || $hc['fuma'] || $hc['alcohol'] || $hc['cirugia_previa']): ?>
  <div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($hc['embarazo']): ?><span class="badge badge-red">🤰 Embarazada</span><?php endif; ?>
    <?php if ($hc['fuma']): ?><span class="badge badge-gray">🚬 Fumador/a</span><?php endif; ?>
    <?php if ($hc['alcohol']): ?><span class="badge badge-gray">🍺 Consumo de alcohol</span><?php endif; ?>
    <?php if ($hc['cirugia_previa']): ?><span class="badge badge-blue">🔪 Cirugías previas</span><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── DATOS DE LA CONSULTA ── -->
<div class="section no-break">
  <div class="section-title">📋 I. Datos de la consulta</div>
  <div class="grid-2">
    <div class="field">
      <div class="field-label">Doctor tratante</div>
      <div class="field-value"><?= rv($hc['doctor']) ?><?= $hc['cmp'] ? ' — '.$hc['cmp'] : '' ?></div>
    </div>
    <div class="field">
      <div class="field-label">Especialidad</div>
      <div class="field-value"><?= rv($hc['especialidad']) ?></div>
    </div>
  </div>
  <?php if ($hc['presion_arterial'] || $hc['peso'] || $hc['talla']): ?>
  <div class="vitales">
    <?php if ($hc['presion_arterial']): ?>
    <div class="vital-item"><div class="vital-val"><?= htmlspecialchars($hc['presion_arterial']) ?></div><div class="vital-label">PA mmHg</div></div>
    <?php endif; ?>
    <?php if ($hc['peso']): ?>
    <div class="vital-item"><div class="vital-val"><?= $hc['peso'] ?></div><div class="vital-label">Peso kg</div></div>
    <?php endif; ?>
    <?php if ($hc['talla']): ?>
    <div class="vital-item"><div class="vital-val"><?= $hc['talla'] ?></div><div class="vital-label">Talla cm</div></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ── MOTIVO Y ANAMNESIS ── -->
<div class="section no-break">
  <div class="section-title blue">📝 II. Motivo de consulta y anamnesis</div>
  <div class="field">
    <div class="field-label">Motivo de consulta *</div>
    <div class="field-value block"><?= r($hc['motivo_consulta']) ?></div>
  </div>
  <?php if ($hc['enfermedad_actual']): ?>
  <div class="field" style="margin-top:6px">
    <div class="field-label">Enfermedad actual / Tiempo de enfermedad</div>
    <div class="field-value block"><?= r($hc['enfermedad_actual']) ?></div>
  </div>
  <?php endif; ?>
  <?php if ($hc['anamnesis']): ?>
  <div class="field" style="margin-top:6px">
    <div class="field-label">Anamnesis general</div>
    <div class="field-value block"><?= r($hc['anamnesis']) ?></div>
  </div>
  <?php endif; ?>
</div>

<!-- ── EXAMEN CLÍNICO ── -->
<div class="section no-break">
  <div class="section-title green">🔍 III. Examen clínico</div>
  <div class="grid-2">
    <?php if ($hc['examen_extraoral']): ?>
    <div class="field">
      <div class="field-label">Examen extraoral (ATM, asimetría, ganglios)</div>
      <div class="field-value block"><?= r($hc['examen_extraoral']) ?></div>
    </div>
    <?php endif; ?>
    <?php if ($hc['tejidos_blandos']): ?>
    <div class="field">
      <div class="field-label">Tejidos blandos intraorales</div>
      <div class="field-value block"><?= r($hc['tejidos_blandos']) ?></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── ODONTOGRAMA ── -->
<?php if ($odontograma_svg): ?>
<div class="section page-break no-break">
  <div class="section-title">🦷 IV. Odontograma FDI (RM 593-2006/MINSA)</div>
  <div style="font-size:7.5pt;color:#607080;margin-bottom:6px">
    Fecha registro: <?= fd($odont['fecha']) ?>
    <?php if ($odont['observaciones']): ?> | Observaciones: <?= htmlspecialchars($odont['observaciones']) ?><?php endif; ?>
  </div>
  <div class="odont-container">
    <?= $odontograma_svg ?>
    <!-- Leyenda -->
    <div class="odont-leyenda">
      <?php
      $ley=[['#E05252','Caries'],['#00D4EE','Obturado'],['#F5A623','Ausente'],['#8B5CF6','Endodoncia'],
            ['#F59E0B','Corona'],['#10B981','Implante'],['#EF4444','Fractura'],['#3B82F6','Presupuesto'],
            ['#EC4899','Sellante'],['#6366F1','Prótesis'],['#06B6D4','Brackets']];
      foreach ($ley as [$c,$l]): ?>
      <div class="ley-item"><div class="ley-dot" style="background:<?= $c ?>"></div><?= $l ?></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── DIAGNÓSTICO ── -->
<div class="section no-break">
  <div class="section-title red">🏥 <?= $odontograma_svg ? 'V' : 'IV' ?>. Diagnóstico</div>
  <div class="grid-2">
    <div class="field">
      <div class="field-label">Código CIE-10</div>
      <div class="field-value"><?= rv($hc['diagnostico_cie10']) ?></div>
    </div>
    <div class="field">
      <div class="field-label">Diagnóstico</div>
      <div class="field-value"><?= rv($hc['diagnostico_desc']) ?></div>
    </div>
  </div>
  <?php if ($hc['plan_tratamiento']): ?>
  <div class="field" style="margin-top:6px">
    <div class="field-label">Plan de tratamiento (resumen)</div>
    <div class="field-value block"><?= r($hc['plan_tratamiento']) ?></div>
  </div>
  <?php endif; ?>
</div>

<!-- ── PLAN DE TRATAMIENTO DETALLADO ── -->
<?php if ($plan && $plan_det): ?>
<div class="section no-break">
  <div class="section-title purple">💊 <?= $odontograma_svg ? 'VI' : 'V' ?>. Plan de tratamiento detallado</div>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
    <span style="font-size:8pt;color:#607080">Estado del plan: <strong><?= strtoupper($plan['estado']) ?></strong></span>
    <span style="font-size:10pt;font-weight:700;color:#1A2332">Total: <?= getCfg('moneda','S/') ?> <?= number_format((float)$plan['total'],2) ?></span>
  </div>
  <table class="plan-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Tratamiento</th>
        <th>Diente</th>
        <th>Precio</th>
        <th>Sesiones</th>
        <th>Estado</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($plan_det as $i => $det): ?>
    <tr>
      <td><?= $i+1 ?></td>
      <td><?= htmlspecialchars($det['nombre_tratamiento']) ?></td>
      <td><?= rv($det['diente']) ?></td>
      <td><?= getCfg('moneda','S/') ?> <?= number_format((float)$det['precio'],2) ?></td>
      <td><?= $det['sesiones_realizadas'] ?>/<?= $det['sesiones_total'] ?></td>
      <td><span class="badge badge-<?= ['completado'=>'green','pendiente'=>'gray','en_proceso'=>'blue','cancelado'=>'red'][$det['estado']]??'gray' ?>"><?= strtoupper($det['estado']) ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ── EVOLUCIONES / NOTAS CLÍNICAS ── -->
<?php if ($evoluciones): ?>
<div class="section <?= count($evoluciones) > 3 ? 'page-break' : '' ?>">
  <?php $n_ev = ($odontograma_svg ? 'VII' : ($plan ? 'VI' : 'V')); ?>
  <div class="section-title green">📝 <?= $n_ev ?>. Notas de evolución (<?= count($evoluciones) ?>)</div>
  <?php foreach ($evoluciones as $ev): ?>
  <div class="evolucion-item no-break">
    <div class="evol-header">
      <span class="evol-fecha"><?= fDT($ev['fecha']) ?></span>
      <span class="evol-dr">Dr. <?= htmlspecialchars($ev['dr'] ?? '—') ?></span>
    </div>
    <div class="evol-body"><?= r($ev['descripcion']) ?></div>
    <?php if ($ev['procedimiento']): ?><div style="font-size:8.5pt;color:#607080;margin-top:3px">🔧 <strong>Procedimiento:</strong> <?= htmlspecialchars($ev['procedimiento']) ?></div><?php endif; ?>
    <?php if ($ev['diente']): ?><div style="font-size:8.5pt;color:#607080">🦷 <strong>Diente:</strong> <?= htmlspecialchars($ev['diente']) ?></div><?php endif; ?>
    <?php if ($ev['medicacion']): ?><div style="font-size:8.5pt;color:#607080">💊 <strong>Medicación:</strong> <?= htmlspecialchars($ev['medicacion']) ?></div><?php endif; ?>
    <?php if ($ev['proximo_control']): ?><div style="font-size:8.5pt;color:#607080">📅 <strong>Próximo control:</strong> <?= fd($ev['proximo_control']) ?></div><?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── FIRMAS ── -->
<div class="firma-section no-break">
  <div class="firma-box">
    <div class="firma-line"></div>
    <div class="firma-label">Firma y sello del Médico Cirujano Dentista</div>
    <div style="font-size:8pt;color:#1A2332;font-weight:600;margin-top:3px"><?= rv($hc['doctor']) ?></div>
    <?php if ($hc['cmp']): ?><div style="font-size:7.5pt;color:#607080"><?= htmlspecialchars($hc['cmp']) ?></div><?php endif; ?>
  </div>
  <div class="firma-box">
    <div class="firma-line"></div>
    <div class="firma-label">Firma del Paciente / Apoderado</div>
    <div style="font-size:8pt;color:#1A2332;font-weight:600;margin-top:3px"><?= htmlspecialchars(trim($hc['pac_nombre'])) ?></div>
    <div style="font-size:7.5pt;color:#607080">DNI: <?= rv($hc['dni']) ?></div>
  </div>
</div>

<!-- Pie de página -->
<div style="margin-top:20px;padding-top:8px;border-top:1px solid #DDE6EE;display:flex;justify-content:space-between;font-size:7pt;color:#AAB4BE">
  <span><?= htmlspecialchars($clinica) ?> — Documento generado el <?= date('d/m/Y H:i') ?></span>
  <span>HC: <?= htmlspecialchars($hc['numero_hc']) ?> | Pág. 1</span>
</div>

</div><!-- .page -->

<script>
// Auto-print si viene ?print=1
if(new URLSearchParams(location.search).get('print')==='1') {
    setTimeout(() => window.print(), 500);
}
</script>
</body>
</html>
