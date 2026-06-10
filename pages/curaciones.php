<?php
/**
 * CURACIONES — Módulo completo
 * Tablas: curaciones
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

// ── POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $ap = $_POST['accion'] ?? '';

    if ($ap === 'guardar') {
        $ei            = (int)($_POST['id'] ?? 0);
        $hc_id         = (int)($_POST['hc_id'] ?? 0);
        $pieza         = trim($_POST['pieza'] ?? '');
        $obturacion    = isset($_POST['obturacion']) ? 1 : 0;
        $recub_pulpar  = isset($_POST['recub_pulpar']) ? 1 : 0;
        $base           = isset($_POST['base']) ? 1 : 0;
        $otro          = isset($_POST['otro']) ? 1 : 0;
        $resina        = trim($_POST['resina'] ?? '');
        $eugenato      = isset($_POST['eugenato']) ? 1 : 0;
        $material_otro = trim($_POST['material_otro'] ?? '');
        $plan_tratamiento = trim($_POST['plan_tratamiento'] ?? '');
        $observaciones = trim($_POST['observaciones'] ?? '');
        $fecha_control = $_POST['fecha_control'] ?: null;

        $d = [
            'paciente_id'  => $paciente_id,
            'hc_id'        => $hc_id ?: null,
            'pieza'        => $pieza,
            'obturacion'   => $obturacion,
            'recub_pulpar' => $recub_pulpar,
            'base'         => $base,
            'otro'         => $otro,
            'resina'       => $resina,
            'eugenato'     => $eugenato,
            'material_otro'=> $material_otro,
            'plan_tratamiento' => $plan_tratamiento,
            'observaciones'=> $observaciones,
            'fecha_control'=> $fecha_control,
            'doctor_id'    => $_SESSION['uid'],
        ];

        if ($ei) {
            unset($d['paciente_id'],$d['doctor_id']);
            $sets = implode(',', array_map(fn($k)=>"$k=?", array_keys($d)));
            $vals = [...array_values($d), $ei];
            db()->prepare("UPDATE curaciones SET $sets,updated_at=NOW() WHERE id=?")->execute($vals);
            auditar('EDITAR_CURACION','curaciones',$ei);
            flash('ok','Curación actualizada.');
        } else {
            $cols = implode(',', array_keys($d));
            $phs  = implode(',', array_fill(0, count($d), '?'));
            db()->prepare("INSERT INTO curaciones($cols) VALUES($phs)")->execute(array_values($d));
            $nid = db()->lastInsertId();
            auditar('CREAR_CURACION','curaciones',$nid);
            flash('ok','Curación registrada correctamente.');
        }
        go("pages/curaciones.php?paciente_id=$paciente_id");
    }

    if ($ap === 'eliminar') {
        $did = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM curaciones WHERE id=?")->execute([$did]);
        auditar('ELIMINAR_CURACION','curaciones',$did);
        flash('ok','Registro eliminado.');
        go("pages/curaciones.php?paciente_id=$paciente_id");
    }

    if ($ap === 'agregar_control') {
        $cid    = (int)($_POST['cur_id'] ?? 0);
        $fctrl  = $_POST['fecha_control'] ?: null;
        db()->prepare("UPDATE curaciones SET fecha_control=?,updated_at=NOW() WHERE id=?")->execute([$fctrl,$cid]);
        flash('ok','Control actualizado.');
        go("pages/curaciones.php?paciente_id=$paciente_id");
    }
}

// ════════════════════════════════════════════════════════════════════
// LISTA
// ════════════════════════════════════════════════════════════════════
if ($accion === 'lista') {
    $rows = db()->prepare("SELECT c.*, CONCAT(u.nombre,' ',u.apellidos) AS doctor, hc.numero_hc
                           FROM curaciones c
                           LEFT JOIN usuarios u ON c.doctor_id=u.id
                           LEFT JOIN historias_clinicas hc ON c.hc_id=hc.id
                           WHERE c.paciente_id=? ORDER BY c.created_at DESC");
    $rows->execute([$paciente_id]);
    $rows = $rows->fetchAll();

    $titulo = 'Curaciones — '.$pac['nombres'].' '.$pac['apellido_paterno'];
    $pagina_activa = 'pac';
    $topbar_act = '<a href="?accion=nuevo&paciente_id='.$paciente_id.'" class="btn btn-primary"><i class="bi bi-plus me-1"></i>Nueva curación</a>
    <a href="'.BASE_URL.'/pages/pacientes.php?accion=ver&id='.$paciente_id.'" class="btn btn-dk btn-sm"><i class="bi bi-person me-1"></i>Paciente</a>';
    include __DIR__.'/../includes/header.php';
?>
<style>
.cur-card     { background:var(--bg2);border:1px solid var(--bd2);border-radius:10px;padding:14px 16px;margin-bottom:12px;transition:border-color .15s; }
.cur-card:hover { border-color:rgba(245,158,11,.3); }
.proc-chip    { display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:10px;font-size:10px;font-weight:700;background:var(--bg4);border:1px solid var(--bd2);color:var(--t2); }
.proc-active  { background:rgba(0,212,238,.12);border-color:rgba(0,212,238,.3);color:var(--c); }
.pieza-badge  { display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;background:var(--c)22;border:2px solid var(--c)44;color:var(--c);font-weight:800;font-size:14px;flex-shrink:0; }
.pac-info     { background:var(--bg2);border:1px solid var(--bd2);border-radius:10px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap; }
</style>

<div class="pb">
  <?=popFlash()?>

  <div class="pac-info">
    <div style="width:40px;height:40px;border-radius:50%;background:var(--c)22;border:2px solid var(--c)44;display:flex;align-items:center;justify-content:center;font-size:18px">🦷</div>
    <div>
      <div style="font-weight:700;font-size:15px"><?=e($pac['nombres'].' '.$pac['apellido_paterno'])?></div>
      <div style="font-size:12px;color:var(--t2)"><?=$pac['fecha_nacimiento']?edad($pac['fecha_nacimiento']):'—'?> &nbsp;·&nbsp; <?=e($pac['telefono']??'—')?></div>
    </div>
    <div class="ms-auto d-flex gap-2 flex-wrap">
      <a href="?accion=nuevo&paciente_id=<?=$paciente_id?>" class="btn btn-primary btn-sm"><i class="bi bi-plus me-1"></i>Nueva curación</a>
    </div>
  </div>

  <?php if (!$rows): ?>
  <div class="card p-5 text-center" style="color:var(--t2)">
    <i class="bi bi-bandaid" style="font-size:36px;display:block;margin-bottom:10px"></i>
    No hay registros de curaciones para este paciente.
  </div>
  <?php else: ?>
  <?php foreach($rows as $r): ?>
  <div class="cur-card">
    <div class="d-flex align-items-start gap-3">
      <div class="pieza-badge"><?=e($r['pieza']??'?')?></div>
      <div class="flex-fill">
        <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
          <?php if($r['numero_hc']??''): ?><span class="badge bc" style="font-size:10px"><?=e($r['numero_hc'])?></span><?php endif; ?>
          <span style="font-size:11px;color:var(--t2)">Dr. <?=e($r['doctor']??'—')?></span>
          <span style="font-size:10px;color:var(--t3)"><?=fDate($r['created_at'],'d/m/Y H:i')?></span>
        </div>

        <!-- Procedimientos -->
        <div class="d-flex flex-wrap gap-1 mb-2">
          <span class="proc-chip <?=$r['obturacion']?'proc-active':''?>">Obturación</span>
          <span class="proc-chip <?=$r['recub_pulpar']?'proc-active':''?>">Recub. Pulpar</span>
          <span class="proc-chip <?=$r['base']?'proc-active':''?>">Base</span>
          <span class="proc-chip <?=$r['otro']?'proc-active':''?>">Otro</span>
        </div>

        <!-- Materiales -->
        <div class="d-flex flex-wrap gap-1 mb-2">
          <?php if($r['resina']): ?><span class="badge bg" style="font-size:10px">Resina: <?=e($r['resina'])?></span><?php endif; ?>
          <?php if($r['eugenato']): ?><span class="badge bgr" style="font-size:10px">Eugenato</span><?php endif; ?>
          <?php if($r['material_otro']): ?><span class="badge" style="font-size:10px;background:var(--bg4);border:1px solid var(--bd2);color:var(--t2)">Otro: <?=e($r['material_otro'])?></span><?php endif; ?>
        </div>

        <?php if($r['plan_tratamiento']): ?><div style="font-size:12px;color:var(--t2)">Plan: <?=e(mb_substr($r['plan_tratamiento'],0,80)).(mb_strlen($r['plan_tratamiento'])>80?'...':'')?></div><?php endif; ?>
        <?php if($r['observaciones']): ?><div style="font-size:12px;color:var(--t2)">📝 <?=e(mb_substr($r['observaciones'],0,80)).(mb_strlen($r['observaciones'])>80?'...':'')?></div><?php endif; ?>

        <?php if($r['fecha_control']): ?>
        <div style="font-size:12px;margin-top:4px"><span style="color:var(--t2)">Control:</span> <strong><?=fDate($r['fecha_control'])?></strong></div>
        <?php endif; ?>
      </div>

      <div class="d-flex flex-column gap-1 flex-shrink-0">
        <a href="?accion=ver&id=<?=$r['id']?>&paciente_id=<?=$paciente_id?>" class="btn btn-dk btn-ico btn-sm" title="Ver"><i class="bi bi-eye"></i></a>
        <a href="?accion=editar&id=<?=$r['id']?>&paciente_id=<?=$paciente_id?>" class="btn btn-dk btn-ico btn-sm" title="Editar"><i class="bi bi-pencil"></i></a>
        <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar esta curación?')">
          <input type="hidden" name="accion" value="eliminar">
          <input type="hidden" name="id" value="<?=$r['id']?>">
          <button type="submit" class="btn btn-del btn-ico btn-sm" title="Eliminar"><i class="bi bi-trash"></i></button>
        </form>
      </div>
    </div>

    <!-- Agregar control rápido -->
    <?php if(!$r['fecha_control']): ?>
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--bd2)">
      <form method="POST" class="d-flex align-items-center gap-2">
        <input type="hidden" name="accion" value="agregar_control">
        <input type="hidden" name="cur_id" value="<?=$r['id']?>">
        <span style="font-size:11px;color:var(--t2)">Agregar control:</span>
        <input type="date" name="fecha_control" class="form-control form-control-sm" style="width:160px" required>
        <button type="submit" class="btn btn-dk btn-sm">Guardar</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php
    include __DIR__.'/../includes/footer.php';

// ════════════════════════════════════════════════════════════════════
// VER
// ════════════════════════════════════════════════════════════════════
} elseif ($accion === 'ver' && $id) {
    $r = db()->prepare("SELECT c.*, CONCAT(u.nombre,' ',u.apellidos) AS doctor, hc.numero_hc
                        FROM curaciones c
                        LEFT JOIN usuarios u ON c.doctor_id=u.id
                        LEFT JOIN historias_clinicas hc ON c.hc_id=hc.id
                        WHERE c.id=?");
    $r->execute([$id]); $r = $r->fetch();
    if (!$r){ flash('error','Registro no encontrado'); go("pages/curaciones.php?paciente_id=$paciente_id"); }

    $titulo = 'Curación — '.$pac['nombres'].' '.$pac['apellido_paterno'];
    $pagina_activa = 'pac';
    $topbar_act = '<a href="?paciente_id='.$paciente_id.'" class="btn btn-dk btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver</a>
    <a href="?accion=editar&id='.$id.'&paciente_id='.$paciente_id.'" class="btn btn-primary btn-sm"><i class="bi bi-pencil me-1"></i>Editar</a>';
    include __DIR__.'/../includes/header.php';
?>
<div class="row justify-content-center"><div class="col-12 col-lg-8">
  <div class="card mb-4">
    <div class="card-header"><span style="color:var(--t)">🦷 Curación — Pieza <?=e($r['pieza']??'—')?></span></div>
    <div class="p-4" style="font-size:13px"><div class="row g-3">
      <div class="col-12 col-md-6"><div style="color:var(--t2);font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:4px">Doctor</div><?=e($r['doctor']??'—')?></div>
      <?php if($r['numero_hc']): ?><div class="col-12 col-md-6"><div style="color:var(--t2);font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:4px">Historia Clínica</div><?=e($r['numero_hc'])?></div><?php endif; ?>
      <div class="col-12"><hr style="border-color:var(--bd2);margin:4px 0"><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t2)">Procedimientos realizados</div></div>
      <div class="col-12"><div class="d-flex flex-wrap gap-2">
        <?php $procs=[['Obturación',$r['obturacion']],['Recubrimiento Pulpar',$r['recub_pulpar']],['Base',$r['base']],['Otro',$r['otro']]];
        foreach($procs as[$l,$v]): ?>
        <span class="badge <?=$v?'bg':'bgr'?>" style="font-size:11px"><?=$v?'✓':' '?> <?=$l?></span>
        <?php endforeach; ?>
      </div></div>
      <div class="col-12"><hr style="border-color:var(--bd2);margin:4px 0"><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t2)">Materiales</div></div>
      <?php if($r['resina']): ?><div class="col-12 col-md-4"><div style="color:var(--t2);font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:4px">Resina</div><strong><?=e($r['resina'])?></strong></div><?php endif; ?>
      <?php if($r['eugenato']): ?><div class="col-12 col-md-4"><div style="color:var(--t2);font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:4px">Eugenato</div><span class="badge bgr">Sí</span></div><?php endif; ?>
      <?php if($r['material_otro']): ?><div class="col-12 col-md-4"><div style="color:var(--t2);font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:4px">Otro material</div><?=e($r['material_otro'])?></div><?php endif; ?>
      <?php if($r['plan_tratamiento']): ?>
      <div class="col-12"><hr style="border-color:var(--bd2);margin:4px 0">
      <div style="color:var(--t2);font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:4px">Plan de Tratamiento</div>
      <div style="background:var(--bg3);padding:9px 12px;border-radius:6px"><?=nl2br(e($r['plan_tratamiento']))?></div></div>
      <?php endif; ?>
      <?php if($r['observaciones']): ?>
      <div class="col-12"><div style="color:var(--t2);font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:4px">Observaciones</div>
      <div style="background:var(--bg3);padding:9px 12px;border-radius:6px"><?=nl2br(e($r['observaciones']))?></div></div>
      <?php endif; ?>
      <?php if($r['fecha_control']): ?>
      <div class="col-12 col-md-4"><div style="color:var(--t2);font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:4px">Fecha Control</div><strong><?=fDate($r['fecha_control'])?></strong></div>
      <?php endif; ?>
    </div></div>
  </div>
</div></div>
<?php
    include __DIR__.'/../includes/footer.php';

// ════════════════════════════════════════════════════════════════════
// NUEVO / EDITAR
// ════════════════════════════════════════════════════════════════════
} elseif (in_array($accion, ['nuevo','editar'])) {
    $d = ['id'=>0,'hc_id'=>0,'pieza'=>'','obturacion'=>0,'recub_pulpar'=>0,'base'=>0,'otro'=>0,'resina'=>'','eugenato'=>0,'material_otro'=>'','plan_tratamiento'=>'','observaciones'=>'','fecha_control'=>''];
    if ($accion==='editar' && $id) {
        $s = db()->prepare("SELECT * FROM curaciones WHERE id=?");
        $s->execute([$id]); $d = $s->fetch() ?: $d;
    }
    $hcs = db()->prepare("SELECT id, numero_hc FROM historias_clinicas WHERE paciente_id=? ORDER BY fecha_apertura DESC");
    $hcs->execute([$paciente_id]); $hcs = $hcs->fetchAll();

    $titulo = ($accion==='nuevo'?'Nueva':'Editar').' Curación — '.$pac['nombres'].' '.$pac['apellido_paterno'];
    $pagina_activa = 'pac';
    $topbar_act = '<a href="?paciente_id='.$paciente_id.'" class="btn btn-dk btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver</a>';
    include __DIR__.'/../includes/header.php';
?>
<div class="row justify-content-center"><div class="col-12 col-lg-8">
<form method="POST">
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?=$d['id']?>">

  <div class="card mb-4">
    <div class="card-header"><span style="color:var(--t)">🦷 Datos de la curación</span></div>
    <div class="p-4"><div class="row g-3">

      <!-- HC + Pieza -->
      <div class="col-12 col-md-6">
        <label class="form-label">Historia Clínica</label>
        <select name="hc_id" class="form-select">
          <option value="">— Sin HC vinculada —</option>
          <?php foreach($hcs as $h): ?>
          <option value="<?=$h['id']?>" <?=$d['hc_id']==$h['id']?'selected':''?>><?=e($h['numero_hc'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label">Pieza dental *</label>
        <input type="text" name="pieza" class="form-control" value="<?=e($d['pieza']??'')?>" placeholder="ej. 16, 26, 46..." required>
      </div>

      <!-- Plan de tratamiento -->
      <div class="col-12">
        <label class="form-label">Plan de tratamiento</label>
        <input type="text" name="plan_tratamiento" class="form-control" value="<?=e($d['plan_tratamiento']??'')?>" placeholder="Descripción del plan...">
      </div>

      <!-- Procedimientos (checkboxes con iconos) -->
      <div class="col-12">
        <label class="form-label">Procedimientos realizados</label>
        <div class="row g-2">
          <?php $procs=[['obturacion','Obturación'],['recub_pulpar','Recub. Pulpar'],['base','Base'],['otro','Otro']];
          foreach($procs as[$k,$l]): ?>
          <div class="col-6 col-md-3">
            <label class="d-flex align-items-center gap-2 p-3 rounded" style="background:var(--bg3);border:1px solid var(--bd2);cursor:pointer;transition:border-color .12s" onmouseenter="this.style.borderColor='var(--c)'" onmouseleave="this.style.borderColor='var(--bd2)'">
              <input type="checkbox" name="<?=$k?>" class="form-check-input mt-0" <?=($d[$k]??0)?'checked':''?>>
              <span style="font-size:12px;font-weight:600"><?=$l?></span>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Materiales -->
      <div class="col-12"><hr style="border-color:var(--bd2);margin:4px 0">
        <label class="form-label">Materiales utilizados</label>
        <div class="row g-3">
          <div class="col-12 col-md-5">
            <label class="form-label" style="font-size:11px">Resina (tamaño)</label>
            <div class="d-flex gap-1">
              <?php foreach(['P','M','G','C'] as $rs): ?>
              <label class="flex-fill text-center p-2 rounded" style="background:var(--bg3);border:1px solid var(--bd2);cursor:pointer;font-size:12px;font-weight:700">
                <input type="radio" name="resina" value="<?=$rs?>" class="d-none" <?=($d['resina']??'')===$rs?'checked':''?> onchange="this.closest('.d-flex').querySelectorAll('label').forEach(l=>l.style.borderColor='var(--bd2)');this.closest('label').style.borderColor='var(--c)'">
                <?=$rs?>
              </label>
              <?php endforeach; ?>
              <label class="flex-fill text-center p-2 rounded" style="background:var(--bg3);border:1px solid var(--bd2);cursor:pointer;font-size:12px">
                <input type="radio" name="resina" value="" class="d-none" <?=($d['resina']??'')==''?'checked':''?>>
                ✕
              </label>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label" style="font-size:11px">Eugenato</label>
            <label class="d-flex align-items-center gap-2 p-2 rounded" style="background:var(--bg3);border:1px solid var(--bd2);cursor:pointer">
              <input type="checkbox" name="eugenato" class="form-check-input mt-0" <?=($d['eugenato']??0)?'checked':''?>>
              <span style="font-size:12px">Sí, usar eugenato</span>
            </label>
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label" style="font-size:11px">Otro material</label>
            <input type="text" name="material_otro" class="form-control" value="<?=e($d['material_otro']??'')?>" placeholder="Especificar...">
          </div>
        </div>
      </div>

      <!-- Observaciones + Control -->
      <div class="col-12 col-md-8">
        <label class="form-label">Observaciones</label>
        <textarea name="observaciones" class="form-control" rows="3" placeholder="Notas del procedimiento..."><?=e($d['observaciones']??'')?></textarea>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Fecha de control</label>
        <input type="date" name="fecha_control" class="form-control" value="<?=e($d['fecha_control']??'')?>">
      </div>

    </div></div>
  </div>

  <div class="d-flex gap-2 justify-content-end">
    <a href="?paciente_id=<?=$paciente_id?>" class="btn btn-dk">Cancelar</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-floppy me-2"></i>Guardar</button>
  </div>
</form>
</div></div>

<script>
// Resaltar radio activo al cargar
document.querySelectorAll('input[name="resina"]').forEach(r => {
  if(r.checked) r.closest('label').style.borderColor='var(--c)';
  r.addEventListener('change', function(){
    this.closest('.d-flex').querySelectorAll('label').forEach(l=>l.style.borderColor='var(--bd2)');
    if(this.checked) this.closest('label').style.borderColor='var(--c)';
  });
});
</script>
<?php
    include __DIR__.'/../includes/footer.php';
}
