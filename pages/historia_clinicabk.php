<?php
require_once __DIR__.'/../includes/config.php';
requiereLogin();
$accion=$_GET['accion']??'lista'; $id=(int)($_GET['id']??0); $pac_id=(int)($_GET['paciente_id']??0);
// Si viene ?id=X sin accion, mostrar la HC directamente
if($id && $accion==='lista') $accion='ver';

if($_SERVER['REQUEST_METHOD']==='POST'){
 $ap=$_POST['accion']??'';
 if($ap==='guardar_hc'){
  $ei=(int)($_POST['id']??0);
  $d=['paciente_id'=>(int)$_POST['paciente_id'],'doctor_id'=>$_SESSION['uid'],'fecha_apertura'=>$_POST['fecha_apertura'],'motivo_consulta'=>trim($_POST['motivo_consulta']??''),'enfermedad_actual'=>trim($_POST['enfermedad_actual']??''),'anamnesis'=>trim($_POST['anamnesis']??''),'presion_arterial'=>trim($_POST['presion_arterial']??''),'peso'=>$_POST['peso']?:null,'talla'=>$_POST['talla']?:null,'examen_extraoral'=>trim($_POST['examen_extraoral']??''),'tejidos_blandos'=>trim($_POST['tejidos_blandos']??''),'diagnostico_cie10'=>trim($_POST['diagnostico_cie10']??''),'diagnostico_desc'=>trim($_POST['diagnostico_desc']??''),'plan_tratamiento'=>trim($_POST['plan_tratamiento']??'')];
  if($ei){$sets=implode(',',array_map(fn($k)=>"$k=?",array_keys($d)));db()->prepare("UPDATE historias_clinicas SET $sets,updated_at=NOW() WHERE id=?")->execute([...array_values($d),$ei]);flash('ok','HC actualizada.');go("pages/historia_clinica.php?id=$ei");}
  else{$num=genCodigo('HC','historias_clinicas','numero_hc');$d['numero_hc']=$num;
  $cols=implode(',',array_keys($d));$phs=implode(',',array_fill(0,count($d),'?'));
  db()->prepare("INSERT INTO historias_clinicas($cols)VALUES($phs)")->execute(array_values($d));
  $nid=db()->lastInsertId(); auditar('CREAR_HC','historias_clinicas',$nid);
  flash('ok',"HC creada: $num"); go("pages/historia_clinica.php?id=$nid");}
 }
 if($ap==='odontograma'){
  $hcId=(int)$_POST['hc_id']; $pacId=(int)$_POST['paciente_id'];
  $st=db()->prepare("SELECT id FROM odontogramas WHERE hc_id=? AND fecha=CURDATE()"); $st->execute([$hcId]); $oid=$st->fetchColumn();
  if(!$oid){db()->prepare("INSERT INTO odontogramas(hc_id,paciente_id,doctor_id,fecha)VALUES(?,?,?,CURDATE())")->execute([$hcId,$pacId,$_SESSION['uid']]);$oid=db()->lastInsertId();}
  $dts=json_decode($_POST['dientes_json']??'[]',true);
  db()->prepare("DELETE FROM odontograma_dientes WHERE odontograma_id=?")->execute([$oid]);
  foreach($dts as $dt) db()->prepare("INSERT INTO odontograma_dientes(odontograma_id,numero_diente,cara,estado,color,notas)VALUES(?,?,?,?,?,?)")->execute([$oid,$dt['n'],$dt['c']??'total',$dt['e'],$dt['col']??'azul',$dt['notas']??'']);
  db()->prepare("UPDATE odontogramas SET observaciones=? WHERE id=?")->execute([trim($_POST['obs']??''),$oid]);
  auditar('ODONTOGRAMA','odontogramas',$oid); flash('ok','Odontograma guardado.');
  header('Location:'.BASE_URL.'/pages/historia_clinica.php?id='.$hcId.'#odontograma'); exit;
 }
 if($ap==='evolucion'){
  $hcId=(int)$_POST['hc_id'];
  db()->prepare("INSERT INTO evoluciones(hc_id,cita_id,doctor_id,fecha,descripcion,procedimiento,diente,medicacion,proximo_control)VALUES(?,?,?,NOW(),?,?,?,?,?)")->execute([$hcId,$_POST['cita_id']?:null,$_SESSION['uid'],trim($_POST['descripcion']),trim($_POST['procedimiento']??''),trim($_POST['diente']??''),trim($_POST['medicacion']??''),$_POST['proximo_control']?:null]);
  auditar('EVOLUCION','historias_clinicas',$hcId); flash('ok','Evolución registrada.');
  header('Location:'.BASE_URL.'/pages/historia_clinica.php?id='.$hcId.'#evoluciones'); exit;
 }
 if($ap==='adjunto'){
  $hcId=(int)$_POST['hc_id']; $tipo=$_POST['tipo_adj']??'otro';
  $dmap=['radiografia'=>'radiografias','foto_intraoral'=>'fotos','foto_extraoral'=>'fotos','documento'=>'docs','otro'=>'docs'];
  if(!empty($_FILES['archivo']['name'])){
   $ruta=subirArchivo($_FILES['archivo'],$dmap[$tipo]??'docs',['jpg','jpeg','png','pdf','webp']);
   if($ruta){db()->prepare("INSERT INTO adjuntos(hc_id,tipo,nombre,ruta,descripcion,subido_por)VALUES(?,?,?,?,?,?)")->execute([$hcId,$tipo,$_FILES['archivo']['name'],$ruta,trim($_POST['desc_adj']??''),$_SESSION['uid']]);flash('ok','Archivo subido.');}
  }
  header('Location:'.BASE_URL.'/pages/historia_clinica.php?id='.$hcId.'#adjuntos'); exit;
 }
}

