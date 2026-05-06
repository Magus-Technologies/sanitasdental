<?php
/**
 * ODONTOGRAMA 2D — Permanente + Pediátrico completo
 * Tablas: odontogramas + odontograma_dientes
 * URL:    odontograma.php?hc_id=X&paciente_id=Y
 */
require_once __DIR__ . '/../includes/config.php';
requiereLogin();

$hc_id  = (int)($_GET['hc_id']  ?? 0);
$pac_id = (int)($_GET['paciente_id'] ?? 0);
if (!$hc_id || !$pac_id) { flash('error','Parámetros inválidos'); go('pages/historia_clinica.php'); }

// ── HC y paciente ───────────────────────────────────────────────────
$st = db()->prepare("SELECT hc.*, CONCAT(p.nombres,' ',p.apellido_paterno) AS pac_nm,
                     p.fecha_nacimiento, p.alergias
                     FROM historias_clinicas hc
                     JOIN pacientes p ON hc.paciente_id = p.id
                     WHERE hc.id = ?");
$st->execute([$hc_id]);
$hc = $st->fetch();
if (!$hc) { flash('error','Historia clínica no encontrada'); go('pages/historia_clinica.php'); }

// Calcular edad para sugerir vista
$edadPac = 0;
if ($hc['fecha_nacimiento']) {
    $edadPac = (int)date_diff(date_create($hc['fecha_nacimiento']), date_create('today'))->y;
}
$vistaDefault = $edadPac > 0 && $edadPac <= 12 ? 'pediatrico' : 'adulto';

// ── Último odontograma ──────────────────────────────────────────────
$stO = db()->prepare("SELECT * FROM odontogramas WHERE hc_id=? ORDER BY fecha DESC LIMIT 1");
$stO->execute([$hc_id]);
$odont = $stO->fetch();

$dmap = [];
if ($odont) {
    $ds = db()->prepare("SELECT * FROM odontograma_dientes WHERE odontograma_id=?");
    $ds->execute([$odont['id']]);
    foreach ($ds->fetchAll() as $d) {
        $dmap[$d['numero_diente']][$d['cara']] = $d['estado'];
    }
}

// ── POST: guardar ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion']??'') === 'odontograma') {
    $dts = json_decode($_POST['dientes_json'] ?? '[]', true) ?: [];
    $obs = trim($_POST['obs'] ?? '');

    $st2 = db()->prepare("SELECT id FROM odontogramas WHERE hc_id=? AND fecha=CURDATE()");
    $st2->execute([$hc_id]);
    $oid = $st2->fetchColumn();
    if (!$oid) {
        db()->prepare("INSERT INTO odontogramas(hc_id,paciente_id,doctor_id,fecha) VALUES(?,?,?,CURDATE())")
            ->execute([$hc_id,$pac_id,$_SESSION['uid']]);
        $oid = db()->lastInsertId();
    }
    db()->prepare("DELETE FROM odontograma_dientes WHERE odontograma_id=?")->execute([$oid]);
    foreach ($dts as $dt) {
        db()->prepare("INSERT INTO odontograma_dientes(odontograma_id,numero_diente,cara,estado,color,notas) VALUES(?,?,?,?,?,?)")
            ->execute([$oid, $dt['n'], $dt['c'] ?? 'total', $dt['e'], $dt['col'] ?? 'azul', $dt['notas'] ?? '']);
    }
    db()->prepare("UPDATE odontogramas SET observaciones=? WHERE id=?")->execute([$obs,$oid]);
    auditar('GUARDAR_ODONTOGRAMA','odontogramas',$oid);
    flash('ok','Odontograma guardado.');
    header('Location:'.BASE_URL.'/pages/odontograma.php?hc_id='.$hc_id.'&paciente_id='.$pac_id);
    exit;
}

// ── Estados clínicos ────────────────────────────────────────────────
// Compartidos adulto + pediátrico
$COLORES_BASE = [
    'caries'           => '#ef4444',
    'obturado'         => '#3b82f6',
    'corona'           => '#f59e0b',
    'ausente'          => '#6b7280',
    'fractura'         => '#dc2626',
    'endodoncia'       => '#f97316',
    'implante'         => '#8b5cf6',
    'protesis'         => '#ec4899',
    'sellante'         => '#10b981',
    'movilidad'        => '#a78bfa',
    'retenido'         => '#67e8f9',
];
// Estados extra pediátricos
$COLORES_PED = [
    'caries'           => '#ef4444',
    'obturado'         => '#3b82f6',
    'sellante'         => '#10b981',
    'ausente'          => '#6b7280',
    'corona_acero'     => '#94a3b8',
    'corona_resina'    => '#fbbf24',
    'pulpotomia'       => '#f97316',
    'pulpectomia'      => '#ea580c',
    'extraccion_ind'   => '#dc2626',
    'fractura'         => '#991b1b',
    'fluorosis'        => '#06b6d4',
    'hipoplasia'       => '#8b5cf6',
    'erupcion'         => '#22c55e',
    'retenido'         => '#67e8f9',
    'diastema'         => '#a78bfa',
    'movilidad'        => '#fb923c',
    'traumatismo'      => '#e11d48',
];

