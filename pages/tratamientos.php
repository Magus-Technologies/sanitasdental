<?php
require_once __DIR__.'/../includes/config.php';
requiereLogin();
$accion=$_GET['accion']??'catalogo'; $id=(int)($_GET['id']??0);
$hc_id=(int)($_GET['hc_id']??0); $pac_id=(int)($_GET['pac_id']??0);

if($_SERVER['REQUEST_METHOD']==='POST'){
 $ap=$_POST['accion']??'';
 if($ap==='guardar_trat'){
  $ei=(int)($_POST['id']??0);
  $d=['categoria_id'=>$_POST['categoria_id']?:null,'codigo'=>trim($_POST['codigo']??''),'nombre'=>trim($_POST['nombre']??''),'descripcion'=>trim($_POST['descripcion']??''),'precio_base'=>(float)$_POST['precio_base'],'duracion_min'=>(int)$_POST['duracion_min'],'activo'=>isset($_POST['activo'])?1:0];
  if($ei){$sets=implode(',',array_map(fn($k)=>"$k=?",array_keys($d)));db()->prepare("UPDATE tratamientos_catalogo SET $sets WHERE id=?")->execute([...array_values($d),$ei]);}
  else{$cols=implode(',',array_keys($d));$phs=implode(',',array_fill(0,count($d),'?'));db()->prepare("INSERT INTO tratamientos_catalogo($cols)VALUES($phs)")->execute(array_values($d));}
  flash('ok','Tratamiento guardado.'); go("pages/tratamientos.php");
 }
 if($ap==='guardar_plan'){
  $pid_plan=(int)($_POST['plan_id']??0);
  $hcId=(int)$_POST['hc_id']; $pacId=(int)$_POST['pac_id'];
  if(!$pid_plan){
   db()->prepare("INSERT INTO planes_tratamiento(hc_id,paciente_id,doctor_id,fecha,estado,notas)VALUES(?,?,?,CURDATE(),'borrador',?)")->execute([$hcId,$pacId,$_SESSION['uid'],trim($_POST['notas']??'')]);
   $pid_plan=db()->lastInsertId();
  } else {
   db()->prepare("UPDATE planes_tratamiento SET notas=?,updated_at=NOW() WHERE id=? LIMIT 1 ")->execute([trim($_POST['notas']??''),$pid_plan]);
   db()->prepare("DELETE FROM plan_detalles WHERE plan_id=?")->execute([$pid_plan]);
  }
  $nombrs=$_POST['trat_nombre']??[]; $diets=$_POST['trat_diente']??[]; $pxs=$_POST['trat_precio']??[]; $sesns=$_POST['trat_sesiones']??[]; $tids=$_POST['trat_id']??[]; $ords=$_POST['trat_orden']??[];
  $total=0;
  foreach($nombrs as $i=>$nm){
   if(!trim($nm)) continue;
   $px=(float)($pxs[$i]??0); $total+=$px;
   db()->prepare("INSERT INTO plan_detalles(plan_id,tratamiento_id,nombre_tratamiento,diente,precio,sesiones_total,estado,orden)VALUES(?,?,?,?,?,?,?,?)")->execute([$pid_plan,$tids[$i]?:null,trim($nm),trim($diets[$i]??''),$px,max(1,(int)($sesns[$i]??1)),'pendiente',(int)($ords[$i]??($i+1))]);
  }
  db()->prepare("UPDATE planes_tratamiento SET total=? WHERE id=?")->execute([$total,$pid_plan]);
  auditar('PLAN_TRATAMIENTO','planes_tratamiento',$pid_plan);
  flash('ok','Plan guardado. Total: '.mon($total));
  go("pages/historia_clinica.php?id=$hcId#tpl");
 }
 if($ap==='aprobar'){
  $pid=(int)$_POST['plan_id']; db()->prepare("UPDATE planes_tratamiento SET estado='aprobado',aprobado_at=NOW() WHERE id=?")->execute([$pid]);
  flash('ok','Plan aprobado.');
  $hci=db()->query("SELECT hc_id FROM planes_tratamiento WHERE id=$pid")->fetchColumn();
  go("pages/historia_clinica.php?id=$hci#tpl");
 }
 if($ap==='eliminar_trat'){
  $tid=(int)$_POST['id'];
  // Soft delete: marca como inactivo (preserva integridad referencial con planes existentes)
  db()->prepare("UPDATE tratamientos_catalogo SET activo=0 WHERE id=?")->execute([$tid]);
  auditar('ELIMINAR_TRATAMIENTO','tratamientos_catalogo',$tid);
  flash('ok','Tratamiento eliminado del catálogo.');
  go('pages/tratamientos.php');
 }
 // ── Categorías ──────────────────────────────────────────────────
 if($ap==='guardar_cat'){
  $cid=(int)($_POST['cat_id']??0);
  $nom=trim($_POST['cat_nombre']??'');
  $col=trim($_POST['cat_color']??'#00D4EE');
  if(!$nom){ flash('error','Nombre de categoría requerido.'); go('pages/tratamientos.php?accion=categorias'); }
  if($cid){
   db()->prepare("UPDATE categorias_tratamiento SET nombre=?,color=? WHERE id=?")->execute([$nom,$col,$cid]);
   auditar('EDITAR_CATEGORIA','categorias_tratamiento',$cid);
   flash('ok','Categoría actualizada.');
  } else {
   db()->prepare("INSERT INTO categorias_tratamiento(nombre,color)VALUES(?,?)")->execute([$nom,$col]);
   $nid=db()->lastInsertId();
   auditar('CREAR_CATEGORIA','categorias_tratamiento',$nid);
   flash('ok','Categoría creada.');
  }
  go('pages/tratamientos.php?accion=categorias');
 }
 if($ap==='eliminar_cat'){
  $cid=(int)($_POST['cat_id']??0);
  $cnt=db()->prepare("SELECT COUNT(*) FROM tratamientos_catalogo WHERE categoria_id=? AND activo=1");
  $cnt->execute([$cid]); $cnt=$cnt->fetchColumn();
  if($cnt>0){ flash('error',"No se puede eliminar: tiene $cnt tratamiento(s) asociado(s)."); go('pages/tratamientos.php?accion=categorias'); }
  db()->prepare("DELETE FROM categorias_tratamiento WHERE id=?")->execute([$cid]);
  auditar('ELIMINAR_CATEGORIA','categorias_tratamiento',$cid);
  flash('ok','Categoría eliminada.');
  go('pages/tratamientos.php?accion=categorias');
 }
}

