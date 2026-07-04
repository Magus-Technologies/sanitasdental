<?php
/* pages/admin/reservas.php — Configuración de la reserva de citas ONLINE.
   Horario de atención, duración de cada turno y días habilitados.
   Guarda en la tabla `configuracion` (claves reserva_hora_ini/fin/slot_min/dias). */
require_once __DIR__.'/../../includes/config.php';
requiereRol('admin');

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $ini  = preg_match('/^\d{2}:\d{2}$/', $_POST['ini'] ?? '') ? $_POST['ini'] : '08:00';
    $fin  = preg_match('/^\d{2}:\d{2}$/', $_POST['fin'] ?? '') ? $_POST['fin'] : '20:00';
    $slot = max(10, (int)($_POST['slot'] ?? 30));
    $dias = array_values(array_intersect(['1','2','3','4','5','6','7'], (array)($_POST['dias'] ?? [])));
    if (!$dias) $dias = ['1','2','3','4','5','6'];
    $diasStr = implode(',', $dias);
    foreach (['reserva_hora_ini'=>$ini,'reserva_hora_fin'=>$fin,'reserva_slot_min'=>(string)$slot,'reserva_dias'=>$diasStr] as $k=>$v) {
        db()->prepare("INSERT INTO configuracion(clave,valor) VALUES(?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)")->execute([$k,$v]);
    }
    if (function_exists('auditar')) { try { auditar('CONFIG_RESERVAS','configuracion',0); } catch(Throwable $e){} }
    flash('ok','Configuración de reservas guardada.');
    go('pages/admin/reservas.php');
}

$ini  = getCfg('reserva_hora_ini','08:00');
$fin  = getCfg('reserva_hora_fin','20:00');
$slot = (int)getCfg('reserva_slot_min','30');
$dias = explode(',', getCfg('reserva_dias','1,2,3,4,5,6'));
// Arma el enlace ABSOLUTO (con dominio) aunque BASE_URL sea solo una ruta como '/dental'.
$base = BASE_URL;
if (!preg_match('~^https?://~i', $base)) {
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS'])!=='off') ? 'https' : 'http';
    $base = $scheme.'://'.($_SERVER['HTTP_HOST'] ?? '').$base;
}
$link = rtrim($base,'/').'/reservar.php';
$dlabels = [1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo'];

$titulo = 'Reservas online';
$pagina_activa = 'reservas';
require_once __DIR__.'/../../includes/header.php';
?>
<div class="container-fluid py-3" style="max-width:760px">
  <h4 class="mb-1"><i class="bi bi-calendar-check me-2" style="color:var(--c)"></i>Reserva de citas online</h4>
  <p class="text-muted" style="font-size:14px">Configura el horario de atención que verán tus pacientes al reservar por el enlace público.</p>

  <div class="card mb-3" style="border-color:var(--bd2)">
    <div class="card-body">
      <label class="form-label fw-bold mb-1" style="font-size:13px">Enlace público para compartir</label>
      <div class="input-group">
        <input type="text" class="form-control" id="lnk" value="<?=e($link)?>" readonly>
        <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('lnk').value);this.innerHTML='<i class=\'bi bi-check2\'></i> Copiado'"><i class="bi bi-clipboard"></i> Copiar</button>
        <a class="btn btn-outline-primary" href="<?=e($link)?>" target="_blank"><i class="bi bi-box-arrow-up-right"></i> Abrir</a>
      </div>
      <small class="text-muted">Compártelo por WhatsApp, redes o tu web. Las reservas entran a tu agenda como <b>pendientes</b>.</small>
    </div>
  </div>

  <form method="post" class="card" style="border-color:var(--bd2)">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label fw-bold" style="font-size:13px">Hora de apertura</label>
          <input type="time" name="ini" class="form-control" value="<?=e($ini)?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-bold" style="font-size:13px">Hora de cierre</label>
          <input type="time" name="fin" class="form-control" value="<?=e($fin)?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-bold" style="font-size:13px">Duración por cita</label>
          <select name="slot" class="form-select">
            <?php foreach ([15,20,30,45,60] as $m): ?>
              <option value="<?=$m?>" <?=$slot===$m?'selected':''?>><?=$m?> min</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <label class="form-label fw-bold mt-3 mb-2" style="font-size:13px">Días de atención</label>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach ($dlabels as $num=>$lb): ?>
          <label class="border rounded-pill px-3 py-2" style="cursor:pointer;font-size:13px;<?=in_array((string)$num,$dias,true)?'background:rgba(6,182,212,.1);border-color:var(--c)!important;':''?>">
            <input type="checkbox" name="dias[]" value="<?=$num?>" <?=in_array((string)$num,$dias,true)?'checked':''?> class="me-1"><?=$lb?>
          </label>
        <?php endforeach; ?>
      </div>

      <div class="alert alert-light border mt-3 mb-0" style="font-size:12.5px">
        <i class="bi bi-info-circle me-1" style="color:var(--c)"></i>
        El último turno inicia de modo que termine justo a la hora de cierre. Ej.: cierre 20:00 con 30 min → el último turno es 19:30.
      </div>
    </div>
    <div class="card-footer bg-white text-end" style="border-color:var(--bd2)">
      <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Guardar configuración</button>
    </div>
  </form>
</div>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>
