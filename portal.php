<?php
/**
 * PORTAL DEL PACIENTE
 * Acceso por enlace con token:  /portal.php?t=TOKEN
 * Luego navega por secciones:   /portal.php?v=citas|recetas|presupuestos|pagos|tratamiento
 * Imprimibles:                  /portal.php?v=receta&id=  |  ?v=presupuesto&id=
 * Salir:                        /portal.php?salir
 *
 * Todo el contenido se filtra por el paciente autenticado (aislado).
 */
require_once __DIR__ . '/includes/config.php';
sesion();

/* ───────── Salir ───────── */
if (isset($_GET['salir'])) { unset($_SESSION['portal_pid']); header('Location:' . BASE_URL . '/portal.php?bye=1'); exit; }

/* ───────── Datos de la empresa (marca) ───────── */
$emp     = empresa();
$logo    = !empty($emp['logo']) ? BASE_URL . '/uploads/' . ltrim($emp['logo'], '/') : '';
$clinica = $emp['nombre_comercial'] ?: ($emp['razon_social'] ?: getCfg('clinica_nombre', 'Clínica Dental'));
$telCli  = $emp['telefono'] ?? '';
$MON     = getCfg('moneda', 'S/');

/* ───────── Autenticación por token ───────── */
$authError = false;
if (isset($_GET['t'])) {
    $tok = preg_replace('/[^a-f0-9]/i', '', $_GET['t'] ?? '');
    if (strlen($tok) >= 32) {
        $r = db()->prepare("SELECT id FROM pacientes WHERE portal_token=? AND activo=1 AND deleted_at IS NULL");
        $r->execute([$tok]);
        $fid = (int)$r->fetchColumn();
        if ($fid) { $_SESSION['portal_pid'] = $fid; header('Location:' . BASE_URL . '/portal.php'); exit; }
    }
    $authError = true;
}

$pid = (int)($_SESSION['portal_pid'] ?? 0);
$pac = null;
if ($pid) {
    $s = db()->prepare("SELECT * FROM pacientes WHERE id=? AND activo=1 AND deleted_at IS NULL");
    $s->execute([$pid]); $pac = $s->fetch();
    if (!$pac) { unset($_SESSION['portal_pid']); $pid = 0; }
}

