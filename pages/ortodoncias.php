<?php
/**
 * ORTODONCIA — Módulo completo
 * Tablas: ortodoncias (existente, sin cambios de estructura)
 * Acciones: lista | nuevo | editar | ver
 */
require_once __DIR__.'/../includes/config.php';
requiereLogin();

$paciente_id = (int)($_GET['paciente_id'] ?? 0);
$accion      = $_GET['accion'] ?? 'lista';
$id          = (int)($_GET['id'] ?? 0);

if (!$paciente_id) { flash('error','Paciente requerido'); go('pages/pacientes.php'); }

$ps = db()->prepare("SELECT * FROM pacientes WHERE id=?");
$ps->execute([$paciente_id]);
$pac = $ps->fetch();
if (!$pac) { flash('error','Paciente no encontrado'); go('pages/pacientes.php'); }

// ── POST: guardar ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion']??'')==='guardar') {
    $ei               = (int)($_POST['id'] ?? 0);
    $hc_id            = (int)($_POST['hc_id'] ?? 0);
    $tipo             = $_POST['tipo'] ?? 'control';
    $fecha_atencion   = $_POST['fecha_atencion']  ?? date('Y-m-d');
    $fecha_referencia = $_POST['fecha_referencia'] ?? null;
    $proximo_control  = $_POST['proximo_control']  ?? null;
    $observaciones    = trim($_POST['observaciones'] ?? '');
    $procedimientos   = trim($_POST['procedimientos'] ?? '');

    // ── Arcos: tipo + medida + sección ──────────────────────────────
    $arcos = [];
    $tiposArco = ['acero','niti','termico','cobre_niti','tma','beta_titanio','acero_trenzado','acero_rectangular','ideal_archwire'];
    foreach ($tiposArco as $ta) {
        if (isset($_POST['arco_'.$ta])) {
            $arcos[] = [
                'tipo'    => $ta,
                'medida'  => trim($_POST['medida_'.$ta]  ?? ''),
                'seccion' => trim($_POST['seccion_'.$ta] ?? ''),
                'arcada'  => $_POST['arcada_'.$ta] ?? 'ambas',
            ];
        }
    }

    // ── Accesorios / materiales ──────────────────────────────────────
    $accesorios = [];
    $listAcc = ['resorte_abierto','resorte_cerrado','cadena_elastica','ligadura_metalica',
                'ligadura_elastomerica','tubo_molar','bracket_metalico','bracket_ceramico',
                'bracket_safiro','banda_ortodoncia','boton_lingual','gancho_retraccion',
                'arco_utilitario','retenedor','placa_hawley','expansor','disyuntor',
                'elastico_intermaxilar','stop_resorte','barra_palatina','arco_lingual'];
    foreach ($listAcc as $acc) {
        if (isset($_POST['acc_'.$acc])) {
            $accesorios[] = [
                'item'  => $acc,
                'notas' => trim($_POST['acc_nota_'.$acc] ?? ''),
            ];
        }
    }

    // ── Dientes con brackets ─────────────────────────────────────────
    $dientesJson = $_POST['dientes_json'] ?? '[]';

    // ── Evaluación clínica ───────────────────────────────────────────
    $evaluacion = [
        'clase_molar'     => $_POST['clase_molar']     ?? '',
        'clase_canina'    => $_POST['clase_canina']     ?? '',
        'overjet'         => $_POST['overjet']          ?? '',
        'overbite'        => $_POST['overbite']         ?? '',
        'mordida'         => $_POST['mordida']          ?? '',
        'linea_media'     => $_POST['linea_media']      ?? '',
        'apiñamiento'     => $_POST['apiñamiento']      ?? '',
        'espaciamiento'   => $_POST['espaciamiento']    ?? '',
    ];

    $tipo_arco_json   = json_encode($arcos);
    $procedimientos_full = $procedimientos."\n\n[ACCESORIOS:".json_encode($accesorios)."][EVAL:".json_encode($evaluacion)."]";

    if ($ei) {
        db()->prepare("UPDATE ortodoncias SET hc_id=?,tipo=?,fecha_atencion=?,fecha_referencia=?,
                       tipo_arco=?,dientes_json=?,observaciones=?,procedimientos=?,
                       proximo_control=?,updated_at=NOW() WHERE id=?")
           ->execute([$hc_id?:null,$tipo,$fecha_atencion,$fecha_referencia?:null,
                      $tipo_arco_json,$dientesJson,$observaciones,$procedimientos_full,
                      $proximo_control?:null,$ei]);
        auditar('EDITAR_ORTODONCIA','ortodoncias',$ei);
        flash('ok','Control de ortodoncia actualizado.');
    } else {
        db()->prepare("INSERT INTO ortodoncias(paciente_id,hc_id,tipo,fecha_atencion,fecha_referencia,
                       tipo_arco,dientes_json,observaciones,procedimientos,proximo_control,doctor_id)
                       VALUES(?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$paciente_id,$hc_id?:null,$tipo,$fecha_atencion,$fecha_referencia?:null,
                      $tipo_arco_json,$dientesJson,$observaciones,$procedimientos_full,
                      $proximo_control?:null,$_SESSION['uid']]);
        $nid = db()->lastInsertId();
        auditar('CREAR_ORTODONCIA','ortodoncias',$nid);
        flash('ok','Ortodoncia registrada correctamente.');
    }
    go("pages/ortodoncias.php?paciente_id=$paciente_id");
}

