<?php
require_once __DIR__.'/../includes/config.php';
requiereLogin();
$titulo='WhatsApp y Notificaciones'; $pagina_activa='notif';

// Citas de hoy y mañana para recordatorios
$citas_hoy=db()->query("SELECT c.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac,p.telefono,p.email FROM citas c JOIN pacientes p ON c.paciente_id=p.id WHERE c.fecha=CURDATE() AND c.estado IN('pendiente','confirmado') ORDER BY c.hora_inicio")->fetchAll();
$citas_manana=db()->query("SELECT c.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac,p.telefono FROM citas c JOIN pacientes p ON c.paciente_id=p.id WHERE c.fecha=DATE_ADD(CURDATE(),INTERVAL 1 DAY) AND c.estado IN('pendiente','confirmado') ORDER BY c.hora_inicio")->fetchAll();

// Plantillas configuradas
$plantilla_cita=getCfg('plantilla_wa_cita','Estimado(a) *{nombre}*, le recordamos su cita en *{clinica}* el *{fecha}* a las *{hora}*. Ante consultas: {telefono}');

require_once __DIR__.'/../includes/header.php';
?>
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