if($accion==='lista'){
 $titulo='Historias Clínicas'; $pagina_activa='hc';
 $q=trim($_GET['q']??'');
 $w='JOIN pacientes p ON hc.paciente_id=p.id WHERE 1=1'; $pm=[];
 if($pac_id){$w.=' AND hc.paciente_id=?';$pm[]=$pac_id;}
 if($q){$w.=' AND(p.nombres LIKE ? OR p.apellido_paterno LIKE ? OR hc.numero_hc LIKE ?)';$b="%$q%";$pm[]=$b;$pm[]=$b;$pm[]=$b;}
 $st=db()->prepare("SELECT hc.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac,p.codigo AS cod FROM historias_clinicas hc $w ORDER BY hc.fecha_apertura DESC LIMIT 60");
 $st->execute($pm); $lista=$st->fetchAll();
 $topbar_act='<a href="?accion=nueva'.($pac_id?"&paciente_id=$pac_id":'').'" class="btn btn-primary"><i class="bi bi-file-medical me-1"></i>Nueva HC</a>';
 require_once __DIR__.'/../includes/header.php';
?>
<div class="card mb-3 p-3"><form method="GET" class="d-flex gap-2">
 <input type="hidden" name="accion" value="lista"><?php if($pac_id): ?><input type="hidden" name="paciente_id" value="<?=$pac_id?>"><?php endif; ?>
 <div class="flex-fill"><input type="text" name="q" class="form-control" placeholder="Paciente o N° HC..." value="<?=e($q)?>"></div>
 <button type="submit" class="btn btn-dk">Buscar</button>
</form></div>
<div class="card"><div class="table-responsive"><table class="table mb-0">
 <thead><tr><th>N° HC</th><th>Paciente</th><th>Apertura</th><th>Motivo</th><th>Estado</th><th></th></tr></thead>
 <tbody>
 <?php foreach($lista as $hc): ?>
 <tr>
  <td class="mon" style="color:var(--c)"><?=e($hc['numero_hc'])?></td>
  <td><strong><?=e($hc['pac'])?></strong><br><small><?=e($hc['cod'])?></small></td>
  <td><?=fDate($hc['fecha_apertura'])?></td>
  <td><small><?=e(mb_substr($hc['motivo_consulta'],0,60))?>...</small></td>
  <td><span class="badge <?=$hc['estado']==='activa'?'bg':'bgr'?>"><?=$hc['estado']?></span></td>
  <td><div class="d-flex gap-1">
   <a href="?id=<?=$hc['id']?>" class="btn btn-primary btn-sm">Abrir HC</a>
   <a href="<?=BASE_URL?>/pages/hc_pdf.php?id=<?=$hc['id']?>" target="_blank" class="btn btn-dk btn-sm btn-ico" title="Ver PDF"><i class="bi bi-file-pdf"></i></a>
  </div></td>
 </tr>
 <?php endforeach; if(!$lista): ?>
 <tr><td colspan="6" class="text-center py-4" style="color:var(--t2)">No hay historias clínicas</td></tr>
 <?php endif; ?>
 </tbody>
</table></div></div>
<?php require_once __DIR__.'/../includes/footer.php';

}elseif($accion==='ver' && $id){
 $st=db()->prepare("SELECT hc.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac_nm,p.id AS pid,p.dni,p.fecha_nacimiento,p.alergias,p.enfermedades_base,p.medicacion_actual,CONCAT(u.nombre,' ',u.apellidos) AS dr FROM historias_clinicas hc JOIN pacientes p ON hc.paciente_id=p.id LEFT JOIN usuarios u ON hc.doctor_id=u.id WHERE hc.id=?");
 $st->execute([$id]); $hc=$st->fetch(); if(!$hc){flash('error','HC no encontrada');go('pages/historia_clinica.php');}
 $evols=db()->prepare("SELECT e.*,CONCAT(u.nombre,' ',u.apellidos) AS dr FROM evoluciones e LEFT JOIN usuarios u ON e.doctor_id=u.id WHERE e.hc_id=? ORDER BY e.fecha DESC"); $evols->execute([$id]); $evols=$evols->fetchAll();
 $adjs=db()->prepare("SELECT * FROM adjuntos WHERE hc_id=? ORDER BY created_at DESC"); $adjs->execute([$id]); $adjs=$adjs->fetchAll();
 $odont=db()->prepare("SELECT * FROM odontogramas WHERE hc_id=? ORDER BY fecha DESC LIMIT 1"); $odont->execute([$id]); $odont=$odont->fetch();
 $dmap=[];
 if($odont){$ds=db()->prepare("SELECT * FROM odontograma_dientes WHERE odontograma_id=?");$ds->execute([$odont['id']]);foreach($ds->fetchAll() as $d) $dmap[$d['numero_diente']]=$d;}
 $plan=db()->prepare("SELECT * FROM planes_tratamiento WHERE hc_id=? ORDER BY created_at DESC LIMIT 1"); $plan->execute([$id]); $plan=$plan->fetch();
 $plan_det=[];
 if($plan){$pd=db()->prepare("SELECT * FROM plan_detalles WHERE plan_id=? ORDER BY orden");$pd->execute([$plan['id']]);$plan_det=$pd->fetchAll();}
 $titulo='HC: '.$hc['numero_hc'].' — '.$hc['pac_nm']; $pagina_activa='hc';
 $topbar_act='<a href="'.BASE_URL.'/pages/pacientes.php?accion=ver&id='.$hc['pid'].'" class="btn btn-dk btn-sm"><i class="bi bi-person me-1"></i>Paciente</a>
 <a href="?accion=editar&id='.$id.'" class="btn btn-dk btn-sm"><i class="bi bi-pencil me-1"></i>Editar HC</a>
 <a href="'.BASE_URL.'/pages/hc_pdf.php?id='.$id.'" target="_blank" class="btn btn-primary btn-sm"><i class="bi bi-file-pdf me-1"></i>Ver / Imprimir PDF</a>';
 require_once __DIR__.'/../includes/header.php';
?>
<?php if($hc['alergias']): ?>
<div class="alert-bar alert-bar-r mb-4"><div class="d-flex align-items-center gap-2"><span>⚠️</span><strong style="color:var(--r)">ALERGIAS:</strong><span><?=e($hc['alergias'])?></span></div></div>
<?php endif; ?>
<div class="nav-tabs-scroll"><ul class="nav nav-tabs mb-4" style="flex-wrap:nowrap;min-width:max-content">
 <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" data-bs-target="#td">📋 Datos clínicos</a></li>
 <li class="nav-item"><a class="nav-link" href="<?=BASE_URL?>/pages/odontograma.php?paciente_id=<?=$hc['pid']?>&hc_id=<?=$id?>">🦷 Odontograma</a></li>
 <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" data-bs-target="#tpl">💊 Plan (<?=$plan?count($plan_det):0?>)</a></li>
 <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" data-bs-target="#tev">📝 Evoluciones (<?=count($evols)?>)</a></li>
 <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" data-bs-target="#taj">📸 Adjuntos (<?=count($adjs)?>)</a></li>
</ul></div>
<div class="tab-content">
<!-- DATOS CLÍNICOS -->
<div class="tab-pane fade show active" id="td">
 <div class="row g-4">
  <div class="col-12 col-lg-8 order-2 order-lg-1">
   <div class="card">
    <div class="card-header"><span>📋 HC — <?=e($hc['numero_hc'])?></span><span class="badge <?=$hc['estado']==='activa'?'bg':'bgr'?>"><?=$hc['estado']?></span></div>
    <div class="p-4" style="font-size:13px">
     <?php $camps=[['Motivo de consulta',$hc['motivo_consulta']],['Enfermedad actual',$hc['enfermedad_actual']],['Anamnesis',$hc['anamnesis']],['Examen extraoral',$hc['examen_extraoral']],['Tejidos blandos intraorales',$hc['tejidos_blandos']],['Diagnóstico CIE-10',trim($hc['diagnostico_cie10'].' '.$hc['diagnostico_desc'])],['Plan de tratamiento (resumen)',$hc['plan_tratamiento']]];
     foreach($camps as[$l,$v]): ?>
     <div class="mb-3"><div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--t2);margin-bottom:3px"><?=$l?></div>
     <div style="background:var(--bg3);padding:9px 12px;border-radius:6px"><?=$v?nl2br(e($v)):'<span style="color:var(--t3)">Sin registro</span>'?></div></div>
     <?php endforeach; ?>
    </div>
   </div>
  </div>
  <div class="col-12 col-lg-4">
   <div class="card">
    <div class="card-header"><span style="color:var(--t)">👤 Paciente</span></div>
    <div class="p-4" style="font-size:12px">
     <strong style="font-size:14px"><?=e($hc['pac_nm'])?></strong><br>
     <span style="color:var(--t2)">DNI: <?=e($hc['dni']??'—')?> · Edad: <?=$hc['fecha_nacimiento']?edad($hc['fecha_nacimiento']):'—'?></span><br>
     <span style="color:var(--t2)">Dr. <?=e($hc['dr']??'—')?></span><br>
     <span style="color:var(--t2)">Apertura: <?=fDate($hc['fecha_apertura'])?></span>
     <?php if($hc['presion_arterial']): ?><br><span>PA: <span class="mon"><?=e($hc['presion_arterial'])?></span></span><?php endif; ?>
     <?php if($hc['peso']): ?><br>Peso: <?=$hc['peso']?> kg / <?=$hc['talla']?> cm<?php endif; ?>
     <?php if($hc['alergias']): ?><div class="mt-2"><span class="badge br">⚠️ <?=e($hc['alergias'])?></span></div><?php endif; ?>
     <?php if($hc['medicacion_actual']): ?><div class="mt-2" style="font-size:11px"><strong>💊 Medicación:</strong><br><?=e($hc['medicacion_actual'])?></div><?php endif; ?>
    </div>
   </div>
  </div>
 </div>
