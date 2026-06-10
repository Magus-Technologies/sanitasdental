<?php
/**
 * ODONTOGRAMA 2D — 5 caras por diente, integrado al flujo del proyecto
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

// ── Último odontograma ──────────────────────────────────────────────
$stO = db()->prepare("SELECT * FROM odontogramas WHERE hc_id=? ORDER BY fecha DESC LIMIT 1");
$stO->execute([$hc_id]);
$odont = $stO->fetch();

// dmap: numero_diente => [ cara => estado ]  (agrupamos por diente+cara)
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

// ── Colores estándar ────────────────────────────────────────────────
$COLORES = [
    'caries'        => '#ef4444',
    'obturado'      => '#3b82f6',
    'corona'        => '#f59e0b',
    'ausente'       => '#6b7280',
    'fractura'      => '#dc2626',
    'endodoncia'    => '#f97316',
    'implante'      => '#8b5cf6',
    'protesis'      => '#ec4899',
    'sellante'      => '#10b981',
    'movilidad'     => '#a78bfa',
    'retenido'      => '#67e8f9',
    'sano'          => 'transparent',
];

// Helper: color de una cara específica de un diente
function caraColor(int $num, string $cara, array $dmap, array $colores): string {
    $estado = $dmap[(string)$num][$cara] ?? '';
    return $colores[$estado] ?? 'transparent';
}
function caraOpacity(int $num, string $cara, array $dmap): string {
    return isset($dmap[(string)$num][$cara]) ? '1' : '0.25';
}

// ── SVG 2D de 5 caras ─────────────────────────────────────────────
// Caras: V=vestibular(arriba), P=palatino(abajo), M=mesial(izq), D=distal(der), O=oclusal(centro)
function toothSVG2D(int $num, array $dmap, array $colores): string {
    $n = (string)$num;
    $c = fn($cara) => caraColor($num, $cara, $dmap, $colores);
    $o = fn($cara) => caraOpacity($num, $cara, $dmap);
    return '
    <svg class="tooth2d" viewBox="0 0 44 54" xmlns="http://www.w3.org/2000/svg" data-n="'.$n.'">
      <!-- V: Vestibular arriba -->
      <polygon points="1,1 43,1 37,12 7,12" class="cara" data-cara="V" data-n="'.$n.'"
        fill="'.$c('V').'" stroke="#334155" stroke-width="0.7" opacity="'.$o('V').'" style="cursor:pointer"/>
      <!-- P: Palatino/Lingual abajo -->
      <polygon points="7,42 37,42 43,53 1,53" class="cara" data-cara="P" data-n="'.$n.'"
        fill="'.$c('P').'" stroke="#334155" stroke-width="0.7" opacity="'.$o('P').'" style="cursor:pointer"/>
      <!-- M: Mesial izquierda -->
      <polygon points="1,1 7,12 7,42 1,53" class="cara" data-cara="M" data-n="'.$n.'"
        fill="'.$c('M').'" stroke="#334155" stroke-width="0.7" opacity="'.$o('M').'" style="cursor:pointer"/>
      <!-- D: Distal derecha -->
      <polygon points="43,1 37,12 37,42 43,53" class="cara" data-cara="D" data-n="'.$n.'"
        fill="'.$c('D').'" stroke="#334155" stroke-width="0.7" opacity="'.$o('D').'" style="cursor:pointer"/>
      <!-- O: Oclusal centro -->
      <rect x="7" y="12" width="30" height="30" rx="2" class="cara" data-cara="O" data-n="'.$n.'"
        fill="'.$c('O').'" stroke="#334155" stroke-width="0.7" opacity="'.$o('O').'" style="cursor:pointer"/>
      <!-- Borde exterior -->
      <rect x="0.5" y="0.5" width="43" height="53" rx="3" fill="none" stroke="#475569" stroke-width="0.8"/>
    </svg>';
}

$titulo = 'Odontograma 2D';
include __DIR__.'/../includes/header.php';
?>
<style>
/* ── Página ─────────────────────────────────────────────── */
.odo-pg   { padding:20px; }
.odo-top  { display:flex;align-items:center;gap:12px;margin-bottom:18px;flex-wrap:wrap; }
.odo-top h2{ font-size:1.15rem;font-weight:800;color:var(--t);margin:0; }