// ════════════════════════════════════════════════════════════════════
// VISTA: LISTA
// ════════════════════════════════════════════════════════════════════
if ($accion === 'lista') {
    $orts = db()->prepare("SELECT o.*, CONCAT(u.nombre,' ',u.apellidos) AS doctor, hc.numero_hc
                           FROM ortodoncias o
                           LEFT JOIN usuarios u ON o.doctor_id=u.id
                           LEFT JOIN historias_clinicas hc ON o.hc_id=hc.id
                           WHERE o.paciente_id=? ORDER BY o.fecha_atencion DESC");
    $orts->execute([$paciente_id]);
    $orts = $orts->fetchAll();

    $titulo = 'Ortodoncia — '.$pac['nombres'].' '.$pac['apellido_paterno'];
    $pagina_activa = 'pac';
    $topbar_act = '<a href="?accion=nuevo&paciente_id='.$paciente_id.'&tipo=instalacion" class="btn btn-primary"><i class="bi bi-plus me-1"></i>Nuevo registro</a>
    <a href="'.BASE_URL.'/pages/pacientes.php?accion=ver&id='.$paciente_id.'" class="btn btn-dk btn-sm"><i class="bi bi-person me-1"></i>Paciente</a>';
    include __DIR__.'/../includes/header.php';
?>
<style>
.ort-timeline { position:relative;padding-left:32px; }
.ort-timeline::before { content:'';position:absolute;left:14px;top:0;bottom:0;width:2px;background:var(--bd2); }
.ort-node     { position:relative;margin-bottom:16px; }
.ort-dot      { position:absolute;left:-26px;top:12px;width:14px;height:14px;border-radius:50%;
                 border:2px solid var(--bg2);flex-shrink:0; }
.ort-card     { background:var(--bg2);border:1px solid var(--bd2);border-radius:10px;padding:14px 16px;
                 transition:border-color .15s; }
.ort-card:hover{ border-color:rgba(0,212,238,.3); }
.ort-badge    { display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:12px;
                 font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em; }
.arc-chip     { display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:6px;
                 font-size:10px;font-weight:600;background:var(--bg4);border:1px solid var(--bd2);color:var(--t2); }
.pac-info     { background:var(--bg2);border:1px solid var(--bd2);border-radius:10px;padding:14px 18px;
                 margin-bottom:16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap; }
/* ── Responsive lista ───────────────────────────────────── */
@media(max-width:768px){
  .pb { padding:12px; }
  .pac-info { padding:10px 12px;gap:8px; }
  .pac-info .ms-auto{ width:100%;justify-content:stretch; }
  .pac-info .ms-auto .btn{ flex:1;justify-content:center; }
  .ort-timeline{ padding-left:22px; }
  .ort-dot{ left:-18px;width:12px;height:12px; }
  .ort-card{ padding:10px 12px; }
  .arc-chip{ font-size:9px;padding:2px 6px; }
}
@media(max-width:480px){
  .ort-timeline::before{ left:8px; }
  .ort-dot{ left:-14px; }
}
</style>

<div class="pb">
  <!-- Info paciente -->
  <div class="pac-info">
    <div class="ava" style="width:44px;height:44px;font-size:18px;flex-shrink:0"><?=strtoupper(substr($pac['nombres'],0,1))?></div>
    <div>
      <strong style="font-size:14px;color:var(--t)"><?=e($pac['nombres'].' '.$pac['apellido_paterno'].' '.($pac['apellido_materno']??''))?></strong>
      <div style="font-size:11px;color:var(--t2)"><?=e($pac['codigo'])?> · <?=$pac['fecha_nacimiento']?edad($pac['fecha_nacimiento']):'—'?> · DNI: <?=e($pac['dni']??'—')?></div>
    </div>
    <?php if($pac['alergias']): ?><span class="badge br">⚠️ <?=e($pac['alergias'])?></span><?php endif;?>
    <div class="ms-auto d-flex gap-2">
      <a href="?accion=nuevo&paciente_id=<?=$paciente_id?>&tipo=instalacion" class="btn btn-primary btn-sm"><i class="bi bi-plus me-1"></i>Instalación</a>
      <a href="?accion=nuevo&paciente_id=<?=$paciente_id?>&tipo=control"     class="btn btn-dk  btn-sm"><i class="bi bi-plus me-1"></i>Control</a>
    </div>
  </div>

  <?=popFlash()?>

  <?php if($orts): ?>
  <div class="ort-timeline">
    <?php foreach($orts as $o):
      $arcosRaw2 = json_decode($o['tipo_arco']??'[]',true) ?: [];
      $arcos = array_map(fn($a) => is_string($a) ? ['tipo'=>$a,'medida'=>'','arcada'=>'ambas'] : $a, $arcosRaw2);
      $isInst   = $o['tipo']==='instalacion';
      $dotColor = $isInst ? '#10b981' : '#00d4ee';
      $hoy      = date('Y-m-d');
      $vencido  = $o['proximo_control'] && $o['proximo_control'] < $hoy;
    ?>
    <div class="ort-node">
      <div class="ort-dot" style="background:<?=$dotColor?>"></div>
      <div class="ort-card" style="cursor:pointer" onclick="location.href='?accion=ver&id=<?=$o['id']?>&paciente_id=<?=$paciente_id?>'">
        <div class="d-flex align-items-start flex-wrap" style="gap:10px">
          <div class="flex-grow-1">
            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
              <span class="ort-badge" style="background:<?=$isInst?'rgba(16,185,129,.15)':'rgba(0,212,238,.1)'?>;color:<?=$isInst?'#10b981':'var(--c)'?>">
                <?=$isInst?'🦷 Instalación':'🔧 Control'?>
              </span>
              <span style="font-size:12px;color:var(--t2)"><?=fDate($o['fecha_atencion'])?></span>
              <?php if($o['doctor']): ?><span style="font-size:11px;color:var(--t3)">· Dr. <?=e($o['doctor'])?></span><?php endif;?>
              <?php if($o['numero_hc']): ?><span class="badge bc" style="font-size:9px"><?=e($o['numero_hc'])?></span><?php endif;?>
            </div>
            <?php if($arcos): ?>
            <div class="d-flex flex-wrap gap-1 mb-1">
              <?php foreach($arcos as $a): ?>
              <span class="arc-chip">🌀 <?=e($a['tipo']??'')?><?=$a['medida']?' · '.e($a['medida']):''?><?=$a['arcada']&&$a['arcada']!='ambas'?' ('.e($a['arcada']).')':''?></span>
              <?php endforeach;?>
            </div>
            <?php endif;?>
            <?php
            // Extraer procedimientos limpios
            $proc = preg_replace('/\[ACCESORIOS:.*\]\[EVAL:.*\]/s','',$o['procedimientos']??'');
            if(trim($proc)): ?>
            <div style="font-size:11px;color:var(--t2);margin-top:2px"><?=e(mb_strimwidth(trim($proc),0,100,'…'))?></div>
            <?php endif;?>
            <?php if($o['proximo_control']): ?>
            <div class="mt-1" style="font-size:11px">
              <span style="color:<?=$vencido?'var(--r)':'var(--g)'?>">
                <?=$vencido?'⚠️ Control vencido':'📅 Próximo control'?>: <?=fDate($o['proximo_control'])?>
              </span>
            </div>
            <?php endif;?>
          </div>
          <div class="d-flex gap-1 flex-shrink-0">
            <a href="?accion=ver&id=<?=$o['id']?>&paciente_id=<?=$paciente_id?>" class="btn btn-dk btn-sm">Ver</a>
            <a href="?accion=editar&id=<?=$o['id']?>&paciente_id=<?=$paciente_id?>" class="btn btn-dk btn-sm"><i class="bi bi-pencil"></i></a>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach;?>
  </div>
  <?php else: ?>
  <div class="card p-5 text-center" style="color:var(--t2)">
    <i class="bi bi-grid-3x2-gap" style="font-size:48px;display:block;margin-bottom:16px;color:var(--t3)"></i>
    <h3 style="font-size:16px;margin-bottom:8px;color:var(--t)">Sin registros de ortodoncia</h3>
    <p style="font-size:13px">Comienza registrando la instalación del aparato.</p>
    <div class="d-flex gap-2 justify-content-center">
      <a href="?accion=nuevo&paciente_id=<?=$paciente_id?>&tipo=instalacion" class="btn btn-primary"><i class="bi bi-plus me-1"></i>Registrar instalación</a>
      <a href="?accion=nuevo&paciente_id=<?=$paciente_id?>&tipo=control"     class="btn btn-dk"><i class="bi bi-plus me-1"></i>Registrar control</a>
    </div>
  </div>
  <?php endif;?>
</div>
<?php
    require_once __DIR__.'/../includes/footer.php';

// ════════════════════════════════════════════════════════════════════
// VISTA: NUEVO / EDITAR
// ════════════════════════════════════════════════════════════════════
} elseif (in_array($accion,['nuevo','editar'])) {
    $ort = ['id'=>0,'tipo'=>$_GET['tipo']??'control','fecha_atencion'=>date('Y-m-d'),
            'fecha_referencia'=>'','tipo_arco'=>'[]','dientes_json'=>'[]',
            'observaciones'=>'','procedimientos'=>'','proximo_control'=>'','hc_id'=>0];
    if ($accion==='editar' && $id) {
        $s = db()->prepare("SELECT * FROM ortodoncias WHERE id=? AND paciente_id=?");
        $s->execute([$id,$paciente_id]);
        $ort = $s->fetch() ?: $ort;
    }

    $hcs = db()->prepare("SELECT id,numero_hc,fecha_apertura FROM historias_clinicas WHERE paciente_id=? ORDER BY fecha_apertura DESC");
    $hcs->execute([$paciente_id]);
    $hcs = $hcs->fetchAll();

    $arcosRaw3 = json_decode($ort['tipo_arco']??'[]',true) ?: [];
    $arcosMap = [];
    foreach($arcosRaw3 as $a){
        if(is_string($a)){
            $arcosMap[$a] = ['tipo'=>$a,'medida'=>'','seccion'=>'','arcada'=>'ambas'];
        } else {
            $arcosMap[$a['tipo']] = $a;
        }
    }

    // Parsear accesorios y evaluación guardados
    $accGuardados = [];
    $evalGuardada = [];
    if(preg_match('/\[ACCESORIOS:(.*?)\]\[EVAL:(.*?)\]$/s',$ort['procedimientos']??'',$m)){
        $accGuardados = json_decode($m[1],true) ?: [];
        $evalGuardada = json_decode($m[2],true) ?: [];
    }
    $accMap = [];
    foreach($accGuardados as $a) $accMap[$a['item']] = $a;
    $procLimpio = preg_replace('/\[ACCESORIOS:.*\]\[EVAL:.*\]/s','',$ort['procedimientos']??'');

    $titulo = ($accion==='nuevo'?'Nuevo ':'Editar ').($ort['tipo']==='instalacion'?'Instalación':'Control').' de Ortodoncia';
    $pagina_activa = 'pac';
    include __DIR__.'/../includes/header.php';
?>
<style>
.ort-sec    { background:var(--bg2);border:1px solid var(--bd2);border-radius:10px;margin-bottom:14px; }
.ort-sec-hdr{ padding:10px 16px;border-bottom:1px solid var(--bd2);font-size:11px;font-weight:700;
               text-transform:uppercase;letter-spacing:.4px;color:var(--t);display:flex;align-items:center;gap:8px; }
.ort-sec-body{ padding:16px; }
.arc-row    { display:grid;grid-template-columns:auto 1fr 1fr 1fr;gap:8px;align-items:center;
               padding:8px 10px;border-radius:8px;background:var(--bg4);margin-bottom:6px; }
.arc-row label{ margin:0;font-size:12px;font-weight:600;color:var(--t);white-space:nowrap; }
.arc-row input[type=text],
.arc-row select{ font-size:12px;padding:4px 8px;height:30px; }
.acc-grid   { display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px; }
.acc-item   { background:var(--bg4);border:1px solid var(--bd2);border-radius:8px;padding:8px 10px; }
.acc-item label{ font-size:12px;font-weight:600;color:var(--t);margin:0; }
.acc-item input[type=text]{ font-size:11px;padding:3px 7px;margin-top:4px;height:26px; }
.eval-grid  { display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px; }
.eval-grid label{ font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--t2); }

/* Odontograma mini para brackets */
.mini-tooth { width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;
               border-radius:4px;background:var(--bg4);border:1px solid var(--bd2);font-size:9px;
               font-weight:700;color:var(--t3);cursor:pointer;transition:all .12s;user-select:none; }
.mini-tooth.on  { background:rgba(0,212,238,.2);border-color:var(--c);color:var(--c); }
.mini-tooth.on::after{ content:'◾'; }
.odo-mini-row{ display:flex;gap:3px;justify-content:center;flex-wrap:wrap; }
/* ── Responsive nuevo/editar ────────────────────────────── */
@media(max-width:992px){
  .arc-row{ grid-template-columns:1fr 1fr;grid-template-rows:auto auto; }
  .arc-row .form-check{ grid-column:1 / -1; }
}
@media(max-width:768px){
  .pb { padding:12px; }
  .ort-sec-body{ padding:12px; }
  /* Arcos: una columna */
  .arc-row{ grid-template-columns:1fr;grid-template-rows:auto; }
  .arc-row .form-check{ grid-column:1; }
  /* Evaluación: 2 columnas en vez de auto-fill */
  .eval-grid{ grid-template-columns:1fr 1fr; }
  /* Accesorios: 1 columna */
  .acc-grid{ grid-template-columns:1fr 1fr; }
  /* Mini dientes más pequeños */
  .mini-tooth{ width:24px;height:24px;font-size:8px; }
}
@media(max-width:480px){
  .eval-grid{ grid-template-columns:1fr; }
  .acc-grid { grid-template-columns:1fr; }
  .mini-tooth{ width:22px;height:22px;font-size:7px; }
}
</style>

<div class="pb">
  <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <a href="?paciente_id=<?=$paciente_id?>" class="btn btn-dk btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver</a>
    <span style="font-size:14px;font-weight:700;color:var(--t)"><?=$titulo?></span>
    <span class="badge bc ms-2"><?=e($pac['nombres'].' '.$pac['apellido_paterno'])?></span>
  </div>

  <?=popFlash()?>

  <form method="POST" id="fOrt">
    <input type="hidden" name="accion"      value="guardar">
    <input type="hidden" name="id"          value="<?=$ort['id']?>">
    <input type="hidden" name="dientes_json" id="djsonBrack" value="<?=e($ort['dientes_json']??'[]')?>">

    <!-- ── Datos generales ─────────────────────────────────── -->
    <div class="ort-sec">
      <div class="ort-sec-hdr"><i class="bi bi-info-circle"></i> Datos generales</div>
      <div class="ort-sec-body">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label">Tipo de registro</label>
            <select name="tipo" class="form-select" required>
              <option value="instalacion" <?=$ort['tipo']==='instalacion'?'selected':''?>>Instalación inicial</option>
              <option value="control"     <?=$ort['tipo']==='control'    ?'selected':''?>>Control de seguimiento</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Historia Clínica</label>
            <select name="hc_id" class="form-select">
              <option value="">— Sin vincular —</option>
              <?php foreach($hcs as $hc): ?>
              <option value="<?=$hc['id']?>" <?=$ort['hc_id']==$hc['id']?'selected':''?>><?=e($hc['numero_hc'])?> (<?=fDate($hc['fecha_apertura'])?>)</option>
              <?php endforeach;?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Fecha atención</label>
            <input type="date" name="fecha_atencion" class="form-control" value="<?=$ort['fecha_atencion']?>" required>
          </div>
          <div class="col-md-2">
            <label class="form-label">Fecha referencia</label>
            <input type="date" name="fecha_referencia" class="form-control" value="<?=$ort['fecha_referencia']?>">
          </div>
          <div class="col-md-2">
            <label class="form-label">Próximo control</label>
            <input type="date" name="proximo_control" class="form-control" value="<?=$ort['proximo_control']?>">
          </div>
        </div>
      </div>
    </div>

    <!-- ── Evaluación clínica ──────────────────────────────── -->
    <div class="ort-sec">
      <div class="ort-sec-hdr"><i class="bi bi-clipboard-pulse"></i> Evaluación clínica</div>
      <div class="ort-sec-body">
        <div class="eval-grid">
          <div>
            <label class="form-label">Clase molar</label>
            <select name="clase_molar" class="form-select form-select-sm">
              <option value="">—</option>
              <?php foreach(['Clase I','Clase II Div.1','Clase II Div.2','Clase III'] as $v): ?>
              <option value="<?=$v?>" <?=($evalGuardada['clase_molar']??'')===$v?'selected':''?>><?=$v?></option>
              <?php endforeach;?>
            </select>
          </div>
          <div>
            <label class="form-label">Clase canina</label>
            <select name="clase_canina" class="form-select form-select-sm">
              <option value="">—</option>
              <?php foreach(['Clase I','Clase II','Clase III'] as $v): ?>
              <option value="<?=$v?>" <?=($evalGuardada['clase_canina']??'')===$v?'selected':''?>><?=$v?></option>
              <?php endforeach;?>
            </select>
          </div>
          <div>
            <label class="form-label">Overjet (mm)</label>
            <input type="text" name="overjet" class="form-control form-control-sm" value="<?=e($evalGuardada['overjet']??'')?>" placeholder="ej. 3mm">
          </div>
          <div>
            <label class="form-label">Overbite (mm)</label>
            <input type="text" name="overbite" class="form-control form-control-sm" value="<?=e($evalGuardada['overbite']??'')?>" placeholder="ej. 2mm">
          </div>
          <div>
            <label class="form-label">Tipo de mordida</label>
            <select name="mordida" class="form-select form-select-sm">
              <option value="">—</option>
              <?php foreach(['Normal','Abierta anterior','Abierta posterior','Cruzada anterior','Cruzada posterior','Profunda','Borde a borde'] as $v): ?>
              <option value="<?=$v?>" <?=($evalGuardada['mordida']??'')===$v?'selected':''?>><?=$v?></option>
              <?php endforeach;?>
            </select>
          </div>
          <div>
            <label class="form-label">Línea media</label>
            <select name="linea_media" class="form-select form-select-sm">
              <option value="">—</option>
              <?php foreach(['Centrada','Desviada derecha','Desviada izquierda'] as $v): ?>
              <option value="<?=$v?>" <?=($evalGuardada['linea_media']??'')===$v?'selected':''?>><?=$v?></option>
              <?php endforeach;?>
            </select>
          </div>
          <div>
            <label class="form-label">Apiñamiento</label>
            <select name="apiñamiento" class="form-select form-select-sm">
              <option value="">—</option>
              <?php foreach(['Sin apiñamiento','Leve','Moderado','Severo'] as $v): ?>
              <option value="<?=$v?>" <?=($evalGuardada['apiñamiento']??'')===$v?'selected':''?>><?=$v?></option>
              <?php endforeach;?>
            </select>
          </div>
          <div>
            <label class="form-label">Espaciamiento</label>
            <select name="espaciamiento" class="form-select form-select-sm">
              <option value="">—</option>
              <?php foreach(['Sin espacios','Diastema central','Múltiples espacios'] as $v): ?>
              <option value="<?=$v?>" <?=($evalGuardada['espaciamiento']??'')===$v?'selected':''?>><?=$v?></option>
              <?php endforeach;?>
            </select>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Arcos utilizados ───────────────────────────────── -->
    <div class="ort-sec">
      <div class="ort-sec-hdr"><i class="bi bi-bezier2"></i> Arcos utilizados</div>
      <div class="ort-sec-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(600px,1fr));gap:6px">
          <?php
          $arcosConfig = [
            'acero'            => ['🔩 Acero inoxidable',   '#6b7280'],
            'niti'             => ['🌀 NiTi (Nickel-Titanio)', '#3b82f6'],
            'termico'          => ['🌡️ Térmico (ThermoNiTi)', '#ef4444'],
            'cobre_niti'       => ['🟤 Cobre-NiTi',          '#b45309'],
            'tma'              => ['⚡ TMA (Beta-Titanio)',   '#8b5cf6'],
            'beta_titanio'     => ['🔷 Beta-Titanio puro',   '#0ea5e9'],
            'acero_trenzado'   => ['🧵 Acero trenzado',      '#9ca3af'],
            'acero_rectangular'=> ['▬ Acero rectangular',   '#374151'],
            'ideal_archwire'   => ['✨ Arco ideal',           '#f59e0b'],
          ];
          foreach($arcosConfig as $key => [$label,$color]):
            $a = $arcosMap[$key] ?? null;
          ?>
          <div class="arc-row">
            <div class="form-check" style="min-width:220px">
              <input class="form-check-input" type="checkbox" name="arco_<?=$key?>" id="arco_<?=$key?>" <?=$a?'checked':''?>>
              <label class="form-check-label" for="arco_<?=$key?>" style="color:<?=$color?>">
                <?=$label?>
              </label>
            </div>
            <div>
              <input type="text" name="medida_<?=$key?>" class="form-control"
                     placeholder="Medida (ej. .014, .016x.022)"
                     value="<?=e($a['medida']??'')?>">
            </div>
            <div>
              <select name="seccion_<?=$key?>" class="form-select">
                <option value="">Sección</option>
                <?php foreach(['.012','.014','.016','.016x.016','.016x.022','.017x.025','.018x.025','.019x.025','.021x.025'] as $s): ?>
                <option value="<?=$s?>" <?=($a['seccion']??'')===$s?'selected':''?>><?=$s?></option>
                <?php endforeach;?>
              </select>
            </div>
            <div>
              <select name="arcada_<?=$key?>" class="form-select">
                <option value="ambas"    <?=($a['arcada']??'')==='ambas'   ?'selected':''?>>Superior + Inferior</option>
                <option value="superior" <?=($a['arcada']??'')==='superior'?'selected':''?>>Solo superior</option>
                <option value="inferior" <?=($a['arcada']??'')==='inferior'?'selected':''?>>Solo inferior</option>
              </select>
            </div>
          </div>
          <?php endforeach;?>
        </div>
      </div>
    </div>

    <!-- ── Accesorios y materiales ─────────────────────────── -->
    <div class="ort-sec">
      <div class="ort-sec-hdr"><i class="bi bi-tools"></i> Accesorios y materiales</div>
      <div class="ort-sec-body">
        <div class="acc-grid">
          <?php
          $accConfig = [
            'resorte_abierto'      => '🔄 Resorte abierto',
            'resorte_cerrado'      => '🔃 Resorte cerrado',
            'cadena_elastica'      => '⛓️ Cadena elástica',
            'ligadura_metalica'    => '🔗 Ligadura metálica',
            'ligadura_elastomerica'=> '⭕ Ligadura elastomérica',
            'tubo_molar'           => '🟥 Tubo molar',
            'bracket_metalico'     => '⬛ Bracket metálico',
            'bracket_ceramico'     => '⬜ Bracket cerámico',
            'bracket_safiro'       => '💎 Bracket de zafiro',
            'banda_ortodoncia'     => '🟦 Banda de ortodoncia',
            'boton_lingual'        => '🔵 Botón lingual',
            'gancho_retraccion'    => '🪝 Gancho de retracción',
            'arco_utilitario'      => '🔧 Arco utilitario',
            'retenedor'            => '🦷 Retenedor',
            'placa_hawley'         => '📋 Placa Hawley',
            'expansor'             => '↔️ Expansor',
            'disyuntor'            => '⚡ Disyuntor palatino',
            'elastico_intermaxilar'=> '🔵 Elástico intermaxilar',
            'stop_resorte'         => '🛑 Stop de resorte',
            'barra_palatina'       => '— Barra palatina',
            'arco_lingual'         => '〰️ Arco lingual',
          ];
          foreach($accConfig as $key => $label):
            $acc = $accMap[$key] ?? null;
          ?>
          <div class="acc-item">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="acc_<?=$key?>" id="acc_<?=$key?>" <?=$acc?'checked':''?>>
              <label class="form-check-label" for="acc_<?=$key?>"><?=$label?></label>
            </div>
            <input type="text" name="acc_nota_<?=$key?>" class="form-control w-100"
                   placeholder="Notas (cantidad, ubicación...)"
                   value="<?=e($acc['notas']??'')?>">
          </div>
          <?php endforeach;?>
        </div>
      </div>
    </div>

    <!-- ── Dientes con brackets (mini odontograma) ─────────── -->
    <div class="ort-sec">
      <div class="ort-sec-hdr"><i class="bi bi-grid-3x3"></i> Dientes con brackets / aparatología</div>
      <div class="ort-sec-body">
        <p style="font-size:11px;color:var(--t2);margin-bottom:10px">Clic para activar/desactivar diente</p>
        <div style="margin-bottom:6px">
          <div style="font-size:10px;color:var(--t3);margin-bottom:4px;text-align:center">SUPERIOR</div>
          <div class="odo-mini-row">
            <?php foreach([18,17,16,15,14,13,12,11,21,22,23,24,25,26,27,28] as $n): ?>
            <div class="mini-tooth" data-n="<?=$n?>"><?=$n?></div>
            <?php endforeach;?>
          </div>
        </div>
        <div>
          <div class="odo-mini-row">
            <?php foreach([48,47,46,45,44,43,42,41,31,32,33,34,35,36,37,38] as $n): ?>
            <div class="mini-tooth" data-n="<?=$n?>"><?=$n?></div>
            <?php endforeach;?>
          </div>
          <div style="font-size:10px;color:var(--t3);margin-top:4px;text-align:center">INFERIOR</div>
        </div>
      </div>
    </div>

    <!-- ── Observaciones y procedimientos ─────────────────── -->
    <div class="ort-sec">
      <div class="ort-sec-hdr"><i class="bi bi-chat-left-text"></i> Observaciones y procedimientos</div>
      <div class="ort-sec-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Observaciones / Motivo</label>
            <textarea name="observaciones" class="form-control" rows="4" placeholder="Descripción general del estado del tratamiento..."><?=e($ort['observaciones']??'')?></textarea>
          </div>
          <div class="col-md-6">
            <label class="form-label">Procedimientos realizados</label>
            <textarea name="procedimientos" class="form-control" rows="4" placeholder="Detalle de los procedimientos realizados en esta sesión..."><?=e(trim($procLimpio))?></textarea>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Botones ─────────────────────────────────────────── -->
    <div class="d-flex gap-2 flex-wrap mt-1">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-floppy me-2"></i><?php echo $accion==='nuevo'?'Registrar':'Actualizar'; ?>
      </button>
      <a href="?paciente_id=<?=$paciente_id?>" class="btn btn-dk">Cancelar</a>
    </div>
  </form>
</div>

<script>
// Mini odontograma de brackets
let brackDientes = new Set(JSON.parse(document.getElementById('djsonBrack').value || '[]'));

document.querySelectorAll('.mini-tooth').forEach(el => {
  const n = el.dataset.n;
  if(brackDientes.has(+n)||brackDientes.has(n)) el.classList.add('on');
  el.addEventListener('click', function(){
    const num = +this.dataset.n;
    if(brackDientes.has(num)){ brackDientes.delete(num); this.classList.remove('on'); }
    else                     { brackDientes.add(num);    this.classList.add('on');    }
    document.getElementById('djsonBrack').value = JSON.stringify([...brackDientes]);
  });
});
</script>

<?php
    require_once __DIR__.'/../includes/footer.php';

// ════════════════════════════════════════════════════════════════════
// VISTA: VER
// ════════════════════════════════════════════════════════════════════
} elseif ($accion==='ver' && $id) {
    $ort = db()->prepare("SELECT o.*,CONCAT(u.nombre,' ',u.apellidos) AS doctor,hc.numero_hc
                          FROM ortodoncias o LEFT JOIN usuarios u ON o.doctor_id=u.id
                          LEFT JOIN historias_clinicas hc ON o.hc_id=hc.id
                          WHERE o.id=? AND o.paciente_id=?");
    $ort->execute([$id,$paciente_id]);
    $ort = $ort->fetch();
    if(!$ort){ flash('error','No encontrado'); go("pages/ortodoncias.php?paciente_id=$paciente_id"); }

    // Parsear arcos: soporta formato viejo (array simple) y nuevo (array de objetos)
    $arcosRaw = json_decode($ort['tipo_arco']??'[]',true) ?: [];
    $arcos = [];
    foreach($arcosRaw as $a){
        if(is_string($a)){
            // Formato viejo: ["acero","termico"]
            $arcos[] = ['tipo'=>$a,'medida'=>'','seccion'=>'','arcada'=>'ambas'];
        } else {
            // Formato nuevo: [{"tipo":"acero","medida":"..."}]
            $arcos[] = $a;
        }
    }
    $dientes = json_decode($ort['dientes_json']??'[]',true) ?: [];
    $accGuardados = [];
    $evalGuardada = [];
    $procLimpio = $ort['procedimientos']??'';
    if(preg_match('/\[ACCESORIOS:(.*?)\]\[EVAL:(.*?)\]$/s',$procLimpio,$m)){
        $accGuardados = json_decode($m[1],true) ?: [];
        $evalGuardada = json_decode($m[2],true) ?: [];
        $procLimpio   = preg_replace('/\[ACCESORIOS:.*\]\[EVAL:.*\]/s','',$procLimpio);
    }

    $titulo = ($ort['tipo']==='instalacion'?'Instalación':'Control').' de Ortodoncia';
    $pagina_activa = 'pac';
    $topbar_act = '<a href="?paciente_id='.$paciente_id.'" class="btn btn-dk btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver</a>
    <a href="?accion=editar&id='.$id.'&paciente_id='.$paciente_id.'" class="btn btn-dk btn-sm"><i class="bi bi-pencil me-1"></i>Editar</a>';
    include __DIR__.'/../includes/header.php';

    $arcosConfig=[
        'acero'=>'Acero inoxidable','niti'=>'NiTi','termico'=>'Térmico (ThermoNiTi)',
        'cobre_niti'=>'Cobre-NiTi','tma'=>'TMA','beta_titanio'=>'Beta-Titanio',
        'acero_trenzado'=>'Acero trenzado','acero_rectangular'=>'Acero rectangular',
        'ideal_archwire'=>'Arco ideal'
    ];
    $accConfig=[
        'resorte_abierto'=>'Resorte abierto','resorte_cerrado'=>'Resorte cerrado',
        'cadena_elastica'=>'Cadena elástica','ligadura_metalica'=>'Ligadura metálica',
        'ligadura_elastomerica'=>'Ligadura elastomérica','tubo_molar'=>'Tubo molar',
        'bracket_metalico'=>'Bracket metálico','bracket_ceramico'=>'Bracket cerámico',
        'bracket_safiro'=>'Bracket de zafiro','banda_ortodoncia'=>'Banda de ortodoncia',
        'boton_lingual'=>'Botón lingual','gancho_retraccion'=>'Gancho de retracción',
        'arco_utilitario'=>'Arco utilitario','retenedor'=>'Retenedor',
        'placa_hawley'=>'Placa Hawley','expansor'=>'Expansor',
        'disyuntor'=>'Disyuntor palatino','elastico_intermaxilar'=>'Elástico intermaxilar',
        'stop_resorte'=>'Stop de resorte','barra_palatina'=>'Barra palatina',
        'arco_lingual'=>'Arco lingual'
    ];
?>
<style>
.ver-sec   { background:var(--bg2);border:1px solid var(--bd2);border-radius:10px;margin-bottom:12px; }
.ver-hdr   { padding:10px 16px;border-bottom:1px solid var(--bd2);font-size:11px;font-weight:700;
              text-transform:uppercase;letter-spacing:.4px;color:var(--t);display:flex;align-items:center;gap:8px; }
.ver-body  { padding:14px 16px; }
.ver-field { margin-bottom:10px; }
.ver-field small{ font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--t3);display:block; }
.ver-field span { font-size:13px;color:var(--t); }
.arc-tag   { display:inline-flex;flex-direction:column;background:var(--bg4);border:1px solid var(--bd2);
              border-radius:8px;padding:8px 12px;font-size:12px;color:var(--t);min-width:140px;margin:4px; }
.arc-tag strong{ color:var(--c);font-size:11px; }
.arc-sub   { font-size:10px;color:var(--t2);margin-top:2px; }
.acc-tag   { display:inline-flex;flex-direction:column;background:var(--bg4);border:1px solid var(--bd2);
              border-radius:6px;padding:5px 10px;font-size:11px;color:var(--t);margin:3px; }
.acc-tag small{ font-size:10px;color:var(--t3); }
.eval-item { background:var(--bg4);border:1px solid var(--bd2);border-radius:8px;
              padding:8px 12px;font-size:12px; }
.eval-item small{ font-size:10px;color:var(--t3);display:block; }
.eval-item span { color:var(--t); }
.mini-view { display:inline-flex;align-items:center;justify-content:center;
              width:26px;height:26px;border-radius:4px;font-size:9px;font-weight:700; }
/* ── Responsive ver ─────────────────────────────────────── */
@media(max-width:768px){
  .pb { padding:12px; }
  .ver-body{ padding:10px 12px; }
  .arc-tag { min-width:120px; }
  .col-xl-9,.col-xl-3{ width:100%!important; }
}
@media(max-width:480px){
  .arc-tag{ min-width:100px;font-size:11px; }
  .eval-item{ padding:6px 8px; }
  .mini-view{ width:22px;height:22px;font-size:8px; }
}
</style>

<div class="pb">
  <div class="row g-3">
    <div class="col-12 col-xl-9">

      <!-- Info general -->
      <div class="ver-sec">
        <div class="ver-hdr"><i class="bi bi-info-circle"></i> Información del registro</div>
        <div class="ver-body">
          <div class="row g-3">
            <div class="col-md-3"><div class="ver-field"><small>Tipo</small>
              <span><?=$ort['tipo']==='instalacion'?'🦷 Instalación inicial':'🔧 Control de seguimiento'?></span></div></div>
            <div class="col-md-3"><div class="ver-field"><small>Fecha de atención</small>
              <span><?=fDate($ort['fecha_atencion'])?></span></div></div>
            <div class="col-md-3"><div class="ver-field"><small>Doctor</small>
              <span><?=e($ort['doctor']??'—')?></span></div></div>
            <div class="col-md-3"><div class="ver-field"><small>Historia Clínica</small>
              <span><?=$ort['numero_hc']?e($ort['numero_hc']):'—'?></span></div></div>
            <?php if($ort['proximo_control']): ?>
            <div class="col-md-3"><div class="ver-field"><small>Próximo control</small>
              <span style="color:<?=$ort['proximo_control']<date('Y-m-d')?'var(--r)':'var(--g)'?>"><?=fDate($ort['proximo_control'])?></span></div></div>
            <?php endif;?>
          </div>
        </div>
      </div>

      <!-- Evaluación -->
      <?php if(array_filter($evalGuardada)): ?>
      <div class="ver-sec">
        <div class="ver-hdr"><i class="bi bi-clipboard-pulse"></i> Evaluación clínica</div>
        <div class="ver-body">
          <div class="d-flex flex-wrap gap-2">
            <?php foreach($evalGuardada as $k=>$v): if(!$v) continue;
            $labels=['clase_molar'=>'Clase molar','clase_canina'=>'Clase canina','overjet'=>'Overjet',
                     'overbite'=>'Overbite','mordida'=>'Mordida','linea_media'=>'Línea media',
                     'apiñamiento'=>'Apiñamiento','espaciamiento'=>'Espaciamiento']; ?>
            <div class="eval-item"><small><?=$labels[$k]??$k?></small><span><?=e($v)?></span></div>
            <?php endforeach;?>
          </div>
        </div>
      </div>
      <?php endif;?>

      <!-- Arcos -->
      <?php if($arcos): ?>
      <div class="ver-sec">
        <div class="ver-hdr"><i class="bi bi-bezier2"></i> Arcos utilizados</div>
        <div class="ver-body">
          <div class="d-flex flex-wrap">
            <?php foreach($arcos as $a): ?>
            <div class="arc-tag">
              <strong><?=e($arcosConfig[$a['tipo']]??$a['tipo'])?></strong>
              <?php if($a['medida']||$a['seccion']): ?>
              <span class="arc-sub"><?=$a['medida']?' '.$a['medida']:''?><?=$a['seccion']?' · '.$a['seccion']:''?></span>
              <?php endif;?>
              <span class="arc-sub" style="color:var(--t2)"><?=ucfirst($a['arcada']??'ambas')?></span>
            </div>
            <?php endforeach;?>
          </div>
        </div>
      </div>
      <?php endif;?>

      <!-- Accesorios -->
      <?php if($accGuardados): ?>
      <div class="ver-sec">
        <div class="ver-hdr"><i class="bi bi-tools"></i> Accesorios utilizados</div>
        <div class="ver-body">
          <div class="d-flex flex-wrap">
            <?php foreach($accGuardados as $acc): ?>
            <div class="acc-tag">
              <span><?=e($accConfig[$acc['item']]??$acc['item'])?></span>
              <?php if($acc['notas']): ?><small><?=e($acc['notas'])?></small><?php endif;?>
            </div>
            <?php endforeach;?>
          </div>
        </div>
      </div>
      <?php endif;?>

      <!-- Dientes con brackets -->
      <?php if($dientes): ?>
      <div class="ver-sec">
        <div class="ver-hdr"><i class="bi bi-grid-3x3"></i> Dientes con aparatología</div>
        <div class="ver-body">
          <?php
          $supD=[18,17,16,15,14,13,12,11,21,22,23,24,25,26,27,28];
          $infD=[48,47,46,45,44,43,42,41,31,32,33,34,35,36,37,38];
          $dSet = array_map('intval',$dientes);
          ?>
          <div style="font-size:10px;color:var(--t3);margin-bottom:4px;text-align:center">SUPERIOR</div>
          <div class="d-flex justify-content-center gap-1 mb-1 flex-wrap">
            <?php foreach($supD as $n): $on=in_array($n,$dSet); ?>
            <div class="mini-view" style="background:<?=$on?'rgba(0,212,238,.2)':'var(--bg4)'?>;
                 border:1px solid <?=$on?'var(--c)':'var(--bd2)'?>;
                 color:<?=$on?'var(--c)':'var(--t3)'?>"><?=$n?></div>
            <?php endforeach;?>
          </div>
          <div class="d-flex justify-content-center gap-1 mb-1 flex-wrap">
            <?php foreach($infD as $n): $on=in_array($n,$dSet); ?>
            <div class="mini-view" style="background:<?=$on?'rgba(0,212,238,.2)':'var(--bg4)'?>;
                 border:1px solid <?=$on?'var(--c)':'var(--bd2)'?>;
                 color:<?=$on?'var(--c)':'var(--t3)'?>"><?=$n?></div>
            <?php endforeach;?>
          </div>
          <div style="font-size:10px;color:var(--t3);margin-top:4px;text-align:center">INFERIOR</div>
        </div>
      </div>
      <?php endif;?>

      <!-- Observaciones / Procedimientos -->
      <?php if($ort['observaciones']||trim($procLimpio)): ?>
      <div class="ver-sec">
        <div class="ver-hdr"><i class="bi bi-chat-left-text"></i> Notas clínicas</div>
        <div class="ver-body">
          <?php if($ort['observaciones']): ?>
          <div class="ver-field"><small>Observaciones</small>
            <div style="font-size:13px;color:var(--t);line-height:1.6"><?=nl2br(e($ort['observaciones']))?></div>
          </div>
          <?php endif;?>
          <?php if(trim($procLimpio)): ?>
          <div class="ver-field"><small>Procedimientos realizados</small>
            <div style="font-size:13px;color:var(--t);line-height:1.6"><?=nl2br(e(trim($procLimpio)))?></div>
          </div>
          <?php endif;?>
        </div>
      </div>
      <?php endif;?>

    </div>

    <!-- Panel lateral -->
    <div class="col-12 col-xl-3">
      <div class="ver-sec">
        <div class="ver-hdr"><i class="bi bi-person"></i> Paciente</div>
        <div class="ver-body" style="font-size:12px">
          <strong style="font-size:13px;color:var(--t)"><?=e($pac['nombres'].' '.$pac['apellido_paterno'])?></strong>
          <div style="color:var(--t2);margin-top:4px">DNI: <?=e($pac['dni']??'—')?></div>
          <div style="color:var(--t2)">Edad: <?=$pac['fecha_nacimiento']?edad($pac['fecha_nacimiento']):'—'?></div>
          <div style="color:var(--t2)">Cód: <?=e($pac['codigo'])?></div>
          <?php if($pac['alergias']): ?>
          <div class="badge br mt-2">⚠️ <?=e($pac['alergias'])?></div>
          <?php endif;?>
          <div class="d-flex flex-column gap-1 mt-3">
            <a href="?accion=editar&id=<?=$id?>&paciente_id=<?=$paciente_id?>" class="btn btn-dk btn-sm w-100">
              <i class="bi bi-pencil me-1"></i>Editar registro
            </a>
            <a href="?accion=nuevo&paciente_id=<?=$paciente_id?>&tipo=control" class="btn btn-primary btn-sm w-100">
              <i class="bi bi-plus me-1"></i>Nuevo control
            </a>
            <a href="?paciente_id=<?=$paciente_id?>" class="btn btn-dk btn-sm w-100">
              <i class="bi bi-arrow-left me-1"></i>Historial
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
    require_once __DIR__.'/../includes/footer.php';
}
?>
