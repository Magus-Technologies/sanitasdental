<?php
require_once __DIR__.'/../../includes/config.php';
requiereRol('admin');

$titulo = 'Series y Correlativos';
$pagina_activa = 'docs';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ap = $_POST['accion'] ?? '';

    if ($ap === 'nuevo') {
        $tipo  = $_POST['tipo'] ?? '';
        $serie = strtoupper(trim($_POST['serie'] ?? ''));
        $num   = max(0, (int)($_POST['numero'] ?? 0));
        $desc  = trim($_POST['descripcion'] ?? '');

        $tiposVal = ['boleta','factura','nota_venta','nota_credito','ticket'];
        if (!in_array($tipo, $tiposVal, true)) { flash('error','Tipo inválido.'); go('pages/admin/documentos.php'); }
        if (!preg_match('/^[A-Z0-9]{2,4}$/', $serie)) { flash('error','La serie debe tener 2 a 4 letras o números (ej. B001, F001, NV01).'); go('pages/admin/documentos.php'); }

        try {
            db()->prepare("INSERT INTO documentos_empresa (empresa_id, tipo, serie, numero, descripcion, activo)
                           VALUES (1,?,?,?,?,1)")->execute([$tipo,$serie,$num,$desc]);
            auditar('CREAR_SERIE','documentos_empresa',(int)db()->lastInsertId());
            flash('ok',"Serie $serie creada.");
        } catch (Throwable $e) {
            flash('error','Esa serie ya existe para ese tipo de documento.');
        }
        go('pages/admin/documentos.php');
    }

    if ($ap === 'editar') {
        $id    = (int)($_POST['id'] ?? 0);
        $num   = max(0, (int)($_POST['numero'] ?? 0));
        $desc  = trim($_POST['descripcion'] ?? '');
        $act   = isset($_POST['activo']) ? 1 : 0;
        db()->prepare("UPDATE documentos_empresa SET numero=?, descripcion=?, activo=? WHERE id=?")
            ->execute([$num,$desc,$act,$id]);
        auditar('EDITAR_SERIE','documentos_empresa',$id);
        flash('ok','Serie actualizada.');
        go('pages/admin/documentos.php');
    }

    if ($ap === 'eliminar') {
        $id = (int)$_POST['id'];
        db()->prepare("DELETE FROM documentos_empresa WHERE id=?")->execute([$id]);
        auditar('ELIMINAR_SERIE','documentos_empresa',$id);
        flash('ok','Serie eliminada.');
        go('pages/admin/documentos.php');
    }
}

$lista = db()->query("SELECT * FROM documentos_empresa WHERE empresa_id=1 ORDER BY tipo, serie")->fetchAll();
$tiposLbl = [
    'boleta'       => ['Boleta',         'bc'],
    'factura'      => ['Factura',        'bb'],
    'nota_venta'   => ['Nota de venta',  'ba'],
    'nota_credito' => ['Nota de crédito','br'],
    'ticket'       => ['Ticket',         'bgr'],
];
require_once __DIR__.'/../../includes/header.php';
?>

