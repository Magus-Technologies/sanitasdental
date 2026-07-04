<?php
require_once __DIR__.'/../includes/config.php';
requiereLogin();
$accion=$_GET['accion']??'lista'; $id=(int)($_GET['id']??0);

if($_SERVER['REQUEST_METHOD']==='POST'){
 $ap=$_POST['accion']??'';
 if($ap==='guardar'){
  $ei=(int)($_POST['id']??0);
  $d=['nombres'=>trim($_POST['nombres']??''),'apellido_paterno'=>trim($_POST['apellido_paterno']??''),'apellido_materno'=>trim($_POST['apellido_materno']??''),'dni'=>trim($_POST['dni']??'')?:null,'ruc'=>trim($_POST['ruc']??'')?:null,'fecha_nacimiento'=>$_POST['fecha_nacimiento']??'' ?:null,'sexo'=>$_POST['sexo']??null,'estado_civil'=>$_POST['estado_civil']??null,'ocupacion'=>trim($_POST['ocupacion']??''),'telefono'=>trim($_POST['telefono']??''),'email'=>trim($_POST['email']??''),'direccion'=>trim($_POST['direccion']??''),'distrito'=>trim($_POST['distrito']??''),'tipo_seguro'=>$_POST['tipo_seguro']??'ninguno','num_seguro'=>trim($_POST['num_seguro']??''),'alergias'=>trim($_POST['alergias']??''),'enfermedades_base'=>trim($_POST['enfermedades_base']??''),'medicacion_actual'=>trim($_POST['medicacion_actual']??''),'cirugia_previa'=>trim($_POST['cirugia_previa']??''),'embarazo'=>isset($_POST['embarazo'])?1:0,'fuma'=>isset($_POST['fuma'])?1:0,'alcohol'=>isset($_POST['alcohol'])?1:0,'antecedentes_obs'=>trim($_POST['antecedentes_obs']??''),'contacto_nombre'=>trim($_POST['contacto_nombre']??''),'contacto_telefono'=>trim($_POST['contacto_telefono']??''),'contacto_parentesco'=>trim($_POST['contacto_parentesco']??'')];
  
  // Procesar foto de perfil
  if(isset($_FILES['foto_perfil']) && $_FILES['foto_perfil']['error'] === UPLOAD_ERR_OK) {
   $foto = subirArchivo($_FILES['foto_perfil'], 'fotos_perfil', ['jpg','jpeg','png','webp']);
   if($foto) $d['foto_perfil'] = $foto;
  }
  
  if($ei){
   $sets=implode(',',array_map(fn($k)=>"$k=?",array_keys($d)));
   db()->prepare("UPDATE pacientes SET $sets,updated_at=NOW() WHERE id=?")->execute([...array_values($d),$ei]);
   auditar('EDITAR_PAC','pacientes',$ei); flash('ok','Paciente actualizado.'); go("pages/pacientes.php?accion=ver&id=$ei");
  } else {
   $cod=genCodigo('HCL','pacientes'); if(function_exists('sede_para_nuevo')) $d['sede_id']=sede_para_nuevo();
   $cols='codigo,'.implode(',',array_keys($d));
   $phs='?,'.implode(',',array_fill(0,count($d),'?'));
   db()->prepare("INSERT INTO pacientes($cols)VALUES($phs)")->execute([$cod,...array_values($d)]);
   $nid=db()->lastInsertId(); auditar('CREAR_PAC','pacientes',$nid);
   flash('ok',"Paciente registrado. Código: $cod"); go("pages/pacientes.php?accion=ver&id=$nid");
  }
 }
 if($ap==='desactivar'){
  $did=(int)($_POST['id']??0);
  if($did){ db()->prepare("UPDATE pacientes SET activo=0,deleted_at=NOW(),deleted_by=?,updated_at=NOW() WHERE id=?")->execute([$_SESSION['uid'],$did]); auditar('DESACTIVAR_PAC','pacientes',$did); flash('ok','Paciente desactivado correctamente.'); }
  go('pages/pacientes.php');
 }
 if($ap==='restaurar'){
  $rid=(int)($_POST['id']??0);
  if($rid){ db()->prepare("UPDATE pacientes SET activo=1,deleted_at=NULL,deleted_by=NULL,updated_at=NOW() WHERE id=?")->execute([$rid]); auditar('RESTAURAR_PAC','pacientes',$rid); flash('ok','Paciente restaurado correctamente.'); }
  go('pages/pacientes.php');
 }
 if($ap==='portal_token'){
  $tid=(int)($_POST['id']??0);
  if($tid){
   $tok=bin2hex(random_bytes(20)); // 40 hex
   db()->prepare("UPDATE pacientes SET portal_token=?,portal_token_at=NOW() WHERE id=?")->execute([$tok,$tid]);
   auditar('PORTAL_TOKEN','pacientes',$tid);
   flash('ok','Enlace del portal generado.');
  }
  go("pages/pacientes.php?accion=ver&id=$tid");
 }
}