</div>

<!-- ODONTOGRAMA FDI (RM 593-2006/MINSA) -->
<div class="tab-pane fade" id="to">
 <div class="card">
  <div class="card-header"><span style="color:var(--t)">🦷 Odontograma — Sistema FDI (RM 593-2006/MINSA)</span>
  <div class="d-flex gap-2"><span class="badge br">🔴 Rojo = Tratar</span><span class="badge bc">🔵 Azul = Existente</span></div></div>
  <div class="p-4">
   <form method="POST" id="fOdont">
    <input type="hidden" name="accion" value="odontograma">
    <input type="hidden" name="hc_id" value="<?=$id?>">
    <input type="hidden" name="paciente_id" value="<?=$hc['pid']?>">
    <input type="hidden" name="dientes_json" id="djson" value="[]">
    <!-- Controles -->
    <div class="d-flex gap-3 flex-wrap align-items-end mb-4">
     <div><label class="form-label">Estado</label>
      <select id="selEst" class="form-select form-select-sm" style="width:180px">
       <option value="caries">Caries</option><option value="obturado">Obturado/Restaurado</option>
       <option value="ausente">Ausente/Extraído</option><option value="corona">Corona</option>
       <option value="fractura">Fractura</option><option value="endodoncia">Endodoncia</option>
       <option value="implante">Implante</option><option value="sano">Sano</option>
      </select></div>
     <div><label class="form-label">Color</label>
      <select id="selCol" class="form-select form-select-sm" style="width:150px">
       <option value="rojo">🔴 Rojo (tratar)</option><option value="azul">🔵 Azul (existente)</option>
       <option value="negro">⚫ Negro</option><option value="verde">🟢 Verde</option>
      </select></div>
     <div><label class="form-label">Cara</label>
      <select id="selCara" class="form-select form-select-sm" style="width:140px">
       <option value="total">Total</option><option value="vestibular">Vestibular</option>
       <option value="lingual">Lingual/Palatino</option><option value="mesial">Mesial</option>
       <option value="distal">Distal</option><option value="oclusal">Oclusal</option>
      </select></div>
     <button type="button" class="btn btn-del btn-sm" onclick="limpiar()" style="margin-top:20px">🧹 Limpiar todo</button>
    </div>
    <!-- SVG ODONTOGRAMA -->
    <div style="overflow-x:auto;padding:8px 0">
     <svg id="svg1" viewBox="0 0 930 280" style="width:100%;min-width:680px;max-width:980px;font-family:'Nunito',sans-serif;cursor:pointer;display:block">
      <text x="8" y="80" font-size="9" fill="#507080" font-weight="700">SUP</text>
      <text x="8" y="200" font-size="9" fill="#507080" font-weight="700">INF</text>
      <line x1="464" y1="8" x2="464" y2="272" stroke="rgba(0,212,238,.15)" stroke-width="1" stroke-dasharray="4"/>
      <line x1="8" y1="136" x2="922" y2="136" stroke="rgba(0,212,238,.1)" stroke-width="1"/>
      <?php
      $cols_hex=['rojo'=>'#E05252','azul'=>'#00D4EE','negro'=>'#445566','verde'=>'#2ECC8E'];
      function svgDiente(int $n, float $x, float $y, array $dm, array $ch): void {
        $num=(string)$n; $d=$dm[$num]??null;
        $fill=$d?($ch[$d['color']]??'none'):'none';
        $sw=$d?'2':'1';
        $txc=$d&&$d['color']==='azul'?'#0A1520':'#fff';
        $lbl=$d?strtoupper(substr($d['estado'],0,3)):'';
        echo "<g class='dt' data-n='$num'>";
        if($d&&$d['estado']==='ausente'){
          echo "<rect x='".($x-14)."' y='".($y-14)."' width='28' height='28' rx='4' fill='none' stroke='#607080' stroke-width='1' stroke-dasharray='3'/>";
          echo "<line x1='".($x-9)."' y1='".($y-9)."' x2='".($x+9)."' y2='".($y+9)."' stroke='#607080' stroke-width='1.5'/>";
          echo "<line x1='".($x+9)."' y1='".($y-9)."' x2='".($x-9)."' y2='".($y+9)."' stroke='#607080' stroke-width='1.5'/>";
        }else{
          echo "<rect x='".($x-14)."' y='".($y-14)."' width='28' height='28' rx='4' fill='$fill' stroke='#8899A6' stroke-width='$sw'/>";
          if($lbl) echo "<text x='$x' y='".($y+4)."' text-anchor='middle' font-size='7' fill='$txc' font-weight='700'>$lbl</text>";
        }
        echo "<text x='$x' y='".($y+26)."' text-anchor='middle' font-size='8.5' fill='#6BC5D3'>$num</text>";
        echo "</g>";
      }
      $supA=[18,17,16,15,14,13,12,11,21,22,23,24,25,26,27,28];
      $infA=[48,47,46,45,44,43,42,41,31,32,33,34,35,36,37,38];
      $x0=44;
      foreach($supA as $i=>$n) svgDiente($n,$x0+$i*56,82,$dmap,$cols_hex);
      foreach($infA as $i=>$n) svgDiente($n,$x0+$i*56,192,$dmap,$cols_hex);
      ?>
     </svg>
    </div>
    <!-- Deciduos -->
    <details class="mt-3"><summary style="cursor:pointer;color:var(--c);font-size:12px;font-weight:700">🧒 Dentición decidua (51-85)</summary>
    <div style="overflow-x:auto;padding:8px 0;margin-top:10px">
     <svg id="svg2" viewBox="0 0 740 180" style="width:100%;min-width:520px;max-width:800px;font-family:'Nunito',sans-serif;cursor:pointer;display:block">
      <?php
      $supD=[55,54,53,52,51,61,62,63,64,65];
      $infD=[85,84,83,82,81,71,72,73,74,75];
      foreach($supD as $i=>$n) svgDiente($n,40+$i*66,55,$dmap,$cols_hex);
      foreach($infD as $i=>$n) svgDiente($n,40+$i*66,130,$dmap,$cols_hex);
      ?>
     </svg>
    </div></details>
    <div class="mt-4"><label class="form-label">Observaciones del odontograma</label>
    <textarea name="obs" class="form-control" rows="2"><?=e($odont['observaciones']??'')?></textarea></div>
    <div class="mt-3 d-flex align-items-center gap-3">
     <button type="submit" class="btn btn-primary"><i class="bi bi-floppy me-2"></i>Guardar odontograma</button>
     <small style="color:var(--t2)">Clic en diente para marcar · doble clic para borrar</small>
    </div>
   </form>
  </div>
 </div>
