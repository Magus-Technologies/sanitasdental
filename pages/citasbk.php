<?php
require_once __DIR__.'/../includes/config.php';
requiereLogin();
$accion=$_GET['accion']??'agenda'; $id=(int)($_GET['id']??0);

if($_SERVER['REQUEST_METHOD']==='POST'){
 $ap=$_POST['accion']??'';
 if($ap==='guardar'){
  $ei=(int)($_POST['id']??0);
  $d=['paciente_id'=>(int)$_POST['paciente_id'],'doctor_id'=>(int)$_POST['doctor_id'],'sillon_id'=>$_POST['sillon_id']?:null,'fecha'=>$_POST['fecha'],'hora_inicio'=>$_POST['hora_inicio'],'hora_fin'=>$_POST['hora_fin'],'tipo'=>$_POST['tipo']??'primera_vez','especialidad'=>trim($_POST['especialidad']??''),'motivo'=>trim($_POST['motivo']??''),'notas'=>trim($_POST['notas']??'')];
  if($ei){$sets=implode(',',array_map(fn($k)=>"$k=?",array_keys($d)));db()->prepare("UPDATE citas SET $sets,updated_at=NOW() WHERE id=?")->execute([...array_values($d),$ei]);flash('ok','Cita actualizada.');go("pages/citas.php?accion=ver&id=$ei");}
  else{$cod=genCodigo('CIT','citas');$d['codigo']=$cod;$d['estado']='pendiente';$d['created_by']=$_SESSION['uid'];
  $cols=implode(',',array_keys($d));$phs=implode(',',array_fill(0,count($d),'?'));
  db()->prepare("INSERT INTO citas($cols)VALUES($phs)")->execute(array_values($d));
  $nid=db()->lastInsertId(); auditar('CREAR_CITA','citas',$nid);
  // registrar turno
  try{$nt=db()->query("SELECT COALESCE(MAX(numero),0)+1 FROM turnos WHERE DATE(created_at)=CURDATE()")->fetchColumn();
  $nm_pac=db()->query("SELECT CONCAT(nombres,' ',apellido_paterno) FROM pacientes WHERE id=".(int)$_POST['paciente_id'])->fetchColumn();
  db()->prepare("INSERT INTO turnos(cita_id,numero,nombre_mostrar)VALUES(?,?,?)")->execute([$nid,$nt,$nm_pac]);}catch(Exception $e){}
  flash('ok',"Cita agendada: $cod"); go("pages/citas.php?accion=ver&id=$nid");}
 }
 if($ap==='estado'){
  $cid=(int)$_POST['cid']; $est=$_POST['est'];
  db()->prepare("UPDATE citas SET estado=?,updated_at=NOW() WHERE id=?")->execute([$est,$cid]);
  if($est==='en_atencion'){try{db()->prepare("UPDATE turnos SET estado='en_atencion' WHERE cita_id=?")->execute([$cid]);}catch(Exception $e){}}
  if($est==='atendido'){try{db()->prepare("UPDATE turnos SET estado='atendido' WHERE cita_id=?")->execute([$cid]);}catch(Exception $e){}}
  auditar('ESTADO_CITA','citas',$cid,$est); flash('ok','Estado actualizado.'); go("pages/citas.php?accion=ver&id=$cid");
 }
}

$docs=db()->query("SELECT id,nombre,apellidos,especialidad FROM usuarios WHERE rol_id=2 AND activo=1 ORDER BY nombre")->fetchAll();
$sills=db()->query("SELECT * FROM sillones WHERE activo=1 ORDER BY numero")->fetchAll();
$pacs_sel=db()->query("SELECT id,codigo,nombres,apellido_paterno,telefono FROM pacientes WHERE activo=1 ORDER BY apellido_paterno LIMIT 500")->fetchAll();
$ec=['pendiente'=>'ba','confirmado'=>'bc','en_atencion'=>'bb','atendido'=>'bg','no_asistio'=>'br','cancelado'=>'bgr'];
$el=['pendiente'=>'Pendiente','confirmado'=>'Confirmado','en_atencion'=>'En atención','atendido'=>'Atendido','no_asistio'=>'No asistió','cancelado'=>'Cancelado'];

