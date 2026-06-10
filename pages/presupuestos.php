<?php
/**
 * MÓDULO DE PRESUPUESTOS (cotizaciones al paciente)
 * - Ítems desde tratamientos_catalogo + líneas libres
 * - Descuento global (monto o %), sin IGV
 * - Al APROBAR genera automáticamente un Plan de Tratamiento
 * Tablas: presupuestos, presupuesto_detalles
 */
require_once __DIR__ . '/../includes/config.php';
requiereLogin();
if (!puedeVer('presupuestos')) { flash('error','No tienes permiso para ver Presupuestos.'); go('index.php'); }

$accion      = $_GET['accion'] ?? 'lista';
$id          = (int)($_GET['id'] ?? 0);
$paciente_id = (int)($_GET['paciente_id'] ?? 0);

// Calcula el monto de descuento a partir del tipo y valor
function calcDescuento(float $subtotal, string $tipo, float $valor): float {
    if ($tipo === 'porcentaje') return round($subtotal * min(max($valor,0),100) / 100, 2);
    return round(min(max($valor,0), $subtotal), 2);
}

// ───────────────────────────────── POST ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ap = $_POST['accion'] ?? '';

    // ---- Guardar (crear / editar) ----
    if ($ap === 'guardar') {
        $eid = (int)($_POST['id'] ?? 0);
        $pac = (int)($_POST['paciente_id'] ?? 0);
        if (!$pac) { flash('error','Paciente requerido.'); go('pages/presupuestos.php'); }
        $hc    = (int)($_POST['hc_id'] ?? 0) ?: null;
        $doc   = (int)($_POST['doctor_id'] ?? 0) ?: ($_SESSION['uid'] ?? null);
        $fecha = $_POST['fecha'] ?: date('Y-m-d');
        $vdias = max(0,(int)($_POST['validez_dias'] ?? 15));
        $venc  = date('Y-m-d', strtotime($fecha." +$vdias days"));
        $dtipo = ($_POST['descuento_tipo'] ?? 'monto')==='porcentaje' ? 'porcentaje' : 'monto';
        $dval  = round((float)($_POST['descuento_valor'] ?? 0),2);
        $notas = trim($_POST['notas'] ?? '');
        $cond  = trim($_POST['condiciones'] ?? '');

        $nm=$_POST['it_nombre']??[]; $tid=$_POST['it_id']??[]; $di=$_POST['it_diente']??[];
        $ca=$_POST['it_cant']??[];   $px=$_POST['it_precio']??[];
        $rows=[]; $subtotal=0.0;
        foreach ($nm as $i=>$nombre) {
            $nombre=trim($nombre); if ($nombre==='') continue;
            $cant=max(1,(int)($ca[$i]??1));
            $pu=round((float)($px[$i]??0),2);
            $sub=round($cant*$pu,2); $subtotal+=$sub;
            $rows[]=['tid'=>((int)($tid[$i]??0)?:null),'nombre'=>$nombre,'diente'=>trim($di[$i]??''),'cant'=>$cant,'pu'=>$pu,'sub'=>$sub,'orden'=>count($rows)+1];
        }
        if (!$rows) { flash('error','Agrega al menos un ítem al presupuesto.'); go('pages/presupuestos.php?accion='.($eid?'editar&id='.$eid:'nuevo&paciente_id='.$pac)); }
        $subtotal=round($subtotal,2);
        $dmonto=calcDescuento($subtotal,$dtipo,$dval);
        $total=round($subtotal-$dmonto,2);

        try {
            db()->beginTransaction();
            if ($eid) {
                db()->prepare("UPDATE presupuestos SET paciente_id=?,hc_id=?,doctor_id=?,fecha=?,validez_dias=?,fecha_vencimiento=?,subtotal=?,descuento_tipo=?,descuento_valor=?,descuento_monto=?,total=?,notas=?,condiciones=?,updated_at=NOW() WHERE id=? AND estado<>'aprobado'")
                  ->execute([$pac,$hc,$doc,$fecha,$vdias,$venc,$subtotal,$dtipo,$dval,$dmonto,$total,$notas,$cond,$eid]);
                $pid=$eid;
                db()->prepare("DELETE FROM presupuesto_detalles WHERE presupuesto_id=?")->execute([$pid]);
            } else {
                db()->prepare("INSERT INTO presupuestos(paciente_id,hc_id,doctor_id,fecha,validez_dias,fecha_vencimiento,subtotal,descuento_tipo,descuento_valor,descuento_monto,total,estado,notas,condiciones) VALUES(?,?,?,?,?,?,?,?,?,?,?,'borrador',?,?)")
                  ->execute([$pac,$hc,$doc,$fecha,$vdias,$venc,$subtotal,$dtipo,$dval,$dmonto,$total,$notas,$cond]);
                $pid=(int)db()->lastInsertId();
                db()->prepare("UPDATE presupuestos SET codigo=? WHERE id=?")->execute(['PRES-'.str_pad((string)$pid,5,'0',STR_PAD_LEFT),$pid]);
            }
            $ins=db()->prepare("INSERT INTO presupuesto_detalles(presupuesto_id,tratamiento_id,nombre,diente,cantidad,precio_unit,subtotal,orden) VALUES(?,?,?,?,?,?,?,?)");
            foreach ($rows as $r) $ins->execute([$pid,$r['tid'],$r['nombre'],$r['diente'],$r['cant'],$r['pu'],$r['sub'],$r['orden']]);
            db()->commit();
            auditar('GUARDAR_PRESUPUESTO','presupuestos',$pid);
            flash('ok','Presupuesto guardado correctamente.');
            go('pages/presupuestos.php?accion=ver&id='.$pid);
        } catch (\PDOException $e) {
            if (db()->inTransaction()) db()->rollBack();
            flash('error','No se pudo guardar: '.$e->getMessage());
            go('pages/presupuestos.php');
        }
    }

    // ---- Aprobar → genera Plan de Tratamiento ----
    elseif ($ap === 'aprobar') {
        $pid=(int)($_POST['id']??0);
        $pr=db()->prepare("SELECT * FROM presupuestos WHERE id=?"); $pr->execute([$pid]); $pr=$pr->fetch();
        if (!$pr) { flash('error','Presupuesto no encontrado.'); go('pages/presupuestos.php'); }
        if ($pr['estado']==='aprobado' || $pr['plan_id']) { flash('error','Este presupuesto ya fue aprobado.'); go('pages/presupuestos.php?accion=ver&id='.$pid); }
        $det=db()->prepare("SELECT * FROM presupuesto_detalles WHERE presupuesto_id=? ORDER BY orden"); $det->execute([$pid]); $det=$det->fetchAll();
        if (!$det) { flash('error','El presupuesto no tiene ítems.'); go('pages/presupuestos.php?accion=ver&id='.$pid); }
        try {
            db()->beginTransaction();
            // Resolver historia clínica (planes_tratamiento.hc_id es obligatorio)
            $hcId=(int)($pr['hc_id']??0);
            if (!$hcId) $hcId=(int)(db()->query("SELECT id FROM historias_clinicas WHERE paciente_id=".(int)$pr['paciente_id']." ORDER BY fecha_apertura DESC,id DESC LIMIT 1")->fetchColumn() ?: 0);
            if (!$hcId) {
                $numHC='HC-'.str_pad((string)$pr['paciente_id'],5,'0',STR_PAD_LEFT).'-'.date('ymd');
                db()->prepare("INSERT INTO historias_clinicas(paciente_id,numero_hc,doctor_id,fecha_apertura,motivo_consulta,estado) VALUES(?,?,?,CURDATE(),?,'activa')")
                  ->execute([$pr['paciente_id'],$numHC,$pr['doctor_id']?:($_SESSION['uid']??null),'Apertura automática al aprobar presupuesto '.$pr['codigo']]);
                $hcId=(int)db()->lastInsertId();
            }
            // Crear plan
            $notasPlan='Generado del presupuesto '.$pr['codigo'];
            if ((float)$pr['descuento_monto']>0) $notasPlan.=' · Descuento aplicado: '.mon((float)$pr['descuento_monto']);
            if (trim((string)$pr['notas'])!=='') $notasPlan.="\n".$pr['notas'];
            db()->prepare("INSERT INTO planes_tratamiento(hc_id,paciente_id,doctor_id,fecha,total,estado,aprobado_at,notas) VALUES(?,?,?,CURDATE(),?,'aprobado',NOW(),?)")
              ->execute([$hcId,$pr['paciente_id'],$pr['doctor_id'],$pr['total'],$notasPlan]);
            $planId=(int)db()->lastInsertId();
            $insPd=db()->prepare("INSERT INTO plan_detalles(plan_id,tratamiento_id,nombre_tratamiento,diente,precio,sesiones_total,estado,orden) VALUES(?,?,?,?,?,1,'pendiente',?)");
            foreach ($det as $i=>$d) {
                $nombre=$d['nombre'].((int)$d['cantidad']>1 ? ' (x'.$d['cantidad'].')' : '');
                $insPd->execute([$planId,$d['tratamiento_id']?:null,$nombre,$d['diente']?:null,$d['subtotal'],$i+1]);
            }
            db()->prepare("UPDATE presupuestos SET estado='aprobado',aprobado_at=NOW(),plan_id=?,hc_id=? WHERE id=?")->execute([$planId,$hcId,$pid]);
            db()->commit();
            auditar('APROBAR_PRESUPUESTO','presupuestos',$pid);
            flash('ok','Presupuesto aprobado. Se generó el Plan de Tratamiento N° '.$planId.'.');
            go('pages/presupuestos.php?accion=ver&id='.$pid);
        } catch (\PDOException $e) {
            if (db()->inTransaction()) db()->rollBack();
            flash('error','No se pudo aprobar: '.$e->getMessage());
            go('pages/presupuestos.php?accion=ver&id='.$pid);
        }
    }

    // ---- Cambiar estado (enviar / rechazar / volver a borrador) ----
    elseif ($ap === 'cambiar_estado') {
        $pid=(int)($_POST['id']??0); $nuevo=$_POST['estado']??'';
        if ($pid && in_array($nuevo,['borrador','enviado','rechazado'],true)) {
            db()->prepare("UPDATE presupuestos SET estado=? WHERE id=? AND estado<>'aprobado'")->execute([$nuevo,$pid]);
            auditar('ESTADO_PRESUPUESTO','presupuestos',$pid,$nuevo);
            flash('ok','Estado actualizado.');
        }
        go('pages/presupuestos.php?accion=ver&id='.$pid);
    }

    // ---- Eliminar (no permitido si está aprobado) ----
    elseif ($ap === 'eliminar') {
        $pid=(int)($_POST['id']??0);
        if ($pid) {
            $st=db()->query("SELECT estado FROM presupuestos WHERE id=$pid")->fetchColumn();
            if ($st==='aprobado') { flash('error','No se puede eliminar un presupuesto aprobado (ya generó un plan).'); go('pages/presupuestos.php'); }
            db()->prepare("DELETE FROM presupuesto_detalles WHERE presupuesto_id=?")->execute([$pid]);
            db()->prepare("DELETE FROM presupuestos WHERE id=?")->execute([$pid]);
            auditar('ELIMINAR_PRESUPUESTO','presupuestos',$pid);
            flash('ok','Presupuesto eliminado.');
        }
        go('pages/presupuestos.php');
    }
    go('pages/presupuestos.php');
}

