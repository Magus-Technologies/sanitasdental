<?php
/* reservar.php — Reserva de citas ONLINE (página pública, sin login).
   Flujo: DNI → datos (autocompleta si existe / registra si es nuevo) → servicio → doctor
          → día y hora disponible → confirmación. Empuja a Google Calendar del doctor.
   Enlace para compartir:  https://TU-DOMINIO/reservar.php   (opcional: ?sede=ID) */

require_once __DIR__.'/includes/config.php';   // db, empresa, getCfg, genCodigo, e (SIN login)

/* Google Calendar disponible? (mismo criterio que citas.php) */
if (!defined('GC_AVAILABLE')) define('GC_AVAILABLE',
    file_exists(__DIR__.'/includes/GoogleCalendarService.php') &&
    (function(){ try{ db()->query('SELECT 1 FROM google_calendar_tokens LIMIT 1'); return true; } catch(Throwable $e){ return false; } })());
if (GC_AVAILABLE) require_once __DIR__.'/includes/GoogleCalendarService.php';

/* Marca de origen para distinguir reservas online en la agenda (aditivo) */
try { db()->exec("ALTER TABLE citas ADD COLUMN IF NOT EXISTS origen VARCHAR(20) NULL"); } catch(Throwable $e){}
try { db()->exec("ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS tipo_documento VARCHAR(30) DEFAULT 'DNI'"); } catch(Throwable $e){}

/* Sede desde el enlace (opcional) */
$SEDE = (int)($_GET['sede'] ?? 0);
function sede_reserva(int $sede): int {
    if ($sede > 0) { try { $ok=db()->prepare("SELECT 1 FROM sedes WHERE id=? AND activo=1"); $ok->execute([$sede]); if($ok->fetchColumn()) return $sede; } catch(Throwable $e){} }
    if (function_exists('sede_principal_id')) return sede_principal_id();
    return 1;
}

/* ── Config de horario y cálculo de disponibilidad ── */
function reserva_cfg(): array {
    return [
        'ini'  => getCfg('reserva_hora_ini','08:00'),
        'fin'  => getCfg('reserva_hora_fin','20:00'),
        'slot' => max(10,(int)getCfg('reserva_slot_min','30')),
        'dias' => getCfg('reserva_dias','1,2,3,4,5,6'),   // 1=Lun … 7=Dom
    ];
}
/* Devuelve solo los pares cuyo campo EXISTE en la tabla (evita 'Unknown column'
   en instalaciones antiguas que no tienen sede_id / origen / tipo_documento, etc.) */
function cols_tabla(string $t): array {
    static $c=[];
    if (isset($c[$t])) return $c[$t];
    $m=[];
    try { foreach (db()->query("SHOW COLUMNS FROM `$t`") as $r) { $m[$r['Field']]=true; } } catch(Throwable $e){}
    return $c[$t]=$m;
}
function solo_cols(string $t, array $data): array {
    $ok=cols_tabla($t);
    if (!$ok) return $data; // si no pudimos leer columnas, no filtramos
    return array_filter($data, fn($k)=>isset($ok[$k]), ARRAY_FILTER_USE_KEY);
}

function slots_libres(int $doctorId, string $fecha): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$fecha)) return [];
    $cfg = reserva_cfg();
    $dow = (int)date('N', strtotime($fecha));
    if (!in_array((string)$dow, explode(',', $cfg['dias']), true)) return [];
    $slot = $cfg['slot']*60;
    $start= strtotime("$fecha ".$cfg['ini']);
    $end  = strtotime("$fecha ".$cfg['fin']);
    if (!$start || !$end || $end<=$start) return [];
    $busy = [];
    try {
        $st=db()->prepare("SELECT hora_inicio,hora_fin FROM citas WHERE doctor_id=? AND fecha=? AND estado NOT IN('cancelado')");
        $st->execute([$doctorId,$fecha]); $busy=$st->fetchAll();
    } catch(Throwable $e){}
    $now=time(); $out=[];
    for ($t=$start; $t+$slot<=$end; $t+=$slot) {
        if ($fecha===date('Y-m-d') && $t < $now+15*60) continue; // margen 15 min para hoy
        $s=$t; $e=$t+$slot; $ocup=false;
        foreach ($busy as $b) {
            $bi=strtotime("$fecha ".$b['hora_inicio']);
            $bf=strtotime("$fecha ".($b['hora_fin'] ?: $b['hora_inicio']));
            if ($bf<=$bi) $bf=$bi+$slot;
            if ($s < $bf && $e > $bi) { $ocup=true; break; }
        }
        if (!$ocup) $out[]=date('H:i',$t);
    }
    return $out;
}

