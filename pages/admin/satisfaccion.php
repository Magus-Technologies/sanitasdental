<?php
/** Resultados de la Encuesta de Satisfacción (solo administrador). */
require_once __DIR__.'/../../includes/config.php';
requiereRol('admin');
$titulo='Satisfacción de pacientes'; $pagina_activa='satisf';

$face=function($v){ if($v===null||$v==='') return '—'; $m=[1=>'😡',2=>'🙁',3=>'😐',4=>'🙂',5=>'😀']; return $m[(int)round($v)]??'—'; };
$txt =[1=>'Muy insatisfecho',2=>'Insatisfecho',3=>'Neutral',4=>'Satisfecho',5=>'Muy satisfecho'];

$ok=true; $err='';
$res=['n'=>0,'pc'=>null,'pd'=>null,'sat'=>0]; $porDoc=[]; $lista=[]; $dist=[1=>0,2=>0,3=>0,4=>0,5=>0];
try {
    $row=db()->query("SELECT COUNT(*) n, AVG(calif_clinica) pc, AVG(calif_doctor) pd, SUM(calif_clinica>=4) sat FROM encuestas_satisfaccion")->fetch();
    if($row){ $res=['n'=>(int)$row['n'],'pc'=>$row['pc'],'pd'=>$row['pd'],'sat'=>(int)$row['sat']]; }
    foreach(db()->query("SELECT calif_clinica c, COUNT(*) n FROM encuestas_satisfaccion GROUP BY calif_clinica")->fetchAll() as $d){ $dist[(int)$d['c']]=(int)$d['n']; }
    $porDoc=db()->query("SELECT CONCAT(u.nombre,' ',u.apellidos) doctor, COUNT(*) n, AVG(e.calif_doctor) prom FROM encuestas_satisfaccion e JOIN usuarios u ON e.doctor_id=u.id WHERE e.calif_doctor IS NOT NULL GROUP BY u.id ORDER BY prom DESC, n DESC")->fetchAll();
    $lista=db()->query("SELECT e.*, CONCAT(p.nombres,' ',p.apellido_paterno) pac, p.codigo cod, CONCAT(u.nombre,' ',u.apellidos) doctor FROM encuestas_satisfaccion e JOIN pacientes p ON e.paciente_id=p.id LEFT JOIN usuarios u ON e.doctor_id=u.id ORDER BY e.created_at DESC LIMIT 200")->fetchAll();
} catch (Throwable $e) { $ok=false; $err=$e->getMessage(); }

$pctSat = $res['n'] ? round($res['sat']*100/$res['n']) : 0;
require_once __DIR__.'/../../includes/header.php';
?>
<div class="pb">
<?=popFlash()?>
<?php if(!$ok): ?>
  <div class="card p-4" style="color:var(--t2)"><i class="bi bi-exclamation-triangle me-2" style="color:#f59e0b"></i>No se pudo leer la encuesta. Asegúrate de haber importado la migración <code>022_satisfaccion.sql</code>.</div>
<?php elseif(!$res['n']): ?>
  <div class="card p-5 text-center" style="color:var(--t2)"><i class="bi bi-star" style="font-size:38px;display:block;margin-bottom:10px"></i>Aún no hay respuestas de pacientes.<br><span style="font-size:13px">Las encuestas se responden desde el portal del paciente.</span></div>
