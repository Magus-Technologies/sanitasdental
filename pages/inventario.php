<?php
require_once __DIR__.'/../includes/config.php';
requiereLogin();
$accion=$_GET['accion']??'lista'; $id=(int)($_GET['id']??0);

if($_SERVER['REQUEST_METHOD']==='POST'){
 $ap=$_POST['accion']??'';
 if($ap==='guardar'){
  $ei=(int)($_POST['id']??0);
  $d=['categoria_id'=>$_POST['categoria_id']?:null,'codigo'=>trim($_POST['codigo']??''),'nombre'=>trim($_POST['nombre']??''),'descripcion'=>trim($_POST['descripcion']??''),'unidad'=>trim($_POST['unidad']??'unidad'),'stock_minimo'=>(float)$_POST['stock_minimo'],'precio_costo'=>(float)$_POST['precio_costo'],'proveedor'=>trim($_POST['proveedor']??''),'activo'=>isset($_POST['activo'])?1:0];
  if($ei){$sets=implode(',',array_map(fn($k)=>"$k=?",array_keys($d)));db()->prepare("UPDATE inventario SET $sets,updated_at=NOW() WHERE id=?")->execute([...array_values($d),$ei]);flash('ok','Producto actualizado.');}
  else{$cols=implode(',',array_keys($d));$phs=implode(',',array_fill(0,count($d),'?'));db()->prepare("INSERT INTO inventario($cols)VALUES($phs)")->execute(array_values($d));flash('ok','Producto creado.');}
  go('pages/inventario.php');
 }
 if($ap==='movimiento'){
  $pid=(int)$_POST['producto_id']; $tipo=$_POST['tipo']; $cant=(float)$_POST['cantidad'];
  $st=db()->prepare("SELECT stock_actual FROM inventario WHERE id=?"); $st->execute([$pid]); $sa=(float)$st->fetchColumn();
  $nd=$tipo==='entrada'?$sa+$cant:($tipo==='salida'?$sa-$cant:$cant);
  db()->prepare("UPDATE inventario SET stock_actual=?,updated_at=NOW() WHERE id=?")->execute([$nd,$pid]);
  db()->prepare("INSERT INTO inventario_movimientos(producto_id,tipo,cantidad,stock_antes,stock_despues,motivo,usuario_id)VALUES(?,?,?,?,?,?,?)")->execute([$pid,$tipo,$cant,$sa,$nd,trim($_POST['motivo']??''),$_SESSION['uid']]);
  // Registrar lote si es entrada
  if($tipo==='entrada'&&!empty($_POST['lote'])){
   db()->prepare("INSERT INTO inventario_lotes(producto_id,lote,fecha_venc,cantidad,precio_costo)VALUES(?,?,?,?,?)")->execute([$pid,trim($_POST['lote']),$_POST['fecha_venc']?:null,$cant,(float)($_POST['precio_lote']??0)]);
  }
  auditar('MOVIMIENTO_INV','inventario',$pid,$tipo.':'.$cant);
  flash('ok','Movimiento registrado. Stock nuevo: '.$nd.' '.db()->query("SELECT unidad FROM inventario WHERE id=$pid")->fetchColumn());
  go("pages/inventario.php?accion=ver&id=$pid");
 }
}

$cats=db()->query("SELECT * FROM inventario_categorias ORDER BY nombre")->fetchAll();

