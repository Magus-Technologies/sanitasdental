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
        'caries'     => '#ef4444',
        'obturado'   => '#3b82f6',
        'corona'     => '#f59e0b',
        'ausente'    => '#6b7280',
        'fractura'   => '#dc2626',
        'endodoncia' => '#f97316',
        'implante'   => '#8b5cf6',
        'protesis'   => '#ec4899',
        'sellante'   => '#10b981',
        'movilidad'  => '#a78bfa',
        'retenido'   => '#67e8f9',
        'presupuesto'=> '#3b82f6',
        'brackets'   => '#06b6d4',
        'sano'       => 'none',
    ];
    $col_color = ['rojo'=>'#ef4444','azul'=>'#3b82f6','negro'=>'#6b7280','verde'=>'#10b981'];

    $TW=38; $TH=46; $GAP=2; $XC=430;
    $ROW1_Y=38; $ROW2_Y=128;

    $sup_der=[18,17,16,15,14,13,12,11]; $sup_izq=[21,22,23,24,25,26,27,28];
    $inf_izq=[31,32,33,34,35,36,37,38]; $inf_der=[48,47,46,45,44,43,42,41];

    function toothX2(int $n,int $xc,int $tw,int $gap): float {
        $sd=[18,17,16,15,14,13,12,11]; $si=[21,22,23,24,25,26,27,28];
        $ii=[31,32,33,34,35,36,37,38]; $id=[48,47,46,45,44,43,42,41];
        $step=$tw+$gap;
        if(in_array($n,$sd)){$i=array_search($n,array_reverse($sd));return $xc-($tw/2)-($i*$step);}
        if(in_array($n,$si)){$i=array_search($n,$si);return $xc+($tw/2)+($i*$step)-$tw;}
        if(in_array($n,$ii)){$i=array_search($n,$ii);return $xc+($tw/2)+($i*$step)-$tw;}
        if(in_array($n,$id)){$i=array_search($n,array_reverse($id));return $xc-($tw/2)-($i*$step);}
        return $xc;
    }

    function faceC(string $cara,array $estados,array $cm,array $cc): string {
        $e=$estados[$cara]??($estados['total']??null);
        if(!$e||$e['estado']==='sano') return 'none';
        return $cc[$e['color']]??($cm[$e['estado']]??'#E05252');
    }

    function drawTooth2(int $num,float $x,float $y,int $tw,int $th,array $raw_estados,array $cm,array $cc): string {
        $s='';
        $es=[];
        foreach($raw_estados as $e) $es[$e['cara']]=$e;
        $bv=7; $bs=6;
        $x2=$x+$tw; $y2=$y+$th; $cx=$x+$tw/2; $cy=$y+$th/2;

        $main=$es['total']??$es['O']??($es?array_values($es)[0]:null);

        if($main&&$main['estado']==='ausente'){
            $s.="<rect x='".($x+0.5)."' y='".($y+0.5)."' width='".($tw-1)."' height='".($th-1)."' rx='2' fill='#1A2535' stroke='#3A4A5A' stroke-width='0.8'/>";
            $s.="<line x1='".($x+5)."' y1='".($y+5)."' x2='".($x2-5)."' y2='".($y2-5)."' stroke='#506070' stroke-width='1.5'/>";
            $s.="<line x1='".($x2-5)."' y1='".($y+5)."' x2='".($x+5)."' y2='".($y2-5)."' stroke='#506070' stroke-width='1.5'/>";
            return $s;
        }

        $s.="<rect x='$x' y='$y' width='$tw' height='$th' rx='2' fill='#1A2535' stroke='#334155' stroke-width='0.8'/>";

        // V - vestibular top
        $vc=faceC('V',$es,$cm,$cc);
        if($vc!=='none') $s.="<polygon points='".($x+1).",".($y+1)." ".($x2-1).",".($y+1)." ".($x2-$bv).",".($y+$bv)." ".($x+$bv).",".($y+$bv)."' fill='$vc' stroke='#334155' stroke-width='0.5'/>";
        // P - palatino bottom
        $pc=faceC('P',$es,$cm,$cc);
        if($pc!=='none') $s.="<polygon points='".($x+$bv).",".($y2-$bv)." ".($x2-$bv).",".($y2-$bv)." ".($x2-1).",".($y2-1)." ".($x+1).",".($y2-1)."' fill='$pc' stroke='#334155' stroke-width='0.5'/>";
        // M - mesial left
        $mc=faceC('M',$es,$cm,$cc);
        if($mc!=='none') $s.="<polygon points='".($x+1).",".($y+1)." ".($x+$bs).",".($y+$bv)." ".($x+$bs).",".($y2-$bv)." ".($x+1).",".($y2-1)."' fill='$mc' stroke='#334155' stroke-width='0.5'/>";
        // D - distal right
        $dc=faceC('D',$es,$cm,$cc);
        if($dc!=='none') $s.="<polygon points='".($x2-1).",".($y+1)." ".($x2-$bs).",".($y+$bv)." ".($x2-$bs).",".($y2-$bv)." ".($x2-1).",".($y2-1)."' fill='$dc' stroke='#334155' stroke-width='0.5'/>";
        // O - oclusal center
        $oc=faceC('O',$es,$cm,$cc);
        if($oc!=='none') $s.="<rect x='".($x+$bs)."' y='".($y+$bv)."' width='".($tw-$bs*2)."' height='".($th-$bv*2)."' fill='$oc' stroke='#334155' stroke-width='0.5'/>";

        // Outer border
        $s.="<rect x='$x' y='$y' width='$tw' height='$th' rx='2' fill='none' stroke='#475569' stroke-width='0.8'/>";

        // Endodoncia line
        if($main&&$main['estado']==='endodoncia')
            $s.="<line x1='$cx' y1='".($y+3)."' x2='$cx' y2='".($y2-3)."' stroke='#8B5CF6' stroke-width='2' stroke-linecap='round'/>";
        // Implante I
        if($main&&$main['estado']==='implante')
            $s.="<text x='$cx' y='".($cy+4)."' text-anchor='middle' font-size='13' font-weight='900' fill='#8B5CF6' font-family='Arial'>I</text>";
        return $s;
    }

    $W=860; $H=230;
    $svg="<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 $W $H' style='width:100%;max-width:860px;background:#111A26;border-radius:6px;display:block'>";
    $svg.="<rect width='$W' height='$H' fill='#111A26' rx='6'/>";
    // Center line
    $midY=$ROW2_Y-10;
    $svg.="<line x1='$XC' y1='15' x2='$XC' y2='".($H-15)."' stroke='rgba(100,130,160,0.3)' stroke-width='1' stroke-dasharray='4,3'/>";
    $svg.="<line x1='25' y1='$midY' x2='".($W-25)."' y2='$midY' stroke='rgba(100,130,160,0.15)' stroke-width='1'/>";
    $svg.="<text x='$XC' y='".($midY-3)."' text-anchor='middle' font-size='7' fill='#4A6070' font-family='Arial' letter-spacing='1'>Línea media</text>";
    $svg.="<text x='215' y='30' text-anchor='middle' font-size='7.5' fill='#4A6070' font-family='Arial' letter-spacing='2'>MAXILAR SUPERIOR</text>";
    $svg.="<text x='215' y='".($H-5)."' text-anchor='middle' font-size='7.5' fill='#4A6070' font-family='Arial' letter-spacing='2'>MANDÍBULA</text>";

    foreach(array_merge($sup_der,$sup_izq,$inf_der,$inf_izq) as $num){
        $x=toothX2($num,$XC,$TW,$GAP);
        $y=($num<30)?$ROW1_Y:$ROW2_Y;
        $es=$dientes[(string)$num]??[];
        $svg.=drawTooth2($num,$x,$y,$TW,$TH,$es,$col_map,$col_color);
        $ny=($num<30)?($ROW1_Y-5):($ROW2_Y+$TH+9);
        $ncx=$x+$TW/2;
        $svg.="<text x='$ncx' y='$ny' text-anchor='middle' font-size='7' fill='#5A7080' font-family='Arial' font-weight='600'>$num</text>";
    }

    // Legend
    $leg=['caries'=>'#E05252','obturado'=>'#5BA8F5','ausente'=>'#607080','endodoncia'=>'#F5A623','corona'=>'#F5A623','implante'=>'#8B5CF6','fractura'=>'#E05252','protesis'=>'#EC4899'];
    $lx=20; $ly=$H-6;
    foreach($leg as $lbl=>$lc){
        $svg.="<rect x='$lx' y='".($ly-8)."' width='8' height='8' rx='1.5' fill='$lc'/>";
        $lx+=11;
        $svg.="<text x='$lx' y='$ly' font-size='6.5' fill='#7090A0' font-family='Arial'>$lbl</text>";
        $lx+=(int)(strlen($lbl)*3.8)+8;
    }
    $svg.='</svg>';
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
