<?php
/**
 * ODONTOGRAMA - Seleccion de paciente
 * Lista pacientes para seleccionar y ver su odontograma
 */
require_once __DIR__ . '/../includes/config.php';
requiereLogin();
requiereModulo('odontograma');

$titulo = 'Odontograma'; $pagina_activa = 'odont';
$q = trim($_GET['q'] ?? '');
$orden = (($_GET['orden'] ?? 'asc') === 'desc') ? 'desc' : 'asc';
$pg = max(1,(int)($_GET['p'] ?? 1)); $pp = 30;

$w = 'WHERE p.activo=1'; $pm = [];
if ($q) {
    $w .= ' AND (p.nombres LIKE ? OR p.apellido_paterno LIKE ? OR p.dni LIKE ? OR p.codigo LIKE ?)';
    $b = "%$q%"; $pm = [$b,$b,$b,$b];
}

$stc = db()->prepare("SELECT COUNT(*) FROM pacientes p $w"); $stc->execute($pm); $tot=(int)$stc->fetchColumn();
$pages = max(1,ceil($tot/$pp)); $pg=min($pg,$pages); $off=($pg-1)*$pp;

$pacs = db()->prepare("
    SELECT p.id, p.codigo, p.nombres, p.apellido_paterno, p.alergias,
           COUNT(DISTINCT hc.id) as total_hc,
           MAX(hc.id) as last_hc_id,
           MAX(o.fecha) as ultimo_odont
    FROM pacientes p
    LEFT JOIN historias_clinicas hc ON hc.paciente_id = p.id
    LEFT JOIN odontogramas o ON o.paciente_id = p.id
    $w
    GROUP BY p.id
    ORDER BY CAST(REGEXP_REPLACE(p.codigo,'[^0-9]','') AS UNSIGNED) $orden, p.id $orden
    LIMIT $pp OFFSET $off
");
$pacs->execute($pm);
$pacs = $pacs->fetchAll();

$topbar_act = '';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="card mb-3 p-3">
  <form method="GET" class="d-flex gap-2 align-items-end flex-wrap">
    <div class="flex-fill" style="min-width:200px">
      <label class="form-label">Buscar paciente</label>
      <input type="text" name="q" class="form-control" placeholder="Nombre, DNI, c&oacute;digo..." value="<?=e($q)?>">
    </div>
    <input type="hidden" name="orden" value="<?=e($orden)?>">
    <button type="submit" class="btn btn-dk" style="margin-top:auto">&#128269; Buscar</button>
    <?php if($q): ?><a href="?orden=<?=e($orden)?>" class="btn btn-dk" style="margin-top:auto">&#10005;</a><?php endif; ?>
    <?php $flip=$orden==='asc'?'desc':'asc'; $qsOrden=http_build_query(array_filter(['q'=>$q,'orden'=>$flip], fn($v)=>$v!==null&&$v!=='')); ?>
    <a href="?<?=$qsOrden?>" class="btn btn-dk btn-sm" style="margin-top:auto" title="Cambiar orden por c&oacute;digo">
      <i class="bi bi-sort-numeric-<?=$orden==='asc'?'down':'up-alt'?> me-1"></i><?=$orden==='asc'?'C&oacute;digo: 1 &rarr; &uacute;ltimo':'C&oacute;digo: &uacute;ltimo &rarr; 1'?>
    </a>
  </form>
</div>

<div class="card">
  <div class="card-header">
    <span>&#129463; Selecciona un paciente para ver su Odontograma</span>
    <small style="color:var(--t2);font-weight:400"><?=count($pacs)?> paciente(s)</small>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead>
        <tr>
          <th>C&oacute;digo</th>
          <th>Paciente</th>
          <th class="d-none d-md-table-cell">Historias</th>
          <th class="d-none d-md-table-cell">&Uacute;lt. Odontograma</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($pacs as $p): ?>
      <tr>
        <td><span class="mon" style="color:var(--c);font-size:12px"><?=e($p['codigo'])?></span></td>
        <td>
          <strong style="color:var(--t)"><?=e($p['nombres'].' '.$p['apellido_paterno'])?></strong>
          <?php if($p['alergias']): ?><br><span class="badge br" style="font-size:9px">&#9888; Al&eacute;rgico</span><?php endif; ?>
        </td>
        <td class="d-none d-md-table-cell">
          <?php if($p['total_hc']): ?>
            <span class="badge bc"><?=$p['total_hc']?> HC</span>
          <?php else: ?>
            <span style="color:var(--t3);font-size:12px">Sin HC</span>
          <?php endif; ?>
        </td>
        <td class="d-none d-md-table-cell" style="color:var(--t2);font-size:12px">
          <?=$p['ultimo_odont'] ? fDate($p['ultimo_odont']) : '&mdash;'?>
        </td>
        <td>
          <?php if($p['last_hc_id']): ?>
            <a href="<?=BASE_URL?>/pages/odontograma.php?hc_id=<?=$p['last_hc_id']?>&paciente_id=<?=$p['id']?>"
               class="btn btn-primary btn-sm">&#129463; Abrir</a>
          <?php else: ?>
            <a href="<?=BASE_URL?>/pages/historia_clinica.php?paciente_id=<?=$p['id']?>"
               class="btn btn-dk btn-sm">Crear HC</a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if(!$pacs): ?>
      <tr><td colspan="5" class="text-center py-4" style="color:var(--t2)">
        <i class="bi bi-people" style="font-size:32px;display:block;margin-bottom:8px"></i>
        No se encontraron pacientes
      </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if($pages>1): ?>
<nav class="mt-3 d-flex justify-content-end"><ul class="pagination pagination-sm">
 <?php for($i=1;$i<=$pages;$i++): ?>
 <li class="page-item <?=$i===$pg?'active':''?>"><a class="page-link" href="?<?=http_build_query(array_filter(['q'=>$q,'orden'=>$orden,'p'=>$i], fn($v)=>$v!==null&&$v!==''))?>"><?=$i?></a></li>
 <?php endfor; ?>
</ul></nav>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php';