</div>

<!-- PLAN DE TRATAMIENTO -->
<div class="tab-pane fade" id="tpl">
 <div class="card">
  <div class="card-header"><span style="color:var(--t)">💊 Plan de tratamiento</span>
  <a href="<?=BASE_URL?>/pages/tratamientos.php?accion=plan&hc_id=<?=$id?>&pac_id=<?=$hc['pid']?>" class="btn btn-primary btn-sm">+ Crear/editar plan</a></div>
  <?php if($plan): ?>
  <div class="p-4">
   <div class="d-flex justify-content-between align-items-center mb-3">
    <span class="badge <?=['borrador'=>'bgr','aprobado'=>'bc','en_proceso'=>'ba','completado'=>'bg','cancelado'=>'br'][$plan['estado']]?>"><?=strtoupper($plan['estado'])?></span>
    <span class="mon fw-bold" style="font-size:18px;color:var(--c)"><?=mon((float)$plan['total'])?></span>
   </div>
   <div class="table-responsive"><table class="table mb-0">
    <thead><tr><th>Tratamiento</th><th>Diente</th><th>Precio</th><th>Sesiones</th><th>Estado</th></tr></thead>
    <tbody>
    <?php $ec2=['pendiente'=>'ba','en_proceso'=>'bb','completado'=>'bg','cancelado'=>'br'];
    foreach($plan_det as $det): ?><tr>
     <td><strong><?=e($det['nombre_tratamiento'])?></strong><?php if($det['notas']): ?><br><small><?=e($det['notas'])?></small><?php endif; ?></td>
     <td><?=$det['diente']?'<span class="badge bgr">🦷 '.e($det['diente']).'</span>':'—'?></td>
     <td class="mon"><?=mon((float)$det['precio'])?></td>
     <td><small><?=$det['sesiones_realizadas']?>/<?=$det['sesiones_total']?></small></td>
     <td><span class="badge <?=$ec2[$det['estado']]?>"><?=$det['estado']?></span></td>
    </tr><?php endforeach; ?>
    </tbody>
   </table></div>
  </div>
  <?php else: ?>
  <div class="p-4 text-center" style="color:var(--t2)"><i class="bi bi-clipboard2-plus" style="font-size:36px;display:block;margin-bottom:8px"></i>
  Sin plan de tratamiento. <a href="<?=BASE_URL?>/pages/tratamientos.php?accion=plan&hc_id=<?=$id?>&pac_id=<?=$hc['pid']?>" style="color:var(--c)">Crear plan</a></div>
  <?php endif; ?>
 </div>