<?php else: ?>

  <div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card p-3 h-100"><div style="color:var(--t2);font-size:12px">Respuestas</div><div style="font-size:26px;font-weight:700;color:var(--t)"><?=$res['n']?></div></div></div>
    <div class="col-6 col-lg-3"><div class="card p-3 h-100"><div style="color:var(--t2);font-size:12px">Promedio clínica</div><div style="font-size:26px;font-weight:700;color:var(--c)"><?=$face($res['pc'])?> <?=number_format((float)$res['pc'],1)?><span style="font-size:14px;color:var(--t3)">/5</span></div></div></div>
    <div class="col-6 col-lg-3"><div class="card p-3 h-100"><div style="color:var(--t2);font-size:12px">Promedio doctor</div><div style="font-size:26px;font-weight:700;color:var(--c)"><?=$res['pd']!==null?$face($res['pd']).' '.number_format((float)$res['pd'],1):'—'?><?php if($res['pd']!==null):?><span style="font-size:14px;color:var(--t3)">/5</span><?php endif;?></div></div></div>
    <div class="col-6 col-lg-3"><div class="card p-3 h-100"><div style="color:var(--t2);font-size:12px">% Satisfechos (4–5)</div><div style="font-size:26px;font-weight:700;color:#2ECC8E"><?=$pctSat?>%</div></div></div>
  </div>

  <div class="row g-4">
    <div class="col-12 col-lg-5">
      <div class="card mb-4"><div class="card-header"><span style="color:var(--t)">Distribución (clínica)</span></div>
        <div class="p-3">
          <?php $maxd=max(1,max($dist)); for($i=5;$i>=1;$i--): $w=round($dist[$i]*100/$maxd); ?>
          <div class="d-flex align-items-center gap-2 mb-2">
            <span style="width:120px;font-size:12px;color:var(--t2)"><?=$face($i)?> <?=$txt[$i]?></span>
            <div style="flex:1;background:var(--bg3);border-radius:6px;overflow:hidden;height:16px"><div style="height:100%;width:<?=$w?>%;background:linear-gradient(90deg,var(--c),#2ECC8E)"></div></div>
            <span style="width:28px;text-align:right;font-size:12px;color:var(--t)"><?=$dist[$i]?></span>
          </div>
          <?php endfor; ?>
        </div>
      </div>
      <?php if($porDoc): ?>
      <div class="card"><div class="card-header"><span style="color:var(--t)">Promedio por doctor</span></div>
        <div class="table-responsive"><table class="table mb-0">
          <thead><tr><th>Doctor</th><th class="text-center">Respuestas</th><th class="text-end">Promedio</th></tr></thead>
          <tbody>
          <?php foreach($porDoc as $d): ?>
          <tr><td style="color:var(--t)"><?=e($d['doctor'])?></td><td class="text-center" style="color:var(--t2)"><?=$d['n']?></td><td class="text-end" style="color:var(--c);font-weight:600"><?=$face($d['prom'])?> <?=number_format((float)$d['prom'],1)?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
      <?php endif; ?>
    </div>

    <div class="col-12 col-lg-7">
      <div class="card"><div class="card-header"><span style="color:var(--t)">Respuestas recientes</span></div>
        <div class="table-responsive"><table class="table mb-0">
          <thead><tr><th>Fecha</th><th>Paciente</th><th class="text-center">Clínica</th><th class="text-center">Doctor</th><th>Comentario</th></tr></thead>
          <tbody>
          <?php foreach($lista as $r): ?>
          <tr>
            <td style="color:var(--t2);white-space:nowrap"><?=fDate($r['created_at'])?></td>
            <td style="color:var(--t)"><?=e($r['pac'])?><br><small style="color:var(--t3)"><?=e($r['cod'])?></small></td>
            <td class="text-center" title="<?=$txt[(int)$r['calif_clinica']]??''?>" style="font-size:20px"><?=$face($r['calif_clinica'])?><br><small style="color:var(--t3);font-size:10px"><?=(int)$r['calif_clinica']?></small></td>
            <td class="text-center" title="<?=($txt[(int)$r['calif_doctor']]??'').($r['doctor']?' · '.e($r['doctor']):'')?>" style="font-size:20px"><?=$r['calif_doctor']!==null?$face($r['calif_doctor']):'—'?><?php if($r['calif_doctor']!==null):?><br><small style="color:var(--t3);font-size:10px"><?=(int)$r['calif_doctor']?></small><?php endif;?></td>
            <td style="color:var(--t2);font-size:13px"><?=$r['comentario']?nl2br(e($r['comentario'])):'<span style="color:var(--t3)">—</span>'?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
    </div>
  </div>

<?php endif; ?>
</div>
<?php require_once __DIR__.'/../../includes/footer.php';