if($accion==='lista'){
 $titulo='Pacientes'; $pagina_activa='pac';
 $q=trim($_GET['q']??''); $pg=max(1,(int)($_GET['p']??1)); $pp=20;
 $orden=(($_GET['orden']??'asc')==='desc')?'desc':'asc';
 $mostrar_inactivos=isset($_GET['inactivos']);
 $w=$mostrar_inactivos?'WHERE activo=0':'WHERE activo=1'; $pm=[]; if(function_exists('sede_filtro_sql')) $w.=sede_filtro_sql('sede_id',true);
 if($q){$w.=' AND(nombres LIKE ? OR apellido_paterno LIKE ? OR dni LIKE ? OR telefono LIKE ? OR codigo LIKE ?)';$b="%$q%";$pm=[$b,$b,$b,$b,$b];}
 $st=db()->prepare("SELECT COUNT(*) FROM pacientes $w"); $st->execute($pm); $tot=(int)$st->fetchColumn();
 $pages=max(1,ceil($tot/$pp)); $pg=min($pg,$pages); $off=($pg-1)*$pp;
 $st2=db()->prepare("SELECT pacientes.*, (SELECT MAX(c.fecha) FROM citas c WHERE c.paciente_id=pacientes.id AND c.estado='atendido') AS ult_atencion, (SELECT MIN(CONCAT(c.fecha,' ',COALESCE(c.hora_inicio,'00:00:00'))) FROM citas c WHERE c.paciente_id=pacientes.id AND c.fecha>=CURDATE() AND c.estado NOT IN('cancelado','anulado')) AS prox_cita FROM pacientes $w ORDER BY CAST(REGEXP_REPLACE(codigo,'[^0-9]','') AS UNSIGNED) $orden, id $orden LIMIT $pp OFFSET $off");
 $st2->execute($pm); $lista=$st2->fetchAll();
 $topbar_act='<a href="?accion=nuevo" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i>Nuevo paciente</a>';
 require_once __DIR__.'/../includes/header.php';
?>
<div class="card mb-3 p-3">
 <form method="GET" class="d-flex gap-2 flex-wrap align-items-end row-gap-2">
  <div class="flex-fill" style="min-width:180px"><label class="form-label">Buscar</label>
  <input type="text" name="q" class="form-control" placeholder="Nombre, DNI, código, teléfono..." value="<?=e($q)?>"></div>
  <input type="hidden" name="orden" value="<?=e($orden)?>">
  <div class="d-flex gap-2 align-items-end pt-1">
   <button type="submit" class="btn btn-dk">🔍</button>
   <?php if($q): ?><a href="?orden=<?=e($orden)?>" class="btn btn-dk">✕</a><?php endif; ?>
   <?php $flip=$orden==='asc'?'desc':'asc'; $qsOrden=http_build_query(array_filter(['q'=>$q,'inactivos'=>$mostrar_inactivos?1:null,'orden'=>$flip], fn($v)=>$v!==null&&$v!=='')); ?>
   <a href="?<?=$qsOrden?>" class="btn btn-dk btn-sm" title="Cambiar orden por código">
     <i class="bi bi-sort-numeric-<?=$orden==='asc'?'down':'up-alt'?> me-1"></i><?=$orden==='asc'?'Código: 1 → último':'Código: último → 1'?>
   </a>
  </div>
  <small class="ms-auto mt-2" style="color:var(--t2)"><?=$tot?> paciente<?=$tot!=1?'s':''?></small>
  <div class="ms-2 mt-2">
  <?php if(!$mostrar_inactivos): ?>
   <a href="?<?=http_build_query(array_filter(['q'=>$q,'orden'=>$orden,'inactivos'=>1], fn($v)=>$v!==null&&$v!==''))?>" class="btn btn-dk btn-sm" style="font-size:10px" title="Ver desactivados"><i class="bi bi-person-dash me-1"></i>Ver desactivados</a>
  <?php else: ?>
   <a href="?<?=http_build_query(array_filter(['q'=>$q,'orden'=>$orden], fn($v)=>$v!==null&&$v!==''))?>" class="btn btn-primary btn-sm" style="font-size:10px"><i class="bi bi-person-check me-1"></i>Ver activos</a>
  <?php endif; ?>
  </div>
 </form>
</div>
<?php if($mostrar_inactivos): ?>
<div class="alert" style="background:rgba(224,82,82,.1);border:1px solid rgba(224,82,82,.25);color:var(--r);border-radius:8px;padding:10px 14px;font-size:12px;margin-bottom:12px">
 <i class="bi bi-person-dash-fill me-2"></i><strong>Mostrando pacientes desactivados.</strong> Los datos se conservan en la BD. Puedes restaurarlos.
</div>
<?php endif; ?>
<style>
.pac-list{display:flex;flex-direction:column;gap:8px}
.pac-item{background:var(--bg2);border:1px solid var(--bd2);border-radius:12px;overflow:hidden;transition:border-color .15s}
.pac-item.open{border-color:var(--c)}
.pac-head{display:flex;align-items:center;gap:12px;padding:12px 14px;cursor:pointer}
.pac-head:hover{background:var(--bg3)}
.pac-ava{width:40px;height:40px;border-radius:50%;object-fit:cover;flex:none}
.pac-ini{width:40px;height:40px;border-radius:50%;background:rgba(6,182,212,.14);color:var(--c);display:flex;align-items:center;justify-content:center;font-weight:700;flex:none}
.pac-name{font-weight:700;color:var(--t)}
.pac-meta{color:var(--t2);font-size:12px;margin-top:2px}
.pac-right{margin-left:auto;color:var(--t2);font-size:12px;white-space:nowrap;text-align:right}
.pac-chev{color:var(--t2);font-size:18px;transition:transform .2s;flex:none}
.pac-item.open .pac-chev{transform:rotate(180deg)}
.pac-body{display:none;border-top:1px solid var(--bd2);padding:14px}
.pac-item.open .pac-body{display:block}
.pac-info{display:flex;flex-wrap:wrap;gap:8px}
.pac-chip{display:inline-flex;align-items:center;gap:6px;background:var(--bg3);border:1px solid var(--bd2);border-radius:20px;padding:6px 12px;font-size:12.5px;color:var(--t)}
.pac-info i{color:var(--c)}
.pac-cita{display:flex;gap:18px;flex-wrap:wrap;margin-top:10px;font-size:12px;color:var(--t2)}
.qa-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(84px,1fr));gap:8px;margin-top:12px}
.qa{display:flex;flex-direction:column;align-items:center;gap:5px;text-align:center;border:1px solid var(--bd2);border-radius:10px;padding:10px 4px;color:var(--t);text-decoration:none;font-size:11px;transition:.15s}
.qa:hover{border-color:var(--c);filter:brightness(1.03)}
.qa i{font-size:20px}
.qa-lbl{line-height:1.15}
</style>
<div class="pac-list">
<?php foreach($lista as $p): ?>
 <div class="pac-item">
  <div class="pac-head" onclick="pacToggle(this)">
   <?php if($p['foto_perfil']): ?>
    <img class="pac-ava" src="<?=BASE_URL?>/uploads/<?=e($p['foto_perfil'])?>" alt="">
   <?php else: ?>
    <div class="pac-ini"><?=strtoupper(substr($p['nombres'],0,1))?></div>
   <?php endif; ?>
   <div style="min-width:0">
    <div class="d-flex align-items-center gap-2 flex-wrap">
     <span class="pac-name"><?=e($p['nombres'].' '.$p['apellido_paterno'])?></span>
     <?php if($p['alergias']): ?><span class="badge br" style="font-size:9px"><i class="bi bi-exclamation-triangle-fill me-1"></i>Alérgico</span><?php endif; ?>
    </div>
    <div class="pac-meta"><span class="mon" style="color:var(--c)"><?=e($p['codigo'])?></span> · DNI <?=e($p['dni']?:'—')?> · <?=strtoupper($p['tipo_seguro']?:'—')?> · <?=edad($p['fecha_nacimiento'])?></div>
   </div>
   <div class="pac-right d-none d-md-block"><?=$p['ult_atencion']?'Última: '.date('d/m/Y',strtotime($p['ult_atencion'])):'Sin atenciones'?></div>
   <i class="bi bi-chevron-down pac-chev"></i>
  </div>
  <div class="pac-body">
   <div class="pac-info">
    <span class="pac-chip"><i class="bi bi-fingerprint"></i>DNI <?=e($p['dni']?:'—')?></span>
    <span class="pac-chip"><i class="bi bi-telephone-fill"></i><?=e($p['telefono']?:'—')?></span>
    <span class="pac-chip"><i class="bi bi-envelope-fill"></i><?=e($p['email']?:'—')?></span>
    <span class="pac-chip"><i class="bi bi-shield-fill"></i><?=strtoupper($p['tipo_seguro']?:'—')?><?=$p['num_seguro']?' · '.e($p['num_seguro']):''?></span>
    <span class="pac-chip"><i class="bi bi-person-badge"></i><?=edad($p['fecha_nacimiento'])?><?=$p['sexo']?' · '.e($p['sexo']):''?></span>
    <?php if($p['direccion']): ?><span class="pac-chip"><i class="bi bi-geo-alt-fill"></i><?=e($p['direccion'])?><?=$p['distrito']?', '.e($p['distrito']):''?></span><?php endif; ?>
   </div>
   <div class="pac-cita">
    <span><i class="bi bi-clock-history me-1" style="color:var(--c)"></i><?=$p['ult_atencion']?'Última atención: '.date('d/m/Y',strtotime($p['ult_atencion'])):'Sin atenciones previas'?></span>
    <?php if($p['prox_cita']): ?><span style="color:var(--c)"><i class="bi bi-calendar-event me-1"></i>Próxima cita: <?=date('d/m/Y · g:i a',strtotime($p['prox_cita']))?></span><?php endif; ?>
   </div>
   <div class="qa-grid">
    <a class="qa" style="background:rgba(6,182,212,.08)" href="<?=BASE_URL?>/pages/historia_clinica.php?paciente_id=<?=$p['id']?>"><i class="bi bi-file-medical-fill" style="color:#06B6D4"></i><span class="qa-lbl">Historia clínica</span></a>
    <a class="qa" style="background:rgba(59,130,246,.08)" href="<?=BASE_URL?>/pages/odontograma.php?paciente_id=<?=$p['id']?>"><i class="bi bi-grid-3x3-gap-fill" style="color:#3B82F6"></i><span class="qa-lbl">Odontograma</span></a>
    <a class="qa" style="background:rgba(16,185,129,.08)" href="<?=BASE_URL?>/pages/citas.php?accion=nueva&paciente_id=<?=$p['id']?>"><i class="bi bi-calendar-plus" style="color:#10B981"></i><span class="qa-lbl">Agendar cita</span></a>
    <a class="qa" style="background:rgba(99,102,241,.08)" href="<?=BASE_URL?>/pages/presupuestos.php?paciente_id=<?=$p['id']?>"><i class="bi bi-clipboard2-pulse-fill" style="color:#6366F1"></i><span class="qa-lbl">Planes</span></a>
    <a class="qa" style="background:rgba(236,72,153,.08)" href="<?=BASE_URL?>/pages/recetarios.php?paciente_id=<?=$p['id']?>"><i class="bi bi-prescription2" style="color:#EC4899"></i><span class="qa-lbl">Recetas</span></a>
    <a class="qa" style="background:rgba(20,184,166,.08)" href="<?=BASE_URL?>/pages/ortodoncias.php?paciente_id=<?=$p['id']?>"><i class="bi bi-bezier2" style="color:#14B8A6"></i><span class="qa-lbl">Ortodoncia</span></a>
    <a class="qa" style="background:rgba(239,68,68,.08)" href="<?=BASE_URL?>/pages/endodoncia.php?paciente_id=<?=$p['id']?>"><i class="bi bi-diagram-3-fill" style="color:#EF4444"></i><span class="qa-lbl">Endodoncia</span></a>
    <a class="qa" style="background:rgba(14,165,233,.08)" href="<?=BASE_URL?>/pages/destartraje.php?paciente_id=<?=$p['id']?>"><i class="bi bi-droplet-fill" style="color:#0EA5E9"></i><span class="qa-lbl">Destartraje</span></a>
    <a class="qa" style="background:rgba(245,158,11,.08)" href="<?=BASE_URL?>/pages/curaciones.php?paciente_id=<?=$p['id']?>"><i class="bi bi-bandaid-fill" style="color:#F59E0B"></i><span class="qa-lbl">Curaciones</span></a>
    <a class="qa" style="background:rgba(139,92,246,.08)" href="<?=BASE_URL?>/pages/protesis_fija.php?paciente_id=<?=$p['id']?>"><i class="bi bi-gem" style="color:#8B5CF6"></i><span class="qa-lbl">Prótesis</span></a>
    <a class="qa" style="background:rgba(249,115,22,.08)" href="<?=BASE_URL?>/pages/facturacion.php?paciente_id=<?=$p['id']?>"><i class="bi bi-receipt" style="color:#F97316"></i><span class="qa-lbl">Facturación</span></a>
    <a class="qa" style="background:rgba(100,116,139,.08)" href="?accion=ver&id=<?=$p['id']?>"><i class="bi bi-person-vcard-fill" style="color:#64748B"></i><span class="qa-lbl">Ficha completa</span></a>
   </div>
   <div class="d-flex gap-2 mt-3 justify-content-end">
    <a href="?accion=editar&id=<?=$p['id']?>" class="btn btn-dk btn-sm"><i class="bi bi-pencil me-1"></i>Editar</a>
    <?php if(!$mostrar_inactivos): ?>
    <form method="POST" class="d-inline" onsubmit="return confirm('¿Desactivar paciente? Los datos se conservan en la BD.')">
     <input type="hidden" name="accion" value="desactivar"><input type="hidden" name="id" value="<?=$p['id']?>">
     <button type="submit" class="btn btn-del btn-sm"><i class="bi bi-person-dash-fill me-1"></i>Desactivar</button>
    </form>
    <?php else: ?>
    <form method="POST" class="d-inline">
     <input type="hidden" name="accion" value="restaurar"><input type="hidden" name="id" value="<?=$p['id']?>">
     <button type="submit" class="btn btn-ok btn-sm"><i class="bi bi-person-check-fill me-1"></i>Restaurar</button>
    </form>
    <?php endif; ?>
   </div>
  </div>
 </div>
