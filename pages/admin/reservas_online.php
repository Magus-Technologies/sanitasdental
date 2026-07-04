<?php
/* pages/admin/reservas_online.php — Gestión de reservas ONLINE.
   Confirma (estado='confirmado') o rechaza (estado='cancelado') las citas que
   entran por el enlace público (origen='online'). Sincroniza Google Calendar. */
require_once __DIR__.'/../../includes/config.php';
requiereLogin();

/* Google Calendar disponible (mismo criterio que citas.php) */
if (!defined('GC_AVAILABLE')) define('GC_AVAILABLE',
    file_exists(__DIR__.'/../../includes/GoogleCalendarService.php') &&
    (function(){ try{ db()->query('SELECT 1 FROM google_calendar_tokens LIMIT 1'); return true; } catch(Throwable $e){ return false; } })());
if (GC_AVAILABLE) require_once __DIR__.'/../../includes/GoogleCalendarService.php';
if (file_exists(__DIR__.'/../../includes/wa_notify.php')) require_once __DIR__.'/../../includes/wa_notify.php';

/* Asegura la columna origen (por si abren esto antes de recibir una reserva) */
try { db()->exec("ALTER TABLE citas ADD COLUMN IF NOT EXISTS origen VARCHAR(20) NULL"); } catch(Throwable $e){}

$ver = $_GET['ver'] ?? 'pendientes';
if (!in_array($ver,['pendientes','confirmadas','rechazadas'],true)) $ver='pendientes';

/* ── Acciones ── */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $id = (int)($_POST['id'] ?? 0);
    $ac = $_POST['accion'] ?? '';
    if ($id && in_array($ac,['confirmar','rechazar'],true)) {
        if ($ac==='confirmar') {
            db()->prepare("UPDATE citas SET estado='confirmado',updated_at=NOW() WHERE id=? AND origen='online' AND estado='pendiente'")->execute([$id]);
            $msg='Cita confirmada.';
            // WhatsApp de confirmación al paciente (usa el mismo switch que el módulo citas: wa_confirma_cita)
            if (function_exists('wa_enviar') && getCfg('wa_confirma_cita','0')==='1') { try {
                $q=db()->prepare("SELECT CONCAT(p.nombres,' ',p.apellido_paterno) pac, p.telefono, c.fecha, c.hora_inicio
                                  FROM citas c JOIN pacientes p ON c.paciente_id=p.id WHERE c.id=?");
                $q->execute([$id]); $cf=$q->fetch();
                if ($cf && trim((string)($cf['telefono']??''))!=='') {
                    $tpl=getCfg('plantilla_wa_confirma','Hola *{nombre}*, tu cita en *{clinica}* quedó agendada para el *{fecha}* a las *{hora}*. ¡Te esperamos! Ante consultas: {telefono}');
                    $mc=wa_plantilla($tpl,['{nombre}'=>$cf['pac'],'{clinica}'=>getCfg('clinica_nombre','la clínica'),'{fecha}'=>fDate($cf['fecha']),'{hora}'=>substr($cf['hora_inicio'],0,5),'{telefono}'=>getCfg('clinica_telefono','')]);
                    $okc=wa_enviar($cf['telefono'],$mc);
                    db()->prepare("INSERT INTO notificaciones(tipo,destinatario,asunto,mensaje,estado,referencia_tipo,referencia_id) VALUES('whatsapp',?,?,?,?, 'cita_confirma', ?)")->execute([$cf['telefono'],'Confirmación de cita',$mc,$okc?'enviado':'error',$id]);
                }
            } catch(Throwable $e){} }
        } else {
            $mot  = trim($_POST['motivo'] ?? '');
            $nota = $mot!=='' ? " [Rechazada: $mot]" : ' [Rechazada online]';
            db()->prepare("UPDATE citas SET estado='cancelado',updated_at=NOW(),notas=CONCAT(COALESCE(notas,''),?) WHERE id=? AND origen='online'")->execute([$nota,$id]);
            $msg='Cita rechazada.';
        }
        // Sincronizar Google Calendar (best effort)
        if (GC_AVAILABLE) { try {
            $g=db()->prepare("SELECT c.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac FROM citas c JOIN pacientes p ON c.paciente_id=p.id WHERE c.id=?");
            $g->execute([$id]); $row=$g->fetch();
            if ($row) { $svc=new GoogleCalendarService((int)$row['doctor_id']);
                if ($svc->isConnected()) { if ($ac==='rechazar') $svc->deleteEvent($row); else $svc->updateEvent($row); } }
        } catch(Throwable $e){} }
        if (function_exists('auditar')) { try{ auditar(strtoupper($ac).'_RESERVA_ONLINE','citas',$id); }catch(Throwable $e){} }
        flash('ok',$msg);
        go('pages/admin/reservas_online.php?ver='.$ver);
    }
}

/* ── Conteos por estado ── */
$nP=$nC=$nR=0;
try {
    $cnt=db()->query("SELECT estado,COUNT(*) n FROM citas WHERE origen='online' GROUP BY estado")->fetchAll(\PDO::FETCH_KEY_PAIR);
    $nP=(int)($cnt['pendiente']??0); $nC=(int)($cnt['confirmado']??0); $nR=(int)($cnt['cancelado']??0);
} catch(Throwable $e){}