if($accion==='catalogo'){
 $titulo='Catálogo de Tratamientos'; $pagina_activa='trat';
 $cats=db()->query("SELECT * FROM categorias_tratamiento ORDER BY nombre")->fetchAll();
 $cat_sel=(int)($_GET['cat']??0);
 $w='WHERE t.activo=1'; $pm=[];
 if($cat_sel){$w.=' AND t.categoria_id=?';$pm[]=$cat_sel;}
 $trats=db()->prepare("SELECT t.*,c.nombre AS cat_nm,c.color AS cat_col FROM tratamientos_catalogo t LEFT JOIN categorias_tratamiento c ON t.categoria_id=c.id $w ORDER BY c.nombre,t.nombre");
 $trats->execute($pm); $trats=$trats->fetchAll();
 $topbar_act='<a href="?accion=nuevo_trat" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nuevo tratamiento</a> <a href="?accion=categorias" class="btn btn-dk btn-sm ms-1"><i class="bi bi-tags me-1"></i>Gestionar categorías</a>';
 require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex gap-2 flex-wrap mb-4">
 <a href="?" class="btn btn-sm <?=!$cat_sel?'btn-primary':'btn-dk'?>">Todos</a>
 <?php foreach($cats as $c): ?>
 <a href="?cat=<?=$c['id']?>" class="btn btn-sm <?=$cat_sel==$c['id']?'btn-primary':'btn-dk'?>" style="<?=$cat_sel==$c['id']?'':'border-left:3px solid '.$c['color']?>"><?=e($c['nombre'])?></a>
 <?php endforeach; ?>
</div>
<div class="card"><div class="table-responsive"><table class="table mb-0">
 <thead><tr><th>Código</th><th>Tratamiento</th><th>Categoría</th><th>Precio base</th><th>Duración</th><th></th></tr></thead>
 <tbody>
 <?php foreach($trats as $t): ?>
 <tr>
  <td class="mon" style="color:var(--c);font-size:11px"><?=e($t['codigo']??'—')?></td>
  <td><strong><?=e($t['nombre'])?></strong><?php if($t['descripcion']): ?><br><small><?=e(mb_substr($t['descripcion'],0,50))?></small><?php endif; ?></td>
  <td><span class="badge" style="background:<?=$t['cat_col']??'#607080'?>22;color:<?=$t['cat_col']??'#A0B0C0'?>;border:1px solid <?=$t['cat_col']??'#607080'?>44"><?=e($t['cat_nm']??'—')?></span></td>
  <td class="mon fw-bold"><?=mon((float)$t['precio_base'])?></td>
  <td><small><?=$t['duracion_min']?> min</small></td>
  <td>
   <div class="d-flex gap-1">
    <a href="?accion=editar_trat&id=<?=$t['id']?>" class="btn btn-dk btn-ico" title="Editar"><i class="bi bi-pencil"></i></a>
    <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar el tratamiento &quot;<?=e($t['nombre'])?>&quot; del catálogo?\n\nQuedará oculto pero los planes que lo usan seguirán funcionando.')">
     <input type="hidden" name="accion" value="eliminar_trat">
     <input type="hidden" name="id" value="<?=$t['id']?>">
     <button type="submit" class="btn btn-del btn-ico" title="Eliminar"><i class="bi bi-trash"></i></button>
    </form>
   </div>
  </td>
 </tr>
 <?php endforeach; ?>
 </tbody>
</table></div></div>
<?php require_once __DIR__.'/../includes/footer.php';

}elseif(in_array($accion,['nuevo_trat','editar_trat'])){
 $t=['id'=>0,'categoria_id'=>'','codigo'=>'','nombre'=>'','descripcion'=>'','precio_base'=>0,'duracion_min'=>60,'activo'=>1];
 if($accion==='editar_trat'&&$id){$s=db()->prepare("SELECT * FROM tratamientos_catalogo WHERE id=?");$s->execute([$id]);$t=$s->fetch()?:$t;}
 $cats=db()->query("SELECT * FROM categorias_tratamiento ORDER BY nombre")->fetchAll();
 $titulo=$accion==='nuevo_trat'?'Nuevo Tratamiento':'Editar Tratamiento'; $pagina_activa='trat';
 require_once __DIR__.'/../includes/header.php';
?>
<div class="row justify-content-center"><div class="col-12 col-lg-7">
<form method="POST">
 <input type="hidden" name="accion" value="guardar_trat"><input type="hidden" name="id" value="<?=$t['id']?>">
 <div class="card mb-4"><div class="card-header"><span style="color:var(--t)">💊 Datos del tratamiento</span></div>
 <div class="p-4"><div class="row g-3">
  <div class="col-12 col-md-4"><label class="form-label">Código</label><input type="text" name="codigo" class="form-control" value="<?=e($t['codigo']??'')?>" placeholder="R001"></div>
  <div class="col-12 col-md-8"><label class="form-label">Nombre del tratamiento *</label><input type="text" name="nombre" class="form-control" value="<?=e($t['nombre'])?>" required></div>
  <div class="col-12"><label class="form-label">Categoría</label>
  <select name="categoria_id" class="form-select"><option value="">— Sin categoría —</option>
  <?php foreach($cats as $c): ?><option value="<?=$c['id']?>" <?=$t['categoria_id']==$c['id']?'selected':''?>><?=e($c['nombre'])?></option><?php endforeach; ?></select></div>
  <div class="col-12"><label class="form-label">Descripción</label><textarea name="descripcion" class="form-control" rows="2"><?=e($t['descripcion']??'')?></textarea></div>
  <div class="col-12 col-md-4"><label class="form-label">Precio base (S/) *</label><input type="number" name="precio_base" class="form-control" value="<?=$t['precio_base']?>" step="0.01" min="0" required></div>
  <div class="col-12 col-md-4"><label class="form-label">Duración (min)</label><input type="number" name="duracion_min" class="form-control" value="<?=$t['duracion_min']?>" min="5"></div>
  <div class="col-12 col-md-4"><div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="activo" id="ckAct" <?=$t['activo']?'checked':''?>><label class="form-check-label" for="ckAct">Activo</label></div></div>
 </div></div></div>
 <div class="d-flex gap-2 justify-content-end">
  <a href="?" class="btn btn-dk">Cancelar</a>
  <button type="submit" class="btn btn-primary px-4"><i class="bi bi-floppy me-2"></i>Guardar</button>
 </div>
</form></div></div>
<?php require_once __DIR__.'/../includes/footer.php';

}elseif($accion==='categorias'){
 $titulo='Categorías de Tratamiento'; $pagina_activa='trat';
 $cats=db()->query("SELECT c.*,(SELECT COUNT(*) FROM tratamientos_catalogo WHERE categoria_id=c.id AND activo=1) AS total FROM categorias_tratamiento c ORDER BY c.nombre")->fetchAll();
 $catEdit=null;
 $editId=(int)($_GET['editar']??0);
 if($editId){ foreach($cats as $c){ if($c['id']===$editId){$catEdit=$c;break;} } }
 $topbar_act='<a href="?accion=catalogo" class="btn btn-dk btn-sm"><i class="bi bi-arrow-left me-1"></i>Catálogo</a>';
 require_once __DIR__.'/../includes/header.php';
?>
<style>
.cat-grid  { display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-bottom:20px; }
.cat-card  { background:var(--bg2);border:1px solid var(--bd2);border-radius:10px;padding:14px 16px;
              display:flex;align-items:center;gap:12px;transition:border-color .15s; }
.cat-card:hover{ border-color:rgba(255,255,255,.15); }
.cat-swatch{ width:38px;height:38px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;
              justify-content:center;font-size:16px; }
.cat-info  { flex:1;min-width:0; }
.cat-name  { font-size:13px;font-weight:700;color:var(--t); }
.cat-count { font-size:11px;color:var(--t2); }
.cat-actions{ display:flex;gap:4px;flex-shrink:0; }
.form-card { background:var(--bg2);border:1px solid var(--bd2);border-radius:10px;padding:20px;margin-bottom:16px; }
.form-card h3{ font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--t);margin-bottom:14px; }
.color-presets{ display:flex;flex-wrap:wrap;gap:6px;margin-top:6px; }
.color-preset { width:24px;height:24px;border-radius:5px;cursor:pointer;border:2px solid transparent;
                 transition:all .12s;flex-shrink:0; }
