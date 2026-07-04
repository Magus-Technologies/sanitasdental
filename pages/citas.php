<?php
require_once __DIR__.'/../includes/config.php';
// Google Calendar - load only if available and table exists
define('GC_AVAILABLE', file_exists(__DIR__.'/../includes/GoogleCalendarService.php') && 
    (function(){ try{
        db()->query('SELECT 1 FROM google_calendar_tokens LIMIT 1');
        // Also ensure google_event_id column exists
        db()->query('SELECT google_event_id FROM citas LIMIT 1');
        return true;
    }catch(Exception $e){ return false; } })());
if(GC_AVAILABLE) require_once __DIR__.'/../includes/GoogleCalendarService.php';
require_once __DIR__.'/../includes/wa_notify.php';
requiereLogin();
$accion=$_GET['accion']??'calendario'; $id=(int)($_GET['id']??0);

if($_SERVER['REQUEST_METHOD']==='POST'){
 $ap=$_POST['accion']??'';
 if($ap==='guardar'){
  $ei=(int)($_POST['id']??0);
  $d=['paciente_id'=>(int)$_POST['paciente_id'],'doctor_id'=>(int)$_POST['doctor_id'],'sillon_id'=>$_POST['sillon_id']?:null,'fecha'=>$_POST['fecha'],'hora_inicio'=>$_POST['hora_inicio'],'hora_fin'=>$_POST['hora_fin'],'tipo'=>$_POST['tipo']??'primera_vez','especialidad'=>trim($_POST['especialidad']??''),'motivo'=>trim($_POST['motivo']??''),'notas'=>trim($_POST['notas']??'')];
  if($ei){$sets=implode(',',array_map(fn($k)=>"$k=?",array_keys($d)));db()->prepare("UPDATE citas SET $sets,updated_at=NOW() WHERE id=?")->execute([...array_values($d),$ei]);
  // Auto-sync update to Google Calendar
  if(GC_AVAILABLE) try{
    $gc_svc2=new GoogleCalendarService((int)($_POST['doctor_id']?:$_SESSION['uid']));
    if($gc_svc2->isConnected()){
      $gc_cita2=db()->prepare("SELECT c.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac FROM citas c JOIN pacientes p ON c.paciente_id=p.id WHERE c.id=?");
      $gc_cita2->execute([$ei]); $gc_cita2=$gc_cita2->fetch();
      if($gc_cita2) $gc_svc2->updateEvent($gc_cita2);
    }
  }catch(Exception $e){}
  flash('ok','Cita actualizada.');go("pages/citas.php?accion=ver&id=$ei");}
  else{$cod=genCodigo('CIT','citas');$d['codigo']=$cod;$d['estado']='pendiente';$d['created_by']=$_SESSION['uid'];
  $cols=implode(',',array_keys($d));$phs=implode(',',array_fill(0,count($d),'?'));
  db()->prepare("INSERT INTO citas($cols)VALUES($phs)")->execute(array_values($d));
  $nid=db()->lastInsertId(); auditar('CREAR_CITA','citas',$nid);
  // Auto-sync to Google Calendar
  if(GC_AVAILABLE) try{
    $gc_svc=new GoogleCalendarService((int)($_POST['doctor_id']?:$_SESSION['uid']));
    if($gc_svc->isConnected()){
      $gc_cita=db()->prepare("SELECT c.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac FROM citas c JOIN pacientes p ON c.paciente_id=p.id WHERE c.id=?");
      $gc_cita->execute([$nid]); $gc_cita=$gc_cita->fetch();
      if($gc_cita) $gc_svc->createEvent($gc_cita);
    }
  }catch(Exception $e){}
  // registrar turno
  try{$nt=db()->query("SELECT COALESCE(MAX(numero),0)+1 FROM turnos WHERE DATE(created_at)=CURDATE()")->fetchColumn();
  $st_pac=db()->prepare("SELECT CONCAT(nombres,' ',apellido_paterno) FROM pacientes WHERE id=?"); $st_pac->execute([(int)$_POST['paciente_id']]); $nm_pac=$st_pac->fetchColumn();
  db()->prepare("INSERT INTO turnos(cita_id,numero,nombre_mostrar)VALUES(?,?,?)")->execute([$nid,$nt,$nm_pac]);}catch(Exception $e){}
  // Confirmación automática por WhatsApp (si está activada en Configuración)
  if(getCfg('wa_confirma_cita','0')==='1'){
    try{
      $pcf=db()->prepare("SELECT CONCAT(nombres,' ',apellido_paterno) pac, telefono FROM pacientes WHERE id=?");
      $pcf->execute([(int)$_POST['paciente_id']]); $pcf=$pcf->fetch();
      if($pcf && trim((string)($pcf['telefono']??''))!==''){
        $tplc=getCfg('plantilla_wa_confirma','Hola *{nombre}*, tu cita en *{clinica}* quedó agendada para el *{fecha}* a las *{hora}*. ¡Te esperamos! Ante consultas: {telefono}');
        $mc=wa_plantilla($tplc,['{nombre}'=>$pcf['pac'],'{clinica}'=>getCfg('clinica_nombre','la clínica'),'{fecha}'=>fDate($d['fecha']),'{hora}'=>substr($d['hora_inicio'],0,5),'{telefono}'=>getCfg('clinica_telefono','')]);
        $okc=wa_enviar($pcf['telefono'],$mc);
        db()->prepare("INSERT INTO notificaciones(tipo,destinatario,asunto,mensaje,estado,referencia_tipo,referencia_id) VALUES('whatsapp',?,?,?,?, 'cita_confirma', ?)")->execute([$pcf['telefono'],'Confirmación de cita',$mc,$okc?'enviado':'error',$nid]);
      }
    }catch(Exception $e){}
  }
  flash('ok',"Cita agendada: $cod"); go("pages/citas.php?accion=ver&id=$nid");}
 }
 if($ap==='estado'){
  $cid=(int)$_POST['cid']; $est=$_POST['est'];
  db()->prepare("UPDATE citas SET estado=?,updated_at=NOW() WHERE id=?")->execute([$est,$cid]);
  if(GC_AVAILABLE) try{
    $gc_ec=db()->prepare("SELECT c.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac FROM citas c JOIN pacientes p ON c.paciente_id=p.id WHERE c.id=?");
    $gc_ec->execute([$cid]); $gc_ec=$gc_ec->fetch();
    if($gc_ec){$gc_s3=new GoogleCalendarService((int)$gc_ec['doctor_id']);
    if($gc_s3->isConnected()){if(in_array($est,['cancelado','no_asistio']))$gc_s3->deleteEvent($gc_ec);else $gc_s3->updateEvent($gc_ec);}}
  }catch(Exception $e){}
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

// Eliminar cita
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['accion']??'')==='eliminar_cita'){
 $eid=(int)($_POST['id']??0);
 if($eid){
  $gc_del=db()->prepare("SELECT c.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac FROM citas c JOIN pacientes p ON c.paciente_id=p.id WHERE c.id=?");
  $gc_del->execute([$eid]); $gc_del=$gc_del->fetch();
  if(GC_AVAILABLE&&$gc_del&&($gc_del['google_event_id']??'')){
   try{ $gc_d=new GoogleCalendarService((int)($gc_del['doctor_id']?:$_SESSION['uid']));
   if($gc_d->isConnected()) $gc_d->deleteEvent($gc_del); }catch(Exception $e){}
  }
  db()->prepare("DELETE FROM citas WHERE id=?")->execute([$eid]);
  auditar('ELIMINAR_CITA','citas',$eid);
  flash('ok','Cita eliminada correctamente.');
 }
 go('pages/citas.php');
}