/* ───────── Helpers de presentación ───────── */
function pe(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function pmoney($m): string { global $MON; return $MON . ' ' . number_format((float)$m, 2); }
function pfecha(?string $d): string { return $d ? date('d/m/Y', strtotime($d)) : '—'; }
function phora(?string $h): string { return $h ? date('g:i A', strtotime($h)) : ''; }
function pfechaLarga(?string $d): string {
    if (!$d) return '—';
    $ts = strtotime($d);
    $dias = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
    $mes  = ['','enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    return $dias[(int)date('w',$ts)].' '.date('j',$ts).' de '.$mes[(int)date('n',$ts)].', '.date('Y',$ts);
}

$CITA_EST = [
    'pendiente'   => ['#b45309', '#fef3c7', 'Pendiente'],
    'confirmado'  => ['#0e7490', '#cffafe', 'Confirmada'],
    'en_atencion' => ['#6d28d9', '#ede9fe', 'En atención'],
    'atendido'    => ['#15803d', '#dcfce7', 'Atendida'],
    'no_asistio'  => ['#b91c1c', '#fee2e2', 'No asistió'],
    'cancelado'   => ['#64748b', '#f1f5f9', 'Cancelada'],
];

/* ════════════════════════════════════════════════════════════
   CABECERA HTML (tema claro)
   ════════════════════════════════════════════════════════════ */
function portal_head(string $title, string $clinica): void { ?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=pe($title)?> · <?=pe($clinica)?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root{
  --teal:#0a8aa0; --teal-d:#076073; --teal-l:#12b3c7;
  --ink:#15303a; --muted:#5b7682; --line:#e3edf0;
  --bg:#eef4f6; --card:#ffffff; --soft:#f5fafb;
  --ok:#15803d; --warn:#b45309; --bad:#b91c1c;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Outfit',sans-serif;color:var(--ink);background:
  radial-gradient(1200px 500px at 100% -10%, rgba(18,179,199,.12), transparent 60%),
  radial-gradient(900px 500px at -10% 0%, rgba(10,138,160,.10), transparent 55%),
  var(--bg); min-height:100vh; line-height:1.5;
  -webkit-font-smoothing:antialiased;}
a{color:inherit;text-decoration:none}
.wrap{max-width:920px;margin:0 auto;padding:0 16px 90px}
h1,h2,h3{font-family:'Fraunces',serif;font-weight:600;letter-spacing:-.01em;color:var(--ink)}

/* Header */
.top{background:linear-gradient(135deg,var(--teal-d),var(--teal));color:#fff;position:relative;overflow:hidden}
.top::after{content:"";position:absolute;right:-60px;top:-60px;width:260px;height:260px;border-radius:50%;background:rgba(255,255,255,.07)}
.top .wrap{padding:22px 16px 26px;position:relative;z-index:1}
.top-row{display:flex;align-items:center;gap:14px}
.brand{display:flex;align-items:center;gap:12px;flex:1;min-width:0}
.brand .logo{width:48px;height:48px;border-radius:14px;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;flex:0 0 auto;box-shadow:0 6px 18px rgba(0,0,0,.18)}
.brand .logo img{max-width:100%;max-height:100%}
.brand .logo .ph{font-size:24px}
.brand .name{font-family:'Fraunces',serif;font-size:18px;font-weight:600;line-height:1.1}
.brand .sub{font-size:11px;opacity:.85;letter-spacing:.12em;text-transform:uppercase}
.logout{color:#fff;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.25);border-radius:999px;padding:7px 14px;font-size:13px;font-weight:500;white-space:nowrap}
.logout:hover{background:rgba(255,255,255,.24)}
.hi{margin-top:18px}
.hi .h{font-family:'Fraunces',serif;font-size:26px;font-weight:600}
.hi .p{opacity:.9;font-size:14px}

/* Nav (tabs) */
.nav{position:sticky;top:0;z-index:20;background:rgba(255,255,255,.9);backdrop-filter:blur(10px);border-bottom:1px solid var(--line)}
.nav .wrap{display:flex;gap:4px;overflow-x:auto;padding:0 12px;-ms-overflow-style:none;scrollbar-width:none}
.nav .wrap::-webkit-scrollbar{display:none}
.nav a{flex:0 0 auto;padding:14px 14px;font-size:14px;font-weight:500;color:var(--muted);border-bottom:3px solid transparent;white-space:nowrap}
.nav a i{margin-right:6px}
.nav a.on{color:var(--teal-d);border-bottom-color:var(--teal)}
.nav a:hover{color:var(--teal-d)}

/* Cards */
.grid{display:grid;gap:14px;margin-top:18px}
.g2{grid-template-columns:repeat(2,1fr)} .g3{grid-template-columns:repeat(3,1fr)}
@media(max-width:640px){.g3{grid-template-columns:repeat(2,1fr)} .g2{grid-template-columns:1fr}}
.card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:18px;box-shadow:0 8px 26px rgba(15,48,58,.05)}
.card h3{font-size:16px;margin-bottom:4px}
.stat{display:flex;flex-direction:column;gap:2px}
.stat .n{font-family:'Fraunces',serif;font-size:26px;font-weight:600;color:var(--teal-d)}
.stat .l{font-size:12px;color:var(--muted)}
.stat .ic{width:38px;height:38px;border-radius:12px;background:var(--soft);color:var(--teal);display:flex;align-items:center;justify-content:center;font-size:18px;margin-bottom:8px}

.sec-title{display:flex;align-items:center;gap:10px;margin:26px 2px 12px}
.sec-title h2{font-size:20px}
.sec-title .ln{flex:1;height:1px;background:var(--line)}

.item{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:15px 16px;display:flex;gap:14px;align-items:flex-start;box-shadow:0 6px 18px rgba(15,48,58,.04)}
.item + .item{margin-top:10px}
.item .ic{width:44px;height:44px;border-radius:13px;flex:0 0 auto;display:flex;align-items:center;justify-content:center;font-size:20px;background:var(--soft);color:var(--teal)}
.item .bd{flex:1;min-width:0}
.item .t{font-weight:600;font-size:15px}
.item .m{font-size:13px;color:var(--muted)}
.item .r{text-align:right;white-space:nowrap}
.badge{display:inline-block;font-size:11.5px;font-weight:600;padding:3px 10px;border-radius:999px}
.btn{display:inline-flex;align-items:center;gap:6px;background:var(--teal);color:#fff;border:none;border-radius:999px;padding:9px 16px;font-size:13.5px;font-weight:600;font-family:inherit;cursor:pointer}
.btn:hover{background:var(--teal-d)}
.btn.ghost{background:var(--soft);color:var(--teal-d);border:1px solid var(--line)}
.empty{text-align:center;color:var(--muted);padding:42px 16px}
.empty i{font-size:40px;color:var(--teal-l);display:block;margin-bottom:10px;opacity:.7}
.money{font-family:'Fraunces',serif;font-weight:600}

/* Progreso */
.bar{height:9px;border-radius:999px;background:var(--soft);overflow:hidden;border:1px solid var(--line)}
.bar > span{display:block;height:100%;background:linear-gradient(90deg,var(--teal),var(--teal-l));border-radius:999px}

/* Timeline */
.tl{position:relative;margin-left:8px;padding-left:22px;border-left:2px solid var(--line)}
.tl .ev{position:relative;padding:0 0 18px}
.tl .ev::before{content:"";position:absolute;left:-29px;top:2px;width:12px;height:12px;border-radius:50%;background:var(--teal);box-shadow:0 0 0 4px var(--soft)}
.tl .ev .d{font-size:12px;color:var(--teal-d);font-weight:600}
.foot{text-align:center;color:var(--muted);font-size:12px;padding:26px 0 0}
/* Encuesta */
.rate{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.rate input{position:absolute;opacity:0;width:0;height:0}
.rate label{flex:1;min-width:88px;text-align:center;border:1.5px solid var(--line);border-radius:14px;padding:12px 6px;cursor:pointer;background:var(--card);transition:.15s}
.rate label .em{font-size:30px;display:block;line-height:1}
.rate label .tx{font-size:11px;color:var(--muted);margin-top:6px;display:block;font-weight:500}
.rate input:checked + label{border-color:var(--teal);background:var(--soft);box-shadow:0 0 0 3px rgba(18,179,199,.16)}
.rate label:hover{border-color:var(--teal-l)}
.thanks{text-align:center;padding:30px 16px}
.thanks .big{font-size:56px;line-height:1}
.thanks h2{margin-top:8px}
textarea.fld{width:100%;border:1px solid var(--line);border-radius:12px;padding:11px 13px;font-family:inherit;font-size:14px;color:var(--ink);background:var(--card);resize:vertical}
</style></head><body>
<?php }

/* ════════════════════════════════════════════════════════════
   SHELL: header + nav
   ════════════════════════════════════════════════════════════ */
function portal_shell_top(array $pac, string $clinica, string $logo, string $v): void {
    $tabs = [
        'inicio'       => ['bi-house-heart', 'Inicio'],
        'citas'        => ['bi-calendar-check', 'Mis citas'],
        'tratamiento'  => ['bi-activity', 'Mi tratamiento'],
        'recetas'      => ['bi-capsule', 'Recetas'],
        'presupuestos' => ['bi-receipt', 'Presupuestos'],
        'pagos'        => ['bi-wallet2', 'Pagos'],
        'encuesta'     => ['bi-star', 'Encuesta'],
    ];
    portal_head(ucfirst($v), $clinica);
    ?>
    <header class="top"><div class="wrap">
      <div class="top-row">
        <div class="brand">
          <div class="logo"><?php if($logo): ?><img src="<?=pe($logo)?>" alt=""><?php else: ?><span class="ph">🦷</span><?php endif; ?></div>
          <div><div class="name"><?=pe($clinica)?></div><div class="sub">Portal del paciente</div></div>
        </div>
        <a class="logout" href="<?=BASE_URL?>/portal.php?salir"><i class="bi bi-box-arrow-right"></i> Salir</a>
      </div>
      <div class="hi">
        <div class="h">Hola, <?=pe($pac['nombres'])?> 👋</div>
        <div class="p"><?=pe($pac['codigo'])?><?php if(!empty($pac['fecha_nacimiento'])): ?> · <?=pe((new DateTime($pac['fecha_nacimiento']))->diff(new DateTime())->y)?> años<?php endif; ?></div>
      </div>
    </div></header>
    <nav class="nav"><div class="wrap">
      <?php foreach($tabs as $k=>$t): ?>
        <a href="?v=<?=$k?>" class="<?=$v===$k?'on':''?>"><i class="bi <?=$t[0]?>"></i><?=$t[1]?></a>
      <?php endforeach; ?>
    </div></nav>
    <main class="wrap">
    <?php
}
function portal_shell_bottom(string $clinica, string $tel): void { ?>
    <div class="foot"><?=pe($clinica)?><?php if($tel): ?> · Tel: <?=pe($tel)?><?php endif; ?><br>Portal del paciente</div>
    </main></body></html>
<?php }

/* ════════════════════════════════════════════════════════════
   PÁGINA DE ACCESO (token inválido / sin sesión)
   ════════════════════════════════════════════════════════════ */
function portal_acceso(string $clinica, string $logo, bool $error, bool $bye): void {
    portal_head('Acceso', $clinica); ?>
    <div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px">
      <div class="card" style="max-width:420px;text-align:center;padding:34px 26px">
        <div class="logo" style="width:64px;height:64px;border-radius:18px;background:var(--soft);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;overflow:hidden">
          <?php if($logo): ?><img src="<?=pe($logo)?>" style="max-width:100%;max-height:100%" alt=""><?php else: ?><span style="font-size:30px">🦷</span><?php endif; ?>
        </div>
        <h1 style="font-size:22px"><?=pe($clinica)?></h1>
        <p style="color:var(--muted);margin-top:6px">Portal del paciente</p>
        <?php if($bye): ?>
          <div style="margin-top:20px;background:var(--soft);border:1px solid var(--line);border-radius:14px;padding:16px;color:var(--teal-d)"><i class="bi bi-check-circle"></i> Cerraste tu sesión correctamente.</div>
        <?php elseif($error): ?>
          <div style="margin-top:20px;background:#fff1f1;border:1px solid #ffd7d7;border-radius:14px;padding:16px;color:var(--bad)"><i class="bi bi-exclamation-triangle"></i> El enlace no es válido o expiró.</div>
        <?php endif; ?>
        <p style="margin-top:18px;color:var(--muted);font-size:14px">Para ingresar, usa el <strong>enlace de acceso</strong> que te compartió la clínica. Si no lo tienes, solicítalo en recepción.</p>
      </div>
    </div></body></html>
<?php }

/* ════════════════════════════════════════════════════════════
   RUTEO
   ════════════════════════════════════════════════════════════ */
if (!$pid || !$pac) { portal_acceso($clinica, $logo, $authError, isset($_GET['bye'])); exit; }

/* ───────── Guardar encuesta de satisfacción ───────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'encuesta') {
    $cc = (int)($_POST['calif_clinica'] ?? 0);
    $cd = (int)($_POST['calif_doctor'] ?? 0);
    $com = trim($_POST['comentario'] ?? '');
    if ($cc >= 1 && $cc <= 5) {
        try {
            // Solo una encuesta por paciente: si ya respondió, no se vuelve a guardar.
            $ya = (int)(db()->query("SELECT COUNT(*) FROM encuestas_satisfaccion WHERE paciente_id=".$pid)->fetchColumn());
            if (!$ya) {
                $docId = db()->prepare("SELECT doctor_id FROM citas WHERE paciente_id=? AND doctor_id IS NOT NULL ORDER BY fecha DESC, id DESC LIMIT 1");
                $docId->execute([$pid]); $docId = (int)$docId->fetchColumn() ?: null;
                db()->prepare("INSERT INTO encuestas_satisfaccion(paciente_id,doctor_id,calif_clinica,calif_doctor,comentario) VALUES(?,?,?,?,?)")
                    ->execute([$pid, $docId, $cc, ($cd >= 1 && $cd <= 5 ? $cd : null), ($com !== '' ? $com : null)]);
            }
        } catch (Throwable $e) { /* tabla no creada en este cliente */ }
    }
    header('Location:' . BASE_URL . '/portal.php?v=encuesta&ok=1'); exit;
}

$v = $_GET['v'] ?? 'inicio';

/* ---------- IMPRIMIBLE: RECETA ---------- */
if ($v === 'receta' && isset($_GET['id'])) {
    $rid = (int)$_GET['id'];
    $r = db()->prepare("SELECT r.*, CONCAT(u.nombre,' ',u.apellidos) doctor, u.cmp, u.firma_imagen FROM recetas r LEFT JOIN usuarios u ON r.doctor_id=u.id WHERE r.id=? AND r.paciente_id=?");
    $r->execute([$rid, $pid]); $r = $r->fetch();
    if (!$r) { http_response_code(404); exit('Receta no encontrada'); }
    $meds = db()->prepare("SELECT * FROM receta_medicamentos WHERE receta_id=? ORDER BY orden"); $meds->execute([$rid]); $meds = $meds->fetchAll();
    $firma = !empty($r['firma_imagen']) ? BASE_URL.'/uploads/'.ltrim($r['firma_imagen'],'/') : '';
    portal_print_doc($clinica, $logo, $emp, 'RECETA MÉDICA', $r['codigo'] ?? ('#'.$r['id']), $pac, function() use ($r,$meds,$firma) { ?>
        <div class="meta"><span>Fecha: <b><?=pfecha($r['fecha_prescripcion'])?></b></span><?php if($r['valido_hasta']): ?><span>Válida hasta: <b><?=pfecha($r['valido_hasta'])?></b></span><?php endif; ?></div>
        <table class="t"><thead><tr><th>Medicamento</th><th>Tomas</th><th>Frecuencia</th><th>Indicaciones</th></tr></thead><tbody>
        <?php foreach($meds as $m): ?>
          <tr><td><b><?=pe($m['medicamento'])?></b></td><td><?=pe($m['numero_tomas'])?></td><td><?=pe($m['frecuencia'])?></td><td><?=pe($m['indicaciones'])?></td></tr>
        <?php endforeach; if(!$meds): ?><tr><td colspan="4" style="text-align:center;color:#999">Sin medicamentos</td></tr><?php endif; ?>
        </tbody></table>
        <?php if(trim((string)$r['indicaciones_generales'])!==''): ?><p style="margin-top:12px"><b>Indicaciones generales:</b><br><?=nl2br(pe($r['indicaciones_generales']))?></p><?php endif; ?>
        <div class="sign"><?php if($firma): ?><img src="<?=pe($firma)?>"><?php endif; ?><div class="ln">Dr(a). <?=pe($r['doctor']?:'')?><?=!empty($r['cmp'])?' · CMP: '.pe($r['cmp']):''?></div></div>
    <?php });
    exit;
}

/* ---------- IMPRIMIBLE: PRESUPUESTO ---------- */
if ($v === 'presupuesto' && isset($_GET['id'])) {
    try {
        $p = db()->prepare("SELECT pr.*, CONCAT(u.nombre,' ',u.apellidos) doctor FROM presupuestos pr LEFT JOIN usuarios u ON pr.doctor_id=u.id WHERE pr.id=? AND pr.paciente_id=?");
        $p->execute([(int)$_GET['id'], $pid]); $p = $p->fetch();
        if (!$p) { http_response_code(404); exit('Presupuesto no encontrado'); }
        $det = db()->prepare("SELECT * FROM presupuesto_detalles WHERE presupuesto_id=? ORDER BY orden"); $det->execute([(int)$_GET['id']]); $det = $det->fetchAll();
    } catch (Throwable $e) { exit('No disponible'); }
    portal_print_doc($clinica, $logo, $emp, 'PRESUPUESTO', $p['codigo'] ?? ('#'.$p['id']), $pac, function() use ($p,$det) { ?>
        <div class="meta"><span>Fecha: <b><?=pfecha($p['fecha'])?></b></span><span>Válido hasta: <b><?=pfecha($p['fecha_vencimiento'])?></b></span><?php if($p['doctor']): ?><span>Doctor(a): <b><?=pe($p['doctor'])?></b></span><?php endif; ?></div>
        <table class="t"><thead><tr><th>#</th><th>Descripción</th><th>Diente</th><th style="text-align:center">Cant.</th><th style="text-align:right">P. Unit</th><th style="text-align:right">Subtotal</th></tr></thead><tbody>
        <?php foreach($det as $i=>$d): ?>
          <tr><td><?=$i+1?></td><td><?=pe($d['nombre'])?></td><td style="text-align:center"><?=pe($d['diente']?:'—')?></td><td style="text-align:center"><?=(int)$d['cantidad']?></td><td style="text-align:right"><?=pmoney($d['precio_unit'])?></td><td style="text-align:right"><?=pmoney($d['subtotal'])?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <div class="tot">
          <div><span>Subtotal</span><span><?=pmoney($p['subtotal'])?></span></div>
          <div><span>Descuento</span><span>- <?=pmoney($p['descuento_monto'])?></span></div>
          <div class="g"><span>TOTAL</span><span><?=pmoney($p['total'])?></span></div>
        </div>
        <?php if(trim((string)$p['condiciones'])!==''): ?><p style="margin-top:14px;font-size:12px;color:#555"><b>Condiciones:</b> <?=nl2br(pe($p['condiciones']))?></p><?php endif; ?>
    <?php });
    exit;
}

/* ════════════════════════════════════════════════════════════
   SECCIONES (con shell)
   ════════════════════════════════════════════════════════════ */
portal_shell_top($pac, $clinica, $logo, $v);

if ($v === 'inicio') {
    // próxima cita
    $nx = db()->prepare("SELECT c.*, CONCAT(u.nombre,' ',u.apellidos) doctor FROM citas c LEFT JOIN usuarios u ON c.doctor_id=u.id WHERE c.paciente_id=? AND c.fecha>=CURDATE() AND c.estado NOT IN('cancelado','no_asistio') ORDER BY c.fecha, c.hora_inicio LIMIT 1");
    $nx->execute([$pid]); $nx = $nx->fetch();
    // contadores
    $cRec = (int)db()->query("SELECT COUNT(*) FROM recetas WHERE paciente_id=$pid")->fetchColumn();
    $cPag = 0; try { $cPag = (float)db()->query("SELECT COALESCE(SUM(total),0) FROM pagos WHERE paciente_id=$pid AND estado='pagado'")->fetchColumn(); } catch (Throwable $e) {}
    // progreso de tratamiento
    $prog = ['t'=>0,'c'=>0]; try {
        $row = db()->query("SELECT COUNT(*) n, SUM(pd.estado='completado') c FROM plan_detalles pd JOIN planes_tratamiento pt ON pd.plan_id=pt.id WHERE pt.paciente_id=$pid")->fetch();
        $prog = ['t'=>(int)$row['n'], 'c'=>(int)$row['c']];
    } catch (Throwable $e) {}
    $pct = $prog['t'] ? round($prog['c']*100/$prog['t']) : 0;
    ?>
    <?php if($nx): ?>
    <div class="card" style="margin-top:18px;background:linear-gradient(135deg,#fff,var(--soft));border-color:#cfeef3">
      <div style="display:flex;align-items:center;gap:8px;color:var(--teal-d);font-weight:600;font-size:13px"><i class="bi bi-calendar-heart"></i> TU PRÓXIMA CITA</div>
      <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-top:8px;flex-wrap:wrap;gap:10px">
        <div><div style="font-family:'Fraunces',serif;font-size:22px;font-weight:600"><?=pe(pfechaLarga($nx['fecha']))?></div>
          <div style="color:var(--muted)"><?=phora($nx['hora_inicio'])?></div></div>
        <div class="r" style="text-align:right"><?php $e=$GLOBALS['CITA_EST'][$nx['estado']]??['#64748b','#f1f5f9',$nx['estado']]; ?>
          <span class="badge" style="color:<?=$e[0]?>;background:<?=$e[1]?>"><?=pe($e[2])?></span>
          <?php if($nx['doctor']): ?><div style="font-size:13px;color:var(--muted);margin-top:6px">con <?=pe($nx['doctor'])?></div><?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="grid g3">
      <div class="card stat"><div class="ic"><i class="bi bi-activity"></i></div><span class="n"><?=$pct?>%</span><span class="l">Avance del tratamiento</span></div>
      <div class="card stat"><div class="ic"><i class="bi bi-capsule"></i></div><span class="n"><?=$cRec?></span><span class="l">Recetas emitidas</span></div>
      <div class="card stat"><div class="ic"><i class="bi bi-wallet2"></i></div><span class="n money" style="font-size:20px"><?=pmoney($cPag)?></span><span class="l">Total pagado</span></div>
    </div>

    <?php if($prog['t']): ?>
    <div class="card" style="margin-top:14px">
      <h3>Avance de tu tratamiento</h3>
      <div style="display:flex;justify-content:space-between;font-size:13px;color:var(--muted);margin:8px 0 6px"><span><?=$prog['c']?> de <?=$prog['t']?> procedimientos completados</span><span><?=$pct?>%</span></div>
      <div class="bar"><span style="width:<?=$pct?>%"></span></div>
      <a href="?v=tratamiento" class="btn ghost" style="margin-top:14px">Ver detalle <i class="bi bi-arrow-right"></i></a>
    </div>
    <?php endif; ?>

    <div class="grid g2" style="margin-top:14px">
      <a class="card" href="?v=citas"><h3><i class="bi bi-calendar-check" style="color:var(--teal)"></i> Mis citas</h3><p style="color:var(--muted);font-size:14px">Consulta tus próximas citas y tu historial.</p></a>
      <a class="card" href="?v=recetas"><h3><i class="bi bi-capsule" style="color:var(--teal)"></i> Mis recetas</h3><p style="color:var(--muted);font-size:14px">Descarga e imprime tus recetas médicas.</p></a>
      <a class="card" href="?v=presupuestos"><h3><i class="bi bi-receipt" style="color:var(--teal)"></i> Presupuestos</h3><p style="color:var(--muted);font-size:14px">Revisa y descarga tus cotizaciones.</p></a>
      <a class="card" href="?v=pagos"><h3><i class="bi bi-wallet2" style="color:var(--teal)"></i> Pagos</h3><p style="color:var(--muted);font-size:14px">Tu historial de pagos y comprobantes.</p></a>
    </div>

    <a class="card" href="?v=encuesta" style="margin-top:14px;display:flex;align-items:center;gap:14px;background:linear-gradient(135deg,#fff,var(--soft));border-color:#cfeef3">
      <div style="font-size:30px">⭐</div>
      <div style="flex:1"><div style="font-weight:600;font-size:15px">Cuéntanos cómo te atendimos</div><div style="color:var(--muted);font-size:13px">Responde nuestra breve encuesta de satisfacción.</div></div>
      <i class="bi bi-arrow-right" style="color:var(--teal)"></i>
    </a>
    <?php
}

elseif ($v === 'citas') {
    $cs = db()->prepare("SELECT c.*, CONCAT(u.nombre,' ',u.apellidos) doctor, s.nombre sillon FROM citas c LEFT JOIN usuarios u ON c.doctor_id=u.id LEFT JOIN sillones s ON c.sillon_id=s.id WHERE c.paciente_id=? ORDER BY c.fecha DESC, c.hora_inicio DESC");
    $cs->execute([$pid]); $cs = $cs->fetchAll();
    $prox = array_filter($cs, fn($c)=> $c['fecha']>=date('Y-m-d') && !in_array($c['estado'],['cancelado','no_asistio','atendido']));
    $hist = array_filter($cs, fn($c)=> !($c['fecha']>=date('Y-m-d') && !in_array($c['estado'],['cancelado','no_asistio','atendido'])));
    $renderCita = function($c){ global $CITA_EST;
        $e=$CITA_EST[$c['estado']]??['#64748b','#f1f5f9',$c['estado']]; ?>
        <div class="item">
          <div class="ic"><i class="bi bi-calendar-event"></i></div>
          <div class="bd"><div class="t"><?=pe(pfechaLarga($c['fecha']))?></div>
            <div class="m"><i class="bi bi-clock"></i> <?=phora($c['hora_inicio'])?><?php if($c['doctor']): ?> · <?=pe($c['doctor'])?><?php endif; ?><?php if($c['motivo']): ?> · <?=pe($c['motivo'])?><?php endif; ?></div></div>
          <div class="r"><span class="badge" style="color:<?=$e[0]?>;background:<?=$e[1]?>"><?=pe($e[2])?></span></div>
        </div>
    <?php };
    ?>
    <div class="sec-title"><h2>Próximas citas</h2><div class="ln"></div></div>
    <?php if($prox): foreach($prox as $c) $renderCita($c); else: ?>
      <div class="card empty"><i class="bi bi-calendar-x"></i>No tienes citas próximas agendadas.</div>
    <?php endif; ?>
    <div class="sec-title"><h2>Historial</h2><div class="ln"></div></div>
    <?php if($hist): foreach($hist as $c) $renderCita($c); else: ?>
      <div class="card empty"><i class="bi bi-clock-history"></i>Aún no hay citas en tu historial.</div>
    <?php endif;
}

elseif ($v === 'tratamiento') {
    $plan = null; $det = []; $evs = [];
    try {
        $plan = db()->prepare("SELECT * FROM planes_tratamiento WHERE paciente_id=? ORDER BY created_at DESC LIMIT 1"); $plan->execute([$pid]); $plan = $plan->fetch();
        if ($plan) { $d = db()->prepare("SELECT * FROM plan_detalles WHERE plan_id=? ORDER BY orden"); $d->execute([$plan['id']]); $det = $d->fetchAll(); }
        $e = db()->prepare("SELECT e.*, CONCAT(u.nombre,' ',u.apellidos) doctor FROM evoluciones e JOIN historias_clinicas hc ON e.hc_id=hc.id LEFT JOIN usuarios u ON e.doctor_id=u.id WHERE hc.paciente_id=? ORDER BY e.fecha DESC, e.id DESC LIMIT 40"); $e->execute([$pid]); $evs = $e->fetchAll();
    } catch (Throwable $ex) {}
    $estCol = ['pendiente'=>['#b45309','#fef3c7','Pendiente'],'en_proceso'=>['#0e7490','#cffafe','En proceso'],'completado'=>['#15803d','#dcfce7','Completado'],'cancelado'=>['#64748b','#f1f5f9','Cancelado']];
    ?>
    <div class="sec-title"><h2>Mi plan de tratamiento</h2><div class="ln"></div></div>
    <?php if($plan && $det):
        $tot=count($det); $comp=count(array_filter($det,fn($x)=>$x['estado']==='completado')); $pct=$tot?round($comp*100/$tot):0; ?>
      <div class="card">
        <div style="display:flex;justify-content:space-between;font-size:13px;color:var(--muted);margin-bottom:6px"><span><?=$comp?> de <?=$tot?> completados</span><span style="font-weight:600;color:var(--teal-d)"><?=$pct?>%</span></div>
        <div class="bar"><span style="width:<?=$pct?>%"></span></div>
      </div>
      <?php foreach($det as $d): $ec=$estCol[$d['estado']]??['#64748b','#f1f5f9',$d['estado']];
        $st=(int)$d['sesiones_total']?:1; $sr=(int)$d['sesiones_realizadas']; $sp=min(100,round($sr*100/$st)); ?>
        <div class="item" style="margin-top:10px">
          <div class="ic" style="background:<?=$ec[1]?>;color:<?=$ec[0]?>"><i class="bi bi-<?=$d['estado']==='completado'?'check2-circle':'activity'?>"></i></div>
          <div class="bd"><div class="t"><?=pe($d['nombre_tratamiento'])?><?=$d['diente']?' · 🦷 '.pe($d['diente']):''?></div>
            <div class="m">Sesiones: <?=$sr?>/<?=$st?></div>
            <div class="bar" style="margin-top:7px"><span style="width:<?=$sp?>%"></span></div>
          </div>
          <div class="r"><span class="badge" style="color:<?=$ec[0]?>;background:<?=$ec[1]?>"><?=pe($ec[2])?></span></div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="card empty"><i class="bi bi-clipboard2-pulse"></i>Aún no tienes un plan de tratamiento registrado.</div>
    <?php endif; ?>

    <?php if($evs): ?>
    <div class="sec-title"><h2>Evolución</h2><div class="ln"></div></div>
    <div class="card"><div class="tl">
      <?php foreach($evs as $ev): ?>
        <div class="ev"><div class="d"><?=pfecha($ev['fecha'])?></div>
          <div class="t" style="font-weight:600;margin-top:2px"><?=pe($ev['procedimiento']?:'Control')?><?=$ev['diente']?' · 🦷 '.pe($ev['diente']):''?></div>
          <?php if($ev['descripcion']): ?><div class="m" style="font-size:13px;color:var(--muted)"><?=nl2br(pe($ev['descripcion']))?></div><?php endif; ?>
          <?php if($ev['doctor']): ?><div style="font-size:12px;color:var(--teal-d);margin-top:3px"><?=pe($ev['doctor'])?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div></div>
    <?php endif;
}

elseif ($v === 'recetas') {
    $rs = db()->prepare("SELECT r.*, CONCAT(u.nombre,' ',u.apellidos) doctor FROM recetas r LEFT JOIN usuarios u ON r.doctor_id=u.id WHERE r.paciente_id=? ORDER BY r.fecha_prescripcion DESC, r.id DESC"); $rs->execute([$pid]); $rs=$rs->fetchAll();
    ?>
    <div class="sec-title"><h2>Mis recetas</h2><div class="ln"></div></div>
    <?php if($rs): foreach($rs as $r): ?>
      <div class="item">
        <div class="ic"><i class="bi bi-capsule"></i></div>
        <div class="bd"><div class="t">Receta <?=pe($r['codigo']?:('#'.$r['id']))?></div>
          <div class="m"><i class="bi bi-calendar3"></i> <?=pfecha($r['fecha_prescripcion'])?><?php if($r['doctor']): ?> · <?=pe($r['doctor'])?><?php endif; ?></div></div>
        <div class="r"><a class="btn" href="?v=receta&id=<?=$r['id']?>" target="_blank"><i class="bi bi-download"></i> Ver / Descargar</a></div>
      </div>
    <?php endforeach; else: ?>
      <div class="card empty"><i class="bi bi-capsule"></i>No tienes recetas registradas.</div>
    <?php endif;
}

elseif ($v === 'presupuestos') {
    $ps = []; try { $q=db()->prepare("SELECT * FROM presupuestos WHERE paciente_id=? ORDER BY created_at DESC"); $q->execute([$pid]); $ps=$q->fetchAll(); } catch (Throwable $e) {}
    $estCol=['borrador'=>['#64748b','#f1f5f9','Borrador'],'enviado'=>['#0e7490','#cffafe','Enviado'],'aprobado'=>['#15803d','#dcfce7','Aprobado'],'rechazado'=>['#b91c1c','#fee2e2','Rechazado'],'vencido'=>['#b45309','#fef3c7','Vencido']];
    ?>
    <div class="sec-title"><h2>Mis presupuestos</h2><div class="ln"></div></div>
    <?php if($ps): foreach($ps as $p): $ec=$estCol[$p['estado']]??['#64748b','#f1f5f9',$p['estado']]; ?>
      <div class="item">
        <div class="ic"><i class="bi bi-receipt"></i></div>
        <div class="bd"><div class="t"><?=pe($p['codigo']?:('#'.$p['id']))?> · <span class="money" style="color:var(--teal-d)"><?=pmoney($p['total'])?></span></div>
          <div class="m"><i class="bi bi-calendar3"></i> <?=pfecha($p['fecha'])?> · Válido hasta <?=pfecha($p['fecha_vencimiento'])?></div>
          <span class="badge" style="color:<?=$ec[0]?>;background:<?=$ec[1]?>;margin-top:6px"><?=pe($ec[2])?></span></div>
        <div class="r"><a class="btn" href="?v=presupuesto&id=<?=$p['id']?>" target="_blank"><i class="bi bi-download"></i> Ver</a></div>
      </div>
    <?php endforeach; else: ?>
      <div class="card empty"><i class="bi bi-receipt"></i>No tienes presupuestos registrados.</div>
    <?php endif;
}

elseif ($v === 'pagos') {
    $pg = []; $tot=0; try {
        $q=db()->prepare("SELECT * FROM pagos WHERE paciente_id=? ORDER BY fecha DESC, id DESC"); $q->execute([$pid]); $pg=$q->fetchAll();
        $tot=(float)db()->query("SELECT COALESCE(SUM(total),0) FROM pagos WHERE paciente_id=$pid AND estado='pagado'")->fetchColumn();
    } catch (Throwable $e) {}
    $tipoLbl=['boleta'=>'Boleta','factura'=>'Factura','ticket'=>'Ticket','nota_venta'=>'Nota de venta','nota_credito'=>'Nota de crédito'];
    ?>
    <div class="sec-title"><h2>Mis pagos</h2><div class="ln"></div></div>
    <div class="card" style="display:flex;justify-content:space-between;align-items:center;background:linear-gradient(135deg,#fff,var(--soft));border-color:#cfeef3">
      <span style="color:var(--muted)">Total pagado</span><span class="money" style="font-size:24px;color:var(--teal-d)"><?=pmoney($tot)?></span>
    </div>
    <?php if($pg): foreach($pg as $p):
      $anul=($p['estado']??'')==='anulado';
      $tk=preg_replace('/[^a-f0-9]/i','',$p['pdf_token']??''); ?>
      <div class="item" style="margin-top:10px;<?=$anul?'opacity:.6':''?>">
        <div class="ic"><i class="bi bi-<?=$anul?'x-circle':'check2-circle'?>"></i></div>
        <div class="bd"><div class="t"><?=pmoney($p['total'])?> <span style="font-weight:400;color:var(--muted);font-size:13px">· <?=pe(ucfirst($p['metodo']??''))?></span></div>
          <div class="m"><i class="bi bi-calendar3"></i> <?=pfecha($p['fecha'])?>
            <?php $comp = trim(($p['serie']??'').' '.($p['numero']??'')); if($comp): ?> · <?=pe(($tipoLbl[$p['tipo_comprobante']??'']??'Comprobante').' '.$comp)?><?php endif; ?>
            <?php if($anul): ?> · <span style="color:var(--bad)">ANULADO</span><?php endif; ?></div></div>
        <div class="r"><?php if($tk && strlen($tk)===40 && !$anul): ?><a class="btn ghost" href="<?=BASE_URL?>/pages/comprobante_pdf.php?token=<?=$tk?>" target="_blank"><i class="bi bi-file-earmark-pdf"></i> Comprobante</a><?php endif; ?></div>
      </div>
    <?php endforeach; else: ?>
      <div class="card empty" style="margin-top:10px"><i class="bi bi-wallet2"></i>No tienes pagos registrados.</div>
    <?php endif;
}

else if ($v === 'encuesta') {
    $escala = [1=>['😡','Muy insatisfecho'],2=>['🙁','Insatisfecho'],3=>['😐','Neutral'],4=>['🙂','Satisfecho'],5=>['😀','Muy satisfecho']];
    $doctor = '';
    try {
        $dq = db()->prepare("SELECT CONCAT(u.nombre,' ',u.apellidos) FROM citas c JOIN usuarios u ON c.doctor_id=u.id WHERE c.paciente_id=? ORDER BY c.fecha DESC, c.id DESC LIMIT 1");
        $dq->execute([$pid]); $doctor = (string)$dq->fetchColumn();
    } catch (Throwable $e) {}
    // ¿Ya respondió? (una sola encuesta por paciente)
    $mine = null;
    try { $mq = db()->prepare("SELECT * FROM encuestas_satisfaccion WHERE paciente_id=? ORDER BY id DESC LIMIT 1"); $mq->execute([$pid]); $mine = $mq->fetch() ?: null; } catch (Throwable $e) {}

    if ($mine) { // ya respondió → muestra su respuesta, sin formulario
        $fc = $escala[(int)$mine['calif_clinica']] ?? ['',''];
        $fd = $mine['calif_doctor'] !== null ? ($escala[(int)$mine['calif_doctor']] ?? ['','']) : null;
        ?>
        <div class="card thanks" style="margin-top:22px">
          <div class="big"><?=isset($_GET['ok'])?'🙏':'✅'?></div>
          <h2><?=isset($_GET['ok'])?'¡Gracias por tu opinión!':'Ya registramos tu opinión'?></h2>
          <p style="color:var(--muted);margin-top:6px">Respondiste la encuesta el <?=pfecha($mine['created_at'])?>. ¡Gracias por ayudarnos a mejorar!</p>
          <div class="grid g2" style="margin-top:18px;text-align:left">
            <div class="card" style="box-shadow:none"><div style="color:var(--muted);font-size:12px">Clínica</div><div style="font-size:22px;margin-top:4px"><?=$fc[0]?> <span style="font-size:15px;color:var(--ink)"><?=pe($fc[1])?></span></div></div>
            <div class="card" style="box-shadow:none"><div style="color:var(--muted);font-size:12px">Doctor</div><div style="font-size:22px;margin-top:4px"><?=$fd?($fd[0].' <span style="font-size:15px;color:var(--ink)">'.pe($fd[1]).'</span>'):'—'?></div></div>
          </div>
          <?php if(trim((string)$mine['comentario'])!==''): ?>
          <div class="card" style="box-shadow:none;margin-top:12px;text-align:left"><div style="color:var(--muted);font-size:12px">Tu comentario</div><div style="margin-top:4px;color:var(--ink)"><?=nl2br(pe($mine['comentario']))?></div></div>
          <?php endif; ?>
          <a href="?v=inicio" class="btn" style="margin-top:18px"><i class="bi bi-house-heart"></i> Volver al inicio</a>
        </div>
        <?php
    } else { ?>
      <div class="sec-title"><h2>Encuesta de satisfacción</h2><div class="ln"></div></div>
      <p style="color:var(--muted);font-size:13px;margin:-4px 2px 0">La encuesta se responde una sola vez. Tu opinión es muy importante para nosotros.</p>
      <form method="POST" action="?v=encuesta">
        <input type="hidden" name="accion" value="encuesta">
        <div class="card" style="margin-top:14px">
          <h3>¿Cómo calificarías la atención de la clínica?</h3>
          <div class="rate">
            <?php foreach($escala as $n=>$f): ?>
              <input type="radio" id="cl<?=$n?>" name="calif_clinica" value="<?=$n?>" required>
              <label for="cl<?=$n?>"><span class="em"><?=$f[0]?></span><span class="tx"><?=pe($f[1])?> (<?=$n?>)</span></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="card" style="margin-top:14px">
          <h3>¿Y la atención del doctor<?=$doctor?' ('.pe($doctor).')':''?>?</h3>
          <div class="rate">
            <?php foreach($escala as $n=>$f): ?>
              <input type="radio" id="dc<?=$n?>" name="calif_doctor" value="<?=$n?>">
              <label for="dc<?=$n?>"><span class="em"><?=$f[0]?></span><span class="tx"><?=pe($f[1])?> (<?=$n?>)</span></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="card" style="margin-top:14px">
          <h3>Comentario (opcional)</h3>
          <p style="color:var(--muted);font-size:13px;margin:2px 0 8px">Cuéntanos qué te gustó o qué podemos mejorar.</p>
          <textarea name="comentario" class="fld" rows="3" maxlength="600" placeholder="Escribe aquí..."></textarea>
        </div>
        <div style="text-align:right;margin-top:16px"><button type="submit" class="btn"><i class="bi bi-send"></i> Enviar encuesta</button></div>
      </form>
    <?php }
}

else { echo '<div class="card empty" style="margin-top:24px"><i class="bi bi-question-circle"></i>Sección no encontrada.</div>'; }

portal_shell_bottom($clinica, $telCli);

/* ════════════════════════════════════════════════════════════
   Documento imprimible (recetas / presupuestos)
   ════════════════════════════════════════════════════════════ */
function portal_print_doc(string $clinica, string $logo, $emp, string $titulo, string $codigo, array $pac, callable $body): void {
    ?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=pe($titulo)?> · <?=pe($pac['nombres'])?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#16323c;background:#eef4f6;padding:18px}
.sheet{max-width:740px;margin:0 auto;background:#fff;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.12);overflow:hidden}
.hd{display:flex;align-items:center;gap:14px;padding:18px 22px;border-bottom:3px solid #0a8aa0}
.hd .lg{width:60px;height:60px;border-radius:12px;background:#f5fafb;display:flex;align-items:center;justify-content:center;overflow:hidden}
.hd .lg img{max-width:100%;max-height:100%}
.hd .nm{flex:1}.hd .nm b{font-size:17px;color:#0a8aa0}.hd .nm div{font-size:10px;letter-spacing:.18em;color:#666;text-transform:uppercase}
.hd .co{text-align:right;font-size:9px;color:#888}
.bdy{padding:22px}
.ttl{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.ttl h1{font-size:18px;letter-spacing:.5px}.ttl .cd{font-weight:700;color:#0a8aa0}
.pbox{background:#f5fafb;border:1px solid #e3edf0;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12.5px}
.meta{display:flex;gap:20px;flex-wrap:wrap;font-size:12px;color:#555;margin-bottom:12px}
table.t{width:100%;border-collapse:collapse;font-size:12px}
table.t th{background:#eef5f7;border:1px solid #cddde1;padding:7px;text-align:left}
table.t td{border:1px solid #dde7ea;padding:7px}
.tot{margin-left:auto;width:280px;margin-top:10px;font-size:13px}
.tot div{display:flex;justify-content:space-between;padding:3px 0;color:#555}
.tot .g{border-top:2px solid #0a8aa0;margin-top:4px;padding-top:6px;font-size:16px;font-weight:700;color:#0a8aa0}
.sign{margin-top:36px;text-align:center}
.sign img{max-height:50px;display:block;margin:0 auto 2px}
.sign .ln{border-top:1px solid #16323c;display:inline-block;min-width:260px;padding-top:5px}
.bar{text-align:center;padding:16px}
.bar button{background:#0a8aa0;color:#fff;border:none;border-radius:999px;padding:11px 26px;font-size:14px;font-weight:700;cursor:pointer}
.bar a{margin-left:8px;color:#0a8aa0;font-size:13px}
@media print{body{background:#fff;padding:0}.sheet{box-shadow:none;border-radius:0;max-width:100%}.bar{display:none}}
</style></head><body>
<div class="sheet">
  <div class="hd">
    <div class="lg"><?php if($logo): ?><img src="<?=pe($logo)?>"><?php else: ?><span style="font-size:26px">🦷</span><?php endif; ?></div>
    <div class="nm"><b><?=pe($clinica)?></b><div>Consultorio Odontológico</div></div>
    <div class="co"><?php if(!empty($emp['ruc'])): ?>RUC: <?=pe($emp['ruc'])?><br><?php endif; ?><?php if(!empty($emp['telefono'])): ?>Tel: <?=pe($emp['telefono'])?><br><?php endif; ?><?=date('d/m/Y')?></div>
  </div>
  <div class="bdy">
    <div class="ttl"><h1><?=pe($titulo)?></h1><span class="cd"><?=pe($codigo)?></span></div>
    <div class="pbox"><b>Paciente:</b> <?=pe($pac['nombres'].' '.$pac['apellido_paterno'].' '.($pac['apellido_materno']??''))?><?php if(!empty($pac['dni'])): ?> &nbsp;·&nbsp; <b>DNI:</b> <?=pe($pac['dni'])?><?php endif; ?> &nbsp;·&nbsp; <b>Cód.:</b> <?=pe($pac['codigo'])?></div>
    <?php $body(); ?>
  </div>
</div>
<div class="bar"><button onclick="window.print()">🖨️ Imprimir / Guardar PDF</button><a href="javascript:history.back()">← Volver</a></div>
</body></html>
<?php }