// ───────────────────────────────── Carga paciente ─────────────────────────────────
$pac=null;
if ($paciente_id) {
    $s=db()->prepare("SELECT * FROM pacientes WHERE id=?"); $s->execute([$paciente_id]); $pac=$s->fetch();
    if (!$pac) { flash('error','Paciente no encontrado.'); go('pages/presupuestos.php'); }
}

// Badge de estado (clase + texto)
$badge = function(string $est): string {
    $map=[
        'borrador' =>['#A0B0C0','rgba(160,176,192,.12)','✎ Borrador'],
        'enviado'  =>['#22d3ee','rgba(0,212,238,.12)','📤 Enviado'],
        'aprobado' =>['#2ECC8E','rgba(46,204,142,.14)','✓ Aprobado'],
        'rechazado'=>['#f87171','rgba(239,68,68,.14)','✗ Rechazado'],
        'vencido'  =>['#fbbf24','rgba(245,158,11,.14)','⌛ Vencido'],
    ];
    [$c,$bgc,$txt]=$map[$est]??['#A0B0C0','rgba(160,176,192,.12)','—'];
    return '<span class="badge" style="background:'.$bgc.';color:'.$c.';border:1px solid '.$c.'40">'.$txt.'</span>';
};
$MON = getCfg('moneda','S/');