.color-preset:hover,.color-preset.sel{ border-color:#fff;transform:scale(1.15); }
@media(max-width:600px){ .cat-grid{ grid-template-columns:1fr; } }
</style>

<div class="pb">
  <?=popFlash()?>
  <div class="row g-4">

    <!-- ── Lista de categorías ──────────────────────────── -->
    <div class="col-12 col-lg-8">
      <div class="cat-grid">
        <?php foreach($cats as $c): ?>
        <div class="cat-card">
          <div class="cat-swatch" style="background:<?=$c['color']?>22;border:1px solid <?=$c['color']?>44">
            <span style="color:<?=$c['color']?>;font-weight:800;font-size:13px"><?=strtoupper(substr($c['nombre'],0,2))?></span>
          </div>
          <div class="cat-info">
            <div class="cat-name"><?=e($c['nombre'])?></div>
            <div class="cat-count"><?=$c['total']?> tratamiento(s) activo(s)</div>
          </div>
          <div class="cat-actions">
            <a href="?accion=categorias&editar=<?=$c['id']?>" class="btn btn-dk btn-ico btn-sm" title="Editar"><i class="bi bi-pencil"></i></a>
            <?php if($c['total']==0): ?>
            <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar la categoría <?=e(addslashes($c['nombre']))?> ?')">
              <input type="hidden" name="accion"  value="eliminar_cat">
              <input type="hidden" name="cat_id"  value="<?=$c['id']?>">
              <button type="submit" class="btn btn-del btn-ico btn-sm" title="Eliminar"><i class="bi bi-trash"></i></button>
            </form>
            <?php else: ?>
            <button class="btn btn-dk btn-ico btn-sm" disabled title="Tiene tratamientos — no se puede eliminar"><i class="bi bi-trash" style="opacity:.35"></i></button>
            <?php endif;?>
          </div>
        </div>
        <?php endforeach;?>
        <?php if(!$cats): ?>
        <div style="color:var(--t2);font-size:13px;padding:20px">No hay categorías aún.</div>
        <?php endif;?>
      </div>
    </div>

    <!-- ── Formulario agregar / editar ─────────────────── -->
    <div class="col-12 col-lg-4">
      <div class="form-card">
        <h3><?=$catEdit?'✏️ Editar categoría':'➕ Nueva categoría'?></h3>
        <form method="POST">
          <input type="hidden" name="accion"  value="guardar_cat">
          <input type="hidden" name="cat_id"  value="<?=$catEdit?$catEdit['id']:0?>">
          <div class="mb-3">
            <label class="form-label">Nombre *</label>
            <input type="text" name="cat_nombre" class="form-control" required
                   value="<?=e($catEdit?$catEdit['nombre']:'')?>"
                   placeholder="ej. Implantología">
          </div>
          <div class="mb-3">
            <label class="form-label">Color</label>
            <div class="d-flex align-items-center gap-2">
              <input type="color" name="cat_color" id="catColor" class="form-control form-control-color"
                     value="<?=$catEdit?$catEdit['color']:'#00D4EE'?>"
                     style="width:48px;height:38px;padding:2px;cursor:pointer">
              <span id="catColorHex" style="font-size:12px;color:var(--t2);font-family:monospace"><?=$catEdit?$catEdit['color']:'#00D4EE'?></span>
            </div>
            <div class="color-presets mt-2">
              <?php foreach(['#00D4EE','#2ECC8E','#E05252','#F5A623','#8B5CF6','#EC4899','#6366F1','#F59E0B','#06B6D4','#10B981','#EF4444','#3B82F6','#A78BFA','#FB923C','#34D399','#F472B6'] as $pc): ?>
              <div class="color-preset <?=$catEdit&&$catEdit['color']===$pc?'sel':''?>"
                   style="background:<?=$pc?>"
                   onclick="document.getElementById('catColor').value='<?=$pc?>';document.getElementById('catColorHex').textContent='<?=$pc?>';document.querySelectorAll('.color-preset').forEach(x=>x.classList.remove('sel'));this.classList.add('sel')">
              </div>
              <?php endforeach;?>
            </div>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-grow-1">
              <i class="bi bi-floppy me-1"></i><?=$catEdit?'Actualizar':'Crear categoría'?>
            </button>
            <?php if($catEdit): ?>
            <a href="?accion=categorias" class="btn btn-dk">Cancelar</a>
            <?php endif;?>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<script>
document.getElementById('catColor').addEventListener('input',function(){
  document.getElementById('catColorHex').textContent = this.value;
  document.querySelectorAll('.color-preset').forEach(x=>x.classList.remove('sel'));
});
</script>
<?php
 require_once __DIR__.'/../includes/footer.php';

}elseif($accion==='plan'){
 if(!$hc_id||!$pac_id){flash('error','Parámetros inválidos');go('pages/tratamientos.php');}
 $pac_d=db()->prepare("SELECT CONCAT(nombres,' ',apellido_paterno) AS nm FROM pacientes WHERE id=?"); $pac_d->execute([$pac_id]); $pac_nm=$pac_d->fetchColumn();
 $plan_ex=db()->prepare("SELECT * FROM planes_tratamiento WHERE hc_id=? ORDER BY created_at DESC LIMIT 1"); $plan_ex->execute([$hc_id]); $plan_ex=$plan_ex->fetch();
 $det_ex=[];
 if($plan_ex){$s=db()->prepare("SELECT * FROM plan_detalles WHERE plan_id=? ORDER BY orden");$s->execute([$plan_ex['id']]);$det_ex=$s->fetchAll();}
 $trats=db()->query("SELECT t.id,t.nombre,t.precio_base,c.nombre AS cat FROM tratamientos_catalogo t LEFT JOIN categorias_tratamiento c ON t.categoria_id=c.id WHERE t.activo=1 ORDER BY c.nombre,t.nombre")->fetchAll();
 $titulo='Plan de Tratamiento — '.$pac_nm; $pagina_activa='trat';
 require_once __DIR__.'/../includes/header.php';
?>
<form method="POST" id="fPlan">
 <input type="hidden" name="accion" value="guardar_plan">
 <input type="hidden" name="hc_id" value="<?=$hc_id?>">
 <input type="hidden" name="pac_id" value="<?=$pac_id?>">
 <input type="hidden" name="plan_id" value="<?=$plan_ex['id']??0?>">
<div class="row g-4">
 <div class="col-12 col-lg-8">
  <div class="card mb-4">
   <div class="card-header"><span style="color:var(--t)">💊 Líneas de tratamiento</span>
   <button type="button" class="btn btn-primary btn-sm" onclick="addRow()">+ Agregar tratamiento</button></div>
   <div class="p-4">
    <div class="table-responsive"><table class="table mb-0" id="tblPlan">
     <thead><tr><th>#</th><th>Tratamiento</th><th>Diente</th><th>Precio S/</th><th>Sesiones</th><th></th></tr></thead>
     <tbody id="tbPlan">
     <?php if($det_ex): foreach($det_ex as $i=>$det): ?>
     <tr>
      <td><input type="hidden" name="trat_orden[]" value="<?=$i+1?>"><?=$i+1?></td>
      <td><input type="hidden" name="trat_id[]" value="<?=$det['tratamiento_id']??''?>"><input type="text" name="trat_nombre[]" class="form-control form-control-sm" value="<?=e($det['nombre_tratamiento'])?>" required></td>
      <td><input type="text" name="trat_diente[]" class="form-control form-control-sm" value="<?=e($det['diente']??'')?>" style="width:70px" placeholder="11"></td>
      <td><input type="number" name="trat_precio[]" class="form-control form-control-sm precio-inp" value="<?=$det['precio']?>" step="0.01" min="0" style="width:90px" oninput="calcTotal()"></td>
      <td><input type="number" name="trat_sesiones[]" class="form-control form-control-sm" value="<?=$det['sesiones_total']?>" min="1" style="width:60px"></td>
      <td><button type="button" class="btn btn-del btn-ico btn-sm" onclick="this.closest('tr').remove();calcTotal()"><i class="bi bi-trash"></i></button></td>
     </tr>
     <?php endforeach; else: ?>
     <tr id="emptyRow"><td colspan="6" class="text-center py-3" style="color:var(--t2)">Agrega tratamientos usando el catálogo →</td></tr>
     <?php endif; ?>
     </tbody>
    </table></div>
    <div class="text-end mt-3 p-3" style="background:var(--bg3);border-radius:8px;border:1px solid var(--bd)">
     <span style="font-size:14px;color:var(--t2)">TOTAL PRESUPUESTO:</span>
     <span class="mon fw-bold" style="font-size:24px;color:var(--c);margin-left:12px" id="totalLbl"><?=mon((float)($plan_ex['total']??0))?></span>
    </div>
   </div>
  </div>
  <div class="card mb-4"><div class="card-header"><span style="color:var(--t)">📝 Notas del plan</span></div>
  <div class="p-4"><textarea name="notas" class="form-control" rows="3"><?=e($plan_ex['notas']??'')?></textarea></div></div>
  <div class="d-flex gap-2 justify-content-end">
   <a href="<?=BASE_URL?>/pages/historia_clinica.php?id=<?=$hc_id?>" class="btn btn-dk">Cancelar</a>
   <button type="submit" class="btn btn-primary px-4"><i class="bi bi-floppy me-2"></i>Guardar plan</button>
  </div>
 </div>
 <div class="col-12 col-lg-4">
  <div class="card" style="position:sticky;top:70px">
   <div class="card-header"><span style="color:var(--t)">📚 Catálogo rápido</span></div>
   <div style="max-height:500px;overflow-y:auto">
    <?php $cat_curr=''; foreach($trats as $t):
     if($cat_curr!==$t['cat']){$cat_curr=$t['cat']; echo "<div class='sb-sec'>".$t['cat']."</div>"; }
    ?>
    <div class="d-flex justify-content-between align-items-center px-3 py-2" style="border-bottom:1px solid var(--bd2);cursor:pointer;hover:background:rgba(0,212,238,.04)" onclick='addTratamiento(<?=e(json_encode(['id'=>$t['id'],'nombre'=>$t['nombre'],'precio'=>$t['precio_base']]))?> )'>
     <span style="font-size:12px"><?=e($t['nombre'])?></span>
     <span class="mon" style="color:var(--c);font-size:11px"><?=mon((float)$t['precio_base'])?></span>
    </div>
    <?php endforeach; ?>
   </div>
  </div>
 </div>
</div>
</form>
<?php
$xscript='<script>
let rowCount='.($det_ex?count($det_ex):0).';
function addTratamiento(t){
 addRow(t.id,t.nombre,t.precio);
}
function addRow(tid="",nm="",px=0){
 const empty=document.getElementById("emptyRow"); if(empty)empty.remove();
 rowCount++;
 const tr=document.createElement("tr");
 tr.innerHTML=`<td><input type="hidden" name="trat_orden[]" value="${rowCount}">${rowCount}</td>
  <td><input type="hidden" name="trat_id[]" value="${tid}"><input type="text" name="trat_nombre[]" class="form-control form-control-sm" value="${nm}" required></td>
  <td><input type="text" name="trat_diente[]" class="form-control form-control-sm" style="width:70px" placeholder="11"></td>
  <td><input type="number" name="trat_precio[]" class="form-control form-control-sm precio-inp" value="${px}" step="0.01" min="0" style="width:90px" oninput="calcTotal()"></td>
  <td><input type="number" name="trat_sesiones[]" class="form-control form-control-sm" value="1" min="1" style="width:60px"></td>
  <td><button type="button" class="btn btn-del btn-ico btn-sm" onclick="this.closest(\'tr\').remove();calcTotal()"><i class="bi bi-trash"></i></button></td>`;
 document.getElementById("tbPlan").appendChild(tr);
 calcTotal();
}
function calcTotal(){
 let tot=0; document.querySelectorAll(".precio-inp").forEach(i=>tot+=parseFloat(i.value)||0);
 document.getElementById("totalLbl").textContent="S/ "+tot.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,",");
}
</script>';
require_once __DIR__.'/../includes/footer.php';
}