</div>

<!-- EVOLUCIONES -->
<div class="tab-pane fade" id="tev">
 <div class="row g-4">
  <div class="col-12 col-lg-7">
   <?php if($evols): ?>
   <div class="d-grid gap-3"><?php foreach($evols as $ev): ?>
   <div class="card p-4">
    <div class="d-flex justify-content-between align-items-start mb-2">
     <span class="mon" style="font-size:12px;color:var(--c)"><?=fDT($ev['fecha'])?></span>
     <?php if($ev['diente']): ?><span class="badge bc">🦷 <?=e($ev['diente'])?></span><?php endif; ?>
    </div>
    <p style="margin:0 0 6px;font-size:13px"><?=nl2br(e($ev['descripcion']))?></p>
    <?php if($ev['procedimiento']): ?><div><small style="color:var(--t2)">Procedimiento: </small><?=e($ev['procedimiento'])?></div><?php endif; ?>
    <?php if($ev['medicacion']): ?><div><small style="color:var(--t2)">💊 </small><?=e($ev['medicacion'])?></div><?php endif; ?>
    <?php if($ev['proximo_control']): ?><div style="margin-top:4px"><small style="color:var(--a)">📅 Próximo: </small><?=fDate($ev['proximo_control'])?></div><?php endif; ?>
    <small style="color:var(--t3);margin-top:6px;display:block">Dr. <?=e($ev['dr']??'—')?></small>
   </div>
   <?php endforeach; ?></div>
   <?php else: ?><div class="card p-4 text-center" style="color:var(--t2)">Sin evoluciones registradas</div><?php endif; ?>
  </div>
  <div class="col-12 col-lg-5">
   <div class="card"><div class="card-header"><span style="color:var(--t)">📝 Nueva evolución</span></div>
   <form method="POST" class="p-4">
    <input type="hidden" name="accion" value="evolucion"><input type="hidden" name="hc_id" value="<?=$id?>">
    <div class="mb-3"><label class="form-label">Descripción *</label><textarea name="descripcion" class="form-control" rows="4" required placeholder="Descripción de la atención..."></textarea></div>
    <div class="mb-3"><label class="form-label">Procedimiento realizado</label><input type="text" name="procedimiento" class="form-control" placeholder="Ej: Extracción pieza 46"></div>
    <div class="mb-3"><label class="form-label">Diente(s)</label><input type="text" name="diente" class="form-control" placeholder="16, 17"></div>
    <div class="mb-3"><label class="form-label">💊 Medicación indicada</label><input type="text" name="medicacion" class="form-control" placeholder="Ibuprofeno 400mg..."></div>
    <div class="mb-4"><label class="form-label">Próximo control</label><input type="date" name="proximo_control" class="form-control"></div>
    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-floppy me-2"></i>Guardar evolución</button>
   </form></div>
  </div>
 </div>
