<?php
/**
 * DESTARTRAJE — Módulo completo
 * Tablas: destartajes
 * Acciones: lista | nuevo | editar | ver | eliminar
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

// ── POST: guardar ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $ap = $_POST['accion'] ?? '';

    if ($ap === 'guardar') {
        $ei              = (int)($_POST['id'] ?? 0);
        $hc_id           = (int)($_POST['hc_id'] ?? 0);
        $grado           = trim($_POST['grado'] ?? '');
        $fecha_1         = $_POST['fecha_1'] ?: null;
        $fecha_2         = $_POST['fecha_2'] ?: null;
        $fecha_3         = $_POST['fecha_3'] ?: null;
        $fecha_control   = $_POST['fecha_control'] ?: null;
        $medicacion      = trim($_POST['medicacion'] ?? '');
        $observaciones   = trim($_POST['observaciones'] ?? '');

        if ($ei) {
            db()->prepare("UPDATE destartajes SET hc_id=?,grado_complejidad=?,fecha_1ra_cita=?,fecha_2da_cita=?,fecha_3ra_cita=?,fecha_control=?,medicacion=?,observaciones=?,updated_at=NOW() WHERE id=?")
               ->execute([$hc_id?:null,$grado,$fecha_1,$fecha_2,$fecha_3,$fecha_control,$medicacion,$observaciones,$ei]);
            auditar('EDITAR_DESTARTRAJE','destartajes',$ei);
            flash('ok','Destartraje actualizado.');
        } else {
            db()->prepare("INSERT INTO destartajes(paciente_id,hc_id,grado_complejidad,fecha_1ra_cita,fecha_2da_cita,fecha_3ra_cita,fecha_control,medicacion,observaciones,doctor_id) VALUES(?,?,?,?,?,?,?,?,?,?)")
               ->execute([$paciente_id,$hc_id?:null,$grado,$fecha_1,$fecha_2,$fecha_3,$fecha_control,$medicacion,$observaciones,$_SESSION['uid']]);
            $nid = db()->lastInsertId();
            auditar('CREAR_DESTARTRAJE','destartajes',$nid);
            flash('ok','Destartraje registrado correctamente.');
        }
        go("pages/destartraje.php?paciente_id=$paciente_id");
    }

    if ($ap === 'eliminar') {
        $did = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM destartajes WHERE id=?")->execute([$did]);
        auditar('ELIMINAR_DESTARTRAJE','destartajes',$did);
        flash('ok','Registro eliminado.');
        go("pages/destartraje.php?paciente_id=$paciente_id");
    }
}

// ════════════════════════════════════════════════════════════════════
// VISTA: LISTA
// ════════════════════════════════════════════════════════════════════
if ($accion === 'lista') {
    $rows = db()->prepare("SELECT d.*, CONCAT(u.nombre,' ',u.apellidos) AS doctor, hc.numero_hc
                           FROM destartajes d
                           LEFT JOIN usuarios u ON d.doctor_id=u.id
                           LEFT JOIN historias_clinicas hc ON d.hc_id=hc.id
                           WHERE d.paciente_id=? ORDER BY d.created_at DESC");
    $rows->execute([$paciente_id]);
    $rows = $rows->fetchAll();

    $titulo = 'Destartraje — '.$pac['nombres'].' '.$pac['apellido_paterno'];
    $pagina_activa = 'pac';
    $topbar_act = '<a href="?accion=nuevo&paciente_id='.$paciente_id.'" class="btn btn-primary"><i class="bi bi-plus me-1"></i>Nuevo registro</a>
    <a href="'.BASE_URL.'/pages/pacientes.php?accion=ver&id='.$paciente_id.'" class="btn btn-dk btn-sm"><i class="bi bi-person me-1"></i>Paciente</a>';
    include __DIR__.'/../includes/header.php';
?>
<style>
.dst-timeline { position:relative;padding-left:32px; }
.dst-timeline::before { content:'';position:absolute;left:14px;top:0;bottom:0;width:2px;background:var(--bd2); }
.dst-node     { position:relative;margin-bottom:16px; }
.dst-dot      { position:absolute;left:-26px;top:12px;width:14px;height:14px;border-radius:50%;border:2px solid var(--bg2);flex-shrink:0; }
.dst-card     { background:var(--bg2);border:1px solid var(--bd2);border-radius:10px;padding:14px 16px;transition:border-color .15s; }
.dst-card:hover { border-color:rgba(16,185,129,.3); }
.pac-info     { background:var(--bg2);border:1px solid var(--bd2);border-radius:10px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap; }
.grado-badge  { display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:12px;font-size:10px;font-weight:700;text-transform:uppercase; }
.grado-simple   { background:#10b98122;color:#10b981;border:1px solid #10b98144; }
.grado-moderada { background:#f59e0b22;color:#f59e0b;border:1px solid #f59e0b44; }
.grado-compleja { background:#ef444422;color:#ef4444;border:1px solid #ef444444; }
</style>

<div class="pb">
  <?=popFlash()?>

  <!-- Info paciente -->
  <div class="pac-info">
    <div style="width:40px;height:40px;border-radius:50%;background:var(--c)22;border:2px solid var(--c)44;display:flex;align-items:center;justify-content:center;font-size:18px">🦷</div>
    <div>
      <div style="font-weight:700;font-size:15px"><?=e($pac['nombres'].' '.$pac['apellido_paterno'])?></div>
      <div style="font-size:12px;color:var(--t2)"><?=$pac['fecha_nacimiento']?edad($pac['fecha_nacimiento']):'—'?> &nbsp;·&nbsp; <?=e($pac['telefono']??'—')?></div>
    </div>
    <div class="ms-auto d-flex gap-2 flex-wrap">
      <a href="?accion=nuevo&paciente_id=<?=$paciente_id?>" class="btn btn-primary btn-sm"><i class="bi bi-plus me-1"></i>Nuevo destartraje</a>
    </div>
  </div>

  <?php if (!$rows): ?>
  <div class="card p-5 text-center" style="color:var(--t2)">
    <i class="bi bi-droplet" style="font-size:36px;display:block;margin-bottom:10px"></i>
    No hay registros de destartraje para este paciente.
  </div>
  <?php else: ?>
  <div class="dst-timeline">
    <?php foreach($rows as $r):
      $gc = $r['grado_complejidad'] ?? 'simple';
      $gc_lower = strtolower($gc);
      $dotColor = $gc_lower==='compleja'?'#ef4444':($gc_lower==='moderada'?'#f59e0b':'#10b981');
    ?>
    <div class="dst-node">
      <div class="dst-dot" style="background:<?=$dotColor?>"></div>
      <div class="dst-card">
        <div class="d-flex align-items-start gap-3 flex-wrap">
          <div class="flex-fill">
            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
              <span class="grado-badge grado-<?=$gc_lower?>"><?=e($r['grado_complejidad']??'—')?></span>
              <?php if($r['numero_hc']??''): ?><span class="badge bc" style="font-size:10px"><?=e($r['numero_hc'])?></span><?php endif; ?>
              <span style="font-size:11px;color:var(--t2)">Dr. <?=e($r['doctor']??'—')?></span>
            </div>
            <div class="row g-2" style="font-size:12px">
              <?php if($r['fecha_1ra_cita']): ?><div class="col-auto"><span style="color:var(--t2)">1ra Cita:</span> <strong><?=fDate($r['fecha_1ra_cita'])?></strong></div><?php endif; ?>
              <?php if($r['fecha_2da_cita']): ?><div class="col-auto"><span style="color:var(--t2)">2da Cita:</span> <strong><?=fDate($r['fecha_2da_cita'])?></strong></div><?php endif; ?>
              <?php if($r['fecha_3ra_cita']): ?><div class="col-auto"><span style="color:var(--t2)">3ra Cita:</span> <strong><?=fDate($r['fecha_3ra_cita'])?></strong></div><?php endif; ?>
              <?php if($r['fecha_control']): ?><div class="col-auto"><span style="color:var(--t2)">Control:</span> <strong><?=fDate($r['fecha_control'])?></strong></div><?php endif; ?>
            </div>
            <?php if($r['medicacion']): ?>
            <div class="mt-2" style="font-size:12px"><span style="color:var(--t2)">💊 Medicación:</span> <?=e($r['medicacion'])?></div>
            <?php endif; ?>
            <?php if($r['observaciones']): ?>
            <div class="mt-1" style="font-size:12px;color:var(--t2)"><?=e(mb_substr($r['observaciones'],0,120)).(mb_strlen($r['observaciones'])>120?'...':'')?></div>
            <?php endif; ?>
          </div>
          <div class="d-flex gap-1 flex-shrink-0">
            <a href="?accion=ver&id=<?=$r['id']?>&paciente_id=<?=$paciente_id?>" class="btn btn-dk btn-ico btn-sm" title="Ver"><i class="bi bi-eye"></i></a>
            <a href="?accion=editar&id=<?=$r['id']?>&paciente_id=<?=$paciente_id?>" class="btn btn-dk btn-ico btn-sm" title="Editar"><i class="bi bi-pencil"></i></a>
            <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este registro de destartraje?')">
              <input type="hidden" name="accion" value="eliminar">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <button type="submit" class="btn btn-del btn-ico btn-sm" title="Eliminar"><i class="bi bi-trash"></i></button>
            </form>
          </div>
        </div>
        <div style="font-size:10px;color:var(--t3);margin-top:8px"><?=fDate($r['created_at'],'d/m/Y H:i')?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php
    include __DIR__.'/../includes/footer.php';

// ════════════════════════════════════════════════════════════════════
// VISTA: VER
// ════════════════════════════════════════════════════════════════════
} elseif ($accion === 'ver' && $id) {
    $r = db()->prepare("SELECT d.*, CONCAT(u.nombre,' ',u.apellidos) AS doctor, hc.numero_hc
                        FROM destartajes d
                        LEFT JOIN usuarios u ON d.doctor_id=u.id
                        LEFT JOIN historias_clinicas hc ON d.hc_id=hc.id
                        WHERE d.id=?");
    $r->execute([$id]); $r = $r->fetch();
    if (!$r){ flash('error','Registro no encontrado'); go("pages/destartraje.php?paciente_id=$paciente_id"); }

    $gc_lower = strtolower($r['grado_complejidad']??'simple');
    $titulo = 'Destartraje — '.$pac['nombres'].' '.$pac['apellido_paterno'];
    $pagina_activa = 'pac';
    $topbar_act = '<a href="?paciente_id='.$paciente_id.'" class="btn btn-dk btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver</a>
    <a href="?accion=editar&id='.$id.'&paciente_id='.$paciente_id.'" class="btn btn-primary btn-sm"><i class="bi bi-pencil me-1"></i>Editar</a>';
    include __DIR__.'/../includes/header.php';
?>
<div class="row justify-content-center"><div class="col-12 col-lg-8">
  <div class="card mb-4">
    <div class="card-header"><span style="color:var(--t)">🦷 Destartraje</span>
      <span class="badge <?=$gc_lower==='compleja'?'br':($gc_lower==='moderada'?'bgr':'bg')?>"><?=e($r['grado_complejidad']??'—')?></span>
    </div>
    <div class="p-4" style="font-size:13px">
      <div class="row g-3">
        <div class="col-12 col-md-6"><div style="color:var(--t2);font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:4px">Doctor</div><?=e($r['doctor']??'—')?></div>
        <?php if($r['numero_hc']): ?><div class="col-12 col-md-6"><div style="color:var(--t2);font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:4px">Historia Clínica</div><?=e($r['numero_hc'])?></div><?php endif; ?>
        <div class="col-12"><hr style="border-color:var(--bd2);margin:4px 0"></div>
        <?php $citas=[['1ra Cita',$r['fecha_1ra_cita']],['2da Cita',$r['fecha_2da_cita']],['3ra Cita',$r['fecha_3ra_cita']],['Cita de Control',$r['fecha_control']]];
        foreach($citas as[$l,$f]): if($f): ?>
        <div class="col-12 col-md-3"><div style="color:var(--t2);font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:4px"><?=$l?></div><strong><?=fDate($f)?></strong></div>
        <?php endif; endforeach; ?>
        <?php if($r['medicacion']): ?>
        <div class="col-12"><div style="color:var(--t2);font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:4px">Medicación</div>
        <div style="background:var(--bg3);padding:9px 12px;border-radius:6px"><?=nl2br(e($r['medicacion']))?></div></div>
        <?php endif; ?>
        <?php if($r['observaciones']): ?>
        <div class="col-12"><div style="color:var(--t2);font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:4px">Observaciones</div>
        <div style="background:var(--bg3);padding:9px 12px;border-radius:6px"><?=nl2br(e($r['observaciones']))?></div></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div></div>
<?php
    include __DIR__.'/../includes/footer.php';

// ════════════════════════════════════════════════════════════════════
// VISTA: NUEVO / EDITAR
// ════════════════════════════════════════════════════════════════════
} elseif (in_array($accion, ['nuevo','editar'])) {
    $d = ['id'=>0,'hc_id'=>0,'grado_complejidad'=>'','fecha_1ra_cita'=>'','fecha_2da_cita'=>'','fecha_3ra_cita'=>'','fecha_control'=>'','medicacion'=>'','observaciones'=>''];
    if ($accion==='editar' && $id) {
        $s = db()->prepare("SELECT * FROM destartajes WHERE id=?");
        $s->execute([$id]); $d = $s->fetch() ?: $d;
    }
    $hcs = db()->prepare("SELECT id, numero_hc FROM historias_clinicas WHERE paciente_id=? ORDER BY fecha_apertura DESC");
    $hcs->execute([$paciente_id]); $hcs = $hcs->fetchAll();

    $titulo = ($accion==='nuevo'?'Nuevo':'Editar').' Destartraje — '.$pac['nombres'].' '.$pac['apellido_paterno'];
    $pagina_activa = 'pac';
    $topbar_act = '<a href="?paciente_id='.$paciente_id.'" class="btn btn-dk btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver</a>';
    include __DIR__.'/../includes/header.php';
?>
<div class="row justify-content-center"><div class="col-12 col-lg-7">
<form method="POST">
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?=$d['id']?>">

  <div class="card mb-4">
    <div class="card-header"><span style="color:var(--t)">🦷 Destartraje</span></div>
    <div class="p-4"><div class="row g-3">

      <!-- HC -->
      <div class="col-12 col-md-6">
        <label class="form-label">Historia Clínica</label>
        <select name="hc_id" class="form-select">
          <option value="">— Sin HC vinculada —</option>
          <?php foreach($hcs as $h): ?>
          <option value="<?=$h['id']?>" <?=$d['hc_id']==$h['id']?'selected':''?>><?=e($h['numero_hc'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Grado de complejidad -->
      <div class="col-12 col-md-6">
        <label class="form-label">Grado de complejidad *</label>
        <select name="grado" class="form-select" required>
          <option value="">— Seleccionar —</option>
          <?php foreach(['Simple','Moderada','Compleja'] as $g): ?>
          <option value="<?=$g?>" <?=(($d['grado_complejidad']??'')===$g)?'selected':''?>><?=$g?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12"><hr style="border-color:var(--bd2);margin:4px 0"><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t2);margin-bottom:4px">Fechas de citas</div></div>

      <!-- Fechas -->
      <div class="col-12 col-md-3">
        <label class="form-label">1ra Cita</label>
        <input type="date" name="fecha_1" class="form-control" value="<?=e($d['fecha_1ra_cita']??'')?>">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">2da Cita</label>
        <input type="date" name="fecha_2" class="form-control" value="<?=e($d['fecha_2da_cita']??'')?>">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">3ra Cita</label>
        <input type="date" name="fecha_3" class="form-control" value="<?=e($d['fecha_3ra_cita']??'')?>">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Cita de Control</label>
        <input type="date" name="fecha_control" class="form-control" value="<?=e($d['fecha_control']??'')?>">
      </div>

      <!-- Medicación -->
      <div class="col-12">
        <label class="form-label">Medicación</label>
        <textarea name="medicacion" class="form-control" rows="2" placeholder="Medicamentos recetados..."><?=e($d['medicacion']??'')?></textarea>
      </div>

      <!-- Observaciones -->
      <div class="col-12">
        <label class="form-label">Observaciones</label>
        <textarea name="observaciones" class="form-control" rows="3" placeholder="Notas adicionales..."><?=e($d['observaciones']??'')?></textarea>
      </div>

    </div></div>
  </div>

  <div class="d-flex gap-2 justify-content-end">
    <a href="?paciente_id=<?=$paciente_id?>" class="btn btn-dk">Cancelar</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-floppy me-2"></i>Guardar</button>
  </div>
</form>
</div></div>
<?php
    include __DIR__.'/../includes/footer.php';
}
