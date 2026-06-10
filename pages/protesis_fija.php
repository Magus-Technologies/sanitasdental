<?php
/**
 * PRÓTESIS FIJA — Módulo completo
 * Tablas: protesis_fija
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
        $ei              = (int)($_POST['id'] ?? 0);
        $hc_id           = (int)($_POST['hc_id'] ?? 0);
        $pieza           = trim($_POST['pieza'] ?? '');
        $provisional     = isset($_POST['provisional']) ? 1 : 0;
        $corona          = trim($_POST['corona'] ?? '');
        $prueba_cofia    = trim($_POST['prueba_cofia'] ?? '');
        $color           = trim($_POST['color'] ?? '');
        $forma           = trim($_POST['forma'] ?? '');
        $fecha_prueba    = $_POST['fecha_prueba'] ?: null;
        $fecha_entrega   = $_POST['fecha_entrega'] ?: null;
        $fecha_control   = $_POST['fecha_control'] ?: null;
        $observaciones   = trim($_POST['observaciones'] ?? '');

        $d = [
            'paciente_id'  => $paciente_id,
            'hc_id'        => $hc_id ?: null,
            'pieza'        => $pieza,
            'provisional'  => $provisional,
            'corona'       => $corona,
            'prueba_cofia' => $prueba_cofia,
            'color'        => $color,
            'forma'        => $forma,
            'fecha_prueba' => $fecha_prueba,
            'fecha_entrega'=> $fecha_entrega,
            'fecha_control'=> $fecha_control,
            'observaciones'=> $observaciones,
            'doctor_id'    => $_SESSION['uid'],
        ];

        if ($ei) {
            unset($d['paciente_id'],$d['doctor_id']);
            $sets = implode(',', array_map(fn($k)=>"$k=?", array_keys($d)));
            $vals = [...array_values($d), $ei];
            db()->prepare("UPDATE protesis_fija SET $sets,updated_at=NOW() WHERE id=?")->execute($vals);
            auditar('EDITAR_PROTESIS','protesis_fija',$ei);
            flash('ok','Prótesis fija actualizada.');
        } else {
            $cols = implode(',', array_keys($d));
            $phs  = implode(',', array_fill(0, count($d), '?'));
            db()->prepare("INSERT INTO protesis_fija($cols) VALUES($phs)")->execute(array_values($d));
            $nid = db()->lastInsertId();
            auditar('CREAR_PROTESIS','protesis_fija',$nid);
            flash('ok','Prótesis fija registrada correctamente.');
        }
        go("pages/protesis_fija.php?paciente_id=$paciente_id");
    }

    if ($ap === 'eliminar') {
        $did = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM protesis_fija WHERE id=?")->execute([$did]);
        auditar('ELIMINAR_PROTESIS','protesis_fija',$did);
        flash('ok','Registro eliminado.');
        go("pages/protesis_fija.php?paciente_id=$paciente_id");
    }
}

// ════════════════════════════════════════════════════════════════════
// LISTA
// ════════════════════════════════════════════════════════════════════
if ($accion === 'lista') {
    $rows = db()->prepare("SELECT p.*, CONCAT(u.nombre,' ',u.apellidos) AS doctor, hc.numero_hc
                           FROM protesis_fija p
                           LEFT JOIN usuarios u ON p.doctor_id=u.id
                           LEFT JOIN historias_clinicas hc ON p.hc_id=hc.id
                           WHERE p.paciente_id=? ORDER BY p.created_at DESC");
    $rows->execute([$paciente_id]);
    $rows = $rows->fetchAll();

    $titulo = 'Prótesis Fija — '.$pac['nombres'].' '.$pac['apellido_paterno'];
    $pagina_activa = 'pac';
    $topbar_act = '<a href="?accion=nuevo&paciente_id='.$paciente_id.'" class="btn btn-primary"><i class="bi bi-plus me-1"></i>Nueva prótesis fija</a>
    <a href="'.BASE_URL.'/pages/pacientes.php?accion=ver&id='.$paciente_id.'" class="btn btn-dk btn-sm"><i class="bi bi-person me-1"></i>Paciente</a>';
    include __DIR__.'/../includes/header.php';
?>
<style>
.prot-card    { background:var(--bg2);border:1px solid var(--bd2);border-radius:10px;padding:14px 16px;margin-bottom:12px;transition:border-color .15s; }
.prot-card:hover { border-color:rgba(139,92,246,.3); }
.prot-chip    { display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:10px;font-size:10px;font-weight:700;background:var(--bg4);border:1px solid var(--bd2);color:var(--t2); }
.pieza-badge  { display:inline-flex;align-items:center;justify-content:center;min-width:40px;height:40px;border-radius:8px;background:#8b5cf622;border:2px solid #8b5cf644;color:#8b5cf6;font-weight:800;font-size:13px;flex-shrink:0;padding:0 6px; }
.pac-info     { background:var(--bg2);border:1px solid var(--bd2);border-radius:10px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap; }
.fecha-item   { display:flex;flex-direction:column;align-items:center;padding:8px 12px;background:var(--bg3);border-radius:8px;border:1px solid var(--bd2);font-size:11px;min-width:90px; }
.fecha-item strong { font-size:13px;color:var(--t); }
.fecha-item small  { color:var(--t2); }
</style>

<div class="pb">
  <?=popFlash()?>

  <div class="pac-info">
    <div style="width:40px;height:40px;border-radius:50%;background:#8b5cf622;border:2px solid #8b5cf644;display:flex;align-items:center;justify-content:center;font-size:18px">👑</div>
    <div>
      <div style="font-weight:700;font-size:15px"><?=e($pac['nombres'].' '.$pac['apellido_paterno'])?></div>
      <div style="font-size:12px;color:var(--t2)"><?=$pac['fecha_nacimiento']?edad($pac['fecha_nacimiento']):'—'?> &nbsp;·&nbsp; <?=e($pac['telefono']??'—')?></div>
    </div>
    <div class="ms-auto d-flex gap-2 flex-wrap">
      <a href="?accion=nuevo&paciente_id=<?=$paciente_id?>" class="btn btn-primary btn-sm" style="background:#8b5cf6;border-color:#8b5cf6"><i class="bi bi-plus me-1"></i>Nueva prótesis</a>
    </div>
  </div>

  <?php if (!$rows): ?>
  <div class="card p-5 text-center" style="color:var(--t2)">
    <i class="bi bi-gem" style="font-size:36px;display:block;margin-bottom:10px"></i>
    No hay registros de prótesis fija para este paciente.
  </div>
  <?php else: ?>
  <?php foreach($rows as $r): ?>
  <div class="prot-card">
    <div class="d-flex align-items-start gap-3">
      <div class="pieza-badge"><?=e($r['pieza']??'?')?></div>
      <div class="flex-fill">
        <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
          <?php if($r['provisional']): ?><span class="badge bgr" style="font-size:10px">Provisional</span><?php endif; ?>
          <?php if($r['corona']): ?><span class="prot-chip" style="background:#8b5cf622;border-color:#8b5cf644;color:#8b5cf6">Corona: <?=e($r['corona'])?></span><?php endif; ?>
          <?php if($r['prueba_cofia']): ?><span class="prot-chip">Cofia: <?=e($r['prueba_cofia'])?></span><?php endif; ?>
          <?php if($r['color']): ?><span class="prot-chip">Color: <?=e($r['color'])?></span><?php endif; ?>
          <?php if($r['forma']): ?><span class="prot-chip">Forma: <?=e($r['forma'])?></span><?php endif; ?>
          <?php if($r['numero_hc']??''): ?><span class="badge bc" style="font-size:10px"><?=e($r['numero_hc'])?></span><?php endif; ?>
        </div>

        <!-- Fechas -->
        <div class="d-flex flex-wrap gap-2 mb-2">
          <?php if($r['fecha_prueba']): ?>
          <div class="fecha-item"><small>Prueba</small><strong><?=fDate($r['fecha_prueba'])?></strong></div>
          <?php endif; ?>
          <?php if($r['fecha_entrega']): ?>
          <div class="fecha-item"><small>Entrega</small><strong><?=fDate($r['fecha_entrega'])?></strong></div>
          <?php endif; ?>
          <?php if($r['fecha_control']): ?>
          <div class="fecha-item"><small>Control</small><strong><?=fDate($r['fecha_control'])?></strong></div>
          <?php endif; ?>
        </div>

        <?php if($r['observaciones']): ?>
        <div style="font-size:12px;color:var(--t2)">📝 <?=e(mb_substr($r['observaciones'],0,100)).(mb_strlen($r['observaciones'])>100?'...':'')?></div>
        <?php endif; ?>
        <div style="font-size:10px;color:var(--t3);margin-top:4px">Dr. <?=e($r['doctor']??'—')?> · <?=fDate($r['created_at'],'d/m/Y H:i')?></div>
      </div>

      <div class="d-flex flex-column gap-1 flex-shrink-0">
        <a href="?accion=ver&id=<?=$r['id']?>&paciente_id=<?=$paciente_id?>" class="btn btn-dk btn-ico btn-sm" title="Ver"><i class="bi bi-eye"></i></a>
        <a href="?accion=editar&id=<?=$r['id']?>&paciente_id=<?=$paciente_id?>" class="btn btn-dk btn-ico btn-sm" title="Editar"><i class="bi bi-pencil"></i></a>
        <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este registro de prótesis fija?')">
          <input type="hidden" name="accion" value="eliminar">
          <input type="hidden" name="id" value="<?=$r['id']?>">
          <button type="submit" class="btn btn-del btn-ico btn-sm" title="Eliminar"><i class="bi bi-trash"></i></button>
        </form>
      </div>
    </div>
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
    $r = db()->prepare("SELECT p.*, CONCAT(u.nombre,' ',u.apellidos) AS doctor, hc.numero_hc
                        FROM protesis_fija p
                        LEFT JOIN usuarios u ON p.doctor_id=u.id
                        LEFT JOIN historias_clinicas hc ON p.hc_id=hc.id
                        WHERE p.id=?");
    $r->execute([$id]); $r = $r->fetch();
    if (!$r){ flash('error','Registro no encontrado'); go("pages/protesis_fija.php?paciente_id=$paciente_id"); }

    $titulo = 'Prótesis Fija — '.$pac['nombres'].' '.$pac['apellido_paterno'];
    $pagina_activa = 'pac';
    $topbar_act = '<a href="?paciente_id='.$paciente_id.'" class="btn btn-dk btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver</a>
    <a href="?accion=editar&id='.$id.'&paciente_id='.$paciente_id.'" class="btn btn-primary btn-sm" style="background:#8b5cf6;border-color:#8b5cf6"><i class="bi bi-pencil me-1"></i>Editar</a>';
    include __DIR__.'/../includes/header.php';
?>
<div class="row justify-content-center"><div class="col-12 col-lg-8">
  <div class="card mb-4">
    <div class="card-header"><span style="color:var(--t)">👑 Prótesis Fija — Pieza <?=e($r['pieza']??'—')?></span>
    <?php if($r['provisional']): ?><span class="badge bgr">Provisional</span><?php endif; ?></div>
    <div class="p-4" style="font-size:13px"><div class="row g-3">
      <div class="col-12 col-md-6"><div style="color:var(--t2);font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:4px">Doctor</div><?=e($r['doctor']??'—')?></div>
      <?php if($r['numero_hc']): ?><div class="col-12 col-md-6"><div style="color:var(--t2);font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:4px">Historia Clínica</div><?=e($r['numero_hc'])?></div><?php endif; ?>
      <div class="col-12"><hr style="border-color:var(--bd2);margin:4px 0"><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t2)">Especificaciones</div></div>
      <?php if($r['corona']): ?><div class="col-12 col-md-3"><div style="color:var(--t2);font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:4px">Corona</div><strong><?=e($r['corona'])?></strong></div><?php endif; ?>
      <?php if($r['prueba_cofia']): ?><div class="col-12 col-md-3"><div style="color:var(--t2);font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:4px">Prueba de Cofia</div><strong><?=e($r['prueba_cofia'])?></strong></div><?php endif; ?>
      <?php if($r['color']): ?><div class="col-12 col-md-3"><div style="color:var(--t2);font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:4px">Color</div><strong><?=e($r['color'])?></strong></div><?php endif; ?>
      <?php if($r['forma']): ?><div class="col-12 col-md-3"><div style="color:var(--t2);font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:4px">Forma</div><strong><?=e($r['forma'])?></strong></div><?php endif; ?>
      <div class="col-12"><hr style="border-color:var(--bd2);margin:4px 0"><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t2)">Fechas</div></div>
      <?php $fechas=[['Fecha de Prueba',$r['fecha_prueba']],['Fecha de Entrega',$r['fecha_entrega']],['Control',$r['fecha_control']]];
      foreach($fechas as[$l,$f]): if($f): ?>
      <div class="col-12 col-md-4"><div style="color:var(--t2);font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:4px"><?=$l?></div><strong><?=fDate($f)?></strong></div>
      <?php endif; endforeach; ?>
      <?php if($r['observaciones']): ?>
      <div class="col-12"><hr style="border-color:var(--bd2);margin:4px 0">
      <div style="color:var(--t2);font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:4px">Observaciones</div>
      <div style="background:var(--bg3);padding:9px 12px;border-radius:6px"><?=nl2br(e($r['observaciones']))?></div></div>
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
    $d = ['id'=>0,'hc_id'=>0,'pieza'=>'','provisional'=>0,'corona'=>'','prueba_cofia'=>'','color'=>'','forma'=>'','fecha_prueba'=>'','fecha_entrega'=>'','fecha_control'=>'','observaciones'=>''];
    if ($accion==='editar' && $id) {
        $s = db()->prepare("SELECT * FROM protesis_fija WHERE id=?");
        $s->execute([$id]); $d = $s->fetch() ?: $d;
    }
    $hcs = db()->prepare("SELECT id, numero_hc FROM historias_clinicas WHERE paciente_id=? ORDER BY fecha_apertura DESC");
    $hcs->execute([$paciente_id]); $hcs = $hcs->fetchAll();

    $titulo = ($accion==='nuevo'?'Nueva':'Editar').' Prótesis Fija — '.$pac['nombres'].' '.$pac['apellido_paterno'];
    $pagina_activa = 'pac';
    $topbar_act = '<a href="?paciente_id='.$paciente_id.'" class="btn btn-dk btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver</a>';
    include __DIR__.'/../includes/header.php';
?>
<div class="row justify-content-center"><div class="col-12 col-lg-8">
<form method="POST">
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?=$d['id']?>">

  <div class="card mb-4">
    <div class="card-header"><span style="color:var(--t)">👑 Prótesis Fija</span></div>
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
      <div class="col-12 col-md-4">
        <label class="form-label">Pieza dental *</label>
        <input type="text" name="pieza" class="form-control" value="<?=e($d['pieza']??'')?>" placeholder="ej. 16, 26..." required>
      </div>
      <div class="col-12 col-md-2 d-flex align-items-end">
        <label class="d-flex align-items-center gap-2 p-2 rounded w-100" style="background:var(--bg3);border:1px solid var(--bd2);cursor:pointer">
          <input type="checkbox" name="provisional" class="form-check-input mt-0" <?=($d['provisional']??0)?'checked':''?>>
          <span style="font-size:12px">Provisional</span>
        </label>
      </div>

      <!-- Especificaciones -->
      <div class="col-12"><hr style="border-color:var(--bd2);margin:4px 0">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t2);margin-bottom:2px">Especificaciones de la prótesis</div>
      </div>

      <div class="col-12 col-md-6">
        <label class="form-label">Corona</label>
        <select name="corona" class="form-select">
          <option value="">— Sin especificar —</option>
          <?php foreach(['Zirconio','Porcelana','Silicato','Metal','Otro'] as $opt): ?>
          <option value="<?=$opt?>" <?=($d['corona']??'')===$opt?'selected':''?>><?=$opt?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label">Prueba de Cofia</label>
        <select name="prueba_cofia" class="form-select">
          <option value="">— Sin especificar —</option>
          <?php foreach(['Metal','Zirconio'] as $opt): ?>
          <option value="<?=$opt?>" <?=($d['prueba_cofia']??'')===$opt?'selected':''?>><?=$opt?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label">Color</label>
        <input type="text" name="color" class="form-control" value="<?=e($d['color']??'')?>" placeholder="ej. A2, B3...">
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label">Forma</label>
        <select name="forma" class="form-select">
          <option value="">— Sin especificar —</option>
          <?php foreach(['Ovalada','Recta','Redondeada','Otro'] as $opt): ?>
          <option value="<?=$opt?>" <?=($d['forma']??'')===$opt?'selected':''?>><?=$opt?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Fechas -->
      <div class="col-12"><hr style="border-color:var(--bd2);margin:4px 0">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t2);margin-bottom:2px">Fechas</div>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Fecha de Prueba</label>
        <input type="date" name="fecha_prueba" class="form-control" value="<?=e($d['fecha_prueba']??'')?>">
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Fecha de Entrega</label>
        <input type="date" name="fecha_entrega" class="form-control" value="<?=e($d['fecha_entrega']??'')?>">
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Control</label>
        <input type="date" name="fecha_control" class="form-control" value="<?=e($d['fecha_control']??'')?>">
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
    <button type="submit" class="btn btn-primary px-4" style="background:#8b5cf6;border-color:#8b5cf6"><i class="bi bi-floppy me-2"></i>Guardar</button>
  </div>
</form>
</div></div>
<?php
    include __DIR__.'/../includes/footer.php';
}
