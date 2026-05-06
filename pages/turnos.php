<?php
require_once __DIR__.'/../includes/config.php';
requiereLogin();
$titulo='Pantalla de Turnos'; $pagina_activa='turnos';

if($_SERVER['REQUEST_METHOD']==='POST'){
 $ap=$_POST['accion']??'';
 if($ap==='llamar'){
  $tid=(int)$_POST['turno_id'];
  db()->prepare("UPDATE turnos SET estado='llamado',llamado_at=NOW() WHERE id=?")->execute([$tid]);
  flash('ok','Turno llamado.'); go('pages/turnos.php');
 }
 if($ap==='atender'){
  $tid=(int)$_POST['turno_id'];
  db()->prepare("UPDATE turnos SET estado='en_atencion' WHERE id=?")->execute([$tid]);
  // También actualizar cita
  $cid=db()->query("SELECT cita_id FROM turnos WHERE id=$tid")->fetchColumn();
  if($cid) db()->prepare("UPDATE citas SET estado='en_atencion' WHERE id=?")->execute([$cid]);
  flash('ok','Paciente en atención.'); go('pages/turnos.php');
 }
 if($ap==='completar'){
  $tid=(int)$_POST['turno_id'];
  db()->prepare("UPDATE turnos SET estado='atendido' WHERE id=?")->execute([$tid]);
  flash('ok','Atención completada.'); go('pages/turnos.php');
 }
}

$turnos=db()->query("SELECT t.*,c.hora_inicio,c.motivo,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac,p.telefono,CONCAT(u.nombre,' ',u.apellidos) AS dr,s.nombre AS sill FROM turnos t JOIN citas c ON t.cita_id=c.id JOIN pacientes p ON c.paciente_id=p.id JOIN usuarios u ON c.doctor_id=u.id LEFT JOIN sillones s ON c.sillon_id=s.id WHERE c.fecha=CURDATE() ORDER BY t.numero")->fetchAll();

require_once __DIR__.'/../includes/header.php';
$topbar_act='<a href="'.BASE_URL.'/pages/turnos_pantalla.php" target="_blank" class="btn btn-primary"><i class="bi bi-display me-1"></i>Abrir pantalla TV</a>';
?>
<div class="row g-4">
 <div class="col-12 col-lg-8">
  <div class="card">
   <div class="card-header"><span><i class="bi bi-list-ol me-1"></i>Cola de turnos — <?=date('d/m/Y')?></span></div>
   <div class="table-responsive"><table class="table mb-0">
    <thead><tr><th>N°</th><th>Paciente</th><th>Doctor</th><th>Hora</th><th>Sillón</th><th>Estado</th><th>Acciones</th></tr></thead>
    <tbody>
    <?php
    $tc=['esperando'=>'bgr','llamado'=>'ba','en_atencion'=>'bb','atendido'=>'bg'];
    $tl=['esperando'=>'⏳ Esperando','llamado'=>'📢 Llamado','en_atencion'=>'🔬 En atención','atendido'=>'✅ Atendido'];
    foreach($turnos as $t): ?>
    <tr>
     <td><span class="mon fw-bold" style="font-size:18px;color:var(--c)"><?=$t['numero']?></span></td>
     <td><strong><?=e($t['pac'])?></strong><?php if($t['llamado_at']&&$t['estado']==='llamado'): ?><br><small style="color:var(--a)">Llamado: <?=fDT($t['llamado_at'])?></small><?php endif; ?></td>
     <td><small><?=e($t['dr'])?></small></td>
     <td class="mon" style="color:var(--c)"><?=$t['hora_inicio']?substr($t['hora_inicio'],0,5):'—'?></td>
     <td><small><?=e($t['sill']??'—')?></small></td>
     <td><span class="badge <?=$tc[$t['estado']]?>"><?=$tl[$t['estado']]?></span></td>
     <td>
      <form method="POST" class="d-inline">
       <?php if($t['estado']==='esperando'): ?>
       <input type="hidden" name="accion" value="llamar"><input type="hidden" name="turno_id" value="<?=$t['id']?>">
       <button type="submit" class="btn btn-ok btn-sm"><i class="bi bi-megaphone me-1"></i>Llamar</button>
       <?php elseif($t['estado']==='llamado'): ?>
       <input type="hidden" name="accion" value="atender"><input type="hidden" name="turno_id" value="<?=$t['id']?>">
       <button type="submit" class="btn btn-primary btn-sm">Atender</button>
       <?php elseif($t['estado']==='en_atencion'): ?>
       <input type="hidden" name="accion" value="completar"><input type="hidden" name="turno_id" value="<?=$t['id']?>">
       <button type="submit" class="btn btn-ok btn-sm">Completar</button>
       <?php else: ?>—<?php endif; ?>
      </form>
     </td>
    </tr>
    <?php endforeach; if(!$turnos): ?>
    <tr><td colspan="7" class="text-center py-4" style="color:var(--t2)"><i class="bi bi-display" style="font-size:36px;display:block;margin-bottom:8px"></i>No hay turnos hoy</td></tr>
    <?php endif; ?></tbody>
   </table></div>
  </div>
 </div>
 <div class="col-12 col-lg-4">
  <!-- Preview pantalla -->
  <div class="card">
   <div class="card-header"><span style="color:var(--t)">📺 Preview pantalla</span></div>
   <div class="p-4" style="background:#0A1520;border-radius:8px;min-height:260px">
    <div class="text-center mb-3">
     <div style="font-size:13px;color:#00D4EE;letter-spacing:2px;text-transform:uppercase">🦷 <?=getCfg('clinica_nombre')?></div>
     <div style="font-size:11px;color:#607080;margin-top:2px"><?=date('d/m/Y H:i')?></div>
    </div>
    <?php foreach(array_filter($turnos,fn($t)=>$t['estado']!=='atendido') as $t): ?>
    <div class="p-3 rounded mb-2" style="background:<?=['esperando'=>'rgba(96,112,128,.15)','llamado'=>'rgba(245,166,35,.15)','en_atencion'=>'rgba(0,212,238,.15)'][$t['estado']]??'rgba(46,204,142,.15)'?>;border:1px solid <?=['esperando'=>'rgba(96,112,128,.25)','llamado'=>'rgba(245,166,35,.35)','en_atencion'=>'rgba(0,212,238,.35)'][$t['estado']]??'rgba(46,204,142,.25)'?>">
     <div class="d-flex justify-content-between">
      <span style="font-size:24px;font-weight:800;font-family:'DM Mono',monospace;color:<?=['llamado'=>'var(--a)','en_atencion'=>'var(--c)'][$t['estado']]??'var(--t2)'?>"><?=str_pad($t['numero'],2,'0',STR_PAD_LEFT)?></span>
      <div class="text-end"><div style="font-size:12px;font-weight:700"><?=e(explode(' ',$t['pac'])[0])?></div><small style="color:var(--t2)"><?=e($t['sill']??'—')?></small></div>
     </div>
    </div>
    <?php endforeach; ?>
   </div>
   <div class="p-3"><a href="<?=BASE_URL?>/pages/turnos_pantalla.php" target="_blank" class="btn btn-primary w-100"><i class="bi bi-display me-2"></i>Abrir pantalla completa (TV)</a></div>
  </div>
 </div>
</div>
<?php require_once __DIR__.'/../includes/footer.php';