// ═════════════════════════════════ LISTA ═════════════════════════════════
if ($accion === 'lista') {
    $esGlobal = !$paciente_id;
    $base="SELECT pr.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac,p.codigo AS cod_pac,CONCAT(u.nombre,' ',u.apellidos) AS doctor
           FROM presupuestos pr JOIN pacientes p ON pr.paciente_id=p.id LEFT JOIN usuarios u ON pr.doctor_id=u.id";
    if ($esGlobal) {
        $lst=db()->query($base." ORDER BY pr.created_at DESC")->fetchAll();
        $titulo='Presupuestos'; $pagina_activa='presup';
        $topbar_act='<a href="'.BASE_URL.'/pages/pacientes.php" class="btn btn-primary"><i class="bi bi-people me-1"></i>Ir a Pacientes</a>';
    } else {
        $q=db()->prepare($base." WHERE pr.paciente_id=? ORDER BY pr.created_at DESC"); $q->execute([$paciente_id]); $lst=$q->fetchAll();
        $titulo='Presupuestos — '.$pac['nombres'].' '.$pac['apellido_paterno']; $pagina_activa='presup';
        $topbar_act='<a href="?accion=nuevo&paciente_id='.$paciente_id.'" class="btn btn-primary"><i class="bi bi-plus me-1"></i>Nuevo presupuesto</a>
        <a href="'.BASE_URL.'/pages/pacientes.php?accion=ver&id='.$paciente_id.'" class="btn btn-dk btn-sm"><i class="bi bi-person me-1"></i>Paciente</a>';
    }
    require_once __DIR__.'/../includes/header.php';
?>
<div class="pb">
<?=popFlash()?>
<?php if(!$esGlobal): ?>
<div class="d-flex align-items-center gap-3 mb-3 p-3" style="background:var(--bg2);border:1px solid var(--bd2);border-radius:10px">
  <div style="width:40px;height:40px;border-radius:50%;background:rgba(0,212,238,.15);border:2px solid rgba(0,212,238,.3);display:flex;align-items:center;justify-content:center;font-size:18px">🧾</div>
  <div>
    <div style="font-weight:700;font-size:15px;color:var(--t)"><?=e($pac['nombres'].' '.$pac['apellido_paterno'])?></div>
    <div style="font-size:12px;color:var(--t2)"><?=e($pac['codigo'])?> &bull; <?=$pac['fecha_nacimiento']?edad($pac['fecha_nacimiento']):'—'?></div>
  </div>
</div>
<?php endif; ?>
<?php if(!$lst): ?>
<div class="card p-5 text-center" style="color:var(--t2)">
  <i class="bi bi-receipt" style="font-size:36px;display:block;margin-bottom:10px"></i>
  <?php if($esGlobal): ?>
    No hay presupuestos registrados.<br><span style="font-size:13px">Abre un paciente desde <strong>Pacientes</strong> y crea uno con el botón “Presupuestos”.</span>
  <?php else: ?>
    No hay presupuestos para este paciente.
  <?php endif; ?>
</div>
<?php else: ?>
<div class="card"><div class="table-responsive"><table class="table mb-0">
  <thead><tr><th>Código</th><?php if($esGlobal):?><th>Paciente</th><?php endif;?><th>Fecha</th><th>Vence</th><th>Total</th><th>Estado</th><th></th></tr></thead>
  <tbody>
  <?php foreach($lst as $pr): $pid=(int)$pr['paciente_id']; ?>
  <tr>
    <td><strong style="color:var(--c)"><?=e($pr['codigo']??('#'.$pr['id']))?></strong></td>
    <?php if($esGlobal):?><td style="color:var(--t)"><a href="?paciente_id=<?=$pid?>" style="color:var(--c);text-decoration:none"><?=e($pr['pac'])?></a><br><small style="color:var(--t3)"><?=e($pr['cod_pac']??'')?></small></td><?php endif;?>
    <td style="color:var(--t2)"><?=fDate($pr['fecha'])?></td>
    <td style="color:var(--t2)"><?=fDate($pr['fecha_vencimiento'])?></td>
    <td><span class="mon" style="color:var(--t);font-weight:700"><?=mon((float)$pr['total'])?></span></td>
    <td><?=$badge($pr['estado'])?></td>
    <td><div class="d-flex gap-1">
      <a href="?accion=ver&id=<?=$pr['id']?>&paciente_id=<?=$pid?>" class="btn btn-dk btn-ico btn-sm" title="Ver"><i class="bi bi-eye"></i></a>
      <?php if($pr['estado']!=='aprobado'): ?>
      <a href="?accion=editar&id=<?=$pr['id']?>&paciente_id=<?=$pid?>" class="btn btn-dk btn-ico btn-sm" title="Editar"><i class="bi bi-pencil"></i></a>
      <?php endif; ?>
      <a href="?accion=pdf&id=<?=$pr['id']?>" class="btn btn-ico btn-sm" style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#ef4444" target="_blank" title="PDF"><i class="bi bi-filetype-pdf"></i></a>
      <?php if($pr['estado']!=='aprobado'): ?>
      <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este presupuesto?')">
        <input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?=$pr['id']?>">
        <button class="btn btn-del btn-ico btn-sm" title="Eliminar"><i class="bi bi-trash"></i></button>
      </form>
      <?php endif; ?>
    </div></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php endif; ?>
</div>
<?php
    require_once __DIR__.'/../includes/footer.php';

// ═════════════════════════════════ NUEVO / EDITAR ═════════════════════════════════
} elseif (in_array($accion,['nuevo','editar'])) {
    $pr=['id'=>0,'paciente_id'=>$paciente_id,'hc_id'=>0,'doctor_id'=>$_SESSION['uid']??0,'fecha'=>date('Y-m-d'),'validez_dias'=>15,'descuento_tipo'=>'monto','descuento_valor'=>0,'subtotal'=>0,'descuento_monto'=>0,'total'=>0,'notas'=>'','condiciones'=>'Precios sujetos a evaluación clínica. Validez del presupuesto según fecha indicada.','estado'=>'borrador'];
    $det=[];
    if ($accion==='editar' && $id) {
        $s=db()->prepare("SELECT * FROM presupuestos WHERE id=?"); $s->execute([$id]); $row=$s->fetch();
        if (!$row) { flash('error','Presupuesto no encontrado.'); go('pages/presupuestos.php'); }
        if ($row['estado']==='aprobado') { flash('error','Un presupuesto aprobado no se puede editar.'); go('pages/presupuestos.php?accion=ver&id='.$id); }
        $pr=$row; $paciente_id=(int)$pr['paciente_id'];
        $s=db()->prepare("SELECT * FROM pacientes WHERE id=?"); $s->execute([$paciente_id]); $pac=$s->fetch();
        $d=db()->prepare("SELECT * FROM presupuesto_detalles WHERE presupuesto_id=? ORDER BY orden"); $d->execute([$id]); $det=$d->fetchAll();
    }
    if (!$paciente_id || !$pac) { flash('error','Selecciona un paciente desde su ficha para crear un presupuesto.'); go('pages/pacientes.php'); }

    $trats=db()->query("SELECT t.id,t.nombre,t.precio_base,c.nombre AS cat FROM tratamientos_catalogo t LEFT JOIN categorias_tratamiento c ON t.categoria_id=c.id WHERE t.activo=1 ORDER BY c.nombre,t.nombre")->fetchAll();
    $docs =db()->query("SELECT id,CONCAT(nombre,' ',apellidos) AS nm FROM usuarios WHERE activo=1 AND rol_id IN (1,2) ORDER BY nombre")->fetchAll();
    $hcs  =db()->prepare("SELECT id,numero_hc FROM historias_clinicas WHERE paciente_id=? ORDER BY fecha_apertura DESC"); $hcs->execute([$paciente_id]); $hcs=$hcs->fetchAll();

    $titulo=($accion==='nuevo'?'Nuevo presupuesto — ':'Editar presupuesto — ').$pac['nombres'].' '.$pac['apellido_paterno'];
    $pagina_activa='presup';
    require_once __DIR__.'/../includes/header.php';
?>
<form method="POST" id="fPres">
 <input type="hidden" name="accion" value="guardar">
 <input type="hidden" name="id" value="<?=$pr['id']?>">
 <input type="hidden" name="paciente_id" value="<?=$paciente_id?>">
<div class="row g-4">
 <div class="col-12 col-lg-8">
  <div class="card mb-4">
   <div class="card-header d-flex justify-content-between align-items-center">
     <span style="color:var(--t)">🧾 Ítems del presupuesto</span>
     <button type="button" class="btn btn-primary btn-sm" onclick="addRow()">+ Agregar ítem</button>
   </div>
   <div class="p-4">
    <div class="table-responsive"><table class="table mb-0" id="tbl">
     <thead><tr><th>#</th><th>Tratamiento / Descripción</th><th>Diente</th><th>Cant.</th><th>P. Unit <?=e($MON)?></th><th>Subtotal</th><th></th></tr></thead>
     <tbody id="tb">
     <?php if($det): foreach($det as $i=>$d): ?>
     <tr>
      <td class="rownum"><?=$i+1?></td>
      <td><input type="hidden" name="it_id[]" value="<?=$d['tratamiento_id']??''?>"><input type="text" name="it_nombre[]" class="form-control form-control-sm ac-nombre" autocomplete="off" value="<?=e($d['nombre'])?>" required></td>
      <td><input type="text" name="it_diente[]" class="form-control form-control-sm" value="<?=e($d['diente']??'')?>" style="width:64px" placeholder="11"></td>
      <td><input type="number" name="it_cant[]" class="form-control form-control-sm cant-inp" value="<?=(int)$d['cantidad']?>" min="1" style="width:64px" oninput="calc()"></td>
      <td><input type="number" name="it_precio[]" class="form-control form-control-sm precio-inp" value="<?=$d['precio_unit']?>" step="0.01" min="0" style="width:90px" oninput="calc()"></td>
      <td class="sub-cell" style="white-space:nowrap;color:var(--t)"></td>
      <td><button type="button" class="btn btn-del btn-ico btn-sm" onclick="this.closest('tr').remove();renum();calc()"><i class="bi bi-trash"></i></button></td>
     </tr>
     <?php endforeach; else: ?>
     <tr id="emptyRow"><td colspan="7" class="text-center py-3" style="color:var(--t2)">Agrega ítems del catálogo (derecha) o con “+ Agregar ítem”.</td></tr>
     <?php endif; ?>
     </tbody>
    </table></div>

    <!-- Totales -->
    <div class="mt-3 p-3" style="background:var(--bg3);border-radius:8px;border:1px solid var(--bd)">
     <div class="d-flex justify-content-between align-items-center mb-2">
       <span style="color:var(--t2)">Subtotal</span>
       <span class="mon" id="subLbl" style="color:var(--t);font-weight:600">—</span>
     </div>
     <div class="d-flex justify-content-between align-items-center mb-2 gap-2">
       <span style="color:var(--t2)">Descuento</span>
       <div class="d-flex gap-2 align-items-center">
         <select name="descuento_tipo" id="dTipo" class="form-control form-control-sm" style="width:auto" onchange="calc()">
           <option value="monto" <?=$pr['descuento_tipo']==='monto'?'selected':''?>>Monto <?=e($MON)?></option>
           <option value="porcentaje" <?=$pr['descuento_tipo']==='porcentaje'?'selected':''?>>%</option>
         </select>
         <input type="number" name="descuento_valor" id="dVal" class="form-control form-control-sm" value="<?=$pr['descuento_valor']?>" step="0.01" min="0" style="width:90px" oninput="calc()">
         <span class="mon" id="descLbl" style="color:#f59e0b;min-width:80px;text-align:right">—</span>
       </div>
     </div>
     <div class="d-flex justify-content-between align-items-center pt-2" style="border-top:1px solid var(--bd)">
       <span style="font-size:15px;color:var(--t)">TOTAL</span>
       <span class="mon fw-bold" id="totLbl" style="font-size:24px;color:var(--c)">—</span>
     </div>
    </div>
   </div>
  </div>

  <div class="card mb-4"><div class="card-header"><span style="color:var(--t)">📝 Notas / Condiciones</span></div>
   <div class="p-4">
     <label class="form-label">Notas internas</label>
     <textarea name="notas" class="form-control mb-3" rows="2"><?=e($pr['notas']??'')?></textarea>
     <label class="form-label">Condiciones (se imprimen en el presupuesto)</label>
     <textarea name="condiciones" class="form-control" rows="2"><?=e($pr['condiciones']??'')?></textarea>
   </div>
  </div>

  <div class="d-flex gap-2 justify-content-end">
   <a href="?paciente_id=<?=$paciente_id?>" class="btn btn-dk">Cancelar</a>
   <button type="submit" class="btn btn-primary px-4"><i class="bi bi-floppy me-2"></i>Guardar presupuesto</button>
  </div>
 </div>

 <!-- Columna derecha: datos + catálogo -->
 <div class="col-12 col-lg-4">
  <div class="card mb-4">
   <div class="card-header"><span style="color:var(--t)">Datos</span></div>
   <div class="p-4">
     <label class="form-label">Fecha</label>
     <input type="date" name="fecha" class="form-control mb-3" value="<?=e($pr['fecha'])?>">
     <label class="form-label">Validez (días)</label>
     <input type="number" name="validez_dias" class="form-control mb-3" value="<?=(int)$pr['validez_dias']?>" min="0">
     <label class="form-label">Doctor</label>
     <select name="doctor_id" class="form-control mb-3">
       <option value="">— Sin asignar —</option>
       <?php foreach($docs as $d): ?><option value="<?=$d['id']?>" <?=(int)$pr['doctor_id']===(int)$d['id']?'selected':''?>><?=e($d['nm'])?></option><?php endforeach; ?>
     </select>
     <label class="form-label">Historia Clínica (opcional)</label>
     <select name="hc_id" class="form-control">
       <option value="">— Ninguna —</option>
       <?php foreach($hcs as $h): ?><option value="<?=$h['id']?>" <?=(int)$pr['hc_id']===(int)$h['id']?'selected':''?>><?=e($h['numero_hc'])?></option><?php endforeach; ?>
     </select>
   </div>
  </div>
  <div class="card" style="position:sticky;top:70px">
   <div class="card-header"><span style="color:var(--t)">📚 Catálogo rápido</span></div>
   <div style="max-height:460px;overflow-y:auto">
    <?php if(!$trats): ?><div class="p-3" style="color:var(--t2);font-size:12px">No hay tratamientos en el catálogo.</div><?php endif; ?>
    <?php $cc=''; foreach($trats as $t): if($cc!==$t['cat']){$cc=$t['cat']; echo '<div class="sb-sec">'.e($cc?:'Sin categoría').'</div>';} ?>
    <div class="d-flex justify-content-between align-items-center px-3 py-2" style="border-bottom:1px solid var(--bd2);cursor:pointer" onclick='addCat(<?=e(json_encode(["id"=>$t["id"],"nombre"=>$t["nombre"],"precio"=>(float)$t["precio_base"]]))?>)'>
     <span style="font-size:12px"><?=e($t['nombre'])?></span>
     <span class="mon" style="color:var(--c);font-size:11px"><?=mon((float)$t['precio_base'])?></span>
    </div>
    <?php endforeach; ?>
   </div>
  </div>
 </div>
</div>
</form>
<?php
$xscript='<script>
const MON='.json_encode($MON).';
window.MON=MON;
window.CATALOGO='.json_encode(array_map(fn($t)=>['id'=>(int)$t['id'],'nombre'=>$t['nombre'],'precio'=>(float)$t['precio_base'],'cat'=>$t['cat']],$trats)).';
let n='.($det?count($det):0).';
function fmt(v){return MON+" "+(parseFloat(v)||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,",");}
function addCat(t){addRow(t.id,t.nombre,t.precio);}
function addRow(tid="",nm="",px=0){
 const e=document.getElementById("emptyRow"); if(e)e.remove();
 const tr=document.createElement("tr");
 tr.innerHTML=`<td class="rownum"></td>
  <td><input type="hidden" name="it_id[]" value="${tid}"><input type="text" name="it_nombre[]" class="form-control form-control-sm ac-nombre" autocomplete="off" value="${nm.replace(/"/g,"&quot;")}" required></td>
  <td><input type="text" name="it_diente[]" class="form-control form-control-sm" style="width:64px" placeholder="11"></td>
  <td><input type="number" name="it_cant[]" class="form-control form-control-sm cant-inp" value="1" min="1" style="width:64px" oninput="calc()"></td>
  <td><input type="number" name="it_precio[]" class="form-control form-control-sm precio-inp" value="${px}" step="0.01" min="0" style="width:90px" oninput="calc()"></td>
  <td class="sub-cell" style="white-space:nowrap;color:var(--t)"></td>
  <td><button type="button" class="btn btn-del btn-ico btn-sm" onclick="this.closest(\'tr\').remove();renum();calc()"><i class="bi bi-trash"></i></button></td>`;
 document.getElementById("tb").appendChild(tr); renum(); calc();
}
function renum(){document.querySelectorAll("#tb tr").forEach((tr,i)=>{const c=tr.querySelector(".rownum"); if(c)c.textContent=i+1;});}
function calc(){
 let sub=0;
 document.querySelectorAll("#tb tr").forEach(tr=>{
   const c=tr.querySelector(".cant-inp"), p=tr.querySelector(".precio-inp"), s=tr.querySelector(".sub-cell");
   if(!c||!p)return;
   const v=(parseFloat(c.value)||0)*(parseFloat(p.value)||0);
   if(s)s.textContent=fmt(v); sub+=v;
 });
 const tipo=document.getElementById("dTipo").value, val=parseFloat(document.getElementById("dVal").value)||0;
 let desc = tipo==="porcentaje" ? sub*Math.min(Math.max(val,0),100)/100 : Math.min(Math.max(val,0),sub);
 desc=Math.round(desc*100)/100;
 document.getElementById("subLbl").textContent=fmt(sub);
 document.getElementById("descLbl").textContent="- "+fmt(desc);
 document.getElementById("totLbl").textContent=fmt(sub-desc);
}
calc();
</script>
<script src="'.BASE_URL.'/assets/js/cat-autocomplete.js"></script>';
require_once __DIR__.'/../includes/footer.php';

// ═════════════════════════════════ VER (detalle) ═════════════════════════════════
} elseif ($accion==='ver' && $id) {
    $pr=db()->prepare("SELECT pr.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac,p.codigo AS cod_pac,p.fecha_nacimiento,CONCAT(u.nombre,' ',u.apellidos) AS doctor,u.cmp FROM presupuestos pr JOIN pacientes p ON pr.paciente_id=p.id LEFT JOIN usuarios u ON pr.doctor_id=u.id WHERE pr.id=?");
    $pr->execute([$id]); $pr=$pr->fetch();
    if (!$pr) { flash('error','Presupuesto no encontrado.'); go('pages/presupuestos.php'); }
    $det=db()->prepare("SELECT * FROM presupuesto_detalles WHERE presupuesto_id=? ORDER BY orden"); $det->execute([$id]); $det=$det->fetchAll();
    $paciente_id=(int)$pr['paciente_id'];
    $titulo='Presupuesto '.($pr['codigo']??('#'.$pr['id'])); $pagina_activa='presup';
    $topbar_act='<a href="?paciente_id='.$paciente_id.'" class="btn btn-dk btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver</a>
    <a href="?accion=pdf&id='.$id.'" target="_blank" class="btn btn-primary btn-sm"><i class="bi bi-filetype-pdf me-1"></i>PDF</a>';
    if ($pr['estado']!=='aprobado') {
        $topbar_act='<a href="?accion=editar&id='.$id.'&paciente_id='.$paciente_id.'" class="btn btn-dk btn-sm"><i class="bi bi-pencil me-1"></i>Editar</a> '.$topbar_act;
    }
    require_once __DIR__.'/../includes/header.php';
?>
<div class="pb">
<?=popFlash()?>
<div class="row g-4">
 <div class="col-12 col-lg-8">
  <div class="card">
   <div class="card-header d-flex justify-content-between align-items-center">
     <span style="color:var(--t)"><strong style="color:var(--c)"><?=e($pr['codigo']??('#'.$pr['id']))?></strong> &nbsp; <?=$badge($pr['estado'])?></span>
     <span style="color:var(--t2);font-size:12px">Fecha: <?=fDate($pr['fecha'])?> · Vence: <?=fDate($pr['fecha_vencimiento'])?></span>
   </div>
   <div class="p-4">
     <div class="table-responsive"><table class="table mb-0">
       <thead><tr><th>#</th><th>Descripción</th><th>Diente</th><th>Cant.</th><th>P. Unit</th><th class="text-end">Subtotal</th></tr></thead>
       <tbody>
       <?php foreach($det as $i=>$d): ?>
       <tr>
         <td style="color:var(--t2)"><?=$i+1?></td>
         <td style="color:var(--t)"><?=e($d['nombre'])?></td>
         <td style="color:var(--t2)"><?=e($d['diente']?:'—')?></td>
         <td style="color:var(--t2)"><?=(int)$d['cantidad']?></td>
         <td class="mon" style="color:var(--t2)"><?=mon((float)$d['precio_unit'])?></td>
         <td class="mon text-end" style="color:var(--t)"><?=mon((float)$d['subtotal'])?></td>
       </tr>
       <?php endforeach; ?>
       </tbody>
     </table></div>
     <div class="mt-3 p-3" style="background:var(--bg3);border-radius:8px;border:1px solid var(--bd)">
       <div class="d-flex justify-content-between mb-1"><span style="color:var(--t2)">Subtotal</span><span class="mon" style="color:var(--t)"><?=mon((float)$pr['subtotal'])?></span></div>
       <div class="d-flex justify-content-between mb-1"><span style="color:var(--t2)">Descuento <?=$pr['descuento_tipo']==='porcentaje'?'('.rtrim(rtrim(number_format((float)$pr['descuento_valor'],2),'0'),'.').'%)':''?></span><span class="mon" style="color:#f59e0b">- <?=mon((float)$pr['descuento_monto'])?></span></div>
       <div class="d-flex justify-content-between pt-2" style="border-top:1px solid var(--bd)"><span style="color:var(--t);font-size:15px">TOTAL</span><span class="mon fw-bold" style="color:var(--c);font-size:20px"><?=mon((float)$pr['total'])?></span></div>
     </div>
     <?php if(trim((string)$pr['condiciones'])!==''): ?><div class="mt-3" style="color:var(--t2);font-size:12px"><strong style="color:var(--t)">Condiciones:</strong> <?=nl2br(e($pr['condiciones']))?></div><?php endif; ?>
     <?php if(trim((string)$pr['notas'])!==''): ?><div class="mt-2" style="color:var(--t2);font-size:12px"><strong style="color:var(--t)">Notas:</strong> <?=nl2br(e($pr['notas']))?></div><?php endif; ?>
   </div>
  </div>
 </div>
 <div class="col-12 col-lg-4">
  <div class="card mb-4"><div class="card-header"><span style="color:var(--t)">Paciente</span></div>
   <div class="p-4">
     <div style="font-weight:700;color:var(--t)"><?=e($pr['pac'])?></div>
     <div style="color:var(--t2);font-size:12px"><?=e($pr['cod_pac']??'')?> &bull; <?=$pr['fecha_nacimiento']?edad($pr['fecha_nacimiento']):'—'?></div>
     <div style="color:var(--t2);font-size:12px;margin-top:8px">Doctor: <?=e($pr['doctor']?:'—')?></div>
   </div>
  </div>
  <div class="card"><div class="card-header"><span style="color:var(--t)">Acciones</span></div>
   <div class="p-4 d-grid gap-2">
     <?php if($pr['estado']==='aprobado'): ?>
       <div style="color:#2ECC8E;font-size:13px"><i class="bi bi-check-circle me-1"></i>Aprobado el <?=fDate($pr['aprobado_at'])?>.</div>
       <?php if($pr['plan_id']): ?>
       <a href="<?=BASE_URL?>/pages/tratamientos.php" class="btn btn-dk btn-sm"><i class="bi bi-clipboard2-pulse me-1"></i>Ver Plan de Tratamiento N° <?=$pr['plan_id']?></a>
       <?php endif; ?>
     <?php else: ?>
       <form method="POST" onsubmit="return confirm('Aprobar este presupuesto y generar el Plan de Tratamiento?')">
         <input type="hidden" name="accion" value="aprobar"><input type="hidden" name="id" value="<?=$id?>">
         <button class="btn btn-primary w-100"><i class="bi bi-check2-circle me-1"></i>Aprobar y generar plan</button>
       </form>
       <div class="d-flex gap-2">
         <form method="POST" class="flex-fill"><input type="hidden" name="accion" value="cambiar_estado"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="estado" value="enviado"><button class="btn btn-dk btn-sm w-100"><i class="bi bi-send me-1"></i>Enviado</button></form>
         <form method="POST" class="flex-fill" onsubmit="return confirm('¿Marcar como rechazado?')"><input type="hidden" name="accion" value="cambiar_estado"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="estado" value="rechazado"><button class="btn btn-del btn-sm w-100"><i class="bi bi-x-circle me-1"></i>Rechazar</button></form>
       </div>
     <?php endif; ?>
   </div>
  </div>
 </div>
</div>
</div>
<?php
    require_once __DIR__.'/../includes/footer.php';

// ═════════════════════════════════ PDF (imprimible) ═════════════════════════════════
} elseif ($accion==='pdf' && $id) {
    $pr=db()->prepare("SELECT pr.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac,p.codigo AS cod_pac,p.dni,p.fecha_nacimiento,CONCAT(u.nombre,' ',u.apellidos) AS doctor,u.cmp,u.firma_imagen FROM presupuestos pr JOIN pacientes p ON pr.paciente_id=p.id LEFT JOIN usuarios u ON pr.doctor_id=u.id WHERE pr.id=?");
    $pr->execute([$id]); $pr=$pr->fetch();
    if (!$pr) { die('Presupuesto no encontrado'); }
    $det=db()->prepare("SELECT * FROM presupuesto_detalles WHERE presupuesto_id=? ORDER BY orden"); $det->execute([$id]); $det=$det->fetchAll();

    $emp=empresa(); $logoRel=$emp['logo']??''; $logoUrl=$logoRel?BASE_URL.'/uploads/'.ltrim($logoRel,'/'):'';
    $clinica=$emp['nombre_comercial']?:($emp['razon_social']?:getCfg('clinica_nombre','Clínica Odontológica'));
    $firmaUrl=!empty($pr['firma_imagen'])?BASE_URL.'/uploads/'.ltrim($pr['firma_imagen'],'/'):'';
    $estTxt=['borrador'=>'BORRADOR','enviado'=>'ENVIADO','aprobado'=>'APROBADO','rechazado'=>'RECHAZADO','vencido'=>'VENCIDO'][$pr['estado']]??'';
    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<title>Presupuesto <?=e($pr['codigo']??$pr['id'])?> — <?=e($pr['pac'])?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#111;background:#fff;padding:22px}
.page{max-width:780px;margin:0 auto}
.header{display:flex;align-items:center;gap:14px;border-bottom:2px solid #0a8aa0;padding-bottom:10px;margin-bottom:10px}
.header .logo{min-width:90px;display:flex;align-items:center;justify-content:center}
.header .logo img{max-height:74px;max-width:170px}
.header .ident{flex:1;text-align:center}
.header .ident .name{font-size:18px;font-weight:800;color:#0a8aa0;line-height:1.1}
.header .ident .sub{font-size:11px;font-weight:700;letter-spacing:3px;color:#333;margin-top:2px}
.header .meta{min-width:120px;text-align:right;font-size:8.5px;color:#666}
.title-row{display:flex;justify-content:space-between;align-items:center;margin:6px 0 12px}
.doc-title{font-size:16px;font-weight:800;letter-spacing:.5px}
.code{font-size:13px;font-weight:700;color:#0a8aa0}
.tag{display:inline-block;border:1px solid #999;border-radius:4px;padding:2px 8px;font-size:9px;font-weight:700;color:#555}
.box{border:1px solid #ddd;border-radius:6px;padding:10px 12px;margin-bottom:12px;font-size:10.5px}
.grid2{display:flex;gap:24px}
.grid2 > div{flex:1}
.muted{color:#777}
table.items{width:100%;border-collapse:collapse;margin-bottom:10px;font-size:10.5px}
table.items th{background:#eef5f7;border:1px solid #b9c9cf;padding:6px;text-align:left;font-size:9.5px}
table.items td{border:1px solid #d2d2d2;padding:6px}
.r{text-align:right}.c{text-align:center}
.totals{margin-left:auto;width:280px;font-size:11px}
.totals .row{display:flex;justify-content:space-between;padding:3px 0}
.totals .grand{border-top:2px solid #0a8aa0;margin-top:4px;padding-top:6px;font-size:15px;font-weight:800;color:#0a8aa0}
.cond{font-size:9.5px;color:#555;margin-top:14px;border-top:1px dashed #ccc;padding-top:8px}
.sign{margin-top:34px;text-align:center}
.sign img{max-height:54px;max-width:200px;display:block;margin:0 auto 2px}
.sign .ln{border-top:1px solid #111;display:inline-block;min-width:260px;padding-top:4px;font-size:10px}
@media print{body{padding:6px}.no-print{display:none}}
</style></head><body><div class="page">

<div class="header">
  <div class="logo"><?php if($logoUrl):?><img src="<?=e($logoUrl)?>" alt="Logo"><?php else:?><div style="font-size:24px;font-weight:800;color:#0a8aa0">🦷</div><?php endif;?></div>
  <div class="ident"><div class="name"><?=e($clinica)?></div><div class="sub">CONSULTORIO ODONTOL&Oacute;GICO</div></div>
  <div class="meta"><?php if(!empty($emp['ruc'])):?>RUC: <?=e($emp['ruc'])?><br><?php endif;?><?php if(!empty($emp['telefono'])):?>Tel: <?=e($emp['telefono'])?><br><?php endif;?><?php if(!empty($emp['direccion'])):?><?=e($emp['direccion'])?><br><?php endif;?>Impreso: <?=date('d/m/Y')?></div>
</div>

<div class="title-row">
  <div class="doc-title">PRESUPUESTO <?php if($estTxt && $pr['estado']!=='aprobado'):?><span class="tag"><?=$estTxt?></span><?php endif;?></div>
  <div class="code"><?=e($pr['codigo']??('#'.$pr['id']))?></div>
</div>

<div class="box grid2">
  <div>
    <strong>Paciente:</strong> <?=e($pr['pac'])?><br>
    <?php if(!empty($pr['dni'])):?><span class="muted">DNI:</span> <?=e($pr['dni'])?> &nbsp; <?php endif;?>
    <span class="muted">Edad:</span> <?=$pr['fecha_nacimiento']?e(edad($pr['fecha_nacimiento'])):'—'?><br>
    <span class="muted">Código HC/Paciente:</span> <?=e($pr['cod_pac']??'—')?>
  </div>
  <div class="r">
    <span class="muted">Fecha:</span> <?=fDate($pr['fecha'])?><br>
    <span class="muted">Válido hasta:</span> <?=fDate($pr['fecha_vencimiento'])?><br>
    <span class="muted">Doctor(a):</span> <?=e($pr['doctor']?:'—')?>
  </div>
</div>

<table class="items">
  <thead><tr><th style="width:26px">#</th><th>Descripción</th><th style="width:50px" class="c">Diente</th><th style="width:46px" class="c">Cant.</th><th style="width:90px" class="r">P. Unit</th><th style="width:100px" class="r">Subtotal</th></tr></thead>
  <tbody>
  <?php foreach($det as $i=>$d): ?>
   <tr><td class="c"><?=$i+1?></td><td><?=e($d['nombre'])?></td><td class="c"><?=e($d['diente']?:'—')?></td><td class="c"><?=(int)$d['cantidad']?></td><td class="r"><?=mon((float)$d['precio_unit'])?></td><td class="r"><?=mon((float)$d['subtotal'])?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>

<div class="totals">
  <div class="row"><span class="muted">Subtotal</span><span><?=mon((float)$pr['subtotal'])?></span></div>
  <div class="row"><span class="muted">Descuento<?=$pr['descuento_tipo']==='porcentaje'?' ('.rtrim(rtrim(number_format((float)$pr['descuento_valor'],2),'0'),'.').'%)':''?></span><span>- <?=mon((float)$pr['descuento_monto'])?></span></div>
  <div class="row grand"><span>TOTAL</span><span><?=mon((float)$pr['total'])?></span></div>
</div>

<?php if(trim((string)$pr['condiciones'])!==''): ?>
<div class="cond"><strong>Condiciones:</strong> <?=nl2br(e($pr['condiciones']))?></div>
<?php endif; ?>

<div class="sign">
  <?php if($firmaUrl):?><img src="<?=e($firmaUrl)?>" alt="Firma"><?php endif;?>
  <div class="ln">Odont&oacute;logo(a): <?=e($pr['doctor']?:'')?></div>
</div>

<div class="no-print" style="text-align:center;margin-top:22px">
  <button onclick="window.print()" style="padding:10px 28px;background:#111A26;color:#00D4EE;border:1px solid #00D4EE;border-radius:6px;font-weight:700;cursor:pointer">🖨️ Imprimir / Guardar PDF</button>
  <button onclick="history.back()" style="margin-left:10px;padding:10px 20px;background:#1E2D40;color:#A0B0C0;border:1px solid #334155;border-radius:6px;cursor:pointer">← Volver</button>
</div>
</div></body></html>
<?php
    exit;

} else {
    flash('error','Acción no válida.');
    go('pages/presupuestos.php');
}