/* ── Cards ──────────────────────────────────────────────── */
.oc       { background:var(--bg2);border:1px solid var(--bd2);border-radius:10px;margin-bottom:14px; }
.oc-hdr   { padding:10px 16px;border-bottom:1px solid var(--bd2);display:flex;align-items:center;
             justify-content:space-between;flex-wrap:wrap;gap:8px; }
.oc-hdr span{ font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--t); }
.oc-body  { padding:16px; }

/* ── Herramientas ───────────────────────────────────────── */
.tool-bar  { display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:16px; }
.tool-btn  { display:flex;align-items:center;gap:5px;padding:5px 10px;border-radius:7px;
              background:var(--bg4);border:2px solid transparent;font-size:11px;font-weight:600;
              color:var(--t2);cursor:pointer;transition:all .15s;white-space:nowrap;user-select:none; }
.tool-btn:hover{ border-color:rgba(255,255,255,.15);color:var(--t); }
.tool-btn.act  { border-color:var(--c);background:rgba(0,212,238,.1);color:var(--c); }
.tool-btn.eraser.act{ border-color:var(--r);background:rgba(224,82,82,.1);color:var(--r); }
.tool-dot  { width:12px;height:12px;border-radius:3px;flex-shrink:0; }

/* ── Arcada ─────────────────────────────────────────────── */
.odo-board { overflow-x:auto;padding:8px 0; }
.odo-arc-lbl{ text-align:center;font-size:9px;font-weight:700;letter-spacing:2px;
               text-transform:uppercase;color:var(--t3);margin:4px 0; }
.odo-row   { display:flex;justify-content:center;gap:3px;min-width:720px; }
.odo-sep   { width:16px;flex-shrink:0; }
.odo-midline{ border-top:1px dashed rgba(0,212,238,.15);margin:8px auto;width:90%;
               text-align:center;position:relative; }
.odo-midline span{ position:relative;top:-9px;background:var(--bg2);
                    padding:0 10px;font-size:9px;color:var(--t3); }

/* ── Diente 2D ──────────────────────────────────────────── */
.tooth-wrap  { display:flex;flex-direction:column;align-items:center;gap:2px; }
.tooth-num   { font-size:9px;color:var(--t3);font-weight:700;line-height:1; }
.tooth2d     { width:44px;height:54px;display:block; }
.tooth-wrap:hover .tooth2d rect[x="0.5"]{ stroke:var(--c)!important; }

/* ── Tooltip ────────────────────────────────────────────── */
#odoTip { position:fixed;background:var(--bg3);border:1px solid var(--c);border-radius:8px;
           padding:7px 11px;font-size:11px;color:var(--t);pointer-events:none;z-index:9999;
           display:none;max-width:170px;box-shadow:0 4px 20px rgba(0,212,238,.2); }
#odoTip strong{ color:var(--c);display:block;margin-bottom:2px; }

/* ── Leyenda ────────────────────────────────────────────── */
.odo-legend{ display:flex;flex-wrap:wrap;gap:8px;margin-top:12px; }
.leg-item  { display:flex;align-items:center;gap:5px;font-size:11px;color:var(--t2); }
.leg-dot   { width:10px;height:10px;border-radius:2px;flex-shrink:0;border:1px solid rgba(255,255,255,.15); }

/* ── Historial ──────────────────────────────────────────── */
.hist-row  { display:flex;align-items:center;gap:8px;font-size:12px;color:var(--t2);
              padding:6px 0;border-bottom:1px solid var(--bd2); }
.hist-row:last-child{ border-bottom:none; }

