<?php
require_once __DIR__.'/../includes/config.php';
requiereLogin();
require_once __DIR__.'/../includes/wa_notify.php';
$titulo='WhatsApp y Notificaciones'; $pagina_activa='notif';

// Guardar plantillas y configuración de WhatsApp (solo admin)
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion']??'')==='guardar_config' && esRol('admin')){
  $textos=['plantilla_wa_cita','plantilla_wa_cumple','plantilla_wa_confirma','wa_url','wa_token'];
  foreach($textos as $k){ $v=trim($_POST[$k]??''); db()->prepare("INSERT INTO configuracion(clave,valor) VALUES(?,?) ON DUPLICATE KEY UPDATE valor=?")->execute([$k,$v,$v]); }
  foreach(['wa_auto_cita','wa_auto_cumple','wa_cita_1dia','wa_cita_hoy','wa_confirma_cita'] as $k){ $v=isset($_POST[$k])?'1':'0'; db()->prepare("INSERT INTO configuracion(clave,valor) VALUES(?,?) ON DUPLICATE KEY UPDATE valor=?")->execute([$k,$v,$v]); }
  flash('ok','Configuración de WhatsApp guardada.'); go('pages/notificaciones.php');
}
// Envío de prueba (solo admin)
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion']??'')==='test_wa' && esRol('admin')){
  $tel=trim($_POST['test_tel']??''); $msg=trim($_POST['test_msg']??''); if($msg==='') $msg='Mensaje de prueba desde '.getCfg('clinica_nombre','la clínica');
  $ok=wa_enviar($tel,$msg);
  flash($ok?'ok':'error', $ok?'✅ Mensaje de prueba enviado a '.$tel:'❌ No se pudo enviar. Revisa que el WhatsApp esté conectado (menú Conexión WhatsApp) y la URL configurada.');
  go('pages/notificaciones.php');
}

// Citas de hoy y mañana para recordatorios
$citas_hoy=db()->query("SELECT c.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac,p.telefono,p.email FROM citas c JOIN pacientes p ON c.paciente_id=p.id WHERE c.fecha=CURDATE() AND c.estado IN('pendiente','confirmado') ORDER BY c.hora_inicio")->fetchAll();
$citas_manana=db()->query("SELECT c.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac,p.telefono FROM citas c JOIN pacientes p ON c.paciente_id=p.id WHERE c.fecha=DATE_ADD(CURDATE(),INTERVAL 1 DAY) AND c.estado IN('pendiente','confirmado') ORDER BY c.hora_inicio")->fetchAll();

// Plantillas configuradas
$plantilla_cita=getCfg('plantilla_wa_cita','Estimado(a) *{nombre}*, le recordamos su cita en *{clinica}* el *{fecha}* a las *{hora}*. Ante consultas: {telefono}');
$plantilla_cumple=getCfg('plantilla_wa_cumple','¡Feliz cumpleaños, *{nombre}*! 🎉 De parte de todo el equipo de *{clinica}* te deseamos un día lleno de alegría. 🦷✨');