/* ─────────────────────────── AJAX ─────────────────────────── */
$ajax = $_GET['ajax'] ?? '';
if ($ajax) {
    header('Content-Type: application/json; charset=utf-8');

    if ($ajax==='dni') {
        // límite anti-enumeración por sesión
        $_SESSION['dni_hits'] = ($_SESSION['dni_hits'] ?? 0) + 1;
        if ($_SESSION['dni_hits'] > 20) { echo json_encode(['error'=>'Demasiadas consultas. Intenta más tarde.']); exit; }
        $dni  = trim($_GET['dni'] ?? '');
        $fnac = $_GET['fnac'] ?? '';
        if (strlen($dni) < 5) { echo json_encode(['error'=>'Documento inválido']); exit; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$fnac)) { echo json_encode(['error'=>'Ingresa tu fecha de nacimiento.']); exit; }
        // Autocompleta SOLO si DNI + fecha de nacimiento coinciden (verificación de identidad).
        $p=false;
        try {
            $s=db()->prepare("SELECT nombres,apellido_paterno,apellido_materno,telefono,email,sexo,fecha_nacimiento FROM pacientes WHERE dni=? AND fecha_nacimiento=? AND activo=1 LIMIT 1");
            $s->execute([$dni,$fnac]); $p=$s->fetch();
        } catch(Throwable $e){ $p=false; }
        // Respuesta uniforme: si no verifica, no revelamos si el DNI existe.
        if ($p) echo json_encode(['existe'=>true,'p'=>['nombres'=>$p['nombres'],'apellido_paterno'=>$p['apellido_paterno'],'apellido_materno'=>$p['apellido_materno'],'telefono'=>$p['telefono'],'email'=>$p['email'],'sexo'=>$p['sexo'],'fecha_nacimiento'=>$p['fecha_nacimiento']]]);
        else echo json_encode(['existe'=>false]);
        exit;
    }

    if ($ajax==='reniec') {  // consulta RENIEC (solo DNI) para autocompletar paciente nuevo
        $_SESSION['reniec_hits'] = ($_SESSION['reniec_hits'] ?? 0) + 1;
        if ($_SESSION['reniec_hits'] > 12) { echo json_encode(['ok'=>false,'msg'=>'Límite de consultas alcanzado.']); exit; }
        $doc = preg_replace('/\\D/','', $_GET['doc'] ?? '');
        if (strlen($doc) !== 8) { echo json_encode(['ok'=>false,'msg'=>'DNI inválido']); exit; }
        // Token/URL espejo de includes/api_documento.php (APISPERU RENIEC) — se mantiene en el servidor.
        $url = 'https://dniruc.apisperu.com/api/v1/dni/'.$doc.'?token=eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJlbWFpbCI6InN5c3RlbWNyYWZ0LnBlQGdtYWlsLmNvbSJ9.yuNS5hRaC0hCwymX_PjXRoSZJWLNNBeOdlLRSUGlHGA';
        $out = ['ok'=>false];
        try {
            $ch=curl_init($url);
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_SSL_VERIFYPEER=>false]);
            $res=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
            if ($code===200 && $res) { $d=json_decode($res,true)?:[]; $out=['ok'=>true,'data'=>['nombres'=>trim($d['nombres']??''),'apellido_paterno'=>trim($d['apellidoPaterno']??''),'apellido_materno'=>trim($d['apellidoMaterno']??'')]]; }
        } catch(Throwable $e){}
        echo json_encode($out, JSON_UNESCAPED_UNICODE); exit;
    }

    if ($ajax==='servicios') {
        $rows=[];
        try { $rows=db()->query("SELECT id,nombre FROM tratamientos_catalogo WHERE activo=1 ORDER BY nombre")->fetchAll(); }
        catch(Throwable $e){ try{ $rows=db()->query("SELECT id,nombre FROM tratamientos_catalogo ORDER BY nombre")->fetchAll(); }catch(Throwable $e2){ $rows=[]; } }
        echo json_encode(['servicios'=>$rows]); exit;
    }

    if ($ajax==='doctores') {
        $rows=[];
        try {
            $sql="SELECT id,nombre,apellidos,especialidad FROM usuarios WHERE rol_id=2 AND activo=1";
            $pm=[];
            if (function_exists('multi_sede_on') && multi_sede_on() && $SEDE>0) { $sql.=" AND (sede_id=? OR sede_id IS NULL)"; $pm[]=$SEDE; }
            $sql.=" ORDER BY nombre";
            $st=db()->prepare($sql); $st->execute($pm); $rows=$st->fetchAll();
        } catch(Throwable $e){}
        echo json_encode(['doctores'=>$rows]); exit;
    }

    if ($ajax==='slots') {
        $doc=(int)($_GET['doctor']??0); $fecha=$_GET['fecha']??'';
        echo json_encode(['slots'=>$doc?slots_libres($doc,$fecha):[]]); exit;
    }

    if ($ajax==='confirmar' && $_SERVER['REQUEST_METHOD']==='POST') {
        $dni  = trim($_POST['dni'] ?? '');
        $tipoDoc = trim($_POST['tipo_documento'] ?? 'DNI');
        $nom  = trim($_POST['nombres'] ?? '');
        $apa  = trim($_POST['apellido_paterno'] ?? '');
        $apm  = trim($_POST['apellido_materno'] ?? '');
        $tel  = trim($_POST['telefono'] ?? '');
        $mail = trim($_POST['email'] ?? '');
        $sexo = trim($_POST['sexo'] ?? '');
        $fnac = trim($_POST['fecha_nacimiento'] ?? '');
        $srvId= (int)($_POST['servicio_id'] ?? 0);
        $srvNm= trim($_POST['servicio_nombre'] ?? '');
        $doc  = (int)($_POST['doctor_id'] ?? 0);
        $fecha= $_POST['fecha'] ?? '';
        $hora = $_POST['hora'] ?? '';

        $docOK = ($tipoDoc==='DNI') ? (bool)preg_match('/^\d{8}$/',$dni) : (strlen($dni)>=5);
        if (!$docOK || $nom==='' || $apa==='' || $tel==='' || !$doc || !$srvNm || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$fecha) || !preg_match('/^\d{2}:\d{2}$/',$hora)) {
            echo json_encode(['error'=>'Faltan datos obligatorios.']); exit;
        }
        // el horario debe seguir libre
        if (!in_array($hora, slots_libres($doc,$fecha), true)) { echo json_encode(['error'=>'Ese horario ya no está disponible, elige otro.']); exit; }

        $sedeB = sede_reserva($SEDE);
        try {
            // paciente: existente o nuevo
            $s=db()->prepare("SELECT id FROM pacientes WHERE dni=? LIMIT 1"); $s->execute([$dni]);
            $pid=(int)$s->fetchColumn(); $esNuevo=!$pid;
            if (!$pid) {
                $cod=genCodigo('HCL','pacientes');
                $pd=['codigo'=>$cod,'nombres'=>$nom,'apellido_paterno'=>$apa,'apellido_materno'=>$apm?:null,'dni'=>$dni,'telefono'=>$tel,'email'=>$mail?:null,'sexo'=>$sexo?:null,'fecha_nacimiento'=>($fnac?:null),'tipo_documento'=>$tipoDoc,'activo'=>1,'sede_id'=>$sedeB];
                $pd=solo_cols('pacientes',$pd);
                $cols=implode(',',array_keys($pd)); $phs=implode(',',array_fill(0,count($pd),'?'));
                db()->prepare("INSERT INTO pacientes($cols)VALUES($phs)")->execute(array_values($pd));
                $pid=(int)db()->lastInsertId();
            }
            // cita
            $cfg=reserva_cfg();
            $hfin=date('H:i', strtotime("$fecha $hora")+$cfg['slot']*60);
            $cod=genCodigo('CIT','citas');
            $cd=['codigo'=>$cod,'paciente_id'=>$pid,'doctor_id'=>$doc,'fecha'=>$fecha,'hora_inicio'=>$hora,'hora_fin'=>$hfin,
                 'tipo'=>$esNuevo?'primera_vez':'control','especialidad'=>$srvNm,'motivo'=>$srvNm,'estado'=>'pendiente',
                 'origen'=>'online','created_by'=>$doc,'sede_id'=>$sedeB];
            $cd=solo_cols('citas',$cd);
            $cols=implode(',',array_keys($cd)); $phs=implode(',',array_fill(0,count($cd),'?'));
            db()->prepare("INSERT INTO citas($cols)VALUES($phs)")->execute(array_values($cd));
            $cid=(int)db()->lastInsertId();

            // Google Calendar (best effort)
            if (GC_AVAILABLE) { try {
                $gc=new GoogleCalendarService($doc);
                if ($gc->isConnected()) {
                    $q=db()->prepare("SELECT c.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac FROM citas c JOIN pacientes p ON c.paciente_id=p.id WHERE c.id=?");
                    $q->execute([$cid]); $cc=$q->fetch(); if ($cc) $gc->createEvent($cc);
                }
            } catch(Throwable $e){} }

            echo json_encode(['ok'=>true,'codigo'=>$cod,'resumen'=>[
                'paciente'=>trim("$nom $apa"), 'fecha'=>date('d/m/Y',strtotime($fecha)),
                'hora'=>$hora, 'servicio'=>$srvNm
            ]]);
        } catch(Throwable $e) {
            // Registra el detalle en el servidor (no se muestra al público)
            @file_put_contents(__DIR__.'/reservar_error.log',
                date('c').' | '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine().PHP_EOL, FILE_APPEND);
            echo json_encode(['error'=>'No se pudo registrar la cita. Intenta de nuevo.']);
        }
        exit;
    }

    echo json_encode(['error'=>'accion desconocida']); exit;
}