// ── SVG 2D 5 caras ──────────────────────────────────────────────────
function toothSVG2D(int $num, array $dmap, array $colores, string $size='normal'): string {
    $n    = (string)$num;
    $w    = $size==='ped' ? 48 : 44;
    $h    = $size==='ped' ? 58 : 54;
    $c    = fn($cara) => isset($dmap[$n][$cara]) && isset($colores[$dmap[$n][$cara]])
                        ? $colores[$dmap[$n][$cara]] : 'transparent';
    $o    = fn($cara) => isset($dmap[$n][$cara]) ? '1' : '0.22';
    return "
    <svg class=\"cara-svg\" viewBox=\"0 0 $w $h\" xmlns=\"http://www.w3.org/2000/svg\" data-n=\"$n\" style=\"width:{$w}px;height:{$h}px;display:block\">
      <polygon points=\"1,1 ".($w-1).",1 ".($w-7).",".($h*.22)." 7,".($h*.22)."\" class=\"cara\" data-cara=\"V\" data-n=\"$n\"
        fill=\"".$c('V')."\" stroke=\"#334155\" stroke-width=\"0.7\" opacity=\"".$o('V')."\" style=\"cursor:pointer\"/>
      <polygon points=\"7,".($h*.77)." ".($w-7).",".($h*.77)." ".($w-1).",".($h-1)." 1,".($h-1)."\" class=\"cara\" data-cara=\"P\" data-n=\"$n\"
        fill=\"".$c('P')."\" stroke=\"#334155\" stroke-width=\"0.7\" opacity=\"".$o('P')."\" style=\"cursor:pointer\"/>
      <polygon points=\"1,1 7,".($h*.22)." 7,".($h*.77)." 1,".($h-1)."\" class=\"cara\" data-cara=\"M\" data-n=\"$n\"
        fill=\"".$c('M')."\" stroke=\"#334155\" stroke-width=\"0.7\" opacity=\"".$o('M')."\" style=\"cursor:pointer\"/>
      <polygon points=\"".($w-1).",1 ".($w-7).",".($h*.22)." ".($w-7).",".($h*.77)." ".($w-1).",".($h-1)."\" class=\"cara\" data-cara=\"D\" data-n=\"$n\"
        fill=\"".$c('D')."\" stroke=\"#334155\" stroke-width=\"0.7\" opacity=\"".$o('D')."\" style=\"cursor:pointer\"/>
      <rect x=\"7\" y=\"".($h*.22)."\" width=\"".($w-14)."\" height=\"".($h*.55)."\" rx=\"2\" class=\"cara\" data-cara=\"O\" data-n=\"$n\"
        fill=\"".$c('O')."\" stroke=\"#334155\" stroke-width=\"0.7\" opacity=\"".$o('O')."\" style=\"cursor:pointer\"/>
      <rect x=\"0.5\" y=\"0.5\" width=\"".($w-1)."\" height=\"".($h-1)."\" rx=\"3\" fill=\"none\" stroke=\"#475569\" stroke-width=\"0.8\"/>
    </svg>";
}

$titulo = 'Odontograma 2D';
include __DIR__.'/../includes/header.php';
?>
<style>
/* ── Base ───────────────────────────────────────────────── */
.odo-pg    { padding:20px; }
.odo-top   { display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap; }
.odo-top h2{ font-size:1.1rem;font-weight:800;color:var(--t);margin:0; }

/* ── Cards ──────────────────────────────────────────────── */
.oc        { background:var(--bg2);border:1px solid var(--bd2);border-radius:10px;margin-bottom:14px; }
.oc-hdr    { padding:10px 16px;border-bottom:1px solid var(--bd2);display:flex;align-items:center;
              justify-content:space-between;flex-wrap:wrap;gap:8px; }
.oc-hdr span{ font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--t); }
.oc-body   { padding:16px; }

/* ── Selector de vista ──────────────────────────────────── */
.vista-tabs { display:flex;gap:0;background:var(--bg4);border-radius:8px;padding:3px;border:1px solid var(--bd2); }
.vista-tab  { padding:6px 18px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;
               color:var(--t2);transition:all .15s;border:none;background:transparent;white-space:nowrap; }