if($accion==='lista'){
 $titulo='Inventario'; $pagina_activa='inv';
 $q=trim($_GET['q']??''); $cat_f=(int)($_GET['cat']??0); $alerta=$_GET['alerta']??'';
 $w='WHERE i.activo=1'; $pm=[];
 if($q){$w.=' AND(i.nombre LIKE ? OR i.codigo LIKE ?)';$b="%$q%";$pm[]=$b;$pm[]=$b;}
 if($cat_f){$w.=' AND i.categoria_id=?';$pm[]=$cat_f;}
 if($alerta==='bajo') $w.=' AND i.stock_actual<=i.stock_minimo';
 $st=db()->prepare("SELECT i.*,c.nombre AS cat FROM inventario i LEFT JOIN inventario_categorias c ON i.categoria_id=c.id $w ORDER BY i.nombre");
 $st->execute($pm); $lista=$st->fetchAll();
 $total_items=db()->query("SELECT COUNT(*) FROM inventario WHERE activo=1")->fetchColumn();
 $stock_bajo=db()->query("SELECT COUNT(*) FROM inventario WHERE stock_actual<=stock_minimo AND activo=1")->fetchColumn();
 $topbar_act='<a href="?accion=nuevo" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nuevo producto</a>';
 require_once __DIR__.'/../includes/header.php';
?>
<div class="row g-3 mb-4">
 <div class="col-6 col-md-3"><div class="kpi kc"><div class="kpi-ico"><i class="bi bi-box-seam-fill"></i></div><div class="kpi-v"><?=$total_items?></div><div class="kpi-l">Productos</div><div class="kpi-s"></div></div></div>
 <div class="col-6 col-md-3"><div class="kpi kr"><div class="kpi-ico"><i class="bi bi-exclamation-triangle-fill"></i></div><div class="kpi-v"><?=$stock_bajo?></div><div class="kpi-l">Stock bajo</div><div class="kpi-s"></div></div></div>
</div>
<?php if($stock_bajo>0): ?>
<div class="alert-bar alert-bar-r mb-4"><div class="d-flex align-items-center gap-2"><span>⚠️</span><strong style="color:var(--r)"><?=$stock_bajo?> producto<?=$stock_bajo>1?'s':''?> con stock bajo el mínimo</strong></div>
<a href="?alerta=bajo" class="btn btn-del btn-sm">Ver solo estos</a></div>
<?php endif; ?>
<div class="card mb-3 p-3"><form method="GET" class="d-flex gap-2 flex-wrap">
 <div class="flex-fill" style="min-width:180px"><input type="text" name="q" class="form-control" placeholder="Nombre o código..." value="<?=e($q)?>"></div>
 <select name="cat" class="form-select" style="width:160px"><option value="">Todas las categorías</option>
 <?php foreach($cats as $c): ?><option value="<?=$c['id']?>" <?=$cat_f==$c['id']?'selected':''?>><?=e($c['nombre'])?></option><?php endforeach; ?></select>
 <button type="submit" class="btn btn-dk">Buscar</button>
 <?php if($q||$cat_f||$alerta): ?><a href="?" class="btn btn-dk">✕</a><?php endif; ?>
</form></div>
<div class="card"><div class="table-responsive"><table class="table mb-0">
 <thead><tr><th class="d-none d-md-table-cell">Código</th><th>Producto</th><th class="d-none d-lg-table-cell">Categoría</th><th>Stock actual</th><th class="d-none d-md-table-cell">Mínimo</th><th>Estado</th><th></th></tr></thead>
 <tbody>
 <?php foreach($lista as $it): $bajo=$it['stock_actual']<=$it['stock_minimo']; ?>
 <tr>
  <td class="mon d-none d-md-table-cell" style="color:var(--c);font-size:11px"><?=e($it['codigo']??'—')?></td>
  <td><strong><?=e($it['nombre'])?></strong><?php if($it['descripcion']): ?><br><small><?=e(mb_substr($it['descripcion'],0,45))?></small><?php endif; ?></td>
  <td class="d-none d-lg-table-cell"><small><?=e($it['cat']??'—')?></small></td>
  <td><span class="mon fw-bold <?=$bajo?'':'?'?>" style="color:<?=$bajo?'var(--r)':'var(--t)'?>"><?=$it['stock_actual']?> <?=e($it['unidad'])?></span></td>
  <td class="d-none d-md-table-cell"><small style="color:var(--t2)"><?=$it['stock_minimo']?> <?=e($it['unidad'])?></small></td>
  <td><span class="badge <?=$bajo?'br':'bg'?>"><?=$bajo?'⚠ BAJO':'OK'?></span></td>
  <td><div class="d-flex gap-1">
   <a href="?accion=ver&id=<?=$it['id']?>" class="btn btn-dk btn-ico"><i class="bi bi-eye"></i></a>
   <a href="?accion=editar&id=<?=$it['id']?>" class="btn btn-dk btn-ico"><i class="bi bi-pencil"></i></a>
  </div></td>
 </tr>
 <?php endforeach; if(!$lista): ?>
 <tr><td colspan="7" class="text-center py-4" style="color:var(--t2)">No se encontraron productos</td></tr>
 <?php endif; ?></tbody>
</table></div></div>
<?php require_once __DIR__.'/../includes/footer.php';

}elseif($accion==='ver'&&$id){
 $st=db()->prepare("SELECT i.*,c.nombre AS cat FROM inventario i LEFT JOIN inventario_categorias c ON i.categoria_id=c.id WHERE i.id=?");
 $st->execute([$id]); $it=$st->fetch(); if(!$it){flash('error','Producto no encontrado');go('pages/inventario.php');}
 $movs=db()->prepare("SELECT m.*,CONCAT(u.nombre,' ',u.apellidos) AS usr FROM inventario_movimientos m LEFT JOIN usuarios u ON m.usuario_id=u.id WHERE m.producto_id=? ORDER BY m.created_at DESC LIMIT 20");
 $movs->execute([$id]); $movs=$movs->fetchAll();
 $lotes=db()->prepare("SELECT * FROM inventario_lotes WHERE producto_id=? ORDER BY fecha_venc ASC"); $lotes->execute([$id]); $lotes=$lotes->fetchAll();
 $titulo=$it['nombre']; $pagina_activa='inv';
 $bajo=$it['stock_actual']<=$it['stock_minimo'];
 require_once __DIR__.'/../includes/header.php';
?>
<div class="row g-4">
 <div class="col-12 col-lg-4">
  <div class="card mb-4">
   <div class="card-header"><span style="color:var(--t)">📦 Info del producto</span></div>
   <div class="p-4" style="font-size:13px">
    <div class="text-center mb-3">
     <div style="font-size:40px">📦</div>
     <h3 style="font-size:16px;font-weight:800;margin:8px 0"><?=e($it['nombre'])?></h3>
     <span class="badge bgr"><?=e($it['cat']??'Sin categoría')?></span>
    </div>
    <div class="text-center p-3 rounded mb-3" style="background:<?=$bajo?'rgba(224,82,82,.1)':'rgba(46,204,142,.08)'?>;border:1px solid <?=$bajo?'rgba(224,82,82,.3)':'rgba(46,204,142,.2)'?>">
     <div class="mon fw-bold" style="font-size:32px;color:<?=$bajo?'var(--r)':'var(--g)'?>"><?=$it['stock_actual']?></div>
     <div style="font-size:11px;color:var(--t2)"><?=strtoupper($it['unidad'])?> EN STOCK</div>
     <?php if($bajo): ?><div class="badge br mt-1">⚠ STOCK BAJO (mín: <?=$it['stock_minimo']?>)</div><?php endif; ?>
    </div>
    <?php foreach([['Código',$it['codigo']??'—'],['Precio costo','S/ '.$it['precio_costo']],['Proveedor',$it['proveedor']??'—'],['Stock mínimo',$it['stock_minimo'].' '.$it['unidad']]] as[$l,$v]): ?>
    <div class="d-flex justify-content-between py-1" style="border-bottom:1px solid var(--bd2)"><span style="color:var(--t2)"><?=$l?></span><strong><?=e($v)?></strong></div>
    <?php endforeach; ?>
   </div>
  </div>
  <!-- Registrar movimiento -->
  <div class="card">
   <div class="card-header"><span style="color:var(--t)">➕ Registrar movimiento</span></div>
   <form method="POST" class="p-4">
    <input type="hidden" name="accion" value="movimiento"><input type="hidden" name="producto_id" value="<?=$id?>">
    <div class="mb-3"><label class="form-label">Tipo *</label>
    <select name="tipo" class="form-select" id="tipoMov">
     <option value="entrada">📥 Entrada (compra/recepción)</option>
     <option value="salida">📤 Salida (uso/consumo)</option>
     <option value="ajuste">🔄 Ajuste de inventario</option>
    </select></div>
    <div class="mb-3"><label class="form-label">Cantidad *</label><input type="number" name="cantidad" class="form-control" step="0.01" min="0.01" required></div>
    <div id="loteFields" class="mb-3">
     <div class="mb-2"><label class="form-label">N° Lote</label><input type="text" name="lote" class="form-control" placeholder="Ej: LOT-2024-001"></div>
     <div class="mb-2"><label class="form-label">Fecha vencimiento</label><input type="date" name="fecha_venc" class="form-control"></div>
     <div class="mb-2"><label class="form-label">Precio costo (lote)</label><div class="input-group"><span class="input-group-text">S/</span><input type="number" name="precio_lote" class="form-control" step="0.01" min="0"></div></div>
    </div>
    <div class="mb-4"><label class="form-label">Motivo / Descripción</label><input type="text" name="motivo" class="form-control" placeholder="Ej: Compra a proveedor, uso en tratamiento..."></div>
    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-floppy me-2"></i>Registrar</button>
   </form>
  </div>
 </div>
 <div class="col-12 col-lg-8">
  <!-- Lotes vigentes -->
  <?php if($lotes): ?>
  <div class="card mb-4">
   <div class="card-header"><span style="color:var(--t)">🏷️ Lotes registrados</span></div>
   <div class="table-responsive"><table class="table mb-0">
    <thead><tr><th>Lote</th><th>Vencimiento</th><th>Cantidad</th><th>Precio costo</th></tr></thead>
    <tbody>
    <?php foreach($lotes as $l): $venc=strtotime($l['fecha_venc']??'2099-01-01'); $prox=$venc<strtotime('+30 days'); ?>
    <tr>
     <td class="mon" style="font-size:12px"><?=e($l['lote']??'—')?></td>
     <td><?php if($l['fecha_venc']): ?><span class="badge <?=$prox?'br':'bg'?>"><?=fDate($l['fecha_venc'])?></span><?php else: ?>—<?php endif; ?></td>
     <td class="mon"><?=$l['cantidad']?></td>
     <td class="mon"><?=$l['precio_costo']>0?mon((float)$l['precio_costo']):'—'?></td>
    </tr>
    <?php endforeach; ?></tbody>
   </table></div>
  </div>
  <?php endif; ?>
  <!-- Kardex -->
  <div class="card">
   <div class="card-header"><span style="color:var(--t)">📋 Kardex / Movimientos</span></div>
   <div class="table-responsive"><table class="table mb-0">
    <thead><tr><th>Fecha</th><th>Tipo</th><th>Cantidad</th><th>Antes</th><th>Después</th><th>Motivo</th><th>Usuario</th></tr></thead>
    <tbody>
    <?php foreach($movs as $m): $tcl=['entrada'=>'bg','salida'=>'br','ajuste'=>'ba']; ?>
    <tr>
     <td><small><?=fDT($m['created_at'])?></small></td>
     <td><span class="badge <?=$tcl[$m['tipo']]?>"><?=strtoupper($m['tipo'])?></span></td>
     <td class="mon fw-bold" style="color:<?=$m['tipo']==='entrada'?'var(--g)':'var(--r)'?>"><?=$m['tipo']==='entrada'?'+':'-'?><?=$m['cantidad']?></td>
     <td class="mon" style="font-size:11px"><?=$m['stock_antes']?></td>
     <td class="mon" style="font-size:11px"><?=$m['stock_despues']?></td>
     <td><small><?=e($m['motivo']??'—')?></small></td>
     <td><small><?=e($m['usr']??'—')?></small></td>
    </tr>
    <?php endforeach; if(!$movs): ?><tr><td colspan="7" class="text-center py-3" style="color:var(--t2)">Sin movimientos</td></tr><?php endif; ?></tbody>
   </table></div>
  </div>
 </div>
</div>
<?php
$xscript='<script>
document.getElementById("tipoMov").addEventListener("change",function(){
 document.getElementById("loteFields").style.display=this.value==="entrada"?"block":"none";
});
</script>';
require_once __DIR__.'/../includes/footer.php';

}elseif(in_array($accion,['nuevo','editar'])){
 $it=['id'=>0,'categoria_id'=>'','codigo'=>'','nombre'=>'','descripcion'=>'','unidad'=>'unidad','stock_minimo'=>0,'precio_costo'=>0,'proveedor'=>'','activo'=>1];
 if($accion==='editar'&&$id){$s=db()->prepare("SELECT * FROM inventario WHERE id=?");$s->execute([$id]);$it=$s->fetch()?:$it;}
 $titulo=$accion==='nuevo'?'Nuevo Producto':'Editar: '.$it['nombre']; $pagina_activa='inv';
 require_once __DIR__.'/../includes/header.php';
?>
<div class="row justify-content-center"><div class="col-12 col-lg-7">
<form method="POST">
 <input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?=$it['id']?>">
 <div class="card mb-4"><div class="card-header"><span style="color:var(--t)">📦 Datos del producto</span></div>
 <div class="p-4"><div class="row g-3">
  <div class="col-12 col-md-4"><label class="form-label">Código</label><input type="text" name="codigo" class="form-control" value="<?=e($it['codigo']??'')?>" placeholder="INS001"></div>
  <div class="col-12 col-md-8"><label class="form-label">Nombre *</label><input type="text" name="nombre" class="form-control" value="<?=e($it['nombre'])?>" required></div>
  <div class="col-12"><label class="form-label">Categoría</label>
  <select name="categoria_id" class="form-select"><option value="">— Sin categoría —</option>
  <?php foreach($cats as $c): ?><option value="<?=$c['id']?>" <?=$it['categoria_id']==$c['id']?'selected':''?>><?=e($c['nombre'])?></option><?php endforeach; ?></select></div>
  <div class="col-12"><label class="form-label">Descripción</label><textarea name="descripcion" class="form-control" rows="2"><?=e($it['descripcion']??'')?></textarea></div>
  <div class="col-12 col-md-4"><label class="form-label">Unidad de medida</label>
  <select name="unidad" class="form-select">
   <?php foreach(['unidad','caja','frasco','tubo','paquete','rollo','litro','ml','gramo','kg'] as $u): ?><option value="<?=$u?>" <?=$it['unidad']===$u?'selected':''?>><?=ucfirst($u)?></option><?php endforeach; ?>
  </select></div>
  <div class="col-12 col-md-4"><label class="form-label">Stock mínimo *</label><input type="number" name="stock_minimo" class="form-control" value="<?=$it['stock_minimo']?>" step="0.01" min="0" required></div>
  <div class="col-12 col-md-4"><label class="form-label">Precio costo (S/)</label><input type="number" name="precio_costo" class="form-control" value="<?=$it['precio_costo']?>" step="0.01" min="0"></div>
  <div class="col-12"><label class="form-label">Proveedor</label><input type="text" name="proveedor" class="form-control" value="<?=e($it['proveedor']??'')?>"></div>
  <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="activo" id="ckAct" <?=$it['activo']?'checked':''?>><label class="form-check-label" for="ckAct">Activo</label></div></div>
 </div></div></div>
 <div class="d-flex gap-2 justify-content-end">
  <a href="?" class="btn btn-dk">Cancelar</a>
  <button type="submit" class="btn btn-primary px-4"><i class="bi bi-floppy me-2"></i>Guardar</button>
 </div>
</form></div></div>
<?php require_once __DIR__.'/../includes/footer.php';
}