if($accion==='agenda'){
 $titulo='Agenda de Citas'; $pagina_activa='citas';
 $fsel=$_GET['fecha']??date('Y-m-d'); $dsel=(int)($_GET['doc']??0);
 $w='WHERE c.fecha=?'; $pm=[$fsel];
 if($dsel){$w.=' AND c.doctor_id=?';$pm[]=$dsel;}
 $list=db()->prepare("SELECT c.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac,CONCAT(u.nombre,' ',u.apellidos) AS dr,s.nombre AS sill FROM citas c JOIN pacientes p ON c.paciente_id=p.id JOIN usuarios u ON c.doctor_id=u.id LEFT JOIN sillones s ON c.sillon_id=s.id $w ORDER BY c.hora_inicio");
 $list->execute($pm); $list=$list->fetchAll();
 $topbar_act='<a href="?accion=nueva" class="btn btn-primary"><i class="bi bi-calendar-plus me-1"></i>Nueva cita</a>';
 require_once __DIR__.'/../includes/header.php';
?>
<form method="GET" class="card mb-4 p-3">
 <input type="hidden" name="accion" value="agenda">
 <div class="row g-2 align-items-end gy-2">
  <div class="col-12 col-sm-4"><label class="form-label">Fecha</label><input type="date" name="fecha" class="form-control" value="<?=$fsel?>"></div>
  <div class="col-12 col-sm-4"><label class="form-label">Doctor</label>
  <select name="doc" class="form-select"><option value="">Todos</option>
  <?php foreach($docs as $d): ?><option value="<?=$d['id']?>" <?=$dsel==$d['id']?'selected':''?>><?=e($d['nombre'].' '.$d['apellidos'])?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-sm-2"><button type="submit" class="btn btn-dk w-100">Ver</button></div>
  <div class="col-6 col-sm-2"><div class="d-flex gap-1">
   <a href="?accion=agenda&fecha=<?=date('Y-m-d',strtotime($fsel.' -1 day'))?>" class="btn btn-dk flex-fill">‹</a>
   <a href="?accion=agenda&fecha=<?=date('Y-m-d')?>" class="btn btn-dk flex-fill" title="Hoy">•</a>
   <a href="?accion=agenda&fecha=<?=date('Y-m-d',strtotime($fsel.' +1 day'))?>" class="btn btn-dk flex-fill">›</a>
  </div></div>
 </div>
</form>
<?php $cnt=array_count_values(array_column($list,'estado')); ?>
<div class="row g-2 mb-4">
 <div class="col"><div class="kpi kc p-3 text-center"><div class="kpi-v"><?=count($list)?></div><div class="kpi-l">Total</div><div class="kpi-s"></div></div></div>
 <div class="col"><div class="kpi ka p-3 text-center"><div class="kpi-v"><?=$cnt['pendiente']??0?></div><div class="kpi-l">Pendiente</div><div class="kpi-s"></div></div></div>
 <div class="col"><div class="kpi kb p-3 text-center"><div class="kpi-v"><?=$cnt['en_atencion']??0?></div><div class="kpi-l">En atención</div><div class="kpi-s"></div></div></div>
 <div class="col"><div class="kpi kg p-3 text-center"><div class="kpi-v"><?=$cnt['atendido']??0?></div><div class="kpi-l">Atendido</div><div class="kpi-s"></div></div></div>
 <div class="col"><div class="kpi kr p-3 text-center"><div class="kpi-v"><?=$cnt['no_asistio']??0?></div><div class="kpi-l">No asistió</div><div class="kpi-s"></div></div></div>
</div>
<div class="card">
 <div class="table-responsive"><table class="table mb-0">
  <thead><tr><th>Hora</th><th>Paciente</th><th class="d-none d-md-table-cell">Doctor</th><th class="d-none d-lg-table-cell">Sillón</th><th class="d-none d-lg-table-cell">Tipo</th><th>Estado</th><th></th></tr></thead>
  <tbody>
  <?php foreach($list as $c): ?>
  <tr>
   <td><span class="mon" style="color:var(--c)"><?=substr($c['hora_inicio'],0,5)?></span><br><small><?=substr($c['hora_fin'],0,5)?></small></td>
   <td><strong><?=e($c['pac'])?></strong><?php if($c['motivo']): ?><br><small><?=e(substr($c['motivo'],0,35))?></small><?php endif; ?></td>
   <td class="d-none d-md-table-cell"><small><?=e($c['dr'])?></small></td>
   <td class="d-none d-lg-table-cell"><small><?=e($c['sill']??'—')?></small></td>
   <td class="d-none d-lg-table-cell"><span class="badge bgr" style="font-size:9px"><?=$c['tipo']?></span></td>
   <td><span class="badge <?=$ec[$c['estado']]?>"><?=$el[$c['estado']]?></span></td>
   <td><div class="d-flex gap-1">
    <a href="?accion=ver&id=<?=$c['id']?>" class="btn btn-dk btn-ico"><i class="bi bi-eye"></i></a>
    <?php if($c['estado']==='pendiente'): ?>
    <form method="POST" class="d-inline"><input type="hidden" name="accion" value="estado"><input type="hidden" name="cid" value="<?=$c['id']?>"><input type="hidden" name="est" value="en_atencion"><button type="submit" class="btn btn-ico btn-ok" title="Iniciar"><i class="bi bi-play-fill"></i></button></form>
    <?php endif; ?>
   </div></td>
  </tr>
  <?php endforeach; if(!$list): ?>
  <tr><td colspan="7" class="text-center py-4" style="color:var(--t2)"><i class="bi bi-calendar-x" style="font-size:32px;display:block;margin-bottom:8px"></i>No hay citas para este día</td></tr>
  <?php endif; ?>
  </tbody>
 </table></div>
</div>
<?php require_once __DIR__.'/../includes/footer.php';

}elseif($accion==='ver'&&$id){
 $st=db()->prepare("SELECT c.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac,p.telefono AS ptl,p.id AS pid,CONCAT(u.nombre,' ',u.apellidos) AS dr,s.nombre AS sill FROM citas c JOIN pacientes p ON c.paciente_id=p.id JOIN usuarios u ON c.doctor_id=u.id LEFT JOIN sillones s ON c.sillon_id=s.id WHERE c.id=?");
 $st->execute([$id]); $cita=$st->fetch();
 if(!$cita){flash('error','Cita no encontrada');go('pages/citas.php');}
 $titulo='Cita '.$cita['codigo']; $pagina_activa='citas';
 $msg_rec=getCfg('plantilla_wa_cita'); $msg_rec=str_replace(['{nombre}','{clinica}','{fecha}','{hora}','{telefono}'],[$cita['pac'],getCfg('clinica_nombre'),fDate($cita['fecha']),substr($cita['hora_inicio'],0,5),getCfg('clinica_telefono')],$msg_rec);
 require_once __DIR__.'/../includes/header.php';
?>
<div class="row g-4">
 <div class="col-12 col-lg-7">
  <div class="card mb-4">
   <div class="card-header"><span><i class="bi bi-calendar2-check me-1"></i><?=e($cita['codigo'])?></span>
   <span class="badge <?=$ec[$cita['estado']]?>" style="font-size:12px"><?=$el[$cita['estado']]?></span></div>
   <div class="p-4">
    <div class="row g-3 mb-4">
     <div class="col-6 col-md-3"><small style="color:var(--t2);display:block">Fecha</small><strong><?=fDate($cita['fecha'])?></strong></div>
     <div class="col-6 col-md-3"><small style="color:var(--t2);display:block">Hora</small><span class="mon" style="color:var(--c)"><?=substr($cita['hora_inicio'],0,5)?>–<?=substr($cita['hora_fin'],0,5)?></span></div>
     <div class="col-6 col-md-3"><small style="color:var(--t2);display:block">Tipo</small><span class="badge bgr"><?=$cita['tipo']?></span></div>
     <div class="col-6 col-md-3"><small style="color:var(--t2);display:block">Sillón</small><?=e($cita['sill']??'—')?></div>
    </div>
    <div class="row g-3 mb-4">
     <div class="col-12 col-md-6"><small style="color:var(--t2)">Paciente</small>
      <div class="d-flex align-items-center gap-2 mt-1"><div class="ava" style="width:28px;height:28px;font-size:11px"><?=strtoupper(substr($cita['pac'],0,1))?></div>
      <a href="<?=BASE_URL?>/pages/pacientes.php?accion=ver&id=<?=$cita['pid']?>" style="color:var(--c);font-weight:700"><?=e($cita['pac'])?></a></div></div>
     <div class="col-12 col-md-6"><small style="color:var(--t2)">Doctor</small><div class="mt-1"><strong><?=e($cita['dr'])?></strong><?php if($cita['especialidad']): ?><br><small><?=e($cita['especialidad'])?></small><?php endif; ?></div></div>
     <?php if($cita['motivo']): ?><div class="col-12"><small style="color:var(--t2)">Motivo</small><div class="mt-1" style="background:var(--bg3);padding:9px 12px;border-radius:7px;border-left:3px solid var(--c)"><?=e($cita['motivo'])?></div></div><?php endif; ?>
    </div>
    <form method="POST">
     <input type="hidden" name="accion" value="estado"><input type="hidden" name="cid" value="<?=$id?>">
     <div class="d-flex gap-2 flex-wrap">
      <?php foreach(['pendiente','confirmado','en_atencion','atendido','no_asistio','cancelado'] as $s): ?>
      <button type="submit" name="est" value="<?=$s?>" class="btn btn-sm <?=$cita['estado']===$s?'btn-primary':'btn-dk'?>"><?=$el[$s]?></button>
      <?php endforeach; ?>
     </div>
    </form>
   </div>
  </div>
 </div>
 <div class="col-12 col-lg-5">
  <?php if($cita['ptl']): ?>
  <div class="card mb-4">
   <div class="card-header"><span style="color:var(--t)"><i class="bi bi-whatsapp me-1"></i>WhatsApp Recordatorio</span></div>
   <div class="p-4 d-grid gap-2">
    <a href="<?=urlWA($cita['ptl'],$msg_rec)?>" target="_blank" class="btn btn-wa"><i class="bi bi-whatsapp me-2"></i>Enviar recordatorio de cita</a>
    <button type="button" class="btn btn-dk" data-bs-toggle="modal" data-bs-target="#modWA"><i class="bi bi-chat-text me-2"></i>Mensaje personalizado</button>
   </div>
  </div>
  <?php endif; ?>
  <div class="card">
   <div class="card-header"><span style="color:var(--t)"><i class="bi bi-lightning me-1"></i>Acciones</span></div>
   <div class="p-3 d-grid gap-2">
    <a href="<?=BASE_URL?>/pages/historia_clinica.php?accion=nueva&paciente_id=<?=$cita['pid']?>&cita_id=<?=$id?>" class="btn btn-primary"><i class="bi bi-file-medical me-2"></i>Abrir historia clínica</a>
    <a href="<?=BASE_URL?>/pages/pagos.php?accion=nuevo&paciente_id=<?=$cita['pid']?>&cita_id=<?=$id?>" class="btn btn-dk"><i class="bi bi-cash me-2"></i>Registrar pago</a>
    <a href="?accion=editar&id=<?=$id?>" class="btn btn-dk"><i class="bi bi-pencil me-2"></i>Editar cita</a>
   </div>
  </div>
 </div>
</div>
<!-- Modal WA -->
<div class="modal fade" id="modWA" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
 <div class="modal-header"><h5 class="modal-title">✉️ Mensaje personalizado</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
 <div class="modal-body p-4">
  <div class="mb-3"><label class="form-label">Plantilla</label>
  <select class="form-select" onchange="document.getElementById('mTxt').value=tpl[this.value]">
   <option value="rec">📅 Recordatorio</option><option value="conf">✅ Confirmar asistencia</option><option value="can">❌ Cancelación</option>
  </select></div>
  <label class="form-label">Mensaje</label>
  <textarea class="form-control" id="mTxt" rows="6"><?=e($msg_rec)?></textarea>
 </div>
 <div class="modal-footer">
  <button type="button" class="btn btn-dk" data-bs-dismiss="modal">Cancelar</button>
  <a href="#" id="btnWA" target="_blank" class="btn btn-wa" onclick="this.href='https://web.whatsapp.com/send?phone=<?=preg_replace('/[^0-9]/','',strlen(preg_replace('/[^0-9]/','',($cita['ptl']??'')))==9?'51'.preg_replace('/[^0-9]/','',($cita['ptl']??'')):preg_replace('/[^0-9]/','',($cita['ptl']??'')))?>'+' &text='+encodeURIComponent(document.getElementById('mTxt').value)"><i class="bi bi-whatsapp me-2"></i>Enviar</a>
 </div>
</div></div></div>
<?php
$tpl_json=json_encode(['rec'=>$msg_rec,'conf'=>"*".$cita['pac']."*, ¿confirma asistencia para ".fDate($cita['fecha'])." a las ".substr($cita['hora_inicio'],0,5)."? Responda SÍ/NO. — ".getCfg('clinica_nombre'),'can'=>"*".$cita['pac']."*, su cita del ".fDate($cita['fecha'])." ha sido cancelada. Contáctenos para reagendar. — ".getCfg('clinica_nombre')]);
$xscript="<script>const tpl=$tpl_json;</script>";
require_once __DIR__.'/../includes/footer.php';

}elseif(in_array($accion,['nueva','editar'])){
 $cita=['id'=>0,'paciente_id'=>(int)($_GET['paciente_id']??0),'doctor_id'=>0,'sillon_id'=>'','fecha'=>date('Y-m-d'),'hora_inicio'=>'09:00','hora_fin'=>'09:30','tipo'=>'primera_vez','especialidad'=>'','motivo'=>'','notas'=>''];
 if($accion==='editar'&&$id){$s=db()->prepare("SELECT * FROM citas WHERE id=?");$s->execute([$id]);$cita=$s->fetch()?:$cita;}
 $pac_pre=null; if($cita['paciente_id']){$s=db()->prepare("SELECT * FROM pacientes WHERE id=?");$s->execute([$cita['paciente_id']]);$pac_pre=$s->fetch();}
 $titulo=$accion==='nueva'?'Nueva Cita':'Editar Cita'; $pagina_activa='citas';
 require_once __DIR__.'/../includes/header.php';
?>
<div class="row justify-content-center"><div class="col-12 col-lg-8">
<form method="POST">
 <input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?=$cita['id']?>">
 <div class="card mb-4">
  <div class="card-header"><span style="color:var(--t)"><i class="bi bi-calendar-plus me-1"></i>Datos de la cita</span></div>
  <div class="p-4"><div class="row g-3">
   <div class="col-12"><label class="form-label">Paciente *</label>
   <?php if($pac_pre): ?>
   <input type="hidden" name="paciente_id" value="<?=$pac_pre['id']?>">
   <div class="p-3 rounded d-flex align-items-center gap-2" style="background:var(--bg3);border:1px solid var(--bd)">
    <div class="ava"><?=strtoupper(substr($pac_pre['nombres'],0,1))?></div>
    <div><strong><?=e($pac_pre['nombres'].' '.$pac_pre['apellido_paterno'])?></strong><br><small><?=e($pac_pre['codigo'])?><?php if($pac_pre['telefono']): ?> · <?=e($pac_pre['telefono'])?><?php endif; ?></small></div>
    <a href="?accion=nueva" class="ms-auto btn btn-dk btn-sm">Cambiar</a>
   </div>
   <?php else: ?>
   <select name="paciente_id" class="form-select" required>
    <option value="">— Seleccionar paciente —</option>
    <?php foreach($pacs_sel as $p): ?><option value="<?=$p['id']?>" <?=$cita['paciente_id']==$p['id']?'selected':''?>><?=e($p['nombres'].' '.$p['apellido_paterno'])?> (<?=e($p['codigo'])?>)</option><?php endforeach; ?>
   </select>
   <?php endif; ?>
   </div>
   <div class="col-12 col-md-6"><label class="form-label">Doctor *</label>
   <select name="doctor_id" class="form-select" required>
    <option value="">— Seleccionar —</option>
    <?php foreach($docs as $d): ?><option value="<?=$d['id']?>" <?=$cita['doctor_id']==$d['id']?'selected':''?>><?=e($d['nombre'].' '.$d['apellidos'])?><?php if($d['especialidad']): ?> (<?=e($d['especialidad'])?>)<?php endif; ?></option><?php endforeach; ?>
   </select></div>
   <div class="col-12 col-md-6"><label class="form-label">Sillón</label>
   <select name="sillon_id" class="form-select">
    <option value="">— Sin asignar —</option>
    <?php foreach($sills as $s): ?><option value="<?=$s['id']?>" <?=$cita['sillon_id']==$s['id']?'selected':''?>><?=e($s['nombre'])?></option><?php endforeach; ?>
   </select></div>
   <div class="col-12 col-md-4"><label class="form-label">Fecha *</label><input type="date" name="fecha" class="form-control" value="<?=$cita['fecha']?>" required></div>
   <div class="col-12 col-md-4"><label class="form-label">Hora inicio *</label><input type="time" name="hora_inicio" class="form-control" value="<?=$cita['hora_inicio']?>" required></div>
   <div class="col-12 col-md-4"><label class="form-label">Hora fin *</label><input type="time" name="hora_fin" class="form-control" value="<?=$cita['hora_fin']?>" required></div>
   <div class="col-12 col-md-6"><label class="form-label">Tipo</label>
   <select name="tipo" class="form-select">
    <?php foreach(['primera_vez'=>'Primera vez','control'=>'Control','urgencia'=>'Urgencia','tratamiento'=>'Tratamiento'] as $v=>$l): ?><option value="<?=$v?>" <?=$cita['tipo']===$v?'selected':''?>><?=$l?></option><?php endforeach; ?>
   </select></div>
   <div class="col-12 col-md-6"><label class="form-label">Especialidad</label><input type="text" name="especialidad" class="form-control" value="<?=e($cita['especialidad']??'')?>" placeholder="Ortodoncia, Endodoncia..."></div>
   <div class="col-12"><label class="form-label">Motivo de consulta</label><textarea name="motivo" class="form-control" rows="3"><?=e($cita['motivo']??'')?></textarea></div>
   <div class="col-12"><label class="form-label">Notas internas</label><textarea name="notas" class="form-control" rows="2"><?=e($cita['notas']??'')?></textarea></div>
  </div></div>
 </div>
 <div class="d-flex gap-2 justify-content-end">
  <a href="?accion=agenda" class="btn btn-dk">Cancelar</a>
  <button type="submit" class="btn btn-primary px-4"><i class="bi bi-floppy me-2"></i><?=$accion==='nueva'?'Agendar cita':'Guardar cambios'?></button>
 </div>
</form>
</div></div>
<?php require_once __DIR__.'/../includes/footer.php';
}