.vista-tab.act{ background:var(--c);color:#000; }
.vista-tab:not(.act):hover{ color:var(--t); }

/* ── Herramientas ───────────────────────────────────────── */
.tool-bar  { display:flex;flex-wrap:wrap;gap:5px;align-items:center;margin-bottom:14px; }
.tool-btn  { display:flex;align-items:center;gap:5px;padding:5px 9px;border-radius:7px;
              background:var(--bg4);border:2px solid transparent;font-size:11px;font-weight:600;
              color:var(--t2);cursor:pointer;transition:all .15s;user-select:none;white-space:nowrap; }
.tool-btn:hover{ border-color:rgba(255,255,255,.15);color:var(--t); }
.tool-btn.act  { border-color:var(--c);background:rgba(0,212,238,.1);color:var(--c); }
.tool-btn.eraser.act{ border-color:#f87171;background:rgba(248,113,113,.08);color:#f87171; }
.tool-dot  { width:11px;height:11px;border-radius:3px;flex-shrink:0; }

/* ── Arcada ─────────────────────────────────────────────── */
.odo-board { overflow-x:auto;padding:6px 0; }
.odo-arc-lbl{ text-align:center;font-size:9px;font-weight:700;letter-spacing:2px;
               text-transform:uppercase;color:var(--t3);margin:4px 0; }
.odo-row   { display:flex;justify-content:center;gap:3px;min-width:700px; }
.odo-row-ped{ min-width:420px; }
.odo-sep   { width:14px;flex-shrink:0; }
.odo-midline{ border-top:1px dashed rgba(0,212,238,.15);margin:7px auto;width:88%;
               text-align:center;position:relative; }
.odo-midline span{ position:relative;top:-9px;background:var(--bg2);
                    padding:0 10px;font-size:9px;color:var(--t3); }

/* ── Diente ─────────────────────────────────────────────── */
.tooth-wrap { display:flex;flex-direction:column;align-items:center;gap:2px;position:relative; }
.tooth-num  { font-size:9px;color:var(--t3);font-weight:700;line-height:1; }
.tooth-wrap:hover .cara-svg rect[x="0.5"]{ stroke:var(--c)!important; }

/* ── Leyenda ────────────────────────────────────────────── */
.odo-legend { display:flex;flex-wrap:wrap;gap:7px;margin-top:10px; }
.leg-item   { display:flex;align-items:center;gap:5px;font-size:11px;color:var(--t2); }
.leg-dot    { width:10px;height:10px;border-radius:2px;flex-shrink:0;border:1px solid rgba(255,255,255,.1); }

/* ── Tooltip ────────────────────────────────────────────── */
#odoTip { position:fixed;background:var(--bg3);border:1px solid var(--c);border-radius:8px;
           padding:7px 11px;font-size:11px;color:var(--t);pointer-events:none;z-index:9999;
           display:none;max-width:180px;box-shadow:0 4px 20px rgba(0,212,238,.2); }
#odoTip strong{ color:var(--c);display:block;margin-bottom:2px; }

/* ── Historial ──────────────────────────────────────────── */
.hist-row  { display:flex;align-items:center;gap:8px;font-size:12px;color:var(--t2);
              padding:6px 0;border-bottom:1px solid var(--bd2); }
.hist-row:last-child{ border-bottom:none; }

/* ── Vistas ─────────────────────────────────────────────── */
.vista-adulto,
.vista-pediatrico { display:none; }
.vista-adulto.show,
.vista-pediatrico.show { display:block; }

/* ── Bloque mixto para pediátrico ───────────────────────── */
.ped-section{ background:rgba(0,212,238,.03);border:1px solid rgba(0,212,238,.1);
               border-radius:10px;padding:14px;margin-bottom:12px; }
.ped-section-title{ font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;
                     color:var(--c);margin-bottom:10px;display:flex;align-items:center;gap:6px; }

/* ── Notas por diente (pediátrico) ──────────────────────── */
.ped-notas-wrap{ margin-top:12px; }
.ped-notas-hdr{ font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;
                 color:var(--t3);margin-bottom:6px; }
.ped-nota-item{ display:flex;align-items:center;gap:8px;padding:5px 8px;border-radius:6px;
                 background:var(--bg4);margin-bottom:4px;font-size:11px; }
.ped-nota-num { font-weight:800;color:var(--c);min-width:26px; }
.ped-nota-est { color:var(--t2); }
.ped-nota-caras{ display:flex;gap:3px;flex-wrap:wrap; }
.ped-cara-tag { padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700;border:1px solid rgba(255,255,255,.15); }

/* ── Responsive ─────────────────────────────────────────── */
@media(max-width:992px){ .tool-btn{ font-size:10px;padding:4px 8px; } }
@media(max-width:768px){
  .odo-pg   { padding:10px; }
  .odo-top h2{ font-size:.95rem; }
  .oc-body  { padding:10px; }
  .tool-bar { gap:4px; }
  .tool-btn { font-size:10px;padding:4px 7px; }
  .tool-dot { width:10px;height:10px; }
  .odo-board::before{ content:'← Desliza →';display:block;text-align:center;font-size:10px;
    color:var(--t3);padding:4px;background:var(--bg4);border-radius:6px;margin-bottom:5px; }
  .odo-row  { gap:2px; }
  .oc-body .d-flex.gap-2.flex-wrap .btn{ width:100%;justify-content:center; }
  .hist-row { flex-wrap:wrap;gap:4px;font-size:11px; }
}
@media(max-width:480px){
  .odo-top .badge{ display:none; }
  .tool-btn { font-size:9px;padding:3px 6px; }
  .vista-tabs{ flex-direction:column;width:100%; }
}

/* ── Print ──────────────────────────────────────────────── */
@media print{
  .topbar,.sidebar,.tool-bar,.odo-top a,.btn,.oc-hdr button,.vista-tabs{ display:none!important; }
  .oc{ border:1px solid #ccc!important;background:#fff!important; }
  body,.odo-pg{ background:#fff!important;color:#000!important; }
  .vista-adulto,.vista-pediatrico{ display:block!important; }
}
</style>

<div class="odo-pg">

  <!-- ── Encabezado ────────────────────────────────────────── -->
  <div class="odo-top">
    <a href="<?=BASE_URL?>/pages/historia_clinica.php?id=<?=$hc_id?>" class="btn btn-dk btn-sm">
      <i class="bi bi-arrow-left me-1"></i>HC
    </a>
    <h2>🦷 Odontograma 2D</h2>
    <span class="badge bc"><?=e($hc['pac_nm'])?></span>
    <?php if($hc['fecha_nacimiento']): ?>
      <span style="font-size:11px;color:var(--t2)"><?=edad($hc['fecha_nacimiento'])?> años</span>
    <?php endif;?>
    <?php if($hc['alergias']): ?><span class="badge br">⚠️ <?=e($hc['alergias'])?></span><?php endif;?>
    <!-- Selector de vista -->
    <div class="vista-tabs ms-auto" id="vistaTabs">
      <button class="vista-tab <?=$vistaDefault==='adulto'?'act':''?>" onclick="setVista('adulto',this)">🦷 Adulto</button>
      <button class="vista-tab <?=$vistaDefault==='pediatrico'?'act':''?>" onclick="setVista('pediatrico',this)">🧒 Pediátrico</button>
    </div>
    <span style="font-size:10px;color:var(--t3)">HC: <?=e($hc['numero_hc'])?></span>
  </div>

  <?=popFlash()?>

  <form method="POST" id="fOdont">
    <input type="hidden" name="accion"       value="odontograma">
    <input type="hidden" name="hc_id"        value="<?=$hc_id?>">
    <input type="hidden" name="paciente_id"  value="<?=$pac_id?>">
    <input type="hidden" name="dientes_json" id="djson" value="[]">

    <!-- ════════════════════════════════════════════
         VISTA ADULTO
    ════════════════════════════════════════════ -->
    <div class="vista-adulto <?=$vistaDefault==='adulto'?'show':''?>" id="vistaAdulto">
      <div class="oc">
        <div class="oc-hdr">
          <span>Herramientas — Dentición Permanente</span>
          <small style="color:var(--t2);font-size:10px">Clic = marcar cara · Doble clic = borrar diente</small>
        </div>
        <div class="oc-body">
          <div class="tool-bar" id="toolBarAdulto">
            <div class="tool-btn eraser act" data-estado="" data-color="" data-vista="adulto">✕ Borrar</div>
            <?php foreach($COLORES_BASE as $estado => $color): ?>
            <div class="tool-btn" data-estado="<?=$estado?>" data-color="<?=$color?>" data-vista="adulto">
              <div class="tool-dot" style="background:<?=$color?>"></div><?=ucfirst($estado)?>
            </div>
            <?php endforeach;?>
          </div>

          <div class="oc" style="margin-bottom:0">
            <div class="oc-hdr"><span>Dentición Permanente — FDI</span></div>
            <div class="oc-body">
              <div class="odo-board">
                <div class="odo-arc-lbl">MAXILAR SUPERIOR</div>
                <div class="odo-row">
                  <?php foreach([18,17,16,15,14,13,12,11] as $n): ?>
                  <div class="tooth-wrap"><div class="tooth-num"><?=$n?></div><?=toothSVG2D($n,$dmap,$COLORES_BASE)?></div>
                  <?php endforeach;?><div class="odo-sep"></div>
                  <?php foreach([21,22,23,24,25,26,27,28] as $n): ?>
                  <div class="tooth-wrap"><div class="tooth-num"><?=$n?></div><?=toothSVG2D($n,$dmap,$COLORES_BASE)?></div>
                  <?php endforeach;?>
                </div>
                <div class="odo-midline"><span>Línea media</span></div>
                <div class="odo-row">
                  <?php foreach([48,47,46,45,44,43,42,41] as $n): ?>
                  <div class="tooth-wrap"><?=toothSVG2D($n,$dmap,$COLORES_BASE)?><div class="tooth-num"><?=$n?></div></div>
                  <?php endforeach;?><div class="odo-sep"></div>
                  <?php foreach([31,32,33,34,35,36,37,38] as $n): ?>
                  <div class="tooth-wrap"><?=toothSVG2D($n,$dmap,$COLORES_BASE)?><div class="tooth-num"><?=$n?></div></div>
                  <?php endforeach;?>
                </div>
                <div class="odo-arc-lbl" style="margin-top:4px">MANDÍBULA</div>
              </div>
              <div class="odo-legend" id="odoLegendAdulto"></div>
            </div>
          </div>
        </div>
      </div>
    </div><!-- /vista adulto -->

    <!-- ════════════════════════════════════════════
         VISTA PEDIÁTRICA
    ════════════════════════════════════════════ -->
    <div class="vista-pediatrico <?=$vistaDefault==='pediatrico'?'show':''?>" id="vistaPediatrico">
      <div class="oc">
        <div class="oc-hdr">
          <span>Herramientas — Dentición Decidua</span>
          <small style="color:var(--t2);font-size:10px">Clic = marcar cara · Doble clic = borrar diente</small>
        </div>
        <div class="oc-body">

          <!-- Herramientas pediátricas agrupadas -->
          <div class="tool-bar" id="toolBarPed">
            <div class="tool-btn eraser act" data-estado="" data-color="" data-vista="ped">✕ Borrar</div>
            <?php
            $gruposPed = [
              'Caries / Resto' => ['caries'=>'Caries','obturado'=>'Obturado'],
              'Pulpa'          => ['pulpotomia'=>'Pulpotomía','pulpectomia'=>'Pulpectomía'],
              'Corona'         => ['corona_acero'=>'Corona acero','corona_resina'=>'Corona resina'],
              'Preventivo'     => ['sellante'=>'Sellante','fluorosis'=>'Fluorosis','hipoplasia'=>'Hipoplasia'],
              'Trauma'         => ['traumatismo'=>'Traumatismo','fractura'=>'Fractura'],
              'Estado'         => ['erupcion'=>'En erupción','retenido'=>'Retenido','ausente'=>'Ausente','extraccion_ind'=>'Extrac. indicada'],
              'Otro'           => ['diastema'=>'Diastema','movilidad'=>'Movilidad'],
            ];
            foreach($gruposPed as $grupo => $items): ?>
            <div style="display:flex;align-items:center;gap:4px;padding:2px 0;flex-wrap:wrap">
              <span style="font-size:9px;color:var(--t3);font-weight:700;min-width:55px"><?=$grupo?></span>
              <?php foreach($items as $estado => $label): $color=$COLORES_PED[$estado]??'#888'; ?>
              <div class="tool-btn" data-estado="<?=$estado?>" data-color="<?=$color?>" data-vista="ped">
                <div class="tool-dot" style="background:<?=$color?>"></div><?=$label?>
              </div>
              <?php endforeach;?>
            </div>
            <?php endforeach;?>
          </div>

          <!-- SVG Decidua Superior -->
          <div class="ped-section">
            <div class="ped-section-title">
              <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0a8 8 0 1 0 0 16A8 8 0 0 0 8 0zm0 14A6 6 0 1 1 8 2a6 6 0 0 1 0 12z"/></svg>
              Maxilar superior deciduo
            </div>
            <div class="odo-board">
              <div class="odo-arc-lbl">SUPERIOR</div>
              <div class="odo-row odo-row-ped">
                <?php foreach([55,54,53,52,51] as $n): ?>
                <div class="tooth-wrap"><div class="tooth-num"><?=$n?></div><?=toothSVG2D($n,$dmap,$COLORES_PED,'ped')?></div>
                <?php endforeach;?>
                <div class="odo-sep"></div>
                <?php foreach([61,62,63,64,65] as $n): ?>
                <div class="tooth-wrap"><div class="tooth-num"><?=$n?></div><?=toothSVG2D($n,$dmap,$COLORES_PED,'ped')?></div>
                <?php endforeach;?>
              </div>
            </div>
          </div>

          <!-- Referencia anatómica pediátrica -->
          <div style="display:flex;gap:10px;justify-content:center;margin:6px 0;flex-wrap:wrap">
            <?php
            $refPed = [
              'I'  => ['Incisivo central','#55'],
              'II' => ['Incisivo lateral','#54/#64'],
              'III'=> ['Canino','#53/#63'],
              'IV' => ['1er molar','#54/#64'],
              'V'  => ['2do molar','#55/#65'],
            ];
            foreach($refPed as $rom => [$nombre,$cod]): ?>
            <div style="text-align:center;font-size:9px;color:var(--t3);padding:4px 8px;background:var(--bg4);border-radius:6px">
              <div style="font-weight:800;color:var(--t2)"><?=$rom?></div>
              <div><?=$nombre?></div>
              <div style="color:var(--t3)"><?=$cod?></div>
            </div>
            <?php endforeach;?>
          </div>

          <!-- Línea media -->
          <div class="odo-midline"><span>Línea media</span></div>

          <!-- SVG Decidua Inferior -->
          <div class="ped-section">
            <div class="ped-section-title">
              <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16zm0-2A6 6 0 1 1 8 2a6 6 0 0 1 0 12z"/></svg>
              Mandíbula inferior decidua
            </div>
            <div class="odo-board">
              <div class="odo-row odo-row-ped">
                <?php foreach([85,84,83,82,81] as $n): ?>
                <div class="tooth-wrap"><?=toothSVG2D($n,$dmap,$COLORES_PED,'ped')?><div class="tooth-num"><?=$n?></div></div>
                <?php endforeach;?>
                <div class="odo-sep"></div>
                <?php foreach([71,72,73,74,75] as $n): ?>
                <div class="tooth-wrap"><?=toothSVG2D($n,$dmap,$COLORES_PED,'ped')?><div class="tooth-num"><?=$n?></div></div>
                <?php endforeach;?>
              </div>
              <div class="odo-arc-lbl" style="margin-top:4px">INFERIOR</div>
            </div>
          </div>

          <!-- Leyenda pediátrica -->
          <div class="odo-legend" id="odoLegendPed"></div>

          <!-- Resumen notas pediátricas (dinámico) -->
          <div class="ped-notas-wrap" id="pedNotas" style="display:none">
            <div class="ped-notas-hdr">📋 Resumen por diente</div>
            <div id="pedNotasLista"></div>
          </div>

        </div>
      </div>
    </div><!-- /vista pediatrico -->

    <!-- ── Observaciones y guardar ───────────────────────── -->
    <div class="oc">
      <div class="oc-hdr"><span>Observaciones</span></div>
      <div class="oc-body">
        <textarea name="obs" class="form-control mb-3" rows="2"
          placeholder="Observaciones generales del odontograma..."><?=e($odont['observaciones']??'')?></textarea>
        <div class="d-flex gap-2 flex-wrap">
          <button type="submit" class="btn btn-primary"><i class="bi bi-floppy me-2"></i>Guardar odontograma</button>
          <button type="button" class="btn btn-dk" onclick="window.print()"><i class="bi bi-printer me-2"></i>Imprimir</button>
          <a href="<?=BASE_URL?>/pages/historia_clinica.php?id=<?=$hc_id?>" class="btn btn-dk"><i class="bi bi-arrow-left me-1"></i>Volver a HC</a>
        </div>
      </div>
    </div>
  </form>

  <!-- ── Historial ─────────────────────────────────────────── -->
  <?php
  $hist=db()->prepare("SELECT o.id,o.fecha,o.observaciones,
                        CONCAT(u.nombre,' ',u.apellidos) AS dr,COUNT(d.id) AS total
                        FROM odontogramas o LEFT JOIN usuarios u ON o.doctor_id=u.id
                        LEFT JOIN odontograma_dientes d ON d.odontograma_id=o.id
                        WHERE o.hc_id=? GROUP BY o.id ORDER BY o.fecha DESC LIMIT 10");
  $hist->execute([$hc_id]);
  $rows=$hist->fetchAll();
  if($rows):?>
  <div class="oc">
    <div class="oc-hdr"><span>📋 Historial</span></div>
    <div class="oc-body" style="padding:10px 16px">
      <?php foreach($rows as $h):?>
      <div class="hist-row">
        <span class="badge bc"><?=fDate($h['fecha'])?></span>
        <span><?=$h['total']?> diente(s)</span>
        <?php if($h['dr']): ?><span style="color:var(--t3)">· Dr. <?=e($h['dr'])?></span><?php endif;?>
        <?php if($h['observaciones']): ?><span style="font-style:italic">— <?=e(mb_strimwidth($h['observaciones'],0,60,'…'))?></span><?php endif;?>
      </div>
      <?php endforeach;?>
    </div>
  </div>
  <?php endif;?>

</div><!-- /odo-pg -->

<div id="odoTip"></div>

<script>
// ── Datos ───────────────────────────────────────────────────────────
const NOMBRES = {
  11:'Inc. central sup. der.',12:'Inc. lateral sup. der.',13:'Canino sup. der.',
  14:'1er premolar sup. der.',15:'2do premolar sup. der.',16:'1er molar sup. der.',
  17:'2do molar sup. der.',18:'3er molar sup. der.',
  21:'Inc. central sup. izq.',22:'Inc. lateral sup. izq.',23:'Canino sup. izq.',
  24:'1er premolar sup. izq.',25:'2do premolar sup. izq.',26:'1er molar sup. izq.',
  27:'2do molar sup. izq.',28:'3er molar sup. izq.',
  31:'Inc. central inf. izq.',32:'Inc. lateral inf. izq.',33:'Canino inf. izq.',
  34:'1er premolar inf. izq.',35:'2do premolar inf. izq.',36:'1er molar inf. izq.',
  37:'2do molar inf. izq.',38:'3er molar inf. izq.',
  41:'Inc. central inf. der.',42:'Inc. lateral inf. der.',43:'Canino inf. der.',
  44:'1er premolar inf. der.',45:'2do premolar inf. der.',46:'1er molar inf. der.',
  47:'2do molar inf. der.',48:'3er molar inf. der.',
  // Deciduos
  51:'Inc. central sup. der. (d)',52:'Inc. lateral sup. der. (d)',53:'Canino sup. der. (d)',
  54:'1er molar sup. der. (d)',55:'2do molar sup. der. (d)',
  61:'Inc. central sup. izq. (d)',62:'Inc. lateral sup. izq. (d)',63:'Canino sup. izq. (d)',
  64:'1er molar sup. izq. (d)',65:'2do molar sup. izq. (d)',
  71:'Inc. central inf. izq. (d)',72:'Inc. lateral inf. izq. (d)',73:'Canino inf. izq. (d)',
  74:'1er molar inf. izq. (d)',75:'2do molar inf. izq. (d)',
  81:'Inc. central inf. der. (d)',82:'Inc. lateral inf. der. (d)',83:'Canino inf. der. (d)',
  84:'1er molar inf. der. (d)',85:'2do molar inf. der. (d)',
};
const CARAS_LABEL = {V:'Vestibular',P:'Palatino/Lingual',M:'Mesial',D:'Distal',O:'Oclusal'};
const COLORES_ADULTO = <?=json_encode($COLORES_BASE)?>;
const COLORES_PED    = <?=json_encode($COLORES_PED)?>;

// ── Estado ──────────────────────────────────────────────────────────
let dm = {};
const saved = <?php
  $out=[];
  foreach($dmap as $num=>$caras)
    foreach($caras as $cara=>$estado)
      $out[]=['n'=>$num,'c'=>$cara,'e'=>$estado];
  echo json_encode($out);
?>;
saved.forEach(r=>{ if(!dm[r.n])dm[r.n]={}; dm[r.n][r.c]=r.e; });

let estadoActivo  = '';
let vistaActiva   = '<?=$vistaDefault?>';

// ── Cambiar vista ────────────────────────────────────────────────────
function setVista(v, btn) {
  vistaActiva = v;
  document.querySelectorAll('.vista-tab').forEach(b=>b.classList.remove('act'));
  btn.classList.add('act');
  document.getElementById('vistaAdulto').classList.toggle('show', v==='adulto');
  document.getElementById('vistaPediatrico').classList.toggle('show', v==='pediatrico');
  // Reset herramienta activa de la vista anterior
  estadoActivo = '';
  document.querySelectorAll('.tool-btn').forEach(b=>b.classList.remove('act'));
  const eraser = document.querySelector(`#toolBar${v==='adulto'?'Adulto':'Ped'} .eraser`);
  if(eraser) eraser.classList.add('act');
}

// ── Toolbars ─────────────────────────────────────────────────────────
['toolBarAdulto','toolBarPed'].forEach(id=>{
  const bar = document.getElementById(id);
  if(!bar) return;
  bar.addEventListener('click',e=>{
    const btn=e.target.closest('.tool-btn');
    if(!btn) return;
    // Solo desactivar botones del mismo toolbar
    bar.querySelectorAll('.tool-btn').forEach(b=>b.classList.remove('act'));
    btn.classList.add('act');
    estadoActivo = btn.dataset.estado;
  });
});

// ── Click en cara ────────────────────────────────────────────────────
document.querySelectorAll('.cara').forEach(el=>{
  el.addEventListener('click',function(e){
    e.stopPropagation();
    const num=this.dataset.n, cara=this.dataset.cara;
    if(!dm[num]) dm[num]={};
    if(estadoActivo===''){
      delete dm[num][cara];
      if(Object.keys(dm[num]).length===0) delete dm[num];
    } else {
      dm[num][cara]=estadoActivo;
    }
    refrescarDiente(num);
    actualizarLeyenda();
    actualizarResumenPed();
    saveJson();
  });

  el.addEventListener('dblclick',function(e){
    e.preventDefault(); e.stopPropagation();
    delete dm[this.dataset.n];
    refrescarDiente(this.dataset.n);
    actualizarLeyenda();
    actualizarResumenPed();
    saveJson();
  });

  // Tooltip
  el.addEventListener('mouseenter',function(){
    const num=this.dataset.n, cara=this.dataset.cara;
    const estado=(dm[num]||{})[cara]||'sano';
    const isPed=[51,52,53,54,55,61,62,63,64,65,71,72,73,74,75,81,82,83,84,85].includes(+num);
    const colMap=isPed?COLORES_PED:COLORES_ADULTO;
    const color=colMap[estado]||'transparent';
    const tip=document.getElementById('odoTip');
    tip.innerHTML='<strong>'+(NOMBRES[+num]||'D.'+num)+'</strong>'
      +'Cara: '+CARAS_LABEL[cara]+'<br>'
      +'Estado: <span style="color:'+(color==='transparent'?'#8b949e':color)+'">'+estado+'</span>';
    tip.style.display='block';
    document.addEventListener('mousemove',moverTip);
  });
  el.addEventListener('mouseleave',()=>{
    document.getElementById('odoTip').style.display='none';
    document.removeEventListener('mousemove',moverTip);
  });
});

function moverTip(e){
  const t=document.getElementById('odoTip');
  t.style.left=(e.clientX+14)+'px'; t.style.top=(e.clientY-10)+'px';
}

// ── Redibujar diente ─────────────────────────────────────────────────
function refrescarDiente(num){
  const isPed=[51,52,53,54,55,61,62,63,64,65,71,72,73,74,75,81,82,83,84,85].includes(+num);
  const colMap=isPed?COLORES_PED:COLORES_ADULTO;
  document.querySelectorAll(`.cara[data-n="${num}"]`).forEach(el=>{
    const cara=el.dataset.cara;
    const estado=(dm[num]||{})[cara]||'';
    el.setAttribute('fill', estado&&colMap[estado]?colMap[estado]:'transparent');
    el.setAttribute('opacity', estado?'1':'0.22');
  });
}

// ── Leyenda ──────────────────────────────────────────────────────────
function actualizarLeyenda(){
  const usadosA=new Set(), usadosP=new Set();
  const numsPed=new Set([51,52,53,54,55,61,62,63,64,65,71,72,73,74,75,81,82,83,84,85]);
  Object.entries(dm).forEach(([num,caras])=>{
    Object.values(caras).forEach(e=>{
      if(numsPed.has(+num)) usadosP.add(e); else usadosA.add(e);
    });
  });
  const makeLeg=(set,colMap,id)=>{
    const el=document.getElementById(id); if(!el) return;
    if(set.size===0){el.innerHTML='';return;}
    el.innerHTML='<span style="font-size:10px;color:#506070;margin-right:4px">Leyenda:</span>'
      +[...set].map(e=>{
        const c=colMap[e]||'#666';
        return `<div class="leg-item"><div class="leg-dot" style="background:${c}"></div>${e}</div>`;
      }).join('');
  };
  makeLeg(usadosA,COLORES_ADULTO,'odoLegendAdulto');
  makeLeg(usadosP,COLORES_PED,'odoLegendPed');
}

// ── Resumen pediátrico ────────────────────────────────────────────────
function actualizarResumenPed(){
  const numsPed=[55,54,53,52,51,61,62,63,64,65,85,84,83,82,81,71,72,73,74,75];
  const lista=document.getElementById('pedNotasLista');
  const wrap=document.getElementById('pedNotas');
  const items=numsPed.filter(n=>dm[n]&&Object.keys(dm[n]).length>0);
  if(items.length===0){wrap.style.display='none';return;}
  wrap.style.display='block';
  lista.innerHTML=items.map(n=>{
    const caras=dm[n];
    const carasHtml=Object.entries(caras).map(([c,e])=>{
      const col=COLORES_PED[e]||'#666';
      return `<span class="ped-cara-tag" style="background:${col}22;color:${col};border-color:${col}44">${CARAS_LABEL[c]}: ${e}</span>`;
    }).join('');
    return `<div class="ped-nota-item">
      <span class="ped-nota-num">${n}</span>
      <div class="ped-nota-caras">${carasHtml}</div>
    </div>`;
  }).join('');
}

// ── Guardar JSON ──────────────────────────────────────────────────────
function saveJson(){
  const arr=[];
  for(const [num,caras] of Object.entries(dm))
    for(const [cara,estado] of Object.entries(caras))
      arr.push({n:num,c:cara,e:estado,col:'azul',notas:''});
  document.getElementById('djson').value=JSON.stringify(arr);
}

// ── Init ──────────────────────────────────────────────────────────────
saved.forEach(r=>refrescarDiente(r.n));
actualizarLeyenda();
actualizarResumenPed();
saveJson();
</script>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