</div>

<!-- ADJUNTOS -->
<div class="tab-pane fade" id="taj">
 <div class="row g-4">
  <div class="col-12 col-lg-8">
   <div class="row g-3">
   <?php $ticons=['radiografia'=>'🩻','foto_intraoral'=>'📸','foto_extraoral'=>'🤳','documento'=>'📄','otro'=>'📎'];
   foreach($adjs as $a): $ext=strtolower(pathinfo($a['ruta'],PATHINFO_EXTENSION)); $isImg=in_array($ext,['jpg','jpeg','png','webp']); ?>
   <div class="col-12 col-sm-6 col-md-4">
    <div class="card p-3 h-100">
     <div class="text-center mb-2">
      <?php if($isImg): ?><img src="<?=BASE_URL?>/uploads/<?=e($a['ruta'])?>" alt="" style="max-width:100%;max-height:110px;border-radius:6px;object-fit:cover">
      <?php else: ?><div style="font-size:36px"><?=$ticons[$a['tipo']]??'📎'?></div><?php endif; ?>
     </div>
     <div style="font-size:11px"><strong><?=e(mb_substr($a['nombre'],0,28))?></strong><br><small><?=fDate($a['created_at'])?></small></div>
     <div class="mt-2 d-flex gap-1">
      <a href="<?=BASE_URL?>/uploads/<?=e($a['ruta'])?>" target="_blank" class="btn btn-dk btn-sm flex-fill"><i class="bi bi-eye"></i></a>
      <a href="<?=BASE_URL?>/uploads/<?=e($a['ruta'])?>" download class="btn btn-primary btn-sm flex-fill"><i class="bi bi-download"></i></a>
     </div>
    </div>
   </div>
   <?php endforeach; if(!$adjs): ?><div class="col-12"><div class="card p-4 text-center" style="color:var(--t2)"><i class="bi bi-images" style="font-size:36px;display:block;margin-bottom:8px"></i>Sin adjuntos</div></div><?php endif; ?>
   </div>
  </div>
  <div class="col-12 col-lg-4">
   <div class="card"><div class="card-header"><span style="color:var(--t)">📤 Subir archivo</span></div>
   <form method="POST" enctype="multipart/form-data" class="p-4">
    <input type="hidden" name="accion" value="adjunto"><input type="hidden" name="hc_id" value="<?=$id?>">
    <div class="mb-3"><label class="form-label">Tipo</label>
    <select name="tipo_adj" class="form-select">
     <option value="radiografia">🩻 Radiografía</option><option value="foto_intraoral">📸 Foto intraoral</option>
     <option value="foto_extraoral">🤳 Foto extraoral</option><option value="documento">📄 Documento</option><option value="otro">📎 Otro</option>
    </select></div>
    <div class="mb-3"><label class="form-label">Archivo *</label><input type="file" name="archivo" class="form-control" accept=".jpg,.jpeg,.png,.pdf,.webp" required></div>
    <div class="mb-4"><label class="form-label">Descripción</label><input type="text" name="desc_adj" class="form-control"></div>
    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-upload me-2"></i>Subir</button>
   </form></div>
  </div>
 </div>
</div>
</div><!-- tab-content -->
<?php
$dmapJS=json_encode(array_values(array_map(fn($d)=>['n'=>$d['numero_diente'],'e'=>$d['estado'],'c'=>$d['cara'],'col'=>$d['color'],'notas'=>$d['notas']??''],$dmap)));
$xscript=<<<JS
<script>
const colHex={rojo:'#E05252',azul:'#00D4EE',negro:'#445566',verde:'#2ECC8E'};
let dm={};
JSON.parse('$dmapJS').forEach(d=>dm[d.n]=d);