/* ─────────────────────────── VISTA (wizard) ─────────────────────────── */
$logo   = empresa('logo', true);
$clinica= empresa('nombre_comercial') ?: getCfg('clinica_nombre','Clínica Dental');
$minFecha = date('Y-m-d');
$maxFecha = date('Y-m-d', strtotime('+60 days'));
?><!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Reserva tu cita · <?=e($clinica)?></title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root{--tq:#06B6D4;--bl:#2563EB;--ink:#1F2937;--mut:#6B7280;--bd:#E3E8EE;--bg:#F1F5F9}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Nunito',sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;padding:20px}
.wrap{max-width:560px;margin:0 auto;background:#fff;border-radius:20px;box-shadow:0 20px 60px rgba(15,30,55,.12);overflow:hidden}
.head{background:linear-gradient(135deg,var(--tq),var(--bl));color:#fff;padding:22px 24px;text-align:center}
.head img{max-height:56px;max-width:180px;object-fit:contain;background:#fff;border-radius:12px;padding:6px 10px;margin-bottom:8px}
.head h1{font-size:19px;font-weight:800}
.head p{font-size:12.5px;opacity:.9;margin-top:2px}
.steps{display:flex;gap:4px;padding:14px 24px 0}
.steps .st{flex:1;height:5px;border-radius:3px;background:var(--bd)}
.steps .st.on{background:var(--tq)}
.body{padding:20px 24px 26px}
.stepwrap{display:none}.stepwrap.on{display:block;animation:fade .25s}
@keyframes fade{from{opacity:0;transform:translateY(6px)}to{opacity:1}}
.slabel{font-size:12px;font-weight:700;color:var(--tq);text-transform:uppercase;letter-spacing:1px}
.stitle{font-size:19px;font-weight:800;margin:2px 0 4px}
.sdesc{font-size:13px;color:var(--mut);margin-bottom:16px}
.lbl{display:block;font-size:13px;font-weight:700;margin-bottom:6px}
.fi{width:100%;background:#F7F9FB;border:1.5px solid var(--bd);border-radius:11px;padding:12px 14px;font-family:inherit;font-size:15px;outline:none;transition:.15s}
.fi:focus{border-color:var(--tq);background:#fff;box-shadow:0 0 0 4px rgba(6,182,212,.12)}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.mb{margin-bottom:14px}
.btn{width:100%;padding:14px;border:none;border-radius:11px;font-family:inherit;font-size:15px;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:.15s}
.btn-p{background:linear-gradient(135deg,var(--tq),var(--bl));color:#fff;box-shadow:0 10px 22px rgba(37,99,235,.25)}
.btn-p:hover{filter:brightness(1.05)}
.btn-p:disabled{opacity:.5;cursor:not-allowed;filter:none}
.btn-g{background:#EEF2F7;color:var(--ink);margin-top:8px}
.opt{display:flex;align-items:center;gap:12px;border:1.5px solid var(--bd);border-radius:12px;padding:13px 14px;margin-bottom:8px;cursor:pointer;transition:.15s}
.opt:hover{border-color:var(--tq);background:#F7FDFE}
.opt.sel{border-color:var(--tq);background:rgba(6,182,212,.07)}
.opt .ic{width:38px;height:38px;border-radius:50%;background:rgba(6,182,212,.12);color:var(--tq);display:flex;align-items:center;justify-content:center;font-size:18px;flex:none}
.opt .nm{font-weight:700;font-size:14px}.opt .sub{font-size:12px;color:var(--mut)}
.slots{display:grid;grid-template-columns:repeat(auto-fill,minmax(74px,1fr));gap:8px;margin-top:12px}
.slot{border:1.5px solid var(--bd);border-radius:10px;padding:10px 0;text-align:center;font-weight:700;font-size:14px;cursor:pointer;transition:.15s}
.slot:hover{border-color:var(--tq)}
.slot.sel{background:linear-gradient(135deg,var(--tq),var(--bl));color:#fff;border-color:transparent}
.msg{font-size:13px;color:var(--mut);text-align:center;padding:14px}
.err{background:#FEF2F2;border:1px solid #FBD5D5;color:#B91C1C;font-size:13px;padding:10px 12px;border-radius:9px;margin-bottom:12px;display:none}
.badge-ok{display:inline-flex;align-items:center;gap:5px;background:rgba(16,185,129,.12);color:#059669;font-size:12px;font-weight:700;padding:4px 10px;border-radius:20px}
.done{text-align:center;padding:10px 0}
.done .circle{width:76px;height:76px;border-radius:50%;background:rgba(16,185,129,.12);color:#10B981;display:flex;align-items:center;justify-content:center;font-size:40px;margin:0 auto 14px}
.rescard{background:#F7F9FB;border:1px solid var(--bd);border-radius:12px;padding:14px;text-align:left;font-size:14px;margin:14px 0}
.rescard div{display:flex;justify-content:space-between;padding:4px 0}
.rescard b{color:var(--ink)}
.cal{border:1.5px solid var(--bd);border-radius:14px;padding:12px;user-select:none}
.cal-h{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.cal-h b{font-size:14px;font-weight:800;text-transform:capitalize}
.cal-nav{width:34px;height:34px;border:none;background:#EEF2F7;border-radius:9px;cursor:pointer;font-size:17px;color:var(--ink);line-height:1}
.cal-nav:disabled{opacity:.35;cursor:not-allowed}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:3px}
.cal-dow{text-align:center;font-size:11px;font-weight:700;color:var(--mut);padding:4px 0}
.cal-d{aspect-ratio:1;display:flex;align-items:center;justify-content:center;border-radius:9px;font-size:13.5px;font-weight:700;cursor:pointer;transition:.12s}
.cal-d:hover:not(.off):not(.empty){background:rgba(6,182,212,.12)}
.cal-d.off{color:#CBD5E1;cursor:not-allowed}
.cal-d.sel{background:linear-gradient(135deg,var(--tq),var(--bl));color:#fff}
.cal-d.empty{cursor:default}
.foot{text-align:center;font-size:11px;color:#9AA7B5;padding:0 24px 18px}
</style></head><body>
<div class="wrap">
  <div class="head">
    <?php if($logo): ?><img src="<?=e($logo)?>" alt=""><?php endif; ?>
    <h1><?=e($clinica)?></h1>
    <p>Reserva tu cita en línea</p>
  </div>
  <div class="steps">
    <div class="st on" data-s="1"></div><div class="st" data-s="2"></div><div class="st" data-s="3"></div>
    <div class="st" data-s="4"></div><div class="st" data-s="5"></div><div class="st" data-s="6"></div>
  </div>
  <div class="body">
    <div class="err" id="err"></div>

    <!-- 1: DNI -->
    <div class="stepwrap on" data-step="1">
      <div class="slabel">Paso 1</div><div class="stitle">Tu documento</div>
      <div class="sdesc">Elige tu tipo de documento, ingresa tu número y tu fecha de nacimiento.</div>
      <label class="lbl">Tipo de documento</label>
      <select id="tipodoc" class="fi mb">
        <option value="DNI">DNI</option>
        <option value="Carné de extranjería">Carné de extranjería</option>
        <option value="Pasaporte">Pasaporte</option>
        <option value="Otro">Otro</option>
      </select>
      <label class="lbl" id="ldoc">Número de DNI</label>
      <input type="tel" id="dni" class="fi mb" maxlength="20" inputmode="numeric" placeholder="Ej. 42799312" autofocus>
      <label class="lbl">Fecha de nacimiento</label>
      <input type="date" id="fnac1" class="fi" max="<?=date('Y-m-d')?>">
      <div class="sdesc" style="margin:8px 0 14px;font-size:12px"><i class="bi bi-shield-lock" style="color:var(--tq)"></i> La usamos solo para verificar tu identidad.</div>
      <button class="btn btn-p" id="b1"><i class="bi bi-arrow-right"></i> Continuar</button>
    </div>

    <!-- 2: Datos -->
    <div class="stepwrap" data-step="2">
      <div class="slabel">Paso 2</div><div class="stitle" id="t2">Tus datos</div>
      <div class="sdesc" id="d2">Completa tus datos para registrarte.</div>
      <div class="row2 mb"><div><label class="lbl">Nombres</label><input id="nombres" class="fi"></div><div><label class="lbl">Ap. paterno</label><input id="apellido_paterno" class="fi"></div></div>
      <div class="row2 mb"><div><label class="lbl">Ap. materno</label><input id="apellido_materno" class="fi"></div><div><label class="lbl">Teléfono</label><input id="telefono" class="fi" inputmode="tel"></div></div>
      <div class="mb"><label class="lbl">Correo (opcional)</label><input id="email" class="fi" inputmode="email" placeholder="correo@ejemplo.com"></div>
      <div class="row2 mb"><div><label class="lbl">Sexo</label><select id="sexo" class="fi"><option value="">—</option><option value="M">Masculino</option><option value="F">Femenino</option></select></div><div><label class="lbl">Nacimiento</label><input id="fecha_nacimiento" type="date" class="fi"></div></div>
      <button class="btn btn-p" id="b2">Continuar</button>
      <button class="btn btn-g" onclick="go(1)">Atrás</button>
    </div>

    <!-- 3: Servicio -->
    <div class="stepwrap" data-step="3">
      <div class="slabel">Paso 3</div><div class="stitle">¿Qué necesitas?</div>
      <div class="sdesc">Elige el servicio o motivo de tu visita.</div>
      <div id="servicios"><div class="msg">Cargando…</div></div>
      <button class="btn btn-g" onclick="go(2)">Atrás</button>
    </div>

    <!-- 4: Doctor -->
    <div class="stepwrap" data-step="4">
      <div class="slabel">Paso 4</div><div class="stitle">Elige tu especialista</div>
      <div class="sdesc">Selecciona con quién deseas atenderte.</div>
      <div id="doctores"><div class="msg">Cargando…</div></div>
      <button class="btn btn-g" onclick="go(3)">Atrás</button>
    </div>

    <!-- 5: Fecha y hora -->
    <div class="stepwrap" data-step="5">
      <div class="slabel">Paso 5</div><div class="stitle">Día y hora</div>
      <div class="sdesc">Elige un día en el calendario y verás los horarios disponibles.</div>
      <div class="cal" id="cal"></div>
      <div id="slotwrap" style="display:none">
        <div class="lbl" id="slotday" style="margin-top:16px"></div>
        <div id="slots" class="slots"></div>
        <div id="slotmsg" class="msg" style="display:none"></div>
      </div>
      <button class="btn btn-p mb" id="b5" style="margin-top:14px" disabled>Continuar</button>
      <button class="btn btn-g" onclick="go(4)">Atrás</button>
    </div>

    <!-- 6: Confirmación -->
    <div class="stepwrap" data-step="6">
      <div class="slabel">Paso 6</div><div class="stitle">Confirma tu cita</div>
      <div class="sdesc">Revisa los datos antes de reservar.</div>
      <div class="rescard" id="resumen"></div>
      <button class="btn btn-p" id="b6"><i class="bi bi-check2-circle"></i> Confirmar reserva</button>
      <button class="btn btn-g" onclick="go(5)">Atrás</button>
    </div>

    <!-- OK -->
    <div class="stepwrap" data-step="7">
      <div class="done">
        <div class="circle"><i class="bi bi-check-lg"></i></div>
        <div class="stitle">¡Cita reservada!</div>
        <div class="sdesc" id="okmsg"></div>
        <div class="rescard" id="okres"></div>
        <span class="badge-ok"><i class="bi bi-clock"></i> Estado: pendiente de confirmación</span>
      </div>
    </div>
  </div>
  <div class="foot">Reserva en línea · <?=e($clinica)?></div>
</div>
<script>
const S={dni:'',fnac:'',tipodoc:'DNI',existe:false,servicio_id:0,servicio_nombre:'',doctor_id:0,doctor_nombre:'',fecha:'',hora:''};
const $=id=>document.getElementById(id);
const errBox=$('err');
function err(m){errBox.textContent=m;errBox.style.display=m?'block':'none';}
function go(n){
  document.querySelectorAll('.stepwrap').forEach(x=>x.classList.remove('on'));
  document.querySelector('.stepwrap[data-step="'+n+'"]').classList.add('on');
  document.querySelectorAll('.steps .st').forEach(x=>x.classList.toggle('on', (+x.dataset.s)<=n));
  err('');
  if(n===3&&!$('servicios').dataset.loaded)loadServicios();
  if(n===4&&!$('doctores').dataset.loaded)loadDoctores();
  if(n===5){initCalOnce();if(S.fecha)loadSlots();}
  if(n===6)buildResumen();
}
async function api(q,opt){const r=await fetch('reservar.php?ajax='+q,opt);return r.json();}

// Paso 1
const tipoSel=$('tipodoc');
tipoSel.onchange=()=>{
  const t=tipoSel.value;
  $('ldoc').textContent = t==='DNI' ? 'Número de DNI' : 'Número de '+t.toLowerCase();
  $('dni').inputMode = t==='DNI' ? 'numeric' : 'text';
  $('dni').placeholder = t==='DNI' ? 'Ej. 42799312' : 'Ingresa tu número';
  $('dni').value='';
};
$('b1').onclick=async()=>{
  const tipo=tipoSel.value;
  const num=(tipo==='DNI') ? $('dni').value.replace(/\D/g,'') : $('dni').value.trim();
  const fn=$('fnac1').value;
  if(tipo==='DNI'){ if(num.length!==8) return err('El DNI debe tener 8 dígitos.'); }
  else if(num.length<5){ return err('Ingresa un número de documento válido.'); }
  if(!fn)return err('Ingresa tu fecha de nacimiento.');
  S.dni=num;S.fnac=fn;S.tipodoc=tipo;$('b1').disabled=true;
  // 1) verificación interna (paciente existente)
  const r=await api('dni&dni='+encodeURIComponent(num)+'&fnac='+fn);
  if(r.error){$('b1').disabled=false;return err(r.error);}
  S.existe=!!r.existe;
  $('fecha_nacimiento').value=fn;
  if(r.existe&&r.p){
    $('nombres').value=r.p.nombres||'';$('apellido_paterno').value=r.p.apellido_paterno||'';
    $('apellido_materno').value=r.p.apellido_materno||'';$('telefono').value=r.p.telefono||'';
    $('email').value=r.p.email||'';$('sexo').value=r.p.sexo||'';$('fecha_nacimiento').value=r.p.fecha_nacimiento||fn;
    $('t2').textContent='¡Hola de nuevo!';$('d2').textContent='Verificamos tu identidad. Confirma que tus datos sigan correctos.';
    $('b1').disabled=false;return go(2);
  }
  // 2) paciente nuevo: si es DNI, autocompletar nombres desde RENIEC
  if(tipo==='DNI'){
    try{ const g=await api('reniec&doc='+num);
      if(g.ok&&g.data){ $('nombres').value=g.data.nombres||''; $('apellido_paterno').value=g.data.apellido_paterno||''; $('apellido_materno').value=g.data.apellido_materno||''; }
    }catch(e){}
  }
  $('b1').disabled=false;
  $('t2').textContent='Tus datos';$('d2').textContent='Completa tus datos para registrarte.';
  go(2);
};
// Paso 2
$('b2').onclick=()=>{
  if(!$('nombres').value.trim()||!$('apellido_paterno').value.trim())return err('Nombres y apellido paterno son obligatorios.');
  if(!$('telefono').value.trim())return err('El teléfono es obligatorio.');
  go(3);
};
// Paso 3 servicios
async function loadServicios(){
  const r=await api('servicios');const c=$('servicios');c.dataset.loaded=1;
  if(!r.servicios||!r.servicios.length){c.innerHTML='<div class="msg">No hay servicios configurados.</div>';return;}
  c.innerHTML=r.servicios.map(s=>`<div class="opt" onclick="pickServ(${s.id},this)" data-nm="${(s.nombre||'').replace(/"/g,'&quot;')}"><div class="ic"><i class="bi bi-clipboard2-pulse"></i></div><div><div class="nm">${s.nombre||''}</div></div></div>`).join('');
}
function pickServ(id,el){document.querySelectorAll('#servicios .opt').forEach(o=>o.classList.remove('sel'));el.classList.add('sel');S.servicio_id=id;S.servicio_nombre=el.dataset.nm;setTimeout(()=>go(4),150);}
// Paso 4 doctores
async function loadDoctores(){
  const r=await api('doctores');const c=$('doctores');c.dataset.loaded=1;
  if(!r.doctores||!r.doctores.length){c.innerHTML='<div class="msg">No hay especialistas disponibles.</div>';return;}
  c.innerHTML=r.doctores.map(d=>{const nm=`${d.nombre||''} ${d.apellidos||''}`.trim();return `<div class="opt" onclick="pickDoc(${d.id},'${nm.replace(/'/g,"\\'")}',this)"><div class="ic"><i class="bi bi-person-badge"></i></div><div><div class="nm">${nm}</div><div class="sub">${d.especialidad||'Odontología'}</div></div></div>`;}).join('');
}
function pickDoc(id,nm,el){document.querySelectorAll('#doctores .opt').forEach(o=>o.classList.remove('sel'));el.classList.add('sel');S.doctor_id=id;S.doctor_nombre=nm;setTimeout(()=>go(5),150);}
// Paso 5: calendario + horarios
const RES={dias:"<?=e(reserva_cfg()['dias'])?>".split(','),min:"<?=$minFecha?>",max:"<?=$maxFecha?>"};
const MESES=['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
const DOWL=['Lu','Ma','Mi','Ju','Vi','Sá','Do'];
let calY,calM,calInit=false;
function ymd(d){return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');}
function initCalOnce(){if(calInit)return;calInit=true;const t=new Date(RES.min+'T00:00:00');calY=t.getFullYear();calM=t.getMonth();renderCal();}
function renderCal(){
  const first=new Date(calY,calM,1),startDow=(first.getDay()+6)%7,ndays=new Date(calY,calM+1,0).getDate();
  const cur=calY+'-'+String(calM+1).padStart(2,'0');
  const minM=cur<=RES.min.slice(0,7),maxM=cur>=RES.max.slice(0,7);
  let h=`<div class="cal-h"><button class="cal-nav" id="cprev" ${minM?'disabled':''}>‹</button><b>${MESES[calM]} ${calY}</b><button class="cal-nav" id="cnext" ${maxM?'disabled':''}>›</button></div><div class="cal-grid">`;
  h+=DOWL.map(d=>`<div class="cal-dow">${d}</div>`).join('');
  for(let i=0;i<startDow;i++)h+='<div class="cal-d empty"></div>';
  for(let dn=1;dn<=ndays;dn++){
    const dd=new Date(calY,calM,dn),sday=ymd(dd),dow=((dd.getDay()+6)%7)+1;
    const off=(!RES.dias.includes(String(dow)))||sday<RES.min||sday>RES.max;
    h+=`<div class="cal-d ${off?'off':''} ${sday===S.fecha?'sel':''}" ${off?'':`onclick="pickDay('${sday}',this)"`}>${dn}</div>`;
  }
  $('cal').innerHTML=h+'</div>';
  const pv=$('cprev'),nx=$('cnext');
  if(pv&&!pv.disabled)pv.onclick=()=>{if(--calM<0){calM=11;calY--;}renderCal();};
  if(nx&&!nx.disabled)nx.onclick=()=>{if(++calM>11){calM=0;calY++;}renderCal();};
}
function pickDay(sday,el){S.fecha=sday;S.hora='';$('b5').disabled=true;document.querySelectorAll('.cal-d').forEach(x=>x.classList.remove('sel'));el.classList.add('sel');$('slotwrap').style.display='block';$('slotday').textContent='Horarios · '+sday.split('-').reverse().join('/');loadSlots();}
async function loadSlots(){
  if(!S.doctor_id||!S.fecha)return;S.hora='';$('b5').disabled=true;
  const box=$('slots');box.innerHTML='<div class="msg">Cargando…</div>';$('slotmsg').style.display='none';
  const r=await api('slots&doctor='+S.doctor_id+'&fecha='+S.fecha);
  if(!r.slots||!r.slots.length){box.innerHTML='';$('slotmsg').textContent='No hay horarios disponibles ese día. Prueba otro.';$('slotmsg').style.display='block';return;}
  box.innerHTML=r.slots.map(hh=>`<div class="slot" onclick="pickSlot('${hh}',this)">${hh}</div>`).join('');
}
function pickSlot(h,el){document.querySelectorAll('#slots .slot').forEach(s=>s.classList.remove('sel'));el.classList.add('sel');S.hora=h;$('b5').disabled=false;}
$('b5').onclick=()=>{if(!S.hora)return err('Elige un horario.');go(6);};
// Paso 6 resumen
function buildResumen(){
  $('resumen').innerHTML=`
    <div><span>Paciente</span><b>${$('nombres').value} ${$('apellido_paterno').value}</b></div>
    <div><span>Servicio</span><b>${S.servicio_nombre}</b></div>
    <div><span>Especialista</span><b>${S.doctor_nombre}</b></div>
    <div><span>Fecha</span><b>${S.fecha.split('-').reverse().join('/')}</b></div>
    <div><span>Hora</span><b>${S.hora}</b></div>`;
}
$('b6').onclick=async()=>{
  $('b6').disabled=true;$('b6').innerHTML='Reservando…';
  const fd=new FormData();
  Object.entries({dni:S.dni,tipo_documento:S.tipodoc,nombres:$('nombres').value.trim(),apellido_paterno:$('apellido_paterno').value.trim(),apellido_materno:$('apellido_materno').value.trim(),telefono:$('telefono').value.trim(),email:$('email').value.trim(),sexo:$('sexo').value,fecha_nacimiento:$('fecha_nacimiento').value,servicio_id:S.servicio_id,servicio_nombre:S.servicio_nombre,doctor_id:S.doctor_id,fecha:S.fecha,hora:S.hora}).forEach(([k,v])=>fd.append(k,v));
  const r=await api('confirmar',{method:'POST',body:fd});
  $('b6').disabled=false;$('b6').innerHTML='<i class="bi bi-check2-circle"></i> Confirmar reserva';
  if(r.error)return err(r.error);
  if(r.ok){
    $('okmsg').textContent='Te esperamos. Guarda tu código: '+r.codigo;
    $('okres').innerHTML=`<div><span>Servicio</span><b>${r.resumen.servicio}</b></div><div><span>Fecha</span><b>${r.resumen.fecha}</b></div><div><span>Hora</span><b>${r.resumen.hora}</b></div><div><span>Código</span><b>${r.codigo}</b></div>`;
    document.querySelectorAll('.steps .st').forEach(x=>x.classList.add('on'));
    document.querySelectorAll('.stepwrap').forEach(x=>x.classList.remove('on'));
    document.querySelector('.stepwrap[data-step="7"]').classList.add('on');
  }
};
$('dni').addEventListener('keydown',e=>{if(e.key==='Enter')$('b1').click();});
</script>
</body></html>