<div class="row g-4">
 <div class="col-12 col-lg-8">
  <div class="card">
   <div class="card-header">
    <span style="color:var(--t)"><i class="bi bi-list-ol me-1"></i>Series configuradas</span>
   </div>
   <div class="table-responsive"><table class="table mb-0">
    <thead><tr><th>Tipo</th><th>Serie</th><th class="text-end">Último N°</th><th>Próximo</th><th>Descripción</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lista as $d):
      [$lbl,$cls] = $tiposLbl[$d['tipo']] ?? [$d['tipo'],'bgr'];
      $next = str_pad((string)((int)$d['numero']+1), 8, '0', STR_PAD_LEFT);
    ?>
    <tr>
     <td><span class="badge <?=$cls?>"><?=$lbl?></span></td>
     <td><strong class="mon"><?=e($d['serie'])?></strong></td>
     <td class="text-end mon"><?=str_pad((string)$d['numero'], 8, '0', STR_PAD_LEFT)?></td>
     <td class="mon" style="color:var(--c)"><?=e($d['serie'])?>-<?=$next?></td>
     <td><small><?=e($d['descripcion']?:'—')?></small></td>
     <td><?=$d['activo'] ? '<span class="badge bg">ACTIVA</span>' : '<span class="badge bgr">INACTIVA</span>'?></td>
     <td>
      <div class="d-flex gap-1">
       <button type="button" class="btn btn-dk btn-ico" data-bs-toggle="modal" data-bs-target="#mEd<?=$d['id']?>" title="Editar"><i class="bi bi-pencil"></i></button>
       <form method="POST" onsubmit="return confirm('¿Eliminar la serie <?=e($d['serie'])?>? Solo si nunca se ha usado.')" style="display:inline">
        <input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?=$d['id']?>">
        <button type="submit" class="btn btn-del btn-ico" title="Eliminar"><i class="bi bi-trash"></i></button>
       </form>
      </div>
     </td>
    </tr>
    <?php endforeach; if (!$lista): ?>
    <tr><td colspan="7" class="text-center py-4" style="color:var(--t2)">No hay series configuradas.</td></tr>
    <?php endif; ?>
    </tbody>
   </table></div>
  </div>
 </div>

 <div class="col-12 col-lg-4">
  <div class="card">
   <div class="card-header"><span style="color:var(--t)"><i class="bi bi-plus-square me-1"></i>Nueva serie</span></div>
   <form method="POST" class="p-3">
    <input type="hidden" name="accion" value="nuevo">
    <div class="mb-3">
     <label class="form-label">Tipo de documento *</label>
     <select name="tipo" class="form-select" required>
      <option value="boleta">Boleta</option>
      <option value="factura">Factura</option>
      <option value="nota_venta">Nota de venta (interna)</option>
      <option value="nota_credito">Nota de crédito</option>
      <option value="ticket">Ticket simple</option>
     </select>
    </div>
    <div class="mb-3">
     <label class="form-label">Serie * <small style="color:var(--t2)">(2-4 caracteres)</small></label>
     <input type="text" name="serie" class="form-control" maxlength="4" placeholder="B001 / F001 / NV01" required style="text-transform:uppercase">
    </div>
    <div class="mb-3">
     <label class="form-label">Numerar desde</label>
     <input type="number" name="numero" class="form-control" value="0" min="0">
     <small style="color:var(--t2)">Si tu última boleta fue B001-00000123, pon <strong>123</strong>. La próxima será 124.</small>
    </div>
    <div class="mb-3">
     <label class="form-label">Descripción</label>
     <input type="text" name="descripcion" class="form-control" maxlength="100" placeholder="Opcional">
    </div>
    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Crear serie</button>
   </form>
  </div>

  <div class="card mt-3">
   <div class="p-3" style="font-size:11px;color:var(--t2)">
    <i class="bi bi-info-circle me-1"></i><strong>Tip:</strong> Para SUNAT las series de boleta empiezan con <code>B</code> y de factura con <code>F</code>. Las notas de venta son internas (no van a SUNAT) y suelen usar <code>NV01</code>.
   </div>
  </div>
 </div>
</div>

<!-- Modales de edición -->
<?php foreach ($lista as $d):
    [$lbl] = $tiposLbl[$d['tipo']] ?? [$d['tipo']];
?>
<div class="modal fade" id="mEd<?=$d['id']?>" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
 <form method="POST">
  <input type="hidden" name="accion" value="editar">
  <input type="hidden" name="id" value="<?=$d['id']?>">
  <div class="modal-header"><h5 class="modal-title"><?=$lbl?> · <?=e($d['serie'])?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body p-4">
   <div class="mb-3">
    <label class="form-label">Último número emitido</label>
    <input type="number" name="numero" class="form-control" value="<?=(int)$d['numero']?>" min="0">
    <small style="color:var(--t2)">El próximo será <strong class="mon" style="color:var(--c)"><?=e($d['serie'])?>-<?=str_pad((string)((int)$d['numero']+1),8,'0',STR_PAD_LEFT)?></strong> (cambia este valor para ajustar).</small>
   </div>
   <div class="mb-3">
    <label class="form-label">Descripción</label>
    <input type="text" name="descripcion" class="form-control" value="<?=e($d['descripcion'])?>" maxlength="100">
   </div>
   <div class="form-check">
    <input class="form-check-input" type="checkbox" name="activo" id="ac<?=$d['id']?>" <?=$d['activo']?'checked':''?>>
    <label class="form-check-label" for="ac<?=$d['id']?>">Serie activa (se puede emitir)</label>
   </div>
  </div>
  <div class="modal-footer">
   <button type="button" class="btn btn-dk" data-bs-dismiss="modal">Cancelar</button>
   <button type="submit" class="btn btn-primary">Guardar</button>
  </div>
 </form>
</div></div></div>
<?php endforeach; ?>

<?php require_once __DIR__.'/../../includes/footer.php'; ?>