function colorOf(col){return colHex[col]||'none';}
function updateDiente(num){
 document.querySelectorAll(`.dt[data-n="${num}"]`).forEach(g=>{
  const d=dm[num];
  const rect=g.querySelector('rect');
  if(!rect)return;
  if(d){
   if(d.e==='ausente'){rect.setAttribute('fill','none');}
   else{rect.setAttribute('fill',colorOf(d.col));rect.setAttribute('stroke-width','2');}
   let t=g.querySelector('.dlbl');
   if(!t){t=document.createElementNS('http://www.w3.org/2000/svg','text');t.classList.add('dlbl');t.setAttribute('text-anchor','middle');t.setAttribute('font-size','7');t.setAttribute('font-weight','700');g.appendChild(t);}
   const bb=rect.getBBox(); t.setAttribute('x',bb.x+bb.width/2); t.setAttribute('y',bb.y+bb.height/2+3);
   t.textContent=d.e.substring(0,3).toUpperCase(); t.setAttribute('fill',d.col==='azul'?'#0A1520':'#fff');
  }else{rect.setAttribute('fill','none');rect.setAttribute('stroke-width','1');const t=g.querySelector('.dlbl');if(t)t.remove();}
 });
}
function saveJson(){document.getElementById('djson').value=JSON.stringify(Object.values(dm));}