/* ── Lista según pestaña ── */
$estMap=['pendientes'=>'pendiente','confirmadas'=>'confirmado','rechazadas'=>'cancelado'];
$est=$estMap[$ver];
$w="c.origen='online' AND c.estado=?"; $pm=[$est];
if (function_exists('sede_filtro_sql')) $w.=sede_filtro_sql('c.sede_id');
$rows=[];
try {
    $sql="SELECT c.*, TRIM(CONCAT(p.nombres,' ',p.apellido_paterno,' ',COALESCE(p.apellido_materno,''))) AS pac,
                 p.dni AS pdni, p.telefono AS ptel, p.tipo_documento AS ptipo,
                 TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellidos,''))) AS dr
          FROM citas c
          JOIN pacientes p ON c.paciente_id=p.id
          LEFT JOIN usuarios u ON c.doctor_id=u.id
          WHERE $w ORDER BY c.fecha ".($ver==='pendientes'?'ASC':'DESC').", c.hora_inicio ASC";
    $st=db()->prepare($sql); $st->execute($pm); $rows=$st->fetchAll();
} catch(Throwable $e){ $rows=[]; }

function fdmy($f){ return $f?date('d/m/Y',strtotime($f)):'—'; }

$titulo='Reservas online';
$pagina_activa='reservas_online';
require_once __DIR__.'/../../includes/header.php';
?>
<style>
.ro-card{border:1px solid var(--bd2);border-radius:14px;padding:14px 16px;margin-bottom:10px;background:var(--bg2,#fff);display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between}
.ro-when{min-width:96px;text-align:center;background:rgba(6,182,212,.08);border-radius:11px;padding:8px 6px}
.ro-when .d{font-size:18px;font-weight:800;color:var(--c)}
.ro-when .h{font-size:12px;color:var(--t2)}
.ro-main{flex:1;min-width:190px}
.ro-main .nm{font-weight:700;font-size:15px}
.ro-chip{display:inline-flex;align-items:center;gap:5px;font-size:12px;color:var(--t2);background:var(--bg3);border:1px solid var(--bd2);border-radius:20px;padding:3px 10px;margin:4px 6px 0 0}
.ro-acts{display:flex;gap:8px}
.nav-pills .nav-link{font-size:13px;font-weight:700}
.badge-n{background:#EF4444;color:#fff;border-radius:20px;font-size:11px;padding:1px 7px;margin-left:5px}
</style>
<div class="container-fluid py-3" style="max-width:900px">
  <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-calendar2-check me-2" style="color:var(--c)"></i>Reservas online</h4>
    <a href="<?=BASE_URL?>/pages/admin/reservas.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-gear"></i> Configurar horario</a>
  </div>
  <p class="text-muted" style="font-size:14px">Citas que llegan por el enlace público. Confírmalas o recházalas.</p>

  <ul class="nav nav-pills mb-3 gap-2">
    <li class="nav-item"><a class="nav-link <?=$ver==='pendientes'?'active':''?>" href="?ver=pendientes">Por revisar<?php if($nP):?><span class="badge-n"><?=$nP?></span><?php endif;?></a></li>
    <li class="nav-item"><a class="nav-link <?=$ver==='confirmadas'?'active':''?>" href="?ver=confirmadas">Confirmadas (<?=$nC?>)</a></li>
    <li class="nav-item"><a class="nav-link <?=$ver==='rechazadas'?'active':''?>" href="?ver=rechazadas">Rechazadas (<?=$nR?>)</a></li>
  </ul>

  <?php if(!$rows): ?>
    <div class="text-center text-muted py-5">
      <i class="bi bi-inbox" style="font-size:38px;opacity:.5"></i>
      <p class="mt-2 mb-0">No hay reservas <?=$ver?>.</p>
    </div>
  <?php else: foreach($rows as $r): ?>
    <div class="ro-card">
      <div class="ro-when">
        <div class="d"><?=substr($r['hora_inicio'],0,5)?></div>
        <div class="h"><?=fdmy($r['fecha'])?></div>
      </div>
      <div class="ro-main">
        <div class="nm"><?=e($r['pac'])?></div>
        <div>
          <span class="ro-chip"><i class="bi bi-person-vcard"></i><?=e($r['ptipo']?:'DNI')?> <?=e($r['pdni'])?></span>
          <?php if($r['ptel']): ?><span class="ro-chip"><i class="bi bi-telephone"></i><?=e($r['ptel'])?></span><?php endif; ?>
          <span class="ro-chip"><i class="bi bi-clipboard2-pulse"></i><?=e($r['especialidad']?:$r['motivo']?:'Consulta')?></span>
          <?php if($r['dr']): ?><span class="ro-chip"><i class="bi bi-person-badge"></i><?=e($r['dr'])?></span><?php endif; ?>
          <span class="ro-chip"><i class="bi bi-hash"></i><?=e($r['codigo'])?></span>
        </div>
      </div>
      <div class="ro-acts">
        <?php if($ver==='pendientes'): ?>
          <form method="post" onsubmit="return confirm('¿Confirmar esta cita?')" style="display:inline">
            <input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="accion" value="confirmar">
            <button class="btn btn-success btn-sm"><i class="bi bi-check-lg"></i> Confirmar</button>
          </form>
          <form method="post" onsubmit="return pedirMotivo(this)" style="display:inline">
            <input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="accion" value="rechazar"><input type="hidden" name="motivo">
            <button class="btn btn-outline-danger btn-sm"><i class="bi bi-x-lg"></i> Rechazar</button>
          </form>
        <?php elseif($ver==='confirmadas'): ?>
          <a href="<?=BASE_URL?>/pages/citas.php?accion=ver&id=<?=$r['id']?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-eye"></i> Ver en agenda</a>
          <span class="badge bg-success align-self-center">Confirmada</span>
        <?php else: ?>
          <span class="badge bg-secondary align-self-center">Rechazada</span>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; endif; ?>
</div>
<script>
function pedirMotivo(f){ const m=prompt('Motivo del rechazo (opcional):',''); if(m===null) return false; f.motivo.value=m; return true; }
</script>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>