require_once __DIR__.'/../includes/header.php';
?>
<?=popFlash()?>
<div class="row g-4">
 <div class="col-12 col-lg-4 order-2 order-lg-1">
  <!-- Plantillas WA -->
  <div class="card mb-4">
   <div class="card-header"><span style="color:var(--t)"><i class="bi bi-whatsapp me-1"></i>Plantillas de mensaje</span></div>
   <div class="p-4">
    <div class="mb-3"><label class="form-label">📅 Recordatorio de cita</label>
    <textarea class="form-control" rows="4" id="tplCita"><?=e($plantilla_cita)?></textarea>
    <small style="color:var(--t2);font-size:11px">Variables: {nombre} {clinica} {fecha} {hora} {telefono}</small></div>
    <div class="mb-3"><label class="form-label">✅ Confirmación de pago</label>
    <textarea class="form-control" rows="4" id="tplPago">Estimado(a) *{nombre}*, su pago de *{monto}* ha sido registrado. Código: {codigo}. Gracias por su confianza. — {clinica}</textarea>
    <small style="color:var(--t2);font-size:11px">Variables: {nombre} {monto} {codigo} {clinica}</small></div>
    <div class="mb-3"><label class="form-label">🔔 Mensaje personalizado</label>
    <textarea class="form-control" rows="4" id="tplCustom">Estimado(a) paciente, le contactamos desde <?=getCfg('clinica_nombre')?>. </textarea></div>
   </div>
  </div>
  <!-- Stats -->
  <div class="card">
   <div class="card-header"><span style="color:var(--t)">📊 Estadísticas</span></div>
   <div class="p-4" style="font-size:13px">
    <?php $enviados=db()->query("SELECT COUNT(*) FROM notificaciones WHERE DATE(created_at)=CURDATE()")->fetchColumn();
    $pend_citas=db()->query("SELECT COUNT(*) FROM citas WHERE fecha=CURDATE() AND estado='pendiente'")->fetchColumn(); ?>
    <div class="d-flex justify-content-between py-2" style="border-bottom:1px solid var(--bd2)"><span style="color:var(--t)">Notificaciones hoy</span><span class="badge bc"><?=$enviados?></span></div>
    <div class="d-flex justify-content-between py-2" style="border-bottom:1px solid var(--bd2)"><span style="color:var(--t)">Citas hoy pendientes</span><span class="badge ba"><?=$pend_citas?></span></div>
    <div class="d-flex justify-content-between py-2"><span style="color:var(--t)">Citas mañana</span><span class="badge bgr"><?=count($citas_manana)?></span></div>
   </div>
  </div>
 </div>

 <div class="col-12 col-lg-8 order-1 order-lg-2">
  <div class="nav-tabs-scroll"><ul class="nav nav-tabs mb-4" style="flex-wrap:nowrap">
   <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" data-bs-target="#tHoy">📅 Hoy (<?=count($citas_hoy)?>)</a></li>
   <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" data-bs-target="#tMan">📅 Mañana (<?=count($citas_manana)?>)</a></li>
   <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" data-bs-target="#tMasivo">📢 Envío masivo</a></li>
   <?php if(esRol('admin')): ?><li class="nav-item"><a class="nav-link" data-bs-toggle="tab" data-bs-target="#tConfig">⚙️ Configuración</a></li><?php endif; ?>
  </ul></div>
  <div class="tab-content">
   <!-- Citas hoy -->
   <div class="tab-pane fade show active" id="tHoy">
    <?php if($citas_hoy): ?>
    <div class="d-grid gap-3">
     <?php foreach($citas_hoy as $c): ?>
     <div class="card p-4">
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
       <div class="d-flex align-items-center gap-2">
        <div class="ava" style="width:34px;height:34px;font-size:13px"><?=strtoupper(substr($c['pac'],0,1))?></div>
        <div><strong><?=e($c['pac'])?></strong><br>
        <span class="mon" style="color:var(--c);font-size:12px"><?=substr($c['hora_inicio'],0,5)?></span>
        <?php if($c['telefono']): ?><span style="color:var(--t2);font-size:11px;margin-left:6px"><?=e($c['telefono'])?></span><?php endif; ?></div>
       </div>
       <span class="badge <?=$c['estado']==='confirmado'?'bg':'ba'?>"><?=$c['estado']?></span>
      </div>
      <?php if($c['telefono']): ?>
      <?php
       $msg=str_replace(['{nombre}','{clinica}','{fecha}','{hora}','{telefono}'],[$c['pac'],getCfg('clinica_nombre'),fDate($c['fecha']),substr($c['hora_inicio'],0,5),getCfg('clinica_telefono')],$plantilla_cita);
      ?>
      <div class="mt-3 d-flex gap-2 flex-wrap">
       <a href="<?=urlWA($c['telefono'],$msg)?>" target="_blank" class="btn btn-wa btn-sm"><i class="bi bi-whatsapp me-1"></i>Recordatorio WA</a>
       <button type="button" class="btn btn-dk btn-sm" onclick="abrirMensaje('<?=e($c['telefono'])?>','<?=e(addslashes($c['pac']))?>')"><i class="bi bi-chat-text me-1"></i>Personalizado</button>
      </div>
      <?php else: ?>
      <div class="mt-2"><small style="color:var(--r)">⚠️ Sin número de teléfono</small></div>
      <?php endif; ?>
     </div>
     <?php endforeach; ?>
    </div>
    <?php else: ?><div class="card p-4 text-center" style="color:var(--t2)"><i class="bi bi-calendar-x" style="font-size:36px;display:block;margin-bottom:8px"></i>No hay citas pendientes hoy</div><?php endif; ?>
   </div>
   <!-- Citas mañana -->
   <div class="tab-pane fade" id="tMan">
    <?php if($citas_manana): ?>
    <div class="d-grid gap-3">
     <?php foreach($citas_manana as $c):
      $msg=str_replace(['{nombre}','{clinica}','{fecha}','{hora}','{telefono}'],[$c['pac'],getCfg('clinica_nombre'),fDate($c['fecha']),substr($c['hora_inicio'],0,5),getCfg('clinica_telefono')],$plantilla_cita);
     ?>
     <div class="card p-4">
      <div class="d-flex justify-content-between align-items-center">
       <div class="d-flex align-items-center gap-2">
        <div class="ava" style="width:34px;height:34px;font-size:13px"><?=strtoupper(substr($c['pac'],0,1))?></div>
        <div><strong><?=e($c['pac'])?></strong><br><span class="mon" style="color:var(--c);font-size:12px"><?=substr($c['hora_inicio'],0,5)?></span></div>
       </div>
       <?php if($c['telefono']): ?>
       <a href="<?=urlWA($c['telefono'],$msg)?>" target="_blank" class="btn btn-wa btn-sm"><i class="bi bi-whatsapp me-1"></i>WA Recordatorio</a>
       <?php endif; ?>
      </div>
     </div>
     <?php endforeach; ?>
    </div>
    <?php else: ?><div class="card p-4 text-center" style="color:var(--t2)">No hay citas para mañana</div><?php endif; ?>
   </div>
   <!-- Envío masivo -->
   <div class="tab-pane fade" id="tMasivo">
    <div class="card p-4">
     <div class="mb-3"><label class="form-label">Plantilla a usar</label>
     <select class="form-select" onchange="document.getElementById('msgMasivo').value=getMsgTpl(this.value)">
      <option value="cita">📅 Recordatorio cita</option><option value="custom">✏️ Personalizado</option>
     </select></div>
     <div class="mb-4"><label class="form-label">Mensaje</label>
     <textarea class="form-control" rows="5" id="msgMasivo"><?=e($plantilla_cita)?></textarea></div>
     <div class="mb-4"><label class="form-label">Números (uno por línea)</label>
     <textarea class="form-control" rows="6" id="teléfonos" placeholder="987654321&#10;912345678&#10;..."></textarea></div>
     <button type="button" class="btn btn-wa" onclick="enviarMasivo()"><i class="bi bi-whatsapp me-2"></i>Abrir WhatsApp (1 por 1)</button>
     <div id="listWA" class="mt-3 d-grid gap-2"></div>
    </div>
   </div>
   <?php if(esRol('admin')):
     $autoCita=getCfg('wa_auto_cita','1')==='1'; $autoCumple=getCfg('wa_auto_cumple','1')==='1';
     $c1d=getCfg('wa_cita_1dia','1')==='1'; $choy=getCfg('wa_cita_hoy','1')==='1';
     $waUrl=getCfg('wa_url','http://127.0.0.1:3041'); $waTok=getCfg('wa_token','');
     $autoConf=getCfg('wa_confirma_cita','0')==='1';
     $plantilla_confirma=getCfg('plantilla_wa_confirma','Hola *{nombre}*, tu cita en *{clinica}* quedó agendada para el *{fecha}* a las *{hora}*. ¡Te esperamos! Ante consultas: {telefono}');
     $ck=fn($b)=>$b?'checked':'';
   ?>
   <!-- Configuración (solo admin) -->
   <div class="tab-pane fade" id="tConfig">
    <form method="POST" class="card p-4 mb-4">
     <input type="hidden" name="accion" value="guardar_config">
     <h6 style="color:var(--c)"><i class="bi bi-chat-left-text me-1"></i>Textos de los mensajes</h6>
     <div class="mb-3"><label class="form-label">📅 Recordatorio de cita</label>
      <textarea name="plantilla_wa_cita" class="form-control" rows="3"><?=e($plantilla_cita)?></textarea>
      <small style="color:var(--t2);font-size:11px">Variables: {nombre} {clinica} {fecha} {hora} {telefono}</small></div>
     <div class="mb-3"><label class="form-label">🎂 Saludo de cumpleaños</label>
      <textarea name="plantilla_wa_cumple" class="form-control" rows="3"><?=e($plantilla_cumple)?></textarea>
      <small style="color:var(--t2);font-size:11px">Variables: {nombre} {clinica} {telefono}</small></div>
     <div class="mb-3"><label class="form-label">✅ Confirmación al agendar la cita</label>
      <textarea name="plantilla_wa_confirma" class="form-control" rows="3"><?=e($plantilla_confirma)?></textarea>
      <small style="color:var(--t2);font-size:11px">Se envía al instante cuando se guarda una cita nueva (si está activado abajo). Variables: {nombre} {clinica} {fecha} {hora} {telefono}</small></div>

     <hr style="border-color:var(--bd2)">
     <h6 style="color:var(--c)"><i class="bi bi-gear me-1"></i>Envío automático</h6>
     <div class="d-flex flex-wrap gap-4 mb-3">
      <label class="d-flex align-items-center gap-2"><input type="checkbox" name="wa_auto_cita" <?=$ck($autoCita)?>> <span style="color:var(--t)">Recordatorios de cita</span></label>
      <label class="d-flex align-items-center gap-2" style="margin-left:18px"><input type="checkbox" name="wa_cita_1dia" <?=$ck($c1d)?>> <span style="color:var(--t2)">Un día antes</span></label>
      <label class="d-flex align-items-center gap-2"><input type="checkbox" name="wa_cita_hoy" <?=$ck($choy)?>> <span style="color:var(--t2)">El mismo día</span></label>
     </div>
     <div class="mb-3"><label class="d-flex align-items-center gap-2"><input type="checkbox" name="wa_auto_cumple" <?=$ck($autoCumple)?>> <span style="color:var(--t)">Saludos de cumpleaños</span></label></div>
     <div class="mb-1"><label class="d-flex align-items-center gap-2"><input type="checkbox" name="wa_confirma_cita" <?=$ck($autoConf)?>> <span style="color:var(--t)">Confirmación inmediata al agendar una cita</span></label>
      <small style="color:var(--t2);font-size:11px;margin-left:26px">Si está activo, al guardar una cita nueva se envía la confirmación al instante (además de los recordatorios del cron).</small></div>

     <hr style="border-color:var(--bd2)">
     <h6 style="color:var(--c)"><i class="bi bi-plug me-1"></i>Conexión con el microservicio de WhatsApp</h6>
     <div class="row g-3">
      <div class="col-md-7"><label class="form-label">URL del microservicio (de esta clínica)</label>
       <input type="text" name="wa_url" class="form-control" value="<?=e($waUrl)?>" placeholder="http://127.0.0.1:3031">
       <small style="color:var(--t2);font-size:11px">Puerto propio por clínica: 3031 / 3032 / 3033.</small></div>
      <div class="col-md-5"><label class="form-label">Token (igual al del micro)</label>
       <input type="text" name="wa_token" class="form-control" value="<?=e($waTok)?>" placeholder="token_secreto"></div>
     </div>
     <div style="color:var(--t3);font-size:11px;margin-top:8px"><i class="bi bi-qr-code me-1"></i>El QR para vincular el número está en el menú <strong>Conexión WhatsApp</strong>.</div>
     <div class="mt-4"><button class="btn btn-wa"><i class="bi bi-save me-1"></i>Guardar configuración</button></div>
    </form>

    <form method="POST" class="card p-4 mb-4">
     <input type="hidden" name="accion" value="test_wa">
     <h6 style="color:var(--c)"><i class="bi bi-send me-1"></i>Probar envío</h6>
     <div class="row g-3 align-items-end">
      <div class="col-md-4"><label class="form-label">Número</label><input type="text" name="test_tel" class="form-control" placeholder="987654321" required></div>
      <div class="col-md-6"><label class="form-label">Mensaje</label><input type="text" name="test_msg" class="form-control" placeholder="Mensaje de prueba"></div>
      <div class="col-md-2"><button class="btn btn-wa w-100"><i class="bi bi-whatsapp"></i></button></div>
     </div>
     <small style="color:var(--t2);font-size:11px;display:block;margin-top:8px">Requiere la URL del servicio configurada y conectada.</small>
    </form>

    <div class="card p-4">
     <h6 style="color:var(--c)"><i class="bi bi-clock-history me-1"></i>Últimos envíos automáticos</h6>
     <?php
      $tipoLbl=['cita_1d'=>'Cita (1 día antes)','cita_hoy'=>'Cita (hoy)','cumple'=>'Cumpleaños','cita_confirma'=>'Confirmación de cita'];
      $log=[]; try{ $log=db()->query("SELECT * FROM notificaciones WHERE tipo='whatsapp' ORDER BY id DESC LIMIT 15")->fetchAll(); }catch(Throwable $e){}
     ?>
     <?php if($log): ?>
     <div class="table-responsive"><table class="table mb-0">
      <thead><tr><th>Fecha</th><th>Tipo</th><th>Destino</th><th>Estado</th></tr></thead><tbody>
      <?php foreach($log as $l): ?>
       <tr><td style="color:var(--t2);white-space:nowrap"><?=fDate($l['created_at'])?> <?=substr($l['created_at'],11,5)?></td>
        <td style="color:var(--t)"><?=e($tipoLbl[$l['referencia_tipo']]??$l['referencia_tipo'])?></td>
        <td style="color:var(--t2)"><?=e($l['destinatario'])?></td>
        <td><span class="badge <?=$l['estado']==='enviado'?'bg':'br'?>"><?=e($l['estado'])?></span></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
     <?php else: ?><p style="color:var(--t2);font-size:13px;margin:0">Aún no hay envíos registrados.</p><?php endif; ?>
     <div style="color:var(--t3);font-size:11px;margin-top:12px;border-top:1px solid var(--bd2);padding-top:10px">
      <i class="bi bi-info-circle me-1"></i>El envío automático corre por <strong>cron</strong>: <code>php <?=dirname(__DIR__)?>/cron_recordatorios_wa.php</code> (recomendado 1 vez al día). El QR para vincular está en el menú <strong>Conexión WhatsApp</strong>.
     </div>
    </div>
   </div>
   <?php endif; ?>
  </div>
 </div>