<?php endforeach; if(!$lista): ?>
 <div class="card text-center py-4" style="color:var(--t2)"><i class="bi bi-people" style="font-size:36px;display:block;margin-bottom:8px"></i>No se encontraron pacientes</div>
<?php endif; ?>
</div>
<script>function pacToggle(h){h.parentElement.classList.toggle('open');}</script>
<?php if($pages>1): ?>
<nav class="mt-3 d-flex justify-content-end"><ul class="pagination pagination-sm">
 <?php for($i=1;$i<=$pages;$i++): ?>
 <li class="page-item <?=$i===$pg?'active':''?>"><a class="page-link" href="?<?=http_build_query(array_filter(['q'=>$q,'orden'=>$orden,'inactivos'=>$mostrar_inactivos?1:null,'p'=>$i], fn($v)=>$v!==null&&$v!==''))?>"><?=$i?></a></li>
 <?php endfor; ?>
</ul></nav>
<?php endif;
 require_once __DIR__.'/../includes/footer.php';

}elseif($accion==='ver'){
 $st=db()->prepare("SELECT * FROM pacientes WHERE id=?"); $st->execute([$id]); $pac=$st->fetch();
 if(!$id || !$pac){flash('error','Paciente no encontrado.');go('pages/pacientes.php');}
 $hcs=db()->prepare("SELECT hc.*,CONCAT(u.nombre,' ',u.apellidos) AS dr FROM historias_clinicas hc LEFT JOIN usuarios u ON hc.doctor_id=u.id WHERE hc.paciente_id=? ORDER BY hc.fecha_apertura DESC"); $hcs->execute([$id]); $hcs=$hcs->fetchAll();
 $cit=db()->prepare("SELECT c.*,CONCAT(u.nombre,' ',u.apellidos) AS dr FROM citas c JOIN usuarios u ON c.doctor_id=u.id WHERE c.paciente_id=? ORDER BY c.fecha DESC LIMIT 8"); $cit->execute([$id]); $cit=$cit->fetchAll();
 $pags=db()->prepare("SELECT * FROM pagos WHERE paciente_id=? ORDER BY fecha DESC LIMIT 6"); $pags->execute([$id]); $pags=$pags->fetchAll();
 
 // Obtener HC más reciente para signos vitales
 $hc_actual = $hcs[0] ?? null;
 
 $titulo=$pac['nombres'].' '.$pac['apellido_paterno']; $pagina_activa='pac';
 $topbar_act='<a href="?accion=editar&id='.$id.'" class="btn btn-dk btn-sm"><i class="bi bi-pencil me-1"></i>Editar</a>
 <a href="'.BASE_URL.'/pages/pacientes.php" class="btn btn-dk btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver</a>
 <a href="'.BASE_URL.'/pages/citas.php?accion=nueva&paciente_id='.$id.'" class="btn btn-primary btn-sm"><i class="bi bi-calendar-plus me-1"></i>Agendar</a>
 <a href="'.BASE_URL.'/pages/ortodoncias.php?paciente_id='.$id.'" class="btn btn-sm" style="background:#06B6D4;border-color:#06B6D4;color:white"><i class="bi bi-grid-3x2-gap me-1"></i>Ortodoncia</a>';
 
 // CSS para dashboard de cards
 $xhead = '<style>
 .dashboard-card {
  background: var(--bg2);
  border: 1px solid var(--bd);
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
  transition: all 0.2s ease;
  height: 100%;
 }
 .dashboard-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 16px rgba(0,0,0,0.12);
 }
 .card-header-custom {
  background: linear-gradient(135deg, var(--c) 0%, #0891b2 100%);
  color: white;
  padding: 12px 16px;
  border-radius: 11px 11px 0 0;
  font-weight: 700;
  font-size: 13px;
  display: flex;
  align-items: center;
  gap: 8px;
 }
 .card-body-custom {
  padding: 16px;
 }
 .profile-photo {
  width: 80px;
  height: 80px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--c) 0%, #0891b2 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
  font-size: 32px;
  font-weight: 700;
  margin-bottom: 12px;
  border: 3px solid white;
  box-shadow: 0 4px 12px rgba(0,0,0,0.15);
 }
 .timeline-item {
  position: relative;
  padding-left: 24px;
  margin-bottom: 12px;
  border-left: 2px solid var(--bd2);
 }
 .timeline-item::before {
  content: "";
  position: absolute;
  left: -5px;
  top: 6px;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--c);
 }
 .timeline-item:last-child {
  border-left-color: transparent;
 }
 .vital-sign {
  background: var(--bg3);
  border-radius: 8px;
  padding: 12px;
  text-align: center;
  border: 1px solid var(--bd2);
 }
 .vital-value {
  font-size: 18px;
  font-weight: 700;
  color: var(--c);
  display: block;
 }
 .vital-label {
  font-size: 11px;
  color: var(--t2);
  text-transform: uppercase;
  letter-spacing: 0.5px;
 }
 .quick-action {
  background: var(--bg3);
  border: 1px solid var(--bd2);
  border-radius: 8px;
  padding: 12px;
  text-decoration: none;
  color: var(--t);
  display: flex;
  align-items: center;
  gap: 10px;
  transition: all 0.2s ease;
 }
 .quick-action:hover {
  background: var(--c);
  color: white;
  transform: translateX(4px);
 }
 .stat-mini {
  background: var(--bg3);
  border-radius: 6px;
  padding: 8px 12px;
  text-align: center;
  border: 1px solid var(--bd2);
 }
 </style>';
 
 require_once __DIR__.'/../includes/header.php';
 $sb=['ninguno'=>'bgr','sis'=>'bg','essalud'=>'bb','privado'=>'bc','otros'=>'ba'];
 $ec=['pendiente'=>'ba','confirmado'=>'bc','en_atencion'=>'bb','atendido'=>'bg','no_asistio'=>'br','cancelado'=>'bgr'];