/* ── Print ──────────────────────────────────────────────── */
@media print{
  .topbar,.sidebar,.tool-bar,.odo-top a,.btn,.oc-hdr button{ display:none!important; }
  .oc{ border:1px solid #ccc!important;background:#fff!important; }
  body,.odo-pg{ background:#fff!important;color:#000!important; }
}
</style>

<div class="odo-pg">

  <!-- Encabezado -->
  <div class="odo-top">
    <a href="<?=BASE_URL?>/pages/historia_clinica.php?id=<?=$hc_id?>" class="btn btn-dk btn-sm">
      <i class="bi bi-arrow-left me-1"></i>Historia Clínica
    </a>
    <h2>🦷 Odontograma 2D</h2>
    <span class="badge bc"><?=e($hc['pac_nm'])?></span>
    <?php if($hc['fecha_nacimiento']): ?>
      <span style="font-size:11px;color:var(--t2)"><?=edad($hc['fecha_nacimiento'])?> años</span>
    <?php endif;?>
    <?php if($hc['alergias']): ?>
      <span class="badge br">⚠️ <?=e($hc['alergias'])?></span>
    <?php endif;?>
    <span style="font-size:10px;color:var(--t3);margin-left:auto">HC: <?=e($hc['numero_hc'])?></span>
  </div>

  <?=popFlash()?>

  <form method="POST" id="fOdont">
    <input type="hidden" name="accion"       value="odontograma">
    <input type="hidden" name="hc_id"        value="<?=$hc_id?>">
    <input type="hidden" name="paciente_id"  value="<?=$pac_id?>">
    <input type="hidden" name="dientes_json" id="djson" value="[]">

    <!-- ── Herramientas ────────────────────────────────── -->
    <div class="oc">
      <div class="oc-hdr">
        <span>Herramientas</span>
        <small style="color:var(--t2);font-size:10px">Clic = marcar cara · Doble clic = borrar diente</small>
      </div>
      <div class="oc-body">
        <div class="tool-bar" id="toolBar">
          <div class="tool-btn eraser act" data-estado="" data-color="">✕ Borrar / Sano</div>
          <?php foreach($COLORES as $estado => $color): if($estado==='sano') continue; ?>
          <div class="tool-btn" data-estado="<?=$estado?>" data-color="<?=$color?>">
            <div class="tool-dot" style="background:<?=$color?>"></div>
            <?=ucfirst($estado)?>
          </div>
          <?php endforeach;?>
        </div>

        <!-- ── SVG Dentición permanente ─────────────── -->
        <div class="oc" style="margin-bottom:0">
          <div class="oc-hdr"><span>Dentición Permanente — FDI</span></div>
          <div class="oc-body">
            <div class="odo-board">
              <div class="odo-arc-lbl">MAXILAR SUPERIOR</div>
              <div class="odo-row">
                <?php foreach([18,17,16,15,14,13,12,11] as $n): ?>
                <div class="tooth-wrap">
                  <div class="tooth-num"><?=$n?></div>
                  <?=toothSVG2D($n,$dmap,$COLORES)?>
                </div>
                <?php endforeach;?>
                <div class="odo-sep"></div>
                <?php foreach([21,22,23,24,25,26,27,28] as $n): ?>
                <div class="tooth-wrap">
                  <div class="tooth-num"><?=$n?></div>
                  <?=toothSVG2D($n,$dmap,$COLORES)?>
                </div>
                <?php endforeach;?>
              </div>
              <div class="odo-midline"><span>Línea media</span></div>
              <div class="odo-row">
                <?php foreach([48,47,46,45,44,43,42,41] as $n): ?>
                <div class="tooth-wrap">
                  <?=toothSVG2D($n,$dmap,$COLORES)?>
                  <div class="tooth-num"><?=$n?></div>
                </div>
                <?php endforeach;?>
                <div class="odo-sep"></div>
                <?php foreach([31,32,33,34,35,36,37,38] as $n): ?>
                <div class="tooth-wrap">
                  <?=toothSVG2D($n,$dmap,$COLORES)?>
                  <div class="tooth-num"><?=$n?></div>
                </div>
                <?php endforeach;?>
              </div>
              <div class="odo-arc-lbl" style="margin-top:4px">MANDÍBULA</div>
            </div>
            <div class="odo-legend" id="odoLegend"></div>
          </div>
        </div>

        <!-- ── Dentición Decidua (colapsable) ────────── -->
        <details class="oc mt-3" style="margin-bottom:0">
          <summary class="oc-hdr" style="cursor:pointer;list-style:none">
            <span>🧒 Dentición Decidua (51–85)</span>
            <i class="bi bi-chevron-down" style="font-size:11px;color:var(--t2)"></i>
          </summary>
          <div class="oc-body">
            <div class="odo-board">
              <div class="odo-arc-lbl">SUPERIOR</div>
              <div class="odo-row" style="min-width:500px">
                <?php foreach([55,54,53,52,51] as $n): ?>
                <div class="tooth-wrap">
                  <div class="tooth-num"><?=$n?></div>
                  <?=toothSVG2D($n,$dmap,$COLORES)?>
                </div>
                <?php endforeach;?>
                <div class="odo-sep"></div>
                <?php foreach([61,62,63,64,65] as $n): ?>
                <div class="tooth-wrap">
                  <div class="tooth-num"><?=$n?></div>
                  <?=toothSVG2D($n,$dmap,$COLORES)?>
                </div>
                <?php endforeach;?>
              </div>
              <div class="odo-midline"><span>Línea media</span></div>
              <div class="odo-row" style="min-width:500px">
                <?php foreach([85,84,83,82,81] as $n): ?>
                <div class="tooth-wrap">
                  <?=toothSVG2D($n,$dmap,$COLORES)?>
                  <div class="tooth-num"><?=$n?></div>
                </div>
                <?php endforeach;?>
                <div class="odo-sep"></div>
                <?php foreach([71,72,73,74,75] as $n): ?>
                <div class="tooth-wrap">
                  <?=toothSVG2D($n,$dmap,$COLORES)?>
                  <div class="tooth-num"><?=$n?></div>
                </div>
                <?php endforeach;?>
              </div>
              <div class="odo-arc-lbl" style="margin-top:4px">INFERIOR</div>
            </div>
          </div>
        </details>
      </div>
    </div>

    <!-- ── Observaciones y guardar ─────────────────────── -->
    <div class="oc">
      <div class="oc-hdr"><span>Observaciones</span></div>
      <div class="oc-body">
        <textarea name="obs" class="form-control mb-3" rows="2"
          placeholder="Observaciones generales..."><?=e($odont['observaciones']??'')?></textarea>
        <div class="d-flex gap-2 flex-wrap">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-floppy me-2"></i>Guardar odontograma
          </button>
          <button type="button" class="btn btn-dk" onclick="window.print()">
            <i class="bi bi-printer me-2"></i>Imprimir
          </button>
          <a href="<?=BASE_URL?>/pages/historia_clinica.php?id=<?=$hc_id?>" class="btn btn-dk">
            <i class="bi bi-arrow-left me-1"></i>Volver a HC
          </a>
        </div>
      </div>
    </div>
  </form>

  <!-- ── Historial ───────────────────────────────────────── -->
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
        <span><?=$h['total']?> diente(s) marcados</span>
        <?php if($h['dr']): ?><span style="color:var(--t3)">· Dr. <?=e($h['dr'])?></span><?php endif;?>
        <?php if($h['observaciones']): ?>
          <span style="font-style:italic">— <?=e(mb_strimwidth($h['observaciones'],0,60,'…'))?></span>
        <?php endif;?>
      </div>
      <?php endforeach;?>
    </div>
  </div>
  <?php endif;?>

</div><!-- /odo-pg -->

<!-- Tooltip flotante -->
<div id="odoTip"></div>

<script>
// ── Nombres de dientes FDI ──────────────────────────────────────────
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
  51:'Inc. central sup. der. (d)',52:'Inc. lateral sup. der. (d)',53:'Canino sup. der. (d)',
  54:'1er molar sup. der. (d)',55:'2do molar sup. der. (d)',
  61:'Inc. central sup. izq. (d)',62:'Inc. lateral sup. izq. (d)',63:'Canino sup. izq. (d)',
  64:'1er molar sup. izq. (d)',65:'2do molar sup. izq. (d)',
  71:'Inc. central inf. izq. (d)',72:'Inc. lateral inf. izq. (d)',73:'Canino inf. izq. (d)',
  74:'1er molar inf. izq. (d)',75:'2do molar inf. izq. (d)',
  81:'Inc. central inf. der. (d)',82:'Inc. lateral inf. der. (d)',83:'Canino inf. der. (d)',
  84:'1er molar inf. der. (d)',85:'2do molar inf. der. (d)',
};
const CARAS = {V:'Vestibular',P:'Palatino/Lingual',M:'Mesial',D:'Distal',O:'Oclusal'};
const COLORES = <?=json_encode($COLORES)?>;

// ── Estado global: dm[num][cara] = estado ───────────────────────────
let dm = {};
const saved = <?php
  $out = [];
  foreach($dmap as $num => $caras) {
      foreach($caras as $cara => $estado) {
          $out[] = ['n'=>$num,'c'=>$cara,'e'=>$estado];
      }
  }
  echo json_encode($out);
?>;
saved.forEach(r => {
  if(!dm[r.n]) dm[r.n] = {};
  dm[r.n][r.c] = r.e;
});

// ── Herramienta activa ──────────────────────────────────────────────
let estadoActivo = '';

document.getElementById('toolBar').addEventListener('click', e => {
  const btn = e.target.closest('.tool-btn');
  if(!btn) return;
  document.querySelectorAll('.tool-btn').forEach(b => b.classList.remove('act'));
  btn.classList.add('act');
  estadoActivo = btn.dataset.estado;
});

// ── Click en cara ───────────────────────────────────────────────────
document.querySelectorAll('.cara').forEach(el => {
  el.addEventListener('click', function(e){
    e.stopPropagation();
    const num  = this.dataset.n;
    const cara = this.dataset.cara;
    if(!dm[num]) dm[num] = {};
    if(estadoActivo === '') {
      delete dm[num][cara];
      if(Object.keys(dm[num]).length === 0) delete dm[num];
    } else {
      dm[num][cara] = estadoActivo;
    }
    refrescarDiente(num);
    actualizarLeyenda();
    saveJson();
  });

  // Doble clic: borrar diente completo
  el.addEventListener('dblclick', function(e){
    e.preventDefault(); e.stopPropagation();
    delete dm[this.dataset.n];
    refrescarDiente(this.dataset.n);
    actualizarLeyenda();
    saveJson();
  });

  // Tooltip
  el.addEventListener('mouseenter', function(e){
    const num = this.dataset.n, cara = this.dataset.cara;
    const estado = (dm[num]||{})[cara] || 'sano';
    const color  = COLORES[estado] || 'transparent';
    const tip = document.getElementById('odoTip');
    tip.innerHTML = '<strong>'+(NOMBRES[+num]||'Diente '+num)+'</strong>'
      +'Cara: '+CARAS[cara]+'<br>'
      +'Estado: <span style="color:'+(color==='transparent'?'#8b949e':color)+'">'+estado+'</span>';
    tip.style.display = 'block';
    document.addEventListener('mousemove', moverTip);
  });
  el.addEventListener('mouseleave', () => {
    document.getElementById('odoTip').style.display='none';
    document.removeEventListener('mousemove', moverTip);
  });
});

function moverTip(e){
  const t=document.getElementById('odoTip');
  t.style.left=(e.clientX+14)+'px';
  t.style.top=(e.clientY-10)+'px';
}

// ── Redibujar un diente ─────────────────────────────────────────────
function refrescarDiente(num) {
  document.querySelectorAll(`.cara[data-n="${num}"]`).forEach(el => {
    const cara   = el.dataset.cara;
    const estado = (dm[num]||{})[cara] || '';
    const color  = estado ? (COLORES[estado]||'transparent') : 'transparent';
    const opac   = estado ? '1' : '0.25';
    el.setAttribute('fill', color);
    el.setAttribute('opacity', opac);
  });
}

// ── Leyenda dinámica ────────────────────────────────────────────────
function actualizarLeyenda() {
  const usados = new Set();
  Object.values(dm).forEach(caras => Object.values(caras).forEach(e => usados.add(e)));
  const leg = document.getElementById('odoLegend');
  if(!leg) return;
  if(usados.size === 0){ leg.innerHTML=''; return; }
  leg.innerHTML = '<span style="font-size:10px;color:#506070;margin-right:4px">Leyenda:</span>'
    + [...usados].map(e => {
        const c = COLORES[e]||'#666';
        return `<div class="leg-item"><div class="leg-dot" style="background:${c}"></div>${e}</div>`;
      }).join('');
}

// ── Serializar para guardar ─────────────────────────────────────────
// Convierte dm{num:{cara:estado}} → array de {n,c,e,col,notas}
function saveJson() {
  const arr = [];
  for(const [num, caras] of Object.entries(dm)){
    for(const [cara, estado] of Object.entries(caras)){
      arr.push({n: num, c: cara, e: estado, col:'azul', notas:''});
    }
  }
  document.getElementById('djson').value = JSON.stringify(arr);
}

// ── Init ─────────────────────────────────────────────────────────────
saved.forEach(r => refrescarDiente(r.n));
actualizarLeyenda();
saveJson();
</script>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