if($accion==='agenda'){
 $titulo='Agenda de Citas'; $pagina_activa='citas';
 $fsel=$_GET['fecha']??date('Y-m-d'); $dsel=(int)($_GET['doc']??0);
 $w='WHERE c.fecha=?'; $pm=[$fsel];
 if($dsel){$w.=' AND c.doctor_id=?';$pm[]=$dsel;}
 $list=db()->prepare("SELECT c.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac,CONCAT(u.nombre,' ',u.apellidos) AS dr,s.nombre AS sill FROM citas c JOIN pacientes p ON c.paciente_id=p.id JOIN usuarios u ON c.doctor_id=u.id LEFT JOIN sillones s ON c.sillon_id=s.id $w ORDER BY c.hora_inicio");
 $list->execute($pm); $list=$list->fetchAll();
 $topbar_act='<a href="?accion=nueva" class="btn btn-primary"><i class="bi bi-calendar-plus me-1"></i>Nueva cita</a> <a href="?accion=calendario" class="btn btn-dk btn-sm ms-1"><i class="bi bi-calendar3 me-1"></i>Vista Calendario</a>';
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
    <form method="POST" class="d-inline" onsubmit="return confirm('\u00bfEliminar esta cita?')">
     <input type="hidden" name="accion" value="eliminar_cita">
     <input type="hidden" name="id" value="<?=$c['id']?>">
     <button type="submit" class="btn btn-del btn-ico" title="Eliminar"><i class="bi bi-trash"></i></button>
    </form>
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
 $topbar_act='<a href="?accion=nueva&id='.$id.'" class="btn btn-dk btn-sm"><i class="bi bi-pencil me-1"></i>Editar</a>'
  .' <form method="POST" class="d-inline" onsubmit="return confirm(\'\u00bfEliminar esta cita? Esta accion no se puede deshacer.\')">'  
  .'<input type="hidden" name="accion" value="eliminar_cita">'
  .'<input type="hidden" name="id" value="'.$id.'">'  
  .'<button type="submit" class="btn btn-del btn-sm"><i class="bi bi-trash me-1"></i>Eliminar cita</button></form>';
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
 $cita=['id'=>0,'paciente_id'=>(int)($_GET['paciente_id']??0),'doctor_id'=>0,'sillon_id'=>'','fecha'=>($_GET['fecha']??date('Y-m-d')),'hora_inicio'=>($_GET['hora']??'09:00'),'hora_fin'=>date('H:i',strtotime(($_GET['hora']??'09:00').' +30 minutes')),'tipo'=>'primera_vez','especialidad'=>'','motivo'=>'','notas'=>''];
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
elseif($accion==='calendario'){
 // ── Calcular semana ──────────────────────────────────────────────
 $semana_base = $_GET['semana'] ?? date('Y-m-d');
 $dsel        = (int)($_GET['doc'] ?? 0);
 // Lunes de la semana seleccionada
 $ts_base  = strtotime($semana_base);
 $dow      = (int)date('N', $ts_base); // 1=lun..7=dom
 $lunes    = date('Y-m-d', $ts_base - ($dow - 1) * 86400);
 $domingo  = date('Y-m-d', strtotime($lunes) + 6 * 86400);
 $prev_sem = date('Y-m-d', strtotime($lunes) - 7 * 86400);
 $next_sem = date('Y-m-d', strtotime($lunes) + 7 * 86400);

 // ── Cargar citas de la semana ────────────────────────────────────
 $w = 'WHERE c.fecha BETWEEN ? AND ?'; $pm = [$lunes, $domingo];
 if ($dsel) { $w .= ' AND c.doctor_id=?'; $pm[] = $dsel; }
 $citas_sem = db()->prepare("SELECT c.*,
    CONCAT(p.nombres,' ',p.apellido_paterno) AS pac,
    CONCAT(u.nombre,' ',u.apellidos) AS dr,
    u.id AS uid
    FROM citas c
    JOIN pacientes p ON c.paciente_id=p.id
    JOIN usuarios u ON c.doctor_id=u.id
    $w ORDER BY c.fecha, c.hora_inicio");
 $citas_sem->execute($pm);
 $citas_sem = $citas_sem->fetchAll();

 // Agrupar por fecha+hora
 $by_day = [];
 foreach ($citas_sem as $c) {
     $by_day[$c['fecha']][] = $c;
 }

 // Colores por doctor (ciclo)
 $doc_colors = ['#00D4EE','#10b981','#8b5cf6','#f59e0b','#ec4899','#ef4444','#06B6D4','#F59E0B'];
 $doc_color_map = [];
 $ci = 0;
 foreach ($docs as $d) {
     $doc_color_map[$d['id']] = $doc_colors[$ci % count($doc_colors)];
     $ci++;
 }

 $titulo = 'Agenda — Semana '.$lunes.' al '.$domingo;
 $pagina_activa = 'citas';
 $topbar_act = '<a href="?accion=nueva" class="btn btn-primary btn-sm"><i class="bi bi-calendar-plus me-1"></i>Nueva cita</a>
 <a href="?accion=agenda" class="btn btn-dk btn-sm ms-1"><i class="bi bi-list-ul me-1"></i>Vista Día</a>';
 $xhead = '<style>
/* ── CALENDARIO ─────────────────────────────────── */
.cal-wrap       { overflow-x:auto;-webkit-overflow-scrolling:touch; }
.cal-grid       { display:grid;min-width:700px;border:1px solid var(--bd2);border-radius:10px;overflow:hidden; }
.cal-header     { display:contents; }
.cal-corner     { background:var(--bg2);border-bottom:1px solid var(--bd2);border-right:1px solid var(--bd2);padding:8px 6px;font-size:10px;color:var(--t3);text-align:center;position:sticky;left:0;z-index:3; }
.cal-day-hd     { background:var(--bg2);border-bottom:1px solid var(--bd2);border-right:1px solid var(--bd2);padding:8px 6px;text-align:center;font-size:11px;font-weight:700; }
.cal-day-hd.today { background:rgba(0,212,238,.08);border-bottom:2px solid var(--c); }
.cal-day-hd .dow  { color:var(--t2);font-size:10px;text-transform:uppercase;letter-spacing:.04em; }
.cal-day-hd .num  { font-size:18px;font-weight:800;color:var(--t); }
.cal-day-hd.today .num { color:var(--c); }
.cal-time-col   { background:var(--bg3);border-right:2px solid var(--bd2);padding:0;width:52px;min-width:52px;position:sticky;left:0;z-index:2; }
.cal-time-slot  { height:48px;display:flex;align-items:flex-start;justify-content:flex-end;padding-right:6px;padding-top:2px;font-size:9px;color:var(--t3);border-bottom:1px solid var(--bd2);font-family:monospace; }
.cal-day-col    { position:relative;border-right:1px solid var(--bd2);min-width:120px; }
.cal-day-col.today { background:rgba(0,212,238,.025); }
.cal-cell       { height:48px;border-bottom:1px solid rgba(255,255,255,.04); }
.cal-cell:hover { background:rgba(0,212,238,.04);cursor:pointer; }
.cal-event      { position:absolute;left:3px;right:3px;border-radius:5px;padding:2px 5px;font-size:10px;font-weight:600;color:#fff;overflow:hidden;cursor:pointer;transition:opacity .12s;z-index:1;line-height:1.25; }
.cal-event:hover{ opacity:.85; }
.cal-event .ev-pac { white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.cal-event .ev-tipo{ font-size:9px;opacity:.85; }
.cal-row        { display:contents; }
/* Estados */
.ev-pendiente   { opacity:1; }
.ev-confirmado  { border-left:3px solid #fff5; }
.ev-en_atencion { animation:pulse-ev 1.5s infinite; }
.ev-atendido    { opacity:.6;filter:saturate(.4); }
.ev-cancelado,.ev-no_asistio { opacity:.35;text-decoration:line-through; }
@keyframes pulse-ev { 0%,100%{box-shadow:0 0 0 0 rgba(255,255,255,.3)} 50%{box-shadow:0 0 0 4px rgba(255,255,255,.0)} }
/* Leyenda doc */
.doc-legend     { display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px; }
.doc-pill       { display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;border:1px solid transparent; }
/* KPIs */
.cal-kpis       { display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px; }
.cal-kpi        { flex:1;min-width:80px;background:var(--bg2);border:1px solid var(--bd2);border-radius:8px;padding:8px 12px;text-align:center; }
.cal-kpi-v      { font-size:22px;font-weight:800;color:var(--c); }
.cal-kpi-l      { font-size:10px;color:var(--t2);text-transform:uppercase; }
@media(max-width:600px){
  .cal-day-hd .num { font-size:14px; }
  .cal-event { font-size:9px; }
  .cal-day-col { min-width:80px; }
}
</style>';
 require_once __DIR__.'/../includes/header.php';

 $dias_semana = ['lun','mar','mié','jue','vie','sáb','dom'];
 $dias_full   = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
 $hoy         = date('Y-m-d');
 $total_sem   = count($citas_sem);
 $cnt_sem     = array_count_values(array_column($citas_sem,'estado'));
 // Horario: 7am a 21pm en slots de 30min
 $hora_ini = 7;   // 7:00
 $hora_fin = 21;  // 21:00
 $slots    = ($hora_fin - $hora_ini) * 2; // 28 slots x 30min
 $px_slot  = 48;  // px por slot (debe coincidir con .cal-cell height)
?>

<!-- Controles -->
<div class="card p-3 mb-3">
 <div class="row g-2 align-items-end">
  <div class="col-12 col-sm-5">
   <label class="form-label">Doctor / Filtro</label>
   <select id="filtroDoc" class="form-select form-select-sm" onchange="location.href='?accion=calendario&semana=<?=$lunes?>&doc='+this.value">
    <option value="0" <?=!$dsel?'selected':''?>>Todos los doctores</option>
    <?php foreach($docs as $d): ?>
    <option value="<?=$d['id']?>" <?=$dsel==$d['id']?'selected':''?>><?=e($d['nombre'].' '.$d['apellidos'])?></option>
    <?php endforeach; ?>
   </select>
  </div>
  <div class="col-12 col-sm-4">
   <label class="form-label">Ir a semana</label>
   <input type="date" id="jumpDate" class="form-control form-control-sm" value="<?=$lunes?>"
    onchange="location.href='?accion=calendario&semana='+this.value+'&doc=<?=$dsel?>'">
  </div>
  <div class="col-12 col-sm-3">
   <div class="d-flex gap-1">
    <a href="?accion=calendario&semana=<?=$prev_sem?>&doc=<?=$dsel?>" class="btn btn-dk btn-sm flex-fill">‹ Ant</a>
    <a href="?accion=calendario&semana=<?=date('Y-m-d')?>&doc=<?=$dsel?>" class="btn btn-dk btn-sm flex-fill" title="Esta semana">Hoy</a>
    <a href="?accion=calendario&semana=<?=$next_sem?>&doc=<?=$dsel?>" class="btn btn-dk btn-sm flex-fill">Sig ›</a>
   </div>
  </div>
 </div>
</div>

<!-- KPIs semana -->
<div class="cal-kpis">
 <div class="cal-kpi"><div class="cal-kpi-v"><?=$total_sem?></div><div class="cal-kpi-l">Esta semana</div></div>
 <div class="cal-kpi"><div class="cal-kpi-v" style="color:#f59e0b"><?=$cnt_sem['pendiente']??0?></div><div class="cal-kpi-l">Pendientes</div></div>
 <div class="cal-kpi"><div class="cal-kpi-v" style="color:#00D4EE"><?=$cnt_sem['confirmado']??0?></div><div class="cal-kpi-l">Confirmadas</div></div>
 <div class="cal-kpi"><div class="cal-kpi-v" style="color:#10b981"><?=$cnt_sem['atendido']??0?></div><div class="cal-kpi-l">Atendidas</div></div>
 <div class="cal-kpi"><div class="cal-kpi-v" style="color:#ef4444"><?=($cnt_sem['cancelado']??0)+($cnt_sem['no_asistio']??0)?></div><div class="cal-kpi-l">Canceladas/N.A.</div></div>
</div>

<!-- Leyenda doctores -->
<?php if(!$dsel && count($docs)>0): ?>
<div class="doc-legend mb-3">
 <?php foreach($docs as $d):
   $c = $doc_color_map[$d['id']]; ?>
 <span class="doc-pill" style="background:<?=$c?>22;color:<?=$c?>;border-color:<?=$c?>44">
   <span style="width:8px;height:8px;border-radius:50%;background:<?=$c?>;display:inline-block"></span>
   <?=e($d['nombre'].' '.$d['apellidos'])?>
 </span>
 <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Semana label -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
 <h2 style="font-size:16px;font-weight:800;color:var(--t);margin:0">
  <?php
  $meses_es=['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
  $ml = (int)date('m',strtotime($lunes))-1;
  $md = (int)date('m',strtotime($domingo))-1;
  if($ml===$md)
    echo date('j',strtotime($lunes)).' – '.date('j',strtotime($domingo)).' de '.$meses_es[$ml].' '.date('Y',strtotime($lunes));
  else
    echo date('j',strtotime($lunes)).' '.$meses_es[$ml].' – '.date('j',strtotime($domingo)).' '.$meses_es[$md].' '.date('Y',strtotime($domingo));
  ?>
 </h2>
 <div class="d-flex gap-1 align-items-center" style="font-size:10px;color:var(--t2)">
  <span style="width:10px;height:10px;border-radius:2px;background:#f59e0b;display:inline-block"></span>Pendiente
  <span style="width:10px;height:10px;border-radius:2px;background:#00D4EE;display:inline-block;margin-left:6px"></span>Confirmado
  <span style="width:10px;height:10px;border-radius:2px;background:#10b981;display:inline-block;margin-left:6px"></span>Atendido
  <span style="width:10px;height:10px;border-radius:2px;background:#ef4444;display:inline-block;margin-left:6px"></span>Cancelado
 </div>
</div>

<!-- CALENDARIO -->
<div class="cal-wrap">
<div class="cal-grid" id="calGrid" style="grid-template-columns:52px repeat(7,1fr);position:relative">

 <!-- Header row -->
 <div class="cal-corner">Hora</div>
 <?php for($di=0;$di<7;$di++):
   $fecha_dia = date('Y-m-d', strtotime($lunes) + $di * 86400);
   $es_hoy = $fecha_dia === $hoy;
   $num_citas_dia = count($by_day[$fecha_dia] ?? []);
 ?>
 <div class="cal-day-hd <?=$es_hoy?'today':''?>">
  <div class="dow"><?=$dias_semana[$di]?></div>
  <div class="num"><?=(int)date('j',strtotime($fecha_dia))?></div>
  <?php if($num_citas_dia): ?>
  <div style="font-size:9px;color:var(--c);font-weight:700"><?=$num_citas_dia?> cita<?=$num_citas_dia>1?'s':''?></div>
  <?php else: ?>
  <div style="font-size:9px;color:var(--t3)">libre</div>
  <?php endif; ?>
 </div>
 <?php endfor; ?>

 <!-- Time rows -->
 <?php for($s=0;$s<$slots;$s++):
   $mins_total = $hora_ini*60 + $s*30;
   $h = (int)($mins_total/60);
   $m = $mins_total%60;
   $label = ($m===0) ? sprintf('%d',($h>12?$h-12:($h===0?12:$h))).'<br><span style="font-size:8px">'.($h>=12?'pm':'am').'</span>' : '';
   $is_half = ($m===30);
 ?>
 <div class="cal-time-col">
  <div class="cal-time-slot" style="<?=$is_half?'border-bottom:1px solid rgba(255,255,255,.03)':'border-bottom:1px solid var(--bd2)'?>">
   <?=$label?>
  </div>
 </div>
 <?php for($di=0;$di<7;$di++):
   $fecha_dia = date('Y-m-d', strtotime($lunes) + $di * 86400);
   $es_hoy = $fecha_dia === $hoy;
 ?>
 <div class="cal-cell <?=$es_hoy?'today':''?>"
      onclick="quickNew('<?=$fecha_dia?>','<?=sprintf('%02d:%02d',$h,$m)?>')"
      title="Nueva cita <?=$fecha_dia?> <?=sprintf('%02d:%02d',$h,$m)?>"></div>
 <?php endfor; ?>
 <?php endfor; ?>

</div><!-- /cal-grid -->
</div><!-- /cal-wrap -->

<!-- Evento overlay (posicionado con JS) -->
<div id="evOverlay" style="display:none;position:fixed;z-index:1050;min-width:220px;max-width:280px;background:var(--bg2);border:1px solid var(--bd2);border-radius:10px;padding:14px;box-shadow:0 8px 32px rgba(0,0,0,.4)" onclick="event.stopPropagation()">
 <div id="evTitle" style="font-weight:700;font-size:13px;color:var(--t);margin-bottom:6px"></div>
 <div id="evMeta" style="font-size:11px;color:var(--t2);margin-bottom:8px"></div>
 <div class="d-flex gap-2">
  <a id="evLink" href="#" class="btn btn-primary btn-sm flex-fill">Ver cita</a>
  <a id="evDel" href="#" class="btn btn-del btn-sm"><i class="bi bi-trash"></i></a>
  <button class="btn btn-dk btn-sm" onclick="document.getElementById('evOverlay').style.display='none'">✕</button>
 </div>
</div>

<script>
(function(){
  const CAL_START_H   = <?=$hora_ini?>;
  const PX_PER_SLOT   = <?=$px_slot?>;   // px por 30min
  const PX_PER_MIN    = PX_PER_SLOT / 30;
  const HEADER_ROWS   = 1;               // 1 header row
  const TOTAL_COLS    = 8;               // 1 time + 7 days
  const grid = document.getElementById('calGrid');

  // Datos de citas desde PHP
  const citas = <?=json_encode(array_map(function($c) use ($doc_color_map,$el){
    $est_col = ['pendiente'=>'#f59e0b','confirmado'=>'#00D4EE','en_atencion'=>'#10b981','atendido'=>'#10b981','cancelado'=>'#ef4444','no_asistio'=>'#ef4444'];
    return [
      'id'         => $c['id'],
      'fecha'      => $c['fecha'],
      'hi'         => substr($c['hora_inicio'],0,5),
      'hf'         => substr($c['hora_fin'],0,5),
      'pac'        => $c['pac'],
      'dr'         => $c['dr'],
      'uid'        => $c['uid'],
      'tipo'       => $c['tipo'],
      'motivo'     => $c['motivo'] ?? '',
      'estado'     => $c['estado'],
      'est_label'  => $el[$c['estado']] ?? $c['estado'],
      'color'      => $doc_color_map[$c['uid']] ?? '#00D4EE',
      'est_color'  => $est_col[$c['estado']] ?? '#607080',
    ];
  }, $citas_sem))?>;

  // Day index map
  const days = <?=json_encode(array_map(fn($i)=>date('Y-m-d',strtotime($lunes." +$i days")), range(0,6)))?>;

  function timeToMin(t){ const [h,m]=t.split(':').map(Number); return h*60+m; }

  citas.forEach(function(c){
    const dayIdx = days.indexOf(c.fecha);
    if(dayIdx < 0) return;

    const startMin = timeToMin(c.hi) - CAL_START_H*60;
    const endMin   = timeToMin(c.hf) - CAL_START_H*60;
    const top      = startMin * PX_PER_MIN + 1;  // +1 for header border
    const height   = Math.max((endMin - startMin) * PX_PER_MIN - 2, 18);

    // Find the day column div
    // Grid: col 0=time, col 1..7=days. Row 1=header, rows 2..=slots.
    // Day columns are .cal-day-col elements
    const dayCols = grid.querySelectorAll('.cal-day-col');
    // Actually we use .cal-cell hover columns – need a wrapper per day
    // We'll overlay events on the cal-day-hd position using a portal approach
    // Better: use absolute positioning relative to a per-day wrapper
    // Since we used display:contents grid, we need a different strategy.
    // We'll use a dedicated overlay container positioned over the grid.
  });

  // Build per-day event containers positioned over the grid
  const wrap = document.querySelector('.cal-wrap');
  const gridEl = document.getElementById('calGrid');

  function renderEvents(){
    // Remove old event els
    document.querySelectorAll('.cal-event').forEach(e=>e.remove());

    const gridRect = gridEl.getBoundingClientRect();
    const wrapRect = wrap.getBoundingClientRect();
    const scrollLeft = wrap.scrollLeft;
    const scrollTop  = wrap.scrollTop;

    // Get header row height (first cal-day-hd)
    const firstHd = gridEl.querySelector('.cal-day-hd');
    if(!firstHd) return;
    const hdRect = firstHd.getBoundingClientRect();
    const hdBottom = hdRect.bottom - gridRect.top;

    // Get first time slot height
    const firstSlot = gridEl.querySelector('.cal-time-slot');
    const slotH = firstSlot ? firstSlot.getBoundingClientRect().height : PX_PER_SLOT;

    // Per day column positions
    const dayHds = Array.from(gridEl.querySelectorAll('.cal-day-hd'));

    days.forEach(function(fecha, di){
      const hd = dayHds[di];
      if(!hd) return;
      const hdR = hd.getBoundingClientRect();
      const dayLeft   = hdR.left - gridRect.left + scrollLeft;
      const dayWidth  = hdR.width;

      const dayCitas = citas.filter(c=>c.fecha===fecha);

      // Simple collision detection — split overlapping events into lanes
      const lanes = [];
      dayCitas.forEach(function(c){
        const s = timeToMin(c.hi);
        const e = timeToMin(c.hf);
        let placed = false;
        for(let l=0;l<lanes.length;l++){
          const last = lanes[l][lanes[l].length-1];
          if(timeToMin(last.hf) <= s){ lanes[l].push(c); placed=true; break; }
        }
        if(!placed) lanes.push([c]);
      });

      const nLanes = lanes.length;
      lanes.forEach(function(lane, li){
        lane.forEach(function(c){
          const startMin = timeToMin(c.hi) - CAL_START_H*60;
          const endMin   = timeToMin(c.hf) - CAL_START_H*60;
          const top    = hdBottom + startMin * (slotH/30);
          const height = Math.max((endMin-startMin)*(slotH/30)-2,18);
          const laneW  = (dayWidth-6) / nLanes;
          const left   = dayLeft + 3 + li*laneW;

          const ev = document.createElement('div');
          ev.className = 'cal-event ev-'+c.estado;
          ev.style.cssText = `top:${top}px;left:${left}px;width:${laneW-2}px;height:${height}px;background:${c.color};`;
          ev.innerHTML = `<div class="ev-pac">${c.pac.split(' ').slice(0,2).join(' ')}</div>`
            + (height>28 ? `<div class="ev-tipo">${c.tipo.replace(/_/g,' ')}</div>` : '');
          ev.title = `${c.pac} | ${c.hi}–${c.hf} | Dr. ${c.dr} | ${c.est_label}`;
          ev.addEventListener('click', function(e){
            e.stopPropagation();
            showOverlay(c, e.clientX, e.clientY);
          });
          // Position relative to gridEl (which is relative to wrap)
          gridEl.appendChild(ev);
        });
      });
    });
  }

  function showOverlay(c, cx, cy){
    const ov = document.getElementById('evOverlay');
    document.getElementById('evTitle').textContent = c.pac;
    document.getElementById('evMeta').innerHTML =
      `🕐 ${c.hi} – ${c.hf}<br>👨‍⚕️ Dr. ${c.dr}<br>📋 ${c.tipo.replace(/_/g,' ')}${c.motivo?'<br>📝 '+c.motivo:''}<br><span style="color:${c.est_color};font-weight:700">${c.est_label}</span>`;
    document.getElementById('evLink').href = '?accion=ver&id='+c.id;
    document.getElementById('evDel').onclick = function(e){
      e.preventDefault();
      if(!confirm('\u00bfEliminar esta cita?')) return;
      var f=document.createElement('form'); f.method='POST'; f.action='citas.php';
      var a=document.createElement('input'); a.type='hidden'; a.name='accion'; a.value='eliminar_cita';
      var b=document.createElement('input'); b.type='hidden'; b.name='id'; b.value=c.id;
      f.appendChild(a); f.appendChild(b); document.body.appendChild(f); f.submit();
    };
    // Position
    const vw=window.innerWidth, vh=window.innerHeight;
    let left = cx+10, top = cy+10;
    ov.style.display='block';
    const ow=ov.offsetWidth, oh=ov.offsetHeight;
    if(left+ow>vw-10) left=cx-ow-10;
    if(top+oh>vh-10)  top=cy-oh-10;
    ov.style.left=left+'px';
    ov.style.top=top+'px';
  }

  document.addEventListener('click',function(){ document.getElementById('evOverlay').style.display='none'; });

  // Render after DOM is fully painted (fixes getBoundingClientRect returning 0)
  function safeRender(){
    // Double rAF ensures layout is complete
    requestAnimationFrame(function(){
      requestAnimationFrame(function(){
        renderEvents();
      });
    });
  }
  // Initial render
  if(document.readyState === 'complete') safeRender();
  else window.addEventListener('load', safeRender);
  // Also render after a short delay as extra fallback
  setTimeout(renderEvents, 300);
  window.addEventListener('resize', renderEvents);
  wrap.addEventListener('scroll', renderEvents);
}());

function quickNew(fecha, hora){
  window.location.href = '?accion=nueva&fecha='+fecha+'&hora='+hora;
}
</script>
<?php
 require_once __DIR__.'/../includes/footer.php';
}