document.querySelectorAll('.dt').forEach(g=>{
 g.addEventListener('click',function(){
  const num=this.dataset.n;
  const est=document.getElementById('selEst').value;
  const col=document.getElementById('selCol').value;
  const cara=document.getElementById('selCara').value;
  if(dm[num]&&dm[num].e===est){delete dm[num];}
  else{dm[num]={n:num,e:est,c:cara,col:col,notas:''};}
  updateDiente(num); saveJson();
 });
 g.addEventListener('dblclick',function(e){e.preventDefault();delete dm[this.dataset.n];updateDiente(this.dataset.n);saveJson();});
 g.style.cursor='pointer';
});
function limpiar(){if(!confirm('¿Limpiar todo el odontograma?'))return;dm={};document.querySelectorAll('.dt rect').forEach(r=>{r.setAttribute('fill','none');r.setAttribute('stroke-width','1');});document.querySelectorAll('.dt .dlbl').forEach(t=>t.remove());saveJson();}
</script>
JS;
require_once __DIR__.'/../includes/footer.php';

}elseif($accion==='nueva'){
 if(!$pac_id){flash('error','Selecciona un paciente');go('pages/pacientes.php');}
 $ps=db()->prepare("SELECT * FROM pacientes WHERE id=?"); $ps->execute([$pac_id]); $pd=$ps->fetch();
 if(!$pd){flash('error','Paciente no encontrado');go('pages/pacientes.php');}
 $titulo='Nueva Historia Clínica'; $pagina_activa='hc';
 require_once __DIR__.'/../includes/header.php';
?>
<div class="alert-bar alert-bar-r mb-4">
 <div class="d-flex align-items-center gap-3">
  <div class="ava"><?=strtoupper(substr($pd['nombres'],0,1))?></div>
  <div><strong><?=e($pd['nombres'].' '.$pd['apellido_paterno'])?></strong> — <?=e($pd['codigo'])?>
  <?php if($pd['alergias']): ?><br><span class="badge br">⚠️ ALÉRGICO: <?=e($pd['alergias'])?></span><?php endif; ?>
  </div>
 </div>
</div>
<div class="row justify-content-center"><div class="col-12 col-xl-9">
<form method="POST">
 <input type="hidden" name="accion" value="guardar_hc">
 <input type="hidden" name="paciente_id" value="<?=$pac_id?>">
 <input type="hidden" name="id" value="0">
 <div class="card mb-4">
  <div class="card-header"><span style="color:var(--t)">📋 Historia Clínica Odontológica (NT N°022-MINSA)</span></div>
  <div class="p-4"><div class="row g-3">
   <div class="col-12 col-md-3"><label class="form-label">Fecha apertura *</label><input type="date" name="fecha_apertura" class="form-control" value="<?=date('Y-m-d')?>" required></div>
   <div class="col-12 col-md-3"><label class="form-label">Presión arterial</label><input type="text" name="presion_arterial" class="form-control" placeholder="120/80"></div>
   <div class="col-12 col-md-3"><label class="form-label">Peso (kg)</label><input type="number" name="peso" class="form-control" step="0.1" min="0"></div>
   <div class="col-12 col-md-3"><label class="form-label">Talla (cm)</label><input type="number" name="talla" class="form-control" step="0.1" min="0"></div>
   <div class="col-12"><label class="form-label">Motivo de consulta *</label><textarea name="motivo_consulta" class="form-control" rows="3" required placeholder="Describe el motivo principal de consulta..."></textarea></div>
   <div class="col-12"><label class="form-label">Enfermedad actual (tiempo, síntomas)</label><textarea name="enfermedad_actual" class="form-control" rows="3"></textarea></div>
   <div class="col-12"><label class="form-label">Anamnesis general</label><textarea name="anamnesis" class="form-control" rows="3"></textarea></div>
   <div class="col-12"><label class="form-label">Examen extraoral (ATM, asimetría, ganglios)</label><textarea name="examen_extraoral" class="form-control" rows="2"></textarea></div>
   <div class="col-12"><label class="form-label">Tejidos blandos intraorales (encías, mucosa, lengua)</label><textarea name="tejidos_blandos" class="form-control" rows="2"></textarea></div>
   <div class="col-12 col-md-4"><label class="form-label">Diagnóstico CIE-10</label><input type="text" name="diagnostico_cie10" class="form-control" placeholder="K02.1"></div>
   <div class="col-12 col-md-8"><label class="form-label">Descripción del diagnóstico</label><input type="text" name="diagnostico_desc" class="form-control" placeholder="Caries dentina..."></div>
   <div class="col-12"><label class="form-label">Plan de tratamiento (resumen)</label><textarea name="plan_tratamiento" class="form-control" rows="3"></textarea></div>
  </div></div>
 </div>
 <div class="d-flex gap-2 justify-content-end">
  <a href="<?=BASE_URL?>/pages/pacientes.php?accion=ver&id=<?=$pac_id?>" class="btn btn-dk">Cancelar</a>
  <button type="submit" class="btn btn-primary px-4"><i class="bi bi-floppy me-2"></i>Crear historia clínica</button>
 </div>
</form>
</div></div>
<?php require_once __DIR__.'/../includes/footer.php';

}elseif($accion==='editar'&&$id){
 $st=db()->prepare("SELECT * FROM historias_clinicas WHERE id=?"); $st->execute([$id]); $hc=$st->fetch();
 if(!$hc){flash('error','HC no encontrada');go('pages/historia_clinica.php');}
 $titulo='Editar HC: '.$hc['numero_hc']; $pagina_activa='hc';
 require_once __DIR__.'/../includes/header.php';
?>
<div class="row justify-content-center"><div class="col-12 col-xl-9">
<form method="POST">
 <input type="hidden" name="accion" value="guardar_hc"><input type="hidden" name="paciente_id" value="<?=$hc['paciente_id']?>"><input type="hidden" name="id" value="<?=$id?>">
 <div class="card mb-4"><div class="card-header"><span>📋 Editar HC <?=e($hc['numero_hc'])?></span></div>
 <div class="p-4"><div class="row g-3">
  <div class="col-12 col-md-3"><label class="form-label">Fecha apertura *</label><input type="date" name="fecha_apertura" class="form-control" value="<?=$hc['fecha_apertura']?>" required></div>
  <div class="col-12 col-md-3"><label class="form-label">Presión arterial</label><input type="text" name="presion_arterial" class="form-control" value="<?=e($hc['presion_arterial']??'')?>"></div>
  <div class="col-12 col-md-3"><label class="form-label">Peso (kg)</label><input type="number" name="peso" class="form-control" value="<?=$hc['peso']??''?>" step="0.1"></div>
  <div class="col-12 col-md-3"><label class="form-label">Talla (cm)</label><input type="number" name="talla" class="form-control" value="<?=$hc['talla']??''?>" step="0.1"></div>
  <div class="col-12"><label class="form-label">Motivo *</label><textarea name="motivo_consulta" class="form-control" rows="3" required><?=e($hc['motivo_consulta'])?></textarea></div>
  <div class="col-12"><label class="form-label">Enfermedad actual</label><textarea name="enfermedad_actual" class="form-control" rows="3"><?=e($hc['enfermedad_actual']??'')?></textarea></div>
  <div class="col-12"><label class="form-label">Anamnesis</label><textarea name="anamnesis" class="form-control" rows="3"><?=e($hc['anamnesis']??'')?></textarea></div>
  <div class="col-12"><label class="form-label">Examen extraoral</label><textarea name="examen_extraoral" class="form-control" rows="2"><?=e($hc['examen_extraoral']??'')?></textarea></div>
  <div class="col-12"><label class="form-label">Tejidos blandos</label><textarea name="tejidos_blandos" class="form-control" rows="2"><?=e($hc['tejidos_blandos']??'')?></textarea></div>
  <div class="col-12 col-md-4"><label class="form-label">CIE-10</label><input type="text" name="diagnostico_cie10" class="form-control" value="<?=e($hc['diagnostico_cie10']??'')?>"></div>
  <div class="col-12 col-md-8"><label class="form-label">Descripción diagnóstico</label><input type="text" name="diagnostico_desc" class="form-control" value="<?=e($hc['diagnostico_desc']??'')?>"></div>
  <div class="col-12"><label class="form-label">Plan resumen</label><textarea name="plan_tratamiento" class="form-control" rows="3"><?=e($hc['plan_tratamiento']??'')?></textarea></div>
 </div></div></div>
 <div class="d-flex gap-2 justify-content-end">
  <a href="?id=<?=$id?>" class="btn btn-dk">Cancelar</a>
  <button type="submit" class="btn btn-primary px-4"><i class="bi bi-floppy me-2"></i>Guardar cambios</button>
 </div>
</form></div></div>
<?php require_once __DIR__.'/../includes/footer.php';
}
