<?php
require_once __DIR__.'/../includes/config.php';
$turnos=db()->query("SELECT t.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac,CONCAT(u.nombre,' ',u.apellidos) AS dr,s.nombre AS sill FROM turnos t JOIN citas c ON t.cita_id=c.id JOIN pacientes p ON c.paciente_id=p.id JOIN usuarios u ON c.doctor_id=u.id LEFT JOIN sillones s ON c.sillon_id=s.id WHERE c.fecha=CURDATE() AND t.estado!='atendido' ORDER BY t.numero")->fetchAll();
?><!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Turnos — <?=getCfg('clinica_nombre')?></title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;700;800;900&family=DM+Mono:wght@500&display=swap" rel="stylesheet">
<meta http-equiv="refresh" content="15">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Nunito',sans-serif;background:#060E1A;color:#E8EDF2;min-height:100vh;padding:24px}
.header{text-align:center;padding:20px 0 30px;border-bottom:2px solid rgba(0,212,238,.2);margin-bottom:30px}
.logo{font-size:32px}
.clinica{font-size:24px;font-weight:800;color:#00D4EE;margin:6px 0}
.fecha{font-size:16px;color:#607080}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px}
@media(max-width:480px){.grid{grid-template-columns:1fr 1fr;gap:10px}.num{font-size:38px!important}.pac{font-size:15px!important}.clinica{font-size:18px!important}}
.turno{border-radius:12px;padding:20px 22px;transition:all .3s}
.t-esp{background:rgba(96,112,128,.1);border:1px solid rgba(96,112,128,.2)}
.t-llam{background:rgba(245,166,35,.12);border:2px solid rgba(245,166,35,.5);animation:pulse 1.5s infinite}
.t-aten{background:rgba(0,212,238,.12);border:2px solid rgba(0,212,238,.5)}
@keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(245,166,35,.4)}50%{box-shadow:0 0 0 12px rgba(245,166,35,0)}}
.num{font-family:'DM Mono',monospace;font-size:52px;font-weight:500;line-height:1}
.num-esp{color:#607080}.num-llam{color:#F5A623}.num-aten{color:#00D4EE}
.pac{font-size:18px;font-weight:800;margin-top:8px}
.info{font-size:13px;color:#7BC8D4;margin-top:4px}
.estado-badge{display:inline-block;font-size:11px;font-weight:700;padding:3px 10px;border-radius:5px;margin-top:8px;text-transform:uppercase;letter-spacing:.8px}
.eb-llam{background:rgba(245,166,35,.2);color:#F5A623;border:1px solid rgba(245,166,35,.4)}
.eb-aten{background:rgba(0,212,238,.2);color:#00D4EE;border:1px solid rgba(0,212,238,.4)}
.eb-esp{background:rgba(96,112,128,.15);color:#A0B0C0;border:1px solid rgba(96,112,128,.25)}
.vacio{text-align:center;padding:80px;color:#506070;font-size:20px}
</style></head><body>
<div class="header">
 <div class="logo">🦷</div>
 <div class="clinica"><?=getCfg('clinica_nombre')?></div>
 <div class="fecha"><?=date('l d/m/Y H:i', time())?> · Sistema de Turnos</div>
</div>
<?php if($turnos): ?>
<div class="grid">
 <?php foreach($turnos as $t):
  $cls=['esperando'=>'t-esp','llamado'=>'t-llam','en_atencion'=>'t-aten'][$t['estado']]??'t-esp';
  $ncls=['esperando'=>'num-esp','llamado'=>'num-llam','en_atencion'=>'num-aten'][$t['estado']]??'num-esp';
  $ecls=['esperando'=>'eb-esp','llamado'=>'eb-llam','en_atencion'=>'eb-aten'][$t['estado']]??'eb-esp';
  $elbl=['esperando'=>'⏳ Esperando','llamado'=>'📢 LLAMADO','en_atencion'=>'🔬 En atención'][$t['estado']]??'';
 ?>
 <div class="turno <?=$cls?>">
  <div class="num <?=$ncls?>"><?=str_pad($t['numero'],2,'0',STR_PAD_LEFT)?></div>
  <div class="pac"><?=e(explode(' ',$t['pac'])[0])?> <?=e(explode(' ',$t['pac'])[1]??'')?></div>
  <div class="info">Dr. <?=e(explode(' ',$t['dr'])[0])?> • <?=e($t['sill']??'—')?></div>
  <div class="estado-badge <?=$ecls?>"><?=$elbl?></div>
 </div>
 <?php endforeach; ?>
</div>
<?php else: ?>
<div class="vacio"><div style="font-size:60px">🦷</div><br>No hay turnos activos hoy</div>
<?php endif; ?>
</body></html>