?>
<!-- DISEÑO COMPACTO CON CARDS -->
<div class="row g-3">
 <!-- Card de Perfil -->
 <div class="col-12 col-lg-6 col-xl-4">
  <div class="card h-100">
   <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-person-circle" style="color:var(--c)"></i>
    <span style="font-weight:600">Perfil del Paciente</span>
   </div>
   <div class="p-3 text-center">
    <?php if($pac['foto_perfil']): ?>
     <img src="<?=BASE_URL?>/uploads/<?=e($pac['foto_perfil'])?>" class="rounded-circle mb-3" style="width:80px;height:80px;object-fit:cover" alt="Foto">
    <?php else: ?>
     <div class="ava mx-auto mb-3" style="width:80px;height:80px;font-size:32px"><?=strtoupper(substr($pac['nombres'],0,1))?></div>
    <?php endif; ?>
    <h3 style="font-size:19px;font-weight:700;margin:0 0 4px"><?=e($pac['nombres'].' '.$pac['apellido_paterno'])?></h3>
    <p style="color:var(--t2);font-size:14px;margin:0"><?=e($pac['codigo'])?> · <?=$pac['fecha_nacimiento']?edad($pac['fecha_nacimiento']):'—'?></p>
    <div class="d-flex gap-1 justify-content-center flex-wrap mt-2">
     <span class="badge <?=$sb[$pac['tipo_seguro']]?>" style="font-size:11px"><?=strtoupper($pac['tipo_seguro'])?></span>
     <?php if($pac['alergias']): ?><span class="badge br" style="font-size:11px">⚠ ALERGIAS</span><?php endif; ?>
    </div>
   </div>
   <div class="p-3 pt-0" style="font-size:15px">
    <div class="row g-2">
     <div class="col-6"><i class="bi bi-phone" style="color:var(--c)"></i> <?=e($pac['telefono']??'—')?></div>
     <div class="col-6"><i class="bi bi-card-text" style="color:var(--c)"></i> <?=e($pac['dni']??'—')?></div>
     <div class="col-12"><i class="bi bi-envelope" style="color:var(--c)"></i> <?=e($pac['email']??'—')?></div>
    </div>
   </div>
  </div>
 </div>

 <!-- Card de Vitales -->
 <div class="col-12 col-lg-6 col-xl-4">
  <div class="card h-100">
   <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-heart-pulse" style="color:#e74c3c"></i>
    <span style="font-weight:600">Signos Vitales</span>
   </div>
   <div class="p-3">
    <?php 
    // Obtener últimos signos vitales de HC más reciente
    $vitales = ['presion_arterial' => $pac['presion_arterial'] ?? null, 'peso' => $pac['peso'] ?? null, 'talla' => $pac['talla'] ?? null];
    if($hcs) {
     $hc_reciente = $hcs[0];
     $vitales['presion_arterial'] = $hc_reciente['presion_arterial'] ?? $vitales['presion_arterial'];
     $vitales['peso'] = $hc_reciente['peso'] ?? $vitales['peso'];
     $vitales['talla'] = $hc_reciente['talla'] ?? $vitales['talla'];
    }
    ?>
    <div class="row g-2 text-center">
     <div class="col-4">
      <div style="background:rgba(231,76,60,.1);border-radius:8px;padding:12px">
       <i class="bi bi-heart" style="color:#e74c3c;font-size:20px;display:block;margin-bottom:4px"></i>
       <div style="font-size:16px;font-weight:700;color:var(--t)"><?=$vitales['presion_arterial']??'—'?></div>
       <small style="color:var(--t2);font-size:10px">Presión Arterial</small>
      </div>
     </div>
     <div class="col-4">
      <div style="background:rgba(52,152,219,.1);border-radius:8px;padding:12px">
       <i class="bi bi-speedometer2" style="color:#3498db;font-size:20px;display:block;margin-bottom:4px"></i>
       <div style="font-size:16px;font-weight:700;color:var(--t)"><?=$vitales['peso']?$vitales['peso'].' kg':'—'?></div>
       <small style="color:var(--t2);font-size:10px">Peso</small>
      </div>
     </div>
     <div class="col-4">
      <div style="background:rgba(46,204,113,.1);border-radius:8px;padding:12px">
       <i class="bi bi-rulers" style="color:#2ecc71;font-size:20px;display:block;margin-bottom:4px"></i>
       <div style="font-size:16px;font-weight:700;color:var(--t)"><?=$vitales['talla']?$vitales['talla'].' m':'—'?></div>
       <small style="color:var(--t2);font-size:10px">Talla</small>
      </div>
     </div>
    </div>
    <?php if($pac['alergias']||$pac['enfermedades_base']||$pac['medicacion_actual']): ?>
    <div class="mt-3 pt-2" style="border-top:1px solid var(--bd2)">
     <small style="color:var(--t2);font-size:11px;font-weight:700">ALERTAS MÉDICAS</small>
     <?php if($pac['alergias']): ?><div class="mt-1" style="background:rgba(224,82,82,.08);padding:6px 8px;border-radius:4px;font-size:12px;border-left:2px solid #e05252">⚠️ <?=e(substr($pac['alergias'],0,40))?><?=strlen($pac['alergias'])>40?'...':''?></div><?php endif; ?>
     <?php if($pac['medicacion_actual']): ?><div class="mt-1" style="background:rgba(52,152,219,.08);padding:6px 8px;border-radius:4px;font-size:12px;border-left:2px solid #3498db">💊 <?=e(substr($pac['medicacion_actual'],0,40))?><?=strlen($pac['medicacion_actual'])>40?'...':''?></div><?php endif; ?>
    </div>
    <?php endif; ?>
   </div>
  </div>
 </div>

 <!-- Card de Consulta Actual -->
 <div class="col-12 col-xl-4">
  <div class="card h-100">
   <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-clipboard-pulse" style="color:var(--c)"></i>
    <span style="font-weight:600">Acceso Rápido</span>
   </div>
   <div class="p-3">
    <div class="d-grid gap-2">
     <a href="<?=BASE_URL?>/pages/historia_clinica.php?paciente_id=<?=$id?>" class="btn btn-primary btn-sm"><i class="bi bi-file-medical me-1"></i>Historia Clínica</a>
     <a href="<?=BASE_URL?>/pages/odontograma.php?paciente_id=<?=$id?>" class="btn btn-dk btn-sm" style="border-color:rgba(0,212,238,.3);color:var(--c)"><i class="bi bi-grid-3x3-gap me-1"></i>Odontograma</a>
     <a href="<?=BASE_URL?>/pages/recetarios.php?paciente_id=<?=$id?>" class="btn btn-dk btn-sm" style="border-color:rgba(236,72,153,.3);color:#ec4899"><i class="bi bi-prescription2 me-1"></i>Recetarios</a>
     <a href="<?=BASE_URL?>/pages/ortodoncias.php?paciente_id=<?=$id?>" class="btn btn-dk btn-sm" style="border-color:rgba(6,182,212,.3);color:#06B6D4"><i class="bi bi-grid-3x2-gap me-1"></i>Ortodoncia</a>
     <a href="<?=BASE_URL?>/pages/destartraje.php?paciente_id=<?=$id?>" class="btn btn-dk btn-sm" style="border-color:rgba(16,185,129,.3);color:#10b981"><i class="bi bi-droplet-fill me-1"></i>Destartraje</a>
     <a href="<?=BASE_URL?>/pages/curaciones.php?paciente_id=<?=$id?>" class="btn btn-dk btn-sm" style="border-color:rgba(245,158,11,.3);color:#f59e0b"><i class="bi bi-bandaid-fill me-1"></i>Curaciones</a>
     <a href="<?=BASE_URL?>/pages/protesis_fija.php?paciente_id=<?=$id?>" class="btn btn-dk btn-sm" style="border-color:rgba(139,92,246,.3);color:#8b5cf6"><i class="bi bi-gem me-1"></i>Prótesis Fija</a>
     <?php if(puedeVer('endodoncia')): ?><a href="<?=BASE_URL?>/pages/endodoncia.php?paciente_id=<?=$id?>" class="btn btn-dk btn-sm" style="border-color:rgba(239,68,68,.3);color:#ef4444"><i class="bi bi-heart-pulse-fill me-1"></i>Endodoncia</a><?php endif; ?>
     <?php if(puedeVer('presupuestos')): ?><a href="<?=BASE_URL?>/pages/presupuestos.php?paciente_id=<?=$id?>" class="btn btn-dk btn-sm" style="border-color:rgba(0,212,238,.3);color:var(--c)"><i class="bi bi-receipt me-1"></i>Presupuesto</a><?php endif; ?>
     <a href="<?=BASE_URL?>/pages/citas.php?accion=nueva&paciente_id=<?=$id?>" class="btn btn-dk btn-sm"><i class="bi bi-calendar-plus me-1"></i>Nueva Cita</a>
     <a href="<?=BASE_URL?>/pages/pagos.php?accion=nuevo&paciente_id=<?=$id?>" class="btn btn-dk btn-sm"><i class="bi bi-cash me-1"></i>Registrar Pago</a>
     <?php if($pac['telefono']): ?>
     <a href="<?=urlWA($pac['telefono'],'Hola '.$pac['nombres'].', le contactamos desde '.getCfg('clinica_nombre').'. ')?>" target="_blank" class="btn btn-wa btn-sm"><i class="bi bi-whatsapp me-1"></i>WhatsApp</a>
     <?php endif; ?>
    </div>
    
    <?php if($hcs): ?>
    <div class="mt-3 pt-2" style="border-top:1px solid var(--bd2)">
     <small style="color:var(--t2);font-size:10px;font-weight:700">ÚLTIMA CONSULTA</small>
     <?php $ultima_hc = $hcs[0]; ?>
     <div class="mt-1" style="font-size:11px">
      <div class="d-flex justify-content-between">
       <span class="badge bc" style="font-size:9px"><?=e($ultima_hc['numero_hc'])?></span>
       <small style="color:var(--t2)"><?=fDate($ultima_hc['fecha_apertura'])?></small>
      </div>
      <div style="margin-top:4px;color:var(--t)"><?=e(substr($ultima_hc['motivo_consulta'],0,60))?><?=strlen($ultima_hc['motivo_consulta'])>60?'...':''?></div>
     </div>
    </div>
    <?php endif; ?>
   </div>
  </div>
 </div>

 <!-- Portal del paciente (fila completa) -->
 <div class="col-12">
  <?php // ── Portal del paciente ──
    $portalUrl = !empty($pac['portal_token']) ? rtrim((isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.$_SERVER['HTTP_HOST'].BASE_URL,'/').'/portal.php?t='.$pac['portal_token'] : '';
    $nomCli = empresa('nombre_comercial') ?: getCfg('clinica_nombre','la clínica');
  ?>
  <div class="card mt-3" style="border:1px solid rgba(0,212,238,.25)">
   <div class="card-header d-flex align-items-center justify-content-between">
    <span style="font-weight:600"><i class="bi bi-phone me-1" style="color:var(--c)"></i>Portal del paciente</span>
    <form method="POST" onsubmit="return confirm('<?=!empty($pac['portal_token'])?'Generar un NUEVO enlace invalidará el anterior. ¿Continuar?':'¿Generar enlace de acceso para este paciente?'?>')">
     <input type="hidden" name="accion" value="portal_token"><input type="hidden" name="id" value="<?=$id?>">
     <button class="btn btn-dk btn-sm"><i class="bi bi-<?=!empty($pac['portal_token'])?'arrow-repeat':'link-45deg'?> me-1"></i><?=!empty($pac['portal_token'])?'Regenerar':'Generar enlace'?></button>
    </form>
   </div>
   <div class="p-3">
    <?php if($portalUrl): ?>
     <p style="color:var(--t2);font-size:12px;margin-bottom:8px">Comparte este enlace para que el paciente vea sus citas, recetas, presupuestos, pagos y la evolución de su tratamiento.</p>
     <div class="d-flex gap-2 flex-wrap align-items-center">
      <input type="text" id="portalUrl" class="form-control form-control-sm" style="flex:1;min-width:220px" value="<?=e($portalUrl)?>" readonly onclick="this.select()">
      <button type="button" class="btn btn-primary btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('portalUrl').value);this.innerHTML='<i class=\'bi bi-check2\'></i> Copiado'"><i class="bi bi-clipboard me-1"></i>Copiar</button>
      <?php $waTxt=rawurlencode("Hola ".$pac['nombres'].", este es tu acceso al portal de ".$nomCli.":\n".$portalUrl); $waTel=preg_replace('/[^0-9]/','',$pac['telefono']??''); ?>
      <a class="btn btn-sm" style="background:#25D366;color:#fff" target="_blank" href="https://wa.me/<?=$waTel?>?text=<?=$waTxt?>"><i class="bi bi-whatsapp me-1"></i>WhatsApp</a>
      <a class="btn btn-dk btn-sm" target="_blank" href="<?=e($portalUrl)?>"><i class="bi bi-box-arrow-up-right me-1"></i>Abrir</a>
     </div>
     <?php if(!empty($pac['portal_token_at'])): ?><div style="color:var(--t3);font-size:11px;margin-top:8px">Enlace generado: <?=fDate($pac['portal_token_at'])?></div><?php endif; ?>
    <?php else: ?>
     <p style="color:var(--t2);font-size:13px;margin:0">Este paciente aún no tiene acceso al portal. Genera un enlace para compartirlo.</p>
    <?php endif; ?>
   </div>
  </div>
 </div>

 <!-- Card de Evolución (Timeline) -->
 <div class="col-12">
  <div class="card">
   <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-clock-history" style="color:#f39c12"></i>
    <span style="font-weight:600">Línea de Tiempo - Evolución del Paciente</span>
   </div>
   <div class="p-3">
    <ul class="nav nav-pills nav-fill mb-3">
     <li class="nav-item"><a class="nav-link active" data-bs-toggle="pill" data-bs-target="#timeline-hc">📋 HC (<?=count($hcs)?>)</a></li>
     <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" data-bs-target="#timeline-citas">📅 Citas (<?=count($cit)?>)</a></li>
     <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" data-bs-target="#timeline-pagos">💰 Pagos (<?=count($pags)?>)</a></li>
    </ul>
    
    <div class="tab-content">
     <!-- Timeline HC -->
     <div class="tab-pane fade show active" id="timeline-hc">
      <?php if($hcs): ?>
       <div class="timeline">
        <?php foreach(array_slice($hcs,0,5) as $i => $hc): ?>
         <div class="timeline-item">
          <div class="timeline-marker" style="background:<?=$hc['estado']==='activa'?'var(--c)':'#95a5a6'?>">
           <i class="bi bi-file-medical"></i>
          </div>
          <div class="timeline-content">
           <div class="d-flex justify-content-between align-items-start mb-1">
            <div>
             <span class="badge bc me-1" style="font-size:9px"><?=e($hc['numero_hc'])?></span>
             <span class="badge <?=$hc['estado']==='activa'?'bg':'bgr'?>" style="font-size:9px"><?=$hc['estado']?></span>
            </div>
            <small style="color:var(--t2);font-size:10px"><?=fDate($hc['fecha_apertura'])?></small>
           </div>
           <div style="font-size:12px;color:var(--t);margin-bottom:4px"><?=e(substr($hc['motivo_consulta'],0,100))?><?=strlen($hc['motivo_consulta'])>100?'...':''?></div>
           <?php if($hc['dr']): ?><small style="color:var(--t2);font-size:10px">Dr. <?=e($hc['dr'])?></small><?php endif; ?>
           <div class="mt-2">
            <a href="<?=BASE_URL?>/pages/historia_clinica.php?id=<?=$hc['id']?>" class="btn btn-primary btn-xs">Abrir HC</a>
           </div>
          </div>
         </div>
        <?php endforeach; ?>
       </div>
      <?php else: ?>
       <div class="text-center py-4" style="color:var(--t2)">
        <i class="bi bi-file-medical" style="font-size:32px;display:block;margin-bottom:8px"></i>
        <div style="font-size:13px">Sin historias clínicas registradas</div>
        <a href="<?=BASE_URL?>/pages/historia_clinica.php?accion=nueva&paciente_id=<?=$id?>" class="btn btn-primary btn-sm mt-2">Crear primera HC</a>
       </div>
      <?php endif; ?>
     </div>
     
     <!-- Timeline Citas -->
     <div class="tab-pane fade" id="timeline-citas">
      <?php if($cit): ?>
       <div class="timeline">
        <?php foreach(array_slice($cit,0,5) as $c): ?>
         <div class="timeline-item">
          <div class="timeline-marker" style="background:<?=['pendiente'=>'#f39c12','confirmado'=>'#3498db','atendido'=>'#2ecc71','no_asistio'=>'#e74c3c'][$c['estado']]??'#95a5a6'?>">
           <i class="bi bi-calendar-event"></i>
          </div>
          <div class="timeline-content">
           <div class="d-flex justify-content-between align-items-start mb-1">
            <span class="badge <?=$ec[$c['estado']]?>" style="font-size:9px"><?=$c['estado']?></span>
            <small style="color:var(--t2);font-size:10px"><?=fDate($c['fecha'])?> <?=substr($c['hora_inicio'],0,5)?></small>
           </div>
           <div style="font-size:12px;color:var(--t);margin-bottom:2px">Dr. <?=e($c['dr'])?></div>
           <?php if($c['motivo']): ?><div style="font-size:11px;color:var(--t2)"><?=e(substr($c['motivo'],0,80))?><?=strlen($c['motivo'])>80?'...':''?></div><?php endif; ?>
          </div>
         </div>
        <?php endforeach; ?>
       </div>
      <?php else: ?>
       <div class="text-center py-4" style="color:var(--t2)">
        <i class="bi bi-calendar-x" style="font-size:32px;display:block;margin-bottom:8px"></i>
        <div style="font-size:13px">Sin citas registradas</div>
       </div>
      <?php endif; ?>
     </div>
     
     <!-- Timeline Pagos -->
     <div class="tab-pane fade" id="timeline-pagos">
      <?php if($pags): ?>
       <div class="timeline">
        <?php foreach(array_slice($pags,0,5) as $pg): ?>
         <div class="timeline-item">
          <div class="timeline-marker" style="background:<?=$pg['estado']==='pagado'?'#2ecc71':($pg['estado']==='anulado'?'#e74c3c':'#f39c12')?>">
           <i class="bi bi-cash-coin"></i>
          </div>
          <div class="timeline-content">
           <div class="d-flex justify-content-between align-items-start mb-1">
            <span class="badge <?=$pg['estado']==='pagado'?'bg':($pg['estado']==='anulado'?'br':'ba')?>" style="font-size:9px"><?=$pg['estado']?></span>
            <small style="color:var(--t2);font-size:10px"><?=fDate($pg['fecha'])?></small>
           </div>
           <div class="d-flex justify-content-between align-items-center">
            <div>
             <div style="font-size:12px;color:var(--t);font-weight:600"><?=mon((float)$pg['total'])?></div>
             <small style="color:var(--t2);font-size:10px"><?=e($pg['codigo'])?> · <?=strtoupper($pg['metodo'])?></small>
            </div>
           </div>
          </div>
         </div>
        <?php endforeach; ?>
       </div>
      <?php else: ?>
       <div class="text-center py-4" style="color:var(--t2)">
        <i class="bi bi-cash-stack" style="font-size:32px;display:block;margin-bottom:8px"></i>
        <div style="font-size:13px">Sin pagos registrados</div>
       </div>
      <?php endif; ?>
     </div>
    </div>
   </div>
  </div>
 </div>
</div>

<!-- CSS para Timeline -->
<style>
.timeline {
 position: relative;
 padding-left: 30px;
}

.timeline::before {
 content: '';
 position: absolute;
 left: 15px;
 top: 0;
 bottom: 0;
 width: 2px;
 background: linear-gradient(to bottom, var(--c), #e9ecef);
}

.timeline-item {
 position: relative;
 margin-bottom: 20px;
}

.timeline-marker {
 position: absolute;
 left: -22px;
 top: 0;
 width: 30px;
 height: 30px;
 border-radius: 50%;
 display: flex;
 align-items: center;
 justify-content: center;
 color: white;
 font-size: 12px;
 border: 3px solid var(--bg);
 box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.timeline-content {
 background: var(--bg2);
 border: 1px solid var(--bd2);
 border-radius: 8px;
 padding: 14px;
 box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.timeline-content:hover {
 box-shadow: 0 4px 8px rgba(0,0,0,0.1);
 transform: translateY(-1px);
 transition: all 0.2s ease;
}

.timeline-content .badge {
 font-size: 10px;
}

.timeline-content > div:first-child {
 margin-bottom: 8px;
}

.timeline-content > div:first-child small {
 color: var(--t2);
 font-size: 11px;
}

.timeline-content > div:nth-child(2) {
 font-size: 14px;
 color: var(--t);
 margin-bottom: 6px;
 line-height: 1.5;
 font-weight: 500;
}

.timeline-content > small {
 color: var(--t2);
 font-size: 11px;
}

.btn-xs {
 padding: 4px 10px;
 font-size: 11px;
 border-radius: 4px;
}

.nav-pills .nav-link {
 font-size: 13px;
 padding: 8px 14px;
}

.nav-pills .nav-link.active {
 background-color: var(--c);
}
</style>
<?php require_once __DIR__.'/../includes/footer.php';

}elseif(in_array($accion,['nuevo','editar'])){
 $pac=['id'=>0,'nombres'=>'','apellido_paterno'=>'','apellido_materno'=>'','dni'=>'','ruc'=>'','fecha_nacimiento'=>'','sexo'=>'','estado_civil'=>'','ocupacion'=>'','telefono'=>'','email'=>'','direccion'=>'','distrito'=>'','tipo_seguro'=>'ninguno','num_seguro'=>'','alergias'=>'','enfermedades_base'=>'','medicacion_actual'=>'','cirugia_previa'=>'','embarazo'=>0,'fuma'=>0,'alcohol'=>0,'antecedentes_obs'=>'','contacto_nombre'=>'','contacto_telefono'=>'','contacto_parentesco'=>''];
 if($accion==='editar'&&$id){$s=db()->prepare("SELECT * FROM pacientes WHERE id=?");$s->execute([$id]);$pac=$s->fetch()?:$pac;}
 $titulo=$accion==='nuevo'?'Nuevo Paciente':'Editar: '.$pac['nombres'].' '.$pac['apellido_paterno'];
 $pagina_activa='pac';
 $topbar_act='<a href="'.BASE_URL.'/pages/pacientes.php" class="btn btn-dk btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver a pacientes</a>';
 
 // CSS mejorado para inputs
 $xhead = '<style>
 /* Estilos mejorados para inputs del formulario */
 .form-control, .form-select {
  transition: all 0.3s ease;
  background: var(--bg3);
  color: var(--t);
  position: relative;
 }
 
 /* TODOS los inputs tienen borde de color por defecto */
 .form-control, .form-select {
  border: 2px solid rgba(0, 212, 238, 0.4);
  box-shadow: 0 0 0 2px rgba(0, 212, 238, 0.1), 0 0 8px rgba(0, 212, 238, 0.15);
 }
 
 /* Efecto al hacer focus (MÁS FUERTE Y VISTOSO) */
 .form-control:focus, .form-select:focus {
  border-color: var(--c);
  box-shadow: 0 0 0 6px rgba(0, 212, 238, 0.25), 0 0 30px rgba(0, 212, 238, 0.5), 0 0 50px rgba(0, 212, 238, 0.3);
  transform: scale(1.05);
  background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 50%, #f0f9ff 100%);
  color: #1e293b;
  outline: none;
 }
 
 /* Animación de glow MÁS INTENSA */
 @keyframes inputGlowIntense {
  0% { 
   box-shadow: 0 0 0 2px rgba(0, 212, 238, 0.1), 0 0 8px rgba(0, 212, 238, 0.15);
   transform: scale(1);
   background: var(--bg3);
  }
  50% { 
   box-shadow: 0 0 0 8px rgba(0, 212, 238, 0.35), 0 0 40px rgba(0, 212, 238, 0.6), 0 0 60px rgba(0, 212, 238, 0.4);
   transform: scale(1.06);
   background: linear-gradient(135deg, #f0f9ff 0%, #dbeafe 50%, #f0f9ff 100%);
  }
  100% { 
   box-shadow: 0 0 0 6px rgba(0, 212, 238, 0.25), 0 0 30px rgba(0, 212, 238, 0.5), 0 0 50px rgba(0, 212, 238, 0.3);
   transform: scale(1.05);
   background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 50%, #f0f9ff 100%);
  }
 }
 
 .form-control:focus, .form-select:focus {
  animation: inputGlowIntense 0.8s ease-in-out;
 }
 
 /* Hover effect más sutil */
 .form-control:hover:not(:focus), .form-select:hover:not(:focus) {
  border-color: rgba(0, 212, 238, 0.6);
  box-shadow: 0 0 0 3px rgba(0, 212, 238, 0.15), 0 0 15px rgba(0, 212, 238, 0.25);
  transform: translateY(-2px);
 }
 
 /* Labels mejorados */
 .form-label {
  font-weight: 600;
  color: var(--t);
  margin-bottom: 6px;
  font-size: 13px;
 }
 
 /* Textarea específico */
 textarea.form-control {
  resize: vertical;
  min-height: 80px;
 }
 
 /* Checkboxes mejorados */
 .form-check-input:checked {
  background-color: var(--c);
  border-color: var(--c);
 }
 
 .form-check-input:focus {
  box-shadow: 0 0 0 3px rgba(0, 212, 238, 0.25);
 }
 </style>';
 
 require_once __DIR__.'/../includes/header.php';
?>
<div class="row justify-content-center"><div class="col-12 col-xl-9">
<form method="POST" enctype="multipart/form-data">
 <input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?=$pac['id']?>">
 <div class="card mb-4">
  <div class="card-header"><span style="color:var(--t)"><i class="bi bi-search me-1"></i>Búsqueda por documento</span></div>
  <div class="p-4">
   <div class="row g-2 align-items-end">
    <div class="col-12 col-md-3">
     <label class="form-label">Tipo de documento</label>
     <select id="tipoDoc" class="form-select">
      <option value="dni">DNI (8 dígitos)</option>
      <option value="ruc">RUC (11 dígitos)</option>
     </select>
    </div>
    <div class="col-12 col-md-5">
     <label class="form-label">Número de documento</label>
     <input type="text" id="docInp" class="form-control" placeholder="Ingresa el número y presiona Buscar" inputmode="numeric" maxlength="11">
    </div>
    <div class="col-12 col-md-4">
     <button type="button" class="btn btn-primary w-100" id="btnBuscarDoc"><i class="bi bi-search me-1"></i>Buscar en RENIEC / SUNAT</button>
    </div>
    <div class="col-12">
     <small id="docMsg" style="font-size:12px"></small>
    </div>
   </div>
  </div>
 </div>
 <input type="hidden" name="dni" id="hidDni" value="<?=e($pac['dni']??'')?>">
 <input type="hidden" name="ruc" id="hidRuc" value="<?=e($pac['ruc']??'')?>">
 <div class="card mb-4">
  <div class="card-header"><span style="color:var(--t)"><i class="bi bi-person-badge me-1"></i>Datos personales</span></div>
  <div class="p-4"><div class="row g-3">
   <div class="col-12">
    <label class="form-label">Foto de perfil</label>
    <input type="file" name="foto_perfil" class="form-control" accept="image/*" onchange="validarTamanoArchivo(this, 1)">
    <small class="form-text text-muted">Máximo 1MB. Formatos: JPG, PNG, WEBP</small>
   </div>
   <div class="col-12 col-md-4"><label class="form-label">Nombres / Razón social *</label><input type="text" name="nombres" class="form-control" value="<?=e($pac['nombres'])?>" required></div>
   <div class="col-12 col-md-4"><label class="form-label">Apellido paterno *</label><input type="text" name="apellido_paterno" class="form-control" value="<?=e($pac['apellido_paterno'])?>" required></div>
   <div class="col-12 col-md-4"><label class="form-label">Apellido materno</label><input type="text" name="apellido_materno" class="form-control" value="<?=e($pac['apellido_materno']??'')?>"></div>
   <div class="col-12 col-md-3"><label class="form-label">DNI registrado</label><input type="text" class="form-control" id="vDni" value="<?=e($pac['dni']??'')?>" readonly placeholder="(se autocompleta)"></div>
   <div class="col-12 col-md-3"><label class="form-label">RUC registrado</label><input type="text" class="form-control" id="vRuc" value="<?=e($pac['ruc']??'')?>" readonly placeholder="(se autocompleta)"></div>
   <div class="col-12 col-md-3"><label class="form-label">Fecha nacimiento</label><input type="date" name="fecha_nacimiento" class="form-control" value="<?=$pac['fecha_nacimiento']??''?>"></div>
   <div class="col-12 col-md-3"><label class="form-label">Sexo</label>
   <select name="sexo" class="form-select"><option value="">—</option><option value="M" <?=$pac['sexo']==='M'?'selected':''?>>Masculino</option><option value="F" <?=$pac['sexo']==='F'?'selected':''?>>Femenino</option><option value="O" <?=$pac['sexo']==='O'?'selected':''?>>Otro</option></select></div>
   <div class="col-12 col-md-3"><label class="form-label">Estado civil</label>
   <select name="estado_civil" class="form-select"><option value="">—</option>
   <?php foreach(['soltero','casado','conviviente','divorciado','viudo'] as $ec): ?><option value="<?=$ec?>" <?=$pac['estado_civil']===$ec?'selected':''?>><?=ucfirst($ec)?></option><?php endforeach; ?></select></div>
   <div class="col-12 col-md-4"><label class="form-label">Ocupación</label><input type="text" name="ocupacion" class="form-control" value="<?=e($pac['ocupacion']??'')?>"></div>
   <div class="col-12 col-md-4"><label class="form-label">Teléfono</label><input type="text" name="telefono" class="form-control" value="<?=e($pac['telefono']??'')?>"></div>
   <div class="col-12 col-md-4"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?=e($pac['email']??'')?>"></div>
   <div class="col-12 col-md-6"><label class="form-label">Dirección</label><input type="text" name="direccion" class="form-control" value="<?=e($pac['direccion']??'')?>"></div>
   <div class="col-12 col-md-6"><label class="form-label">Distrito</label><input type="text" name="distrito" class="form-control" value="<?=e($pac['distrito']??'')?>"></div>
   <div class="col-12 col-md-6"><label class="form-label">Tipo de seguro</label>
   <select name="tipo_seguro" class="form-select">
   <?php foreach(['ninguno'=>'Sin seguro','sis'=>'SIS','essalud'=>'EsSalud','privado'=>'Privado','otros'=>'Otros'] as $v=>$l): ?><option value="<?=$v?>" <?=$pac['tipo_seguro']===$v?'selected':''?>><?=$l?></option><?php endforeach; ?></select></div>
   <div class="col-12 col-md-6"><label class="form-label">N° seguro</label><input type="text" name="num_seguro" class="form-control" value="<?=e($pac['num_seguro']??'')?>"></div>
  </div></div>
 </div>
 <div class="card mb-4">
  <div class="card-header"><span style="color:var(--t)"><i class="bi bi-heart-pulse me-1"></i>Antecedentes médicos (NT N°022-MINSA)</span></div>
  <div class="p-4"><div class="row g-3">
   <div class="col-12 col-md-6"><label class="form-label">⚠️ Alergias conocidas</label><textarea name="alergias" class="form-control" rows="3" placeholder="Penicilina, látex, anestesia..."><?=e($pac['alergias']??'')?></textarea></div>
   <div class="col-12 col-md-6"><label class="form-label">🏥 Enfermedades de base</label><textarea name="enfermedades_base" class="form-control" rows="3" placeholder="Diabetes, HTA, cardiopatía..."><?=e($pac['enfermedades_base']??'')?></textarea></div>
   <div class="col-12 col-md-6"><label class="form-label">💊 Medicación actual</label><textarea name="medicacion_actual" class="form-control" rows="2"><?=e($pac['medicacion_actual']??'')?></textarea></div>
   <div class="col-12 col-md-6"><label class="form-label">🔪 Cirugías previas</label><textarea name="cirugia_previa" class="form-control" rows="2"><?=e($pac['cirugia_previa']??'')?></textarea></div>
   <div class="col-12">
    <div class="d-flex gap-4 flex-wrap">
     <div class="form-check"><input class="form-check-input" type="checkbox" name="embarazo" id="ck1" <?=$pac['embarazo']?'checked':''?>><label class="form-check-label" for="ck1">🤰 Embarazada</label></div>
     <div class="form-check"><input class="form-check-input" type="checkbox" name="fuma" id="ck2" <?=$pac['fuma']?'checked':''?>><label class="form-check-label" for="ck2">🚬 Fumador/a</label></div>
     <div class="form-check"><input class="form-check-input" type="checkbox" name="alcohol" id="ck3" <?=$pac['alcohol']?'checked':''?>><label class="form-check-label" for="ck3">🍺 Alcohol</label></div>
    </div>
   </div>
   <div class="col-12"><label class="form-label">Observaciones adicionales</label><textarea name="antecedentes_obs" class="form-control" rows="2"><?=e($pac['antecedentes_obs']??'')?></textarea></div>
  </div></div>
 </div>
 <div class="card mb-4">
  <div class="card-header"><span style="color:var(--t)"><i class="bi bi-phone-vibrate me-1"></i>Contacto de emergencia</span></div>
  <div class="p-4"><div class="row g-3">
   <div class="col-12 col-md-5"><label class="form-label">Nombre completo</label><input type="text" name="contacto_nombre" class="form-control" value="<?=e($pac['contacto_nombre']??'')?>"></div>
   <div class="col-12 col-md-4"><label class="form-label">Teléfono</label><input type="text" name="contacto_telefono" class="form-control" value="<?=e($pac['contacto_telefono']??'')?>"></div>
   <div class="col-12 col-md-3"><label class="form-label">Parentesco</label><input type="text" name="contacto_parentesco" class="form-control" value="<?=e($pac['contacto_parentesco']??'')?>" placeholder="Hijo, esposo/a..."></div>
  </div></div>
 </div>
 <div class="d-flex gap-2 justify-content-end">
  <a href="<?=$accion==='editar'?'?accion=ver&id='.$id:'?'?>" class="btn btn-dk">Cancelar</a>
  <button type="submit" class="btn btn-primary px-4"><i class="bi bi-floppy me-2"></i><?=$accion==='nuevo'?'Registrar paciente':'Guardar cambios'?></button>
 </div>
</form>
</div></div>
<script>
(function(){
 const API = '<?=BASE_URL?>/api/documento.php';
 const $   = (id) => document.getElementById(id);
 const setMsg = (txt, ok) => {
  const el = $('docMsg');
  el.textContent = txt;
  el.style.color = ok ? '#2ecc71' : '#e05252';
 };
 const inp     = $('docInp');
 const tipoSel = $('tipoDoc');
 const btn     = $('btnBuscarDoc');

 function syncTipo(){
  const tipo = tipoSel.value;
  inp.maxLength = tipo === 'dni' ? 8 : 11;
  inp.placeholder = tipo === 'dni' ? '12345678' : '20123456789';
 }
 tipoSel.addEventListener('change', syncTipo);
 syncTipo();

 inp.addEventListener('input', () => { inp.value = inp.value.replace(/\D/g,''); });
 inp.addEventListener('keydown', e => {
  if (e.key === 'Enter') { e.preventDefault(); btn.click(); }
 });

 async function consultar(){
  const tipo = tipoSel.value;
  const doc  = inp.value.trim();
  const need = tipo === 'dni' ? 8 : 11;
  if (doc.length !== need) {
   setMsg('Ingresa exactamente ' + need + ' dígitos para ' + tipo.toUpperCase() + '.', false);
   inp.focus();
   return;
  }
  const orig = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Consultando...';
  setMsg('Consultando ' + (tipo==='dni'?'RENIEC':'SUNAT') + '...', true);

  let raw = '';
  try {
   const r = await fetch(API + '?doc=' + encodeURIComponent(doc), {
    credentials: 'same-origin',
    headers: {'Accept':'application/json'}
   });
   raw = await r.text();
   let j;
   try { j = JSON.parse(raw); }
   catch (e) {
    console.error('Respuesta no-JSON del proxy:', raw);
    setMsg('El servidor no devolvió JSON (HTTP '+r.status+'). Revisa la consola del navegador.', false);
    return;
   }
   if (!r.ok || !j.ok) {
    setMsg((j && j.msg) ? j.msg : ('Error HTTP '+r.status), false);
    return;
   }
   if (j.tipo === 'dni') {
    document.querySelector('input[name=nombres]').value          = j.data.nombres || '';
    document.querySelector('input[name=apellido_paterno]').value = j.data.apellido_paterno || '';
    document.querySelector('input[name=apellido_materno]').value = j.data.apellido_materno || '';
    $('hidDni').value = doc;
    $('vDni').value   = doc;
    setMsg('✓ ' + [j.data.nombres, j.data.apellido_paterno, j.data.apellido_materno].filter(Boolean).join(' '), true);
   } else {
    document.querySelector('input[name=nombres]').value          = j.data.razon_social || '';
    document.querySelector('input[name=apellido_paterno]').value = '';
    document.querySelector('input[name=apellido_materno]').value = '';
    if (j.data.direccion) document.querySelector('input[name=direccion]').value = j.data.direccion;
    if (j.data.distrito)  document.querySelector('input[name=distrito]').value  = j.data.distrito;
    $('hidRuc').value = doc;
    $('vRuc').value   = doc;
    setMsg('✓ ' + j.data.razon_social + (j.data.estado ? ' · ' + j.data.estado : ''), true);
   }
  } catch (err) {
   console.error('Fetch error:', err, 'raw:', raw);
   setMsg('Error de red: ' + err.message, false);
  } finally {
   btn.disabled = false;
   btn.innerHTML = orig;
  }
 }

 btn.addEventListener('click', consultar);
})();

// Validar tamaño de archivo
function validarTamanoArchivo(input, maxMB) {
 if (input.files && input.files[0]) {
  const file = input.files[0];
  const maxBytes = maxMB * 1024 * 1024;
  if (file.size > maxBytes) {
   alert(`El archivo es muy grande. Máximo permitido: ${maxMB}MB`);
   input.value = '';
   return false;
  }
 }
 return true;
}
</script>
<?php require_once __DIR__.'/../includes/footer.php';
}
