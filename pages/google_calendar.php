<?php
/**
 * GOOGLE CALENDAR — Panel de integración
 * Conectar/desconectar, sincronizar, importar
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/GoogleCalendarService.php';
requiereLogin();

$accion = $_GET['accion'] ?? 'panel';
$uid    = (int)$_SESSION['uid'];
$svc    = new GoogleCalendarService($uid, true); // strict=true: only this user's token

// ── POST handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ap = $_POST['accion'] ?? '';

    // Desconectar
    if ($ap === 'desconectar') {
        $svc->disconnect();
        flash('ok', 'Google Calendar desconectado.');
        go('pages/google_calendar.php');
    }

    // Guardar calendario seleccionado
    if ($ap === 'guardar_calendario') {
        $calId     = trim($_POST['calendar_id'] ?? 'primary');
        $tokenData = $_SESSION['gc_token_tmp'] ?? null;
        if ($tokenData) {
            $svc->saveToken($tokenData, $calId);
            unset($_SESSION['gc_calendars'], $_SESSION['gc_token_tmp']);
            flash('ok', "✅ Conectado con calendario: " . e($_POST['calendar_name'] ?? $calId));
        }
        go('pages/google_calendar.php');
    }

    // Sincronizar TODAS las citas pendientes del doctor hacia Google
    if ($ap === 'sync_todas') {
        if (!$svc->isConnected()) { flash('error','No conectado'); go('pages/google_calendar.php'); }
        $desde  = $_POST['desde'] ?? date('Y-m-d');
        $hasta  = $_POST['hasta'] ?? date('Y-m-d', strtotime('+30 days'));
        // Sync citas del doctor conectado O todas si es admin
        $rol = getRol();
        if($rol==='admin'){
            $citas=db()->prepare("SELECT c.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac
                FROM citas c JOIN pacientes p ON c.paciente_id=p.id
                WHERE c.fecha BETWEEN ? AND ?
                AND c.estado NOT IN('cancelado','no_asistio')
                ORDER BY c.fecha,c.hora_inicio");
            $citas->execute([$desde,$hasta]);
        } else {
            $citas=db()->prepare("SELECT c.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac
                FROM citas c JOIN pacientes p ON c.paciente_id=p.id
                WHERE c.doctor_id=? AND c.fecha BETWEEN ? AND ?
                AND c.estado NOT IN('cancelado','no_asistio')
                ORDER BY c.fecha,c.hora_inicio");
            $citas->execute([$uid,$desde,$hasta]);
        }
        $citas  = $citas->fetchAll();
        $ok=0; $err=0;
        foreach ($citas as $c) {
            try {
                if ($c['google_event_id']) $svc->updateEvent($c);
                else                        $svc->createEvent($c);
                $ok++;
            } catch(Exception $e) { $err++; }
        }
        flash('ok', "Sincronizadas: $ok cita(s). Errores: $err.");
        go('pages/google_calendar.php');
    }

    // Importar evento individual con paciente asignado
    if ($ap === 'importar_evento') {
        if (!$svc->isConnected()) { flash('error','No conectado'); go('pages/google_calendar.php'); }
        $gid  = trim($_POST['google_id'] ?? '');
        $pid  = (int)($_POST['paciente_id'] ?? 0);
        $tipo = $_POST['tipo'] ?? 'primera_vez';
        if ($gid && $pid) {
            $ok = $svc->importSingleEvent($gid, $pid, $tipo);
            flash($ok?'ok':'error', $ok?'Cita importada correctamente.':'Error al importar.');
        } else {
            flash('error','Selecciona un paciente.');
        }
        go('pages/google_calendar.php?accion=importar_manual');
    }

    // Importar desde Google → sistema
    if ($ap === 'importar') {
        if (!$svc->isConnected()) { flash('error','No conectado'); go('pages/google_calendar.php'); }
        $desde  = $_POST['desde'] ?? date('Y-m-d');
        $hasta  = $_POST['hasta'] ?? date('Y-m-d', strtotime('+30 days'));
        $result = $svc->importFromGoogle($desde, $hasta);
        flash('ok', "Eventos encontrados: {$result['total']}. Importados: {$result['imported']}.");
        go('pages/google_calendar.php');
    }
}

// ── Elegir calendario ─────────────────────────────────────────────────────
if ($accion === 'elegir_calendario') {
    $calendarios = $_SESSION['gc_calendars'] ?? [];
    $titulo = 'Elegir Calendario'; $pagina_activa = 'gcal';
    $topbar_act = '';
    require_once __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center pb"><div class="col-12 col-lg-6">
  <div class="card">
    <div class="card-header"><span>&#128197; Selecciona tu Google Calendar</span></div>
    <div class="p-4">
      <p style="color:var(--t2);font-size:13px;margin-bottom:16px">
        Se encontraron varios calendarios en tu cuenta. Selecciona cuál quieres usar para las citas:
      </p>
      <form method="POST">
        <input type="hidden" name="accion" value="guardar_calendario">
        <div class="row g-2 mb-3">
          <?php foreach($calendarios as $cal): ?>
          <div class="col-12">
            <label class="d-flex align-items-center gap-3 p-3 rounded" style="background:var(--bg3);border:1px solid var(--bd2);cursor:pointer">
              <input type="radio" name="calendar_id" value="<?=e($cal['id'])?>"
                     data-name="<?=e($cal['summary']??$cal['id'])?>"
                     class="form-check-input mt-0" <?=$cal['primary']??false?'checked':''?>>
              <div>
                <div style="font-weight:700;color:var(--t)"><?=e($cal['summary']??'Sin nombre')?></div>
                <div style="font-size:11px;color:var(--t2)"><?=e($cal['id'])?></div>
              </div>
              <?php if($cal['primary']??false): ?>
              <span class="badge bg ms-auto">Principal</span>
              <?php endif; ?>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <input type="hidden" name="calendar_name" id="calNameInput" value="">
        <button type="submit" class="btn btn-primary w-100"
                onclick="document.getElementById('calNameInput').value=document.querySelector('[name=calendar_id]:checked')?.dataset.name||''">
          Usar este calendario
        </button>
      </form>
    </div>
  </div>
</div></div>
<?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}


// Importar manual - vista con lista de eventos de Google
if ($accion === 'importar_manual') {
    if (!$svc->isConnected()) { flash('error','Conecta Google Calendar primero'); go('pages/google_calendar.php'); }
    $desde   = $_GET['desde'] ?? date('Y-m-d');
    $hasta   = $_GET['hasta'] ?? date('Y-m-d', strtotime('+30 days'));
    $eventos = $svc->getGoogleEvents($desde, $hasta);
    $pacs    = db()->query("SELECT id,codigo,nombres,apellido_paterno FROM pacientes WHERE activo=1 ORDER BY apellido_paterno,nombres")->fetchAll();
    $titulo = 'Importar desde Google Calendar'; $pagina_activa = 'gcal';
    $topbar_act = '<a href="?" class="btn btn-dk btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver</a>';
    require_once __DIR__ . '/../includes/header.php';
?>
<div class="pb">
<?=popFlash()?>
<div class="card mb-3 p-3">
  <form method="GET" class="d-flex gap-2 flex-wrap align-items-end">
    <input type="hidden" name="accion" value="importar_manual">
    <div><label class="form-label">Desde</label><input type="date" name="desde" class="form-control" value="<?=e($desde)?>"></div>
    <div><label class="form-label">Hasta</label><input type="date" name="hasta" class="form-control" value="<?=e($hasta)?>"></div>
    <button class="btn btn-dk" style="margin-top:auto">Filtrar</button>
    <small class="ms-auto" style="color:var(--t2);align-self:center"><?=count($eventos)?> evento(s) nuevos en Google</small>
  </form>
</div>
<?php if(!$eventos): ?>
<div class="card p-5 text-center" style="color:var(--t2)">
  <i class="bi bi-calendar-check" style="font-size:40px;display:block;margin-bottom:10px"></i>
  No hay eventos nuevos en Google Calendar para importar en este rango.
</div>
<?php else: ?>
<p style="font-size:12px;color:var(--t2);margin-bottom:12px">
  <i class="bi bi-info-circle me-1"></i>Asigna un paciente a cada evento de Google para importarlo al sistema dental.
</p>
<?php foreach($eventos as $ev): ?>
<div style="background:var(--bg2);border:1px solid var(--bd2);border-radius:8px;padding:14px;margin-bottom:10px">
  <form method="POST">
    <input type="hidden" name="accion" value="importar_evento">
    <input type="hidden" name="google_id" value="<?=e($ev['google_id'])?>">
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-4">
        <div style="font-weight:700;color:var(--c)"><?=e($ev['titulo'])?></div>
        <div style="font-size:12px;color:var(--t2)">
          <i class="bi bi-calendar3 me-1"></i><?=e($ev['fecha_fmt'])?>
          &nbsp;<i class="bi bi-clock me-1"></i><?=e($ev['hora_ini'])?>&#8211;<?=e($ev['hora_fin'])?>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label" style="font-size:11px">Paciente *</label>
        <select name="paciente_id" class="form-select form-select-sm" required>
          <option value="">-- Seleccionar paciente --</option>
          <?php foreach($pacs as $p): ?>
          <option value="<?=$p['id']?>"><?=e($p['codigo'].' - '.$p['nombres'].' '.$p['apellido_paterno'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label" style="font-size:11px">Tipo</label>
        <select name="tipo" class="form-select form-select-sm">
          <option value="primera_vez">Primera vez</option>
          <option value="control">Control</option>
          <option value="urgencia">Urgencia</option>
          <option value="procedimiento">Procedimiento</option>
        </select>
      </div>
      <div class="col-12 col-md-1">
        <button type="submit" class="btn btn-primary btn-sm w-100">
          <i class="bi bi-arrow-down-circle"></i>
        </button>
      </div>
    </div>
  </form>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
<?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// ── Panel principal ───────────────────────────────────────────────────────
$connected  = $svc->isConnected();
$authUrl    = GoogleCalendarService::getAuthUrl('uid=' . $uid);

// Get sync stats
$total_citas   = 0;
$synced_citas  = 0;
$pending_sync  = 0;
if ($connected) {
    try { $rol_u = getRol();
    if($rol_u==='admin'){
        $row=db()->query("SELECT COUNT(*) as t, SUM(google_event_id IS NOT NULL) as s FROM citas WHERE fecha>=CURDATE()")->fetch();
    } else {
        $row=db()->prepare("SELECT COUNT(*) as t, SUM(google_event_id IS NOT NULL) as s FROM citas WHERE doctor_id=? AND fecha>=CURDATE()");
        $row->execute([$uid]); $row=$row->fetch();
    }} catch(Exception $e){ $row=['t'=>0,'s'=>0]; }
    $total_citas  = (int)($row['t'] ?? 0);
    $synced_citas = (int)($row['s'] ?? 0);
    $pending_sync = $total_citas - $synced_citas;
}

// Recent sync log
$log = db()->prepare("SELECT l.*,c.codigo FROM google_sync_log l LEFT JOIN citas c ON l.cita_id=c.id WHERE l.usuario_id=? ORDER BY l.created_at DESC LIMIT 20");
$log->execute([$uid]); $log=$log->fetchAll();

$titulo = 'Google Calendar'; $pagina_activa = 'gcal';
$topbar_act = '';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.gc-card{background:var(--bg2);border:1px solid var(--bd2);border-radius:12px;padding:20px;margin-bottom:16px}
.gc-stat{text-align:center;padding:16px}
.gc-stat-v{font-size:28px;font-weight:800;color:var(--c)}
.gc-stat-l{font-size:11px;color:var(--t2);text-transform:uppercase;letter-spacing:.5px}
.log-row{display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:12px}
.status-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.gc-connected{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);border-radius:10px;padding:16px}
.gc-disconnected{background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.2);border-radius:10px;padding:16px}
</style>

<div class="pb">
<?=popFlash()?>

<div class="row g-3">

  <!-- LEFT: Connection status -->
  <div class="col-12 col-lg-5">

    <!-- Status card -->
    <div class="gc-card">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t2);margin-bottom:12px">
        &#128197; Estado de conexión
      </div>

      <?php if ($connected): ?>
      <div class="gc-connected">
        <div class="d-flex align-items-center gap-3 mb-3">
          <img src="https://www.google.com/favicon.ico" width="24" height="24" alt="Google">
          <div>
            <div style="font-weight:700;color:#10b981">&#10003; Conectado</div>
            <div style="font-size:11px;color:var(--t2)">Calendario: <?=e($svc->getCalendarId())?></div>
          </div>
        </div>
        <form method="POST" onsubmit="return confirm('¿Desconectar Google Calendar?')">
          <input type="hidden" name="accion" value="desconectar">
          <button class="btn btn-del btn-sm w-100">
            <i class="bi bi-x-circle me-1"></i>Desconectar
          </button>
        </form>
      </div>
      <?php else: ?>
      <div class="gc-disconnected mb-3">
        <div style="color:var(--t2);font-size:13px;margin-bottom:12px">
          No has conectado tu Google Calendar todavía.
        </div>
      </div>
      <a href="<?=e($authUrl)?>" class="btn btn-primary w-100" style="gap:8px">
        <img src="https://www.google.com/favicon.ico" width="16" height="16" alt="G">
        Conectar con Google Calendar
      </a>
      <?php endif; ?>
    </div>

    <!-- Stats (only if connected) -->
    <?php if ($connected): ?>
    <div class="gc-card">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t2);margin-bottom:12px">Estadísticas</div>
      <div class="row g-0 text-center">
        <div class="col-4 gc-stat" style="border-right:1px solid var(--bd2)">
          <div class="gc-stat-v"><?=$total_citas?></div>
          <div class="gc-stat-l">Mis citas</div>
        </div>
        <div class="col-4 gc-stat" style="border-right:1px solid var(--bd2)">
          <div class="gc-stat-v" style="color:#10b981"><?=$synced_citas?></div>
          <div class="gc-stat-l">Sincronizadas</div>
        </div>
        <div class="col-4 gc-stat">
          <div class="gc-stat-v" style="color:<?=$pending_sync>0?'#f59e0b':'#10b981'?>"><?=$pending_sync?></div>
          <div class="gc-stat-l">Pendientes</div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Info box -->
    <div class="gc-card" style="background:rgba(0,212,238,.04);border-color:rgba(0,212,238,.15)">
      <div style="font-size:12px;color:var(--t2);line-height:1.6">
        <strong style="color:var(--c)">&#8505; Cómo funciona:</strong><br>
        <span style="color:#10b981">&#10003;</span> <strong>Al guardar una cita</strong> → aparece automáticamente en Google Calendar.<br>
        <span style="color:#10b981">&#10003;</span> <strong>Al cambiar estado</strong> → se actualiza el color en Google.<br>
        <span style="color:#10b981">&#10003;</span> <strong>Al cancelar</strong> → se elimina de Google Calendar.<br>
        <span style="color:#f59e0b">&#9888;</span> <strong>Sync masivo</strong>: para sincronizar todas las citas de un rango usa el botón Sync.<br>
        <span style="color:#f59e0b">&#9888;</span> <strong>Importar</strong>: trae eventos de Google que tengan nombre de paciente registrado.<br>
        <span style="color:#3b82f6">&#8505;</span> Cada doctor conecta su propio Google Calendar.
      </div>
    </div>

  </div>

  <!-- RIGHT: Actions + log -->
  <div class="col-12 col-lg-7">

    <?php if ($connected): ?>

    <!-- Sync todas -->
    <div class="gc-card">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t2);margin-bottom:12px">
        &#8593; Exportar al Google Calendar
      </div>
      <p style="font-size:12px;color:var(--t2);margin-bottom:12px">
        Envía todas tus citas del rango seleccionado a Google Calendar.
      </p>
      <form method="POST" class="row g-2">
        <input type="hidden" name="accion" value="sync_todas">
        <div class="col-12 col-sm-5">
          <label class="form-label">Desde</label>
          <input type="date" name="desde" class="form-control" value="<?=date('Y-m-d')?>">
        </div>
        <div class="col-12 col-sm-5">
          <label class="form-label">Hasta</label>
          <input type="date" name="hasta" class="form-control" value="<?=date('Y-m-d',strtotime('+30 days'))?>">
        </div>
        <div class="col-12 col-sm-2 d-flex align-items-end">
          <button class="btn btn-primary w-100">
            <i class="bi bi-arrow-up-circle-fill me-1"></i>Sync
          </button>
        </div>
      </form>
    </div>

    <!-- Import -->
    <div class="gc-card">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t2);margin-bottom:12px">
        &#8595; Importar desde Google Calendar
      </div>
      <p style="font-size:12px;color:var(--t2);margin-bottom:12px">
        Trae eventos de Google al sistema dental. Solo importa eventos que tengan un paciente identificado.
      </p>
      <form method="POST" class="row g-2">
        <input type="hidden" name="accion" value="importar">
        <div class="col-12 col-sm-5">
          <label class="form-label">Desde</label>
          <input type="date" name="desde" class="form-control" value="<?=date('Y-m-d')?>">
        </div>
        <div class="col-12 col-sm-5">
          <label class="form-label">Hasta</label>
          <input type="date" name="hasta" class="form-control" value="<?=date('Y-m-d',strtotime('+30 days'))?>">
        </div>
        <div class="col-12 col-sm-2 d-flex align-items-end">
          <a href="?accion=importar_manual" class="btn btn-dk w-100">
            <i class="bi bi-arrow-down-circle-fill me-1"></i>Importar
          </a>
        </div>
      </form>
    </div>

    <!-- Sync log -->
    <div class="gc-card">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t2);margin-bottom:10px">
        Historial de sincronización
      </div>
      <?php if (!$log): ?>
      <div style="color:var(--t3);font-size:12px">Sin actividad reciente.</div>
      <?php else: ?>
      <?php foreach($log as $l): ?>
      <div class="log-row">
        <div class="status-dot" style="background:<?=$l['estado']==='ok'?'#10b981':'#ef4444'?>"></div>
        <span style="color:var(--t);min-width:70px"><?=e($l['accion'])?></span>
        <span style="color:var(--t2)"><?=$l['codigo']?e($l['codigo']):'—'?></span>
        <?php if($l['detalle']): ?><span style="color:var(--t3);font-size:10px"><?=e(mb_substr($l['detalle'],0,40))?></span><?php endif; ?>
        <span class="ms-auto" style="color:var(--t3);font-size:10px;white-space:nowrap"><?=fDate($l['created_at'],'d/m H:i')?></span>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <div class="gc-card p-5 text-center">
      <i class="bi bi-calendar-x" style="font-size:48px;color:var(--t3);display:block;margin-bottom:12px"></i>
      <div style="color:var(--t2);font-size:14px;margin-bottom:16px">
        Conecta tu Google Calendar para empezar a sincronizar citas.
      </div>
      <a href="<?=e($authUrl)?>" class="btn btn-primary">
        <img src="https://www.google.com/favicon.ico" width="16" height="16" alt="G" style="margin-right:6px">
        Conectar ahora
      </a>
    </div>
    <?php endif; ?>

  </div>
</div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php';