</div>

<!-- Modal mensaje personalizado -->
<div class="modal fade" id="modMsg" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
 <div class="modal-header"><h5 class="modal-title">✉️ Mensaje personalizado</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
 <div class="modal-body p-4">
  <div id="infoMsgPac" class="mb-3 p-3 rounded" style="background:var(--bg3)"></div>
  <label class="form-label">Mensaje</label>
  <textarea class="form-control" rows="6" id="msgCustom"><?=e(getCfg('plantilla_wa_cita'))?></textarea>
 </div>
 <div class="modal-footer">
  <button type="button" class="btn btn-dk" data-bs-dismiss="modal">Cancelar</button>
  <a href="#" id="btnEnviar" target="_blank" class="btn btn-wa"><i class="bi bi-whatsapp me-2"></i>Abrir WhatsApp</a>
 </div>
</div></div></div>
<?php
$xscript='<script>
let curTel="";
function abrirMensaje(tel,nombre){
 curTel=tel;
 document.getElementById("infoMsgPac").innerHTML="<strong>"+nombre+"</strong> — "+tel;
 document.getElementById("btnEnviar").onclick=function(){
  const t=tel.replace(/[^0-9]/g,"");
  const num=t.length===9?"51"+t:t;
  this.href="https://web.whatsapp.com/send?phone="+num+"&text="+encodeURIComponent(document.getElementById("msgCustom").value);
 };
 new bootstrap.Modal(document.getElementById("modMsg")).show();
}
function getMsgTpl(t){
 const tpls={cita:document.getElementById("tplCita").value,custom:document.getElementById("tplCustom").value};
 return tpls[t]||"";
}
function enviarMasivo(){
 const msg=document.getElementById("msgMasivo").value;
 const lines=document.getElementById("teléfonos").value.split("\n").map(l=>l.trim()).filter(l=>l);
 const cont=document.getElementById("listWA"); cont.innerHTML="";
 lines.forEach(tel=>{
  const t=tel.replace(/[^0-9]/g,"");
  const num=t.length===9?"51"+t:t;
  const a=document.createElement("a");
  a.href="https://web.whatsapp.com/send?phone="+num+"&text="+encodeURIComponent(msg);
  a.target="_blank"; a.className="btn btn-wa btn-sm";
  a.innerHTML=\'<i class="bi bi-whatsapp me-1"></i>Enviar a +\'+num;
  cont.appendChild(a);
 });
}
</script>';
require_once __DIR__.'/../includes/footer.php';
