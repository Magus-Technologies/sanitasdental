<?php
/* Conexión de WhatsApp — muestra el QR del microservicio para vincular el celular. */
require_once __DIR__.'/../includes/config.php';
requiereLogin();
requiereModulo('notificaciones');
require_once __DIR__.'/../includes/wa_notify.php';

/* Proxy: estado + QR (consulta el micro por localhost y devuelve JSON al navegador) */
if (isset($_GET['estado'])) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(wa_estado()); exit; }
/* Proxy: desvincular (cambiar de número) */
if (isset($_GET['logout']) && $_SERVER['REQUEST_METHOD']==='POST') { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>wa_logout()]); exit; }

$titulo='Conexión de WhatsApp'; $pagina_activa='wacon';
require_once __DIR__.'/../includes/header.php';
?>
<div class="pb">
 <div style="margin-bottom:18px">
  <h3 style="color:var(--t);font-weight:800;margin:0">💬 Conexión de WhatsApp</h3>
  <p style="color:var(--t2);margin:4px 0 0">Vincula o cambia el número de WhatsApp que envía los mensajes a tus clientes</p>
 </div>

 <div class="row g-4">
  <div class="col-12 col-lg-7 mx-auto">
   <div class="card p-4 text-center">
    <div id="waBadge" class="mb-3"><span class="badge" style="background:#3a3320;color:#f1c40f;padding:8px 16px;font-size:13px">● Consultando estado…</span></div>
    <div id="waBody" style="min-height:300px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px">
     <div style="color:var(--t2)">Cargando…</div>
    </div>
    <div id="waActions" class="mt-3" style="display:none">
     <button type="button" class="btn btn-dk btn-sm" onclick="waCambiar()"><i class="bi bi-arrow-repeat me-1"></i>Cambiar de número / celular</button>
    </div>
    <div style="color:var(--t3);font-size:11px;margin-top:14px">El código se actualiza solo. Si expira, espera unos segundos.</div>
   </div>

   <div class="card p-4 mt-3">
    <div style="color:var(--t);font-weight:600;margin-bottom:8px">ℹ️ Cómo vincular un celular</div>
    <ol style="color:var(--t2);font-size:13px;margin:0;padding-left:18px;line-height:1.9">
     <li>En el celular, abre <strong style="color:var(--t)">WhatsApp</strong>.</li>
     <li>Ve a <strong style="color:var(--t)">Configuración → Dispositivos vinculados</strong>.</li>
     <li>Toca <strong style="color:var(--t)">Vincular un dispositivo</strong>.</li>
     <li>Escanea el código QR que aparece en esta pantalla.</li>
    </ol>
   </div>
  </div>
 </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
let waTimer=null;
function waBadge(html,bg,col){ document.getElementById('waBadge').innerHTML='<span class="badge" style="background:'+bg+';color:'+col+';padding:8px 16px;font-size:13px">'+html+'</span>'; }
function waShowQR(qr){
  const b=document.getElementById('waBody');
  if(/^data:|^https?:\/\//.test(qr)){ b.innerHTML='<p style="color:var(--t2);margin:0">Escanea este código con WhatsApp para vincular el celular</p><img src="'+qr+'" alt="QR" style="width:280px;height:280px;background:#fff;padding:10px;border-radius:12px">'; }
  else { b.innerHTML='<p style="color:var(--t2);margin:0">Escanea este código con WhatsApp para vincular el celular</p><div id="qrHolder" style="background:#fff;padding:12px;border-radius:12px;display:inline-block"></div>'; if(window.QRCode) new QRCode(document.getElementById('qrHolder'),{text:qr,width:260,height:260}); }
}
async function waPoll(){
  try{
    const r=await fetch('?estado=1',{cache:'no-store'}); const d=await r.json();
    const acts=document.getElementById('waActions');
    if(d.connected===true){
      waBadge('● Conectado','#1e3a2b','#2ECC8E');
      document.getElementById('waBody').innerHTML='<div style="font-size:56px">✅</div><div style="color:#2ECC8E;font-weight:700;font-size:18px">WhatsApp conectado'+(d.number?(' ('+d.number+')'):'')+'</div><div style="color:var(--t2);font-size:13px">Ya puede enviar recordatorios y saludos.</div>';
      acts.style.display='block';
    } else if(d.qr){
      waBadge('● Esperando escaneo del QR','#3a3320','#f1c40f');
      waShowQR(d.qr); acts.style.display='block';
    } else if(d.error){
      waBadge('● Sin conexión al servicio','#3a2424','#e05252');
      document.getElementById('waBody').innerHTML='<div style="color:var(--t2);max-width:380px">No se pudo contactar el servicio de WhatsApp. Verifica que el microservicio esté encendido y que la <strong>URL</strong> esté bien configurada en WhatsApp/Notif. → Configuración.</div>';
      acts.style.display='none';
    } else {
      waBadge('● Generando código…','#3a3320','#f1c40f');
      document.getElementById('waBody').innerHTML='<div style="color:var(--t2)">Generando código QR…</div>';
    }
  }catch(e){ waBadge('● Error','#3a2424','#e05252'); }
}
async function waCambiar(){
  if(!confirm('Esto desvinculará el WhatsApp actual y generará un nuevo QR para vincular otro número. ¿Continuar?')) return;
  try{ await fetch('?logout=1',{method:'POST'}); }catch(e){}
  setTimeout(waPoll, 1500);
}
waPoll(); waTimer=setInterval(waPoll, 4000);
</script>
<?php require_once __DIR__.'/../includes/footer.php';