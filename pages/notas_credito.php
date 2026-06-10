<?php
/**
 * notas_credito.php — Notas de Crédito y Débito electrónicas (SUNAT).
 * Acciones: lista / nueva / ver / xml / emitir / enviar_sunat
 */
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/config_sunat.php';
require_once __DIR__.'/../includes/sunat/SunatService.php';
requiereModulo('facturacion');

$accion = $_GET['accion'] ?? 'lista';
$id     = (int)($_GET['id'] ?? 0);

// ─── POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ap = $_POST['accion'] ?? '';

    if ($ap === 'crear') {
        $pago_id    = (int)($_POST['pago_id'] ?? 0);
        $tipo_nota  = $_POST['tipo_nota'] ?? 'credito';
        $cod_motivo = trim($_POST['cod_motivo'] ?? '');
        $des_motivo = trim($_POST['des_motivo'] ?? '');

        if (!in_array($tipo_nota, ['credito', 'debito'], true)) {
            flash('error', 'Tipo de nota inválido.');
            go('pages/notas_credito.php?accion=nueva');
        }
        if (!$pago_id || !$cod_motivo || !$des_motivo) {
            flash('error', 'Completá todos los campos requeridos.');
            go('pages/notas_credito.php?accion=nueva');
        }

        $st = db()->prepare("SELECT * FROM pagos WHERE id=? AND tipo_comprobante IN('boleta','factura') AND sunat_xml IS NOT NULL");
        $st->execute([$pago_id]);
        $pago = $st->fetch();
        if (!$pago) {
            flash('error', 'Comprobante no encontrado o sin XML generado.');
            go('pages/notas_credito.php?accion=nueva');
        }
        if ($pago['estado'] === 'anulado') {
            flash('error', 'Este comprobante ya está anulado — tiene una nota de crédito aceptada.');
            go('pages/notas_credito.php?accion=nueva');
        }
        // Block if already has an accepted nota de crédito for annulment motivos
        $stNC = db()->prepare("SELECT id FROM notas_credito WHERE pago_id=? AND tipo_nota='credito' AND sunat_estado='aceptado' LIMIT 1");
        $stNC->execute([$pago_id]);
        if ($stNC->fetch()) {
            flash('error', 'Este comprobante ya tiene una nota de crédito aceptada por SUNAT.');
            go('pages/notas_credito.php?accion=nueva');
        }

        // Determine series based on document type and note type
        if ($tipo_nota === 'credito') {
            $serie = $pago['tipo_comprobante'] === 'factura' ? SUNAT_SERIE_NC_FACTURA : SUNAT_SERIE_NC_BOLETA;
        } else {
            $serie = $pago['tipo_comprobante'] === 'factura' ? SUNAT_SERIE_ND_FACTURA : SUNAT_SERIE_ND_BOLETA;
        }

        $numero = SunatService::siguienteNumeroNota(db(), $serie);

        $ins = db()->prepare("
            INSERT INTO notas_credito(pago_id, tipo_nota, serie, numero, cod_motivo, des_motivo, total, aplica_igv)
            VALUES(?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $pago_id,
            $tipo_nota,
            $serie,
            $numero,
            $cod_motivo,
            $des_motivo,
            (float)$pago['total'],
            (int)($pago['aplica_igv'] ?? 1),
        ]);
        $notaId = (int)db()->lastInsertId();
        auditar('CREAR_NOTA', 'notas_credito', $notaId);
        flash('ok', 'Nota creada. Podés emitir el XML ahora.');
        go("pages/notas_credito.php?accion=ver&id=$notaId");
    }

    if ($ap === 'emitir') {
        $nid = (int)$_POST['id'];
        $r   = (new SunatService(db()))->generarXmlNota($nid);
        flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'XML generado. Listo para enviar a SUNAT.' : 'Error: '.$r['mensaje']);
        go("pages/notas_credito.php?accion=ver&id=$nid");
    }

    if ($ap === 'enviar_sunat') {
        $nid = (int)$_POST['id'];
        $r   = (new SunatService(db()))->enviarSunatNota($nid);
        flash($r['ok'] ? 'ok' : 'error', ($r['ok'] ? 'SUNAT aceptó: ' : 'SUNAT rechazó: ').$r['mensaje']);
        go("pages/notas_credito.php?accion=ver&id=$nid");
    }

    if ($ap === 'regenerar') {
        $nid = (int)$_POST['id'];
        $r   = (new SunatService(db()))->generarXmlNota($nid);
        flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'XML regenerado.' : 'Error: '.$r['mensaje']);
        go("pages/notas_credito.php?accion=ver&id=$nid");
    }

    if ($ap === 'regenerar_bulk') {
        $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
        if (!$ids) { flash('error', 'No seleccionaste ninguna nota.'); go("pages/notas_credito.php"); }
        $svc = new SunatService(db());
        $ok = $err = 0;
        foreach ($ids as $nid) {
            // Permitir regenerar incluso aceptadas (corrección de RUC)
            db()->prepare("UPDATE notas_credito SET sunat_estado='pendiente' WHERE id=? AND sunat_estado='aceptado'")->execute([$nid]);
            $r = $svc->generarXmlNota($nid);
            $r['ok'] ? $ok++ : $err++;
        }
        flash($err === 0 ? 'ok' : 'error', "XML regenerado: $ok ok, $err error(es).");
        go("pages/notas_credito.php");
    }
}

// ─── DESCARGAS XML / CDR ───────────────────────────────────────────
if (in_array($accion, ['xml', 'cdr'], true) && $id) {
    $st = db()->prepare("SELECT * FROM notas_credito WHERE id=?");
    $st->execute([$id]);
    $nota = $st->fetch();
    if (!$nota) { http_response_code(404); echo 'No encontrado.'; exit; }
    $base = 'nota_' . $nota['serie'] . '-' . str_pad((string)$nota['numero'], 8, '0', STR_PAD_LEFT);
    if ($accion === 'xml') {
        if (empty($nota['sunat_xml'])) { http_response_code(404); echo 'Sin XML.'; exit; }
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/xml; charset=utf-8');
        if (isset($_GET['dl'])) header('Content-Disposition: attachment; filename="' . $base . '.xml"');
        echo $nota['sunat_xml']; exit;
    }
    if (empty($nota['sunat_cdr'])) { http_response_code(404); echo 'Sin CDR.'; exit; }
    $bin = base64_decode($nota['sunat_cdr'], true);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="R-' . $base . '.zip"');
    echo $bin !== false ? $bin : $nota['sunat_cdr']; exit;
}

// ─── LISTA ─────────────────────────────────────────────────────────
if ($accion === 'lista') {
    $titulo = 'Notas de Crédito / Débito';
    $pagina_activa = 'notas';

    $fdesde = $_GET['desde'] ?? date('Y-m-01');
    $fhasta = $_GET['hasta'] ?? date('Y-m-d');
    $ftipo  = $_GET['tipo']  ?? '';
    $fsun   = $_GET['sun']   ?? '';

    $w  = "WHERE DATE(n.created_at) BETWEEN ? AND ?";
    $pm = [$fdesde, $fhasta];
    if ($ftipo) { $w .= " AND n.tipo_nota=?"; $pm[] = $ftipo; }
    if ($fsun)  { $w .= " AND n.sunat_estado=?"; $pm[] = $fsun; }

    $st = db()->prepare("
        SELECT n.*,
               p.tipo_comprobante, p.serie AS p_serie, p.numero AS p_numero,
               CONCAT(pa.nombres,' ',pa.apellido_paterno) AS pac
        FROM notas_credito n
        JOIN pagos p ON n.pago_id = p.id
        JOIN pacientes pa ON p.paciente_id = pa.id
        $w
        ORDER BY n.created_at DESC
    ");
    $st->execute($pm);
    $lista = $st->fetchAll();

    $topbar_act = '<a href="?accion=nueva" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nueva nota</a>';
    require_once __DIR__.'/../includes/header.php';
?>
<div class="card"><div class="card-header">
 <span><i class="bi bi-filter me-1"></i>Filtros</span>
</div>
<div class="p-3">
 <form method="GET" class="row g-2 align-items-end">
  <div class="col-6 col-md-2"><label class="form-label">Desde</label><input type="date" name="desde" class="form-control" value="<?=e($fdesde)?>"></div>
  <div class="col-6 col-md-2"><label class="form-label">Hasta</label><input type="date" name="hasta" class="form-control" value="<?=e($fhasta)?>"></div>
  <div class="col-6 col-md-2"><label class="form-label">Tipo</label>
   <select name="tipo" class="form-select">
    <option value="">— Todos —</option>
    <option value="credito" <?=$ftipo==='credito'?'selected':''?>>Crédito</option>
    <option value="debito"  <?=$ftipo==='debito'?'selected':''?>>Débito</option>
   </select>
  </div>
  <div class="col-6 col-md-2"><label class="form-label">Estado SUNAT</label>
   <select name="sun" class="form-select">
    <option value="">— Todos —</option>
    <option value="pendiente"  <?=$fsun==='pendiente'?'selected':''?>>Pendiente</option>
    <option value="aceptado"   <?=$fsun==='aceptado'?'selected':''?>>Aceptado</option>
    <option value="rechazado"  <?=$fsun==='rechazado'?'selected':''?>>Rechazado</option>
   </select>
  </div>
  <div class="col-12 col-md-2 d-grid"><button type="submit" class="btn btn-dk"><i class="bi bi-search"></i></button></div>
 </form>
</div></div>

<form method="POST" id="frmBulk">
<input type="hidden" name="accion" value="regenerar_bulk">

<div class="card mt-3" id="bulkBar" style="display:none;padding:10px 16px;background:var(--bs-warning-bg-subtle,#fff3cd);border-color:#ffc107">
 <div class="d-flex align-items-center gap-3">
  <span id="bulkCount" class="fw-bold">0 seleccionadas</span>
  <button type="submit" class="btn btn-dk btn-sm" onclick="return confirm('¿Regenerar XML de las notas seleccionadas? Las aceptadas con RUC de prueba pasarán a pendiente.')">
   <i class="bi bi-arrow-clockwise me-1"></i>Regenerar XML seleccionadas
  </button>
  <button type="button" class="btn btn-sm ms-auto" onclick="deselectAll()">Cancelar</button>
 </div>
</div>

<div class="card mt-2"><div class="table-responsive"><table class="table mb-0">
 <thead><tr>
  <th style="width:36px"><input type="checkbox" id="chkAll" class="form-check-input" title="Seleccionar todo"></th>
  <th>Nota</th>
  <th>Comprobante Afectado</th>
  <th>Paciente</th>
  <th>Motivo</th>
  <th class="text-end">Total</th>
  <th>Estado SUNAT</th>
  <th></th>
 </tr></thead>
 <tbody>
 <?php foreach ($lista as $n):
  $se = $n['sunat_estado'];
  $sc = $se==='aceptado'?'bg':($se==='rechazado'?'br':($se==='pendiente'?'ba':'bgr'));
  $tn = $n['tipo_nota']==='credito' ? 'N. CRÉDITO' : 'N. DÉBITO';
  $tc = $tn === 'N. CRÉDITO' ? 'bc' : 'ba';
 ?>
  <tr>
   <td><input type="checkbox" name="ids[]" value="<?=$n['id']?>" class="form-check-input chk-nota"></td>
   <td>
    <span class="badge <?=$tc?>"><?=$tn?></span>
    <div class="mon" style="font-size:12px;margin-top:2px"><?=e($n['serie'])?>-<?=str_pad((string)$n['numero'],8,'0',STR_PAD_LEFT)?></div>
   </td>
   <td>
    <span class="badge bgr"><?=strtoupper($n['tipo_comprobante'])?></span>
    <div class="mon" style="font-size:12px;margin-top:2px"><?=e($n['p_serie'])?>-<?=str_pad((string)$n['p_numero'],8,'0',STR_PAD_LEFT)?></div>
   </td>
   <td><strong><?=e($n['pac'])?></strong></td>
   <td>
    <span class="badge bgr"><?=e($n['cod_motivo'])?></span>
    <small style="display:block;color:var(--t2);margin-top:2px"><?=e(mb_substr($n['des_motivo'],0,50)).(mb_strlen($n['des_motivo'])>50?'…':'')?></small>
   </td>
   <td class="mon fw-bold text-end"><?=mon((float)$n['total'])?></td>
   <td><span class="badge <?=$sc?>"><?=$se?strtoupper($se):'SIN XML'?></span></td>
   <td>
    <div class="d-flex gap-1">
     <a href="?accion=ver&id=<?=$n['id']?>" class="btn btn-dk btn-ico" title="Ver"><i class="bi bi-eye"></i></a>
     <?php if (!empty($n['sunat_xml'])): ?>
      <a href="?accion=xml&id=<?=$n['id']?>" target="_blank" class="btn btn-dk btn-ico" title="Ver XML"><i class="bi bi-file-earmark-code"></i></a>
     <?php endif; ?>
     <button type="button" class="btn btn-dk btn-ico" title="Regenerar XML"
             onclick="postAction('regenerar',<?=$n['id']?>,'¿Regenerar XML?')">
      <i class="bi bi-arrow-clockwise"></i>
     </button>
     <?php if (!empty($n['sunat_xml']) && $se !== 'aceptado'): ?>
      <button type="button" class="btn btn-primary btn-ico" title="Enviar a SUNAT"
              onclick="postAction('enviar_sunat',<?=$n['id']?>,'¿Enviar a SUNAT?')">
       <i class="bi bi-send"></i>
      </button>
     <?php endif; ?>
    </div>
   </td>
  </tr>
 <?php endforeach; if (!$lista): ?>
  <tr><td colspan="8" class="text-center py-4" style="color:var(--t2)">
   <i class="bi bi-inbox" style="font-size:32px;display:block;margin-bottom:8px"></i>
   No hay notas en este período.
  </td></tr>
 <?php endif; ?>
 </tbody>
</table></div></div>
</form>

<script>
function postAction(accion, id, msg) {
  if (msg && !confirm(msg)) return;
  var f = document.createElement('form');
  f.method = 'POST';
  f.innerHTML = '<input name="accion" value="'+accion+'"><input name="id" value="'+id+'">';
  document.body.appendChild(f);
  f.submit();
}

(function(){
  var chkAll  = document.getElementById('chkAll');
  var bar     = document.getElementById('bulkBar');
  var counter = document.getElementById('bulkCount');

  function updateBar() {
    var checked = document.querySelectorAll('.chk-nota:checked').length;
    bar.style.display = checked > 0 ? '' : 'none';
    counter.textContent = checked + (checked === 1 ? ' seleccionada' : ' seleccionadas');
  }

  chkAll.addEventListener('change', function() {
    document.querySelectorAll('.chk-nota').forEach(function(c){ c.checked = chkAll.checked; });
    updateBar();
  });

  document.querySelectorAll('.chk-nota').forEach(function(c){
    c.addEventListener('change', function(){
      chkAll.checked = document.querySelectorAll('.chk-nota:not(:checked)').length === 0;
      updateBar();
    });
  });

  window.deselectAll = function() {
    document.querySelectorAll('.chk-nota').forEach(function(c){ c.checked = false; });
    chkAll.checked = false;
    updateBar();
  };
})();
</script>
<?php require_once __DIR__.'/../includes/footer.php';

// ─── VER DETALLE ───────────────────────────────────────────────────
} elseif ($accion === 'ver' && $id) {
    $st = db()->prepare("
        SELECT n.*,
               p.tipo_comprobante, p.serie AS p_serie, p.numero AS p_numero, p.total AS p_total,
               CONCAT(pa.nombres,' ',pa.apellido_paterno,' ',COALESCE(pa.apellido_materno,'')) AS pac,
               pa.dni, pa.ruc
        FROM notas_credito n
        JOIN pagos p ON n.pago_id = p.id
        JOIN pacientes pa ON p.paciente_id = pa.id
        WHERE n.id=?
    ");
    $st->execute([$id]);
    $nota = $st->fetch();
    if (!$nota) { flash('error', 'Nota no encontrada.'); go('pages/notas_credito.php'); }

    $titulo = ($nota['tipo_nota']==='credito' ? 'NOTA DE CRÉDITO' : 'NOTA DE DÉBITO').' '.$nota['serie'].'-'.str_pad((string)$nota['numero'],8,'0',STR_PAD_LEFT);
    $pagina_activa = 'notas';
    require_once __DIR__.'/../includes/header.php';

    $se = $nota['sunat_estado'];
    $sc = $se==='aceptado'?'bg':($se==='rechazado'?'br':($se==='pendiente'?'ba':'bgr'));
    $sl = $se ? strtoupper($se) : 'SIN EMITIR';
    $motivos = ['01'=>'Anulación de la operación','02'=>'Anulación por error en el RUC','06'=>'Devolución total','07'=>'Devolución por ítem(s)','13'=>'Ajuste en exportación'];
?>
<div class="row g-4">
 <div class="col-12 col-lg-7">
  <div class="card">
   <div class="card-header">
    <span><i class="bi bi-file-earmark-minus me-1"></i><?=e($titulo)?></span>
    <span class="badge <?=$nota['tipo_nota']==='credito'?'bc':'ba'?>"><?=$nota['tipo_nota']==='credito'?'CRÉDITO':'DÉBITO'?></span>
   </div>
   <div class="p-4">
    <div class="row g-3 mb-4">
     <div class="col-md-6">
      <small style="color:var(--t2)">Cliente</small>
      <div><strong><?=e($nota['pac'])?></strong></div>
      <?php if ($nota['ruc']): ?>
       <small><strong>RUC:</strong> <?=e($nota['ruc'])?></small>
      <?php elseif ($nota['dni']): ?>
       <small><strong>DNI:</strong> <?=e($nota['dni'])?></small>
      <?php endif; ?>
     </div>
     <div class="col-md-6 text-md-end">
      <small style="color:var(--t2)">Comprobante afectado</small>
      <div class="mon"><?=strtoupper($nota['tipo_comprobante'])?> <?=e($nota['p_serie'])?>-<?=str_pad((string)$nota['p_numero'],8,'0',STR_PAD_LEFT)?></div>
      <small style="color:var(--t2)">Fecha de creación</small>
      <div class="mon" style="font-size:12px"><?=fDT($nota['created_at'])?></div>
     </div>
    </div>

    <div class="mb-3 p-3 rounded" style="background:var(--bg3)">
     <div class="mb-1"><small style="color:var(--t2)">Motivo</small></div>
     <div><span class="badge bgr me-2"><?=e($nota['cod_motivo'])?></span><strong><?=e($motivos[$nota['cod_motivo']] ?? $nota['cod_motivo'])?></strong></div>
     <div class="mt-2" style="color:var(--t2);font-size:12px"><?=e($nota['des_motivo'])?></div>
    </div>

    <div class="p-3 rounded" style="background:var(--bg3)">
     <div class="d-flex justify-content-between align-items-center">
      <strong>TOTAL</strong>
      <span class="mon fw-bold" style="font-size:24px;color:var(--c)"><?=mon((float)$nota['total'])?></span>
     </div>
     <div class="mt-1">
      <span class="badge <?=$nota['aplica_igv']?'bc':'bpu'?>"><?=$nota['aplica_igv']?'CON IGV (18%)':'SIN IGV'?></span>
     </div>
    </div>
   </div>
  </div>
 </div>

 <div class="col-12 col-lg-5">
  <div class="card mb-4">
   <div class="card-header"><span><i class="bi bi-bank me-1"></i>SUNAT</span><span class="badge <?=$sc?>"><?=$sl?></span></div>
   <div class="p-3">
    <?php if (!empty($nota['sunat_mensaje'])): ?>
     <div class="mb-2" style="font-size:11px;color:var(--t2)"><?=e($nota['sunat_mensaje'])?></div>
    <?php endif; ?>
    <?php if (!empty($nota['sunat_hash'])): ?>
     <div class="mb-3" style="font-size:10px;color:var(--t2);word-break:break-all"><strong>Hash:</strong> <?=e($nota['sunat_hash'])?></div>
    <?php endif; ?>
    <div class="d-grid gap-2">
     <?php if (!empty($nota['sunat_xml'])): ?>
      <div class="d-flex gap-2">
       <a href="?accion=xml&id=<?=$id?>" target="_blank" class="btn btn-dk btn-sm flex-fill"><i class="bi bi-file-earmark-code me-1"></i>Ver XML</a>
       <a href="?accion=xml&id=<?=$id?>&dl=1" class="btn btn-dk btn-sm" title="Descargar XML"><i class="bi bi-download"></i></a>
      </div>
     <?php endif; ?>
     <?php if (!empty($nota['sunat_cdr'])): ?>
      <a href="?accion=cdr&id=<?=$id?>" class="btn btn-ok btn-sm"><i class="bi bi-download me-1"></i>Descargar CDR</a>
     <?php endif; ?>
     <?php if (!empty($nota['sunat_xml']) && $se !== 'aceptado'): ?>
      <form method="POST" onsubmit="return confirm('¿Enviar esta nota a SUNAT?')">
       <input type="hidden" name="accion" value="enviar_sunat"><input type="hidden" name="id" value="<?=$id?>">
       <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-send me-1"></i>Enviar a SUNAT</button>
      </form>
     <?php endif; ?>
     <?php if (empty($nota['sunat_xml']) || $se === 'rechazado'): ?>
      <form method="POST" onsubmit="return confirm('¿Generar XML de esta nota?')">
       <input type="hidden" name="accion" value="<?=empty($nota['sunat_xml'])?'emitir':'regenerar'?>"><input type="hidden" name="id" value="<?=$id?>">
       <button type="submit" class="btn btn-dk btn-sm w-100">
        <i class="bi bi-<?=empty($nota['sunat_xml'])?'lightning':'arrow-clockwise'?> me-1"></i>
        <?=empty($nota['sunat_xml'])?'Generar XML':'Regenerar XML'?>
       </button>
      </form>
     <?php endif; ?>
    </div>
   </div>
  </div>

  <div class="card">
   <div class="card-header"><span><i class="bi bi-lightning me-1"></i>Acciones</span></div>
   <div class="p-3 d-grid gap-2">
    <a href="<?=BASE_URL?>/pages/facturacion.php?accion=ver&id=<?=$nota['pago_id']?>" class="btn btn-dk btn-sm">
     <i class="bi bi-receipt me-2"></i>Ver comprobante original
    </a>
    <a href="<?=BASE_URL?>/pages/notas_credito.php" class="btn btn-dk btn-sm">
     <i class="bi bi-arrow-left me-2"></i>Volver a la lista
    </a>
   </div>
  </div>
 </div>
</div>
<?php require_once __DIR__.'/../includes/footer.php';

// ─── NUEVA NOTA ────────────────────────────────────────────────────
} elseif ($accion === 'nueva') {
    $titulo = 'Nueva Nota de Crédito / Débito';
    $pagina_activa = 'notas';

    $pagos = db()->query("
        SELECT p.id, p.serie, p.numero, p.tipo_comprobante, p.total, p.fecha, p.sunat_estado,
               CONCAT(pa.nombres,' ',pa.apellido_paterno) AS pac
        FROM pagos p
        JOIN pacientes pa ON p.paciente_id = pa.id
        WHERE p.tipo_comprobante IN('boleta','factura')
          AND p.sunat_xml IS NOT NULL
          AND p.estado != 'anulado'
          AND NOT EXISTS (
              SELECT 1 FROM notas_credito nc
              WHERE nc.pago_id = p.id AND nc.tipo_nota='credito' AND nc.sunat_estado='aceptado'
          )
        ORDER BY p.fecha DESC
        LIMIT 500
    ")->fetchAll();

    require_once __DIR__.'/../includes/header.php';
?>
<form method="POST">
 <input type="hidden" name="accion" value="crear">
 <div class="row g-4">
  <div class="col-12 col-lg-7">
   <div class="card mb-4">
    <div class="card-header"><span><i class="bi bi-receipt me-1"></i>Comprobante a afectar</span></div>
    <div class="p-4">
     <label class="form-label">Comprobante (solo aceptados por SUNAT) *</label>
     <select name="pago_id" class="form-select" required id="selPago">
      <option value="">— Seleccioná un comprobante —</option>
      <?php foreach ($pagos as $pg): ?>
       <option value="<?=$pg['id']?>"
         data-tipo="<?=$pg['tipo_comprobante']?>"
         data-total="<?=$pg['total']?>">
        [<?=strtoupper($pg['sunat_estado'])?>] <?=strtoupper($pg['tipo_comprobante'])?> · <?=e($pg['serie'])?>-<?=str_pad((string)$pg['numero'],8,'0',STR_PAD_LEFT)?>
        · <?=e($pg['pac'])?> · <?=mon((float)$pg['total'])?> · <?=fDate($pg['fecha'])?>
       </option>
      <?php endforeach; ?>
     </select>
     <?php if (!$pagos): ?>
      <div class="mt-2" style="color:var(--a);font-size:12px"><i class="bi bi-exclamation-triangle me-1"></i>No hay comprobantes con XML generado. Emití una boleta o factura primero.</div>
     <?php endif; ?>
    </div>
   </div>

   <div class="card">
    <div class="card-header"><span><i class="bi bi-chat-text me-1"></i>Motivo</span></div>
    <div class="p-4">
     <label class="form-label">Código de motivo (catálogo SUNAT 09) *</label>
     <select name="cod_motivo" class="form-select mb-3" required>
      <option value="">— Seleccioná el motivo —</option>
      <option value="01">01 — Anulación de la operación</option>
      <option value="02">02 — Anulación por error en el RUC</option>
      <option value="06">06 — Devolución total</option>
      <option value="07">07 — Devolución por ítem(s) de la operación</option>
      <option value="13">13 — Ajuste en operaciones de exportación</option>
     </select>

     <label class="form-label">Descripción del motivo *</label>
     <textarea name="des_motivo" class="form-control" rows="3" required placeholder="Describí brevemente el motivo de la nota..."></textarea>
    </div>
   </div>
  </div>

  <div class="col-12 col-lg-5">
   <div class="card mb-4">
    <div class="card-header"><span><i class="bi bi-file-earmark-minus me-1"></i>Tipo de nota</span></div>
    <div class="p-4">
     <label class="form-label">Tipo *</label>
     <div class="btn-group w-100 mb-3" role="group">
      <input type="radio" class="btn-check" name="tipo_nota" id="tNC" value="credito" checked>
      <label class="btn btn-dk" id="lNC" for="tNC"><i class="bi bi-dash-circle me-1"></i>Nota de Crédito</label>
      <input type="radio" class="btn-check" name="tipo_nota" id="tND" value="debito">
      <label class="btn btn-dk" id="lND" for="tND"><i class="bi bi-plus-circle me-1"></i>Nota de Débito</label>
     </div>
     <div style="font-size:11px;color:var(--t2);padding:8px 10px;border-radius:6px;background:rgba(0,212,238,.06);border:1px solid rgba(0,212,238,.15)">
      <div id="tipoInfo"><i class="bi bi-info-circle me-1"></i><strong>Crédito:</strong> reduce el importe del comprobante original (devoluciones, anulaciones).</div>
     </div>

     <div id="seriePreview" class="mt-3 p-2 rounded" style="background:var(--bg3);font-size:11px;color:var(--t2)">
      <i class="bi bi-hash me-1"></i>Serie asignada automáticamente al guardar.
     </div>
    </div>
   </div>

   <div class="d-grid gap-2">
    <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-plus-circle me-2"></i>Crear nota</button>
    <a href="?" class="btn btn-dk">Cancelar</a>
   </div>
  </div>
 </div>
</form>
<script>
(function(){
 var tNC = document.getElementById('tNC');
 var tND = document.getElementById('tND');
 var lNC = document.getElementById('lNC');
 var lND = document.getElementById('lND');
 var inf = document.getElementById('tipoInfo');
 var pre = document.getElementById('seriePreview');
 var sel = document.getElementById('selPago');
 var c   = getComputedStyle(document.documentElement).getPropertyValue('--c').trim();

 var seriesMap = {
  'credito': { 'factura': '<?=SUNAT_SERIE_NC_FACTURA?>', 'boleta': '<?=SUNAT_SERIE_NC_BOLETA?>' },
  'debito':  { 'factura': '<?=SUNAT_SERIE_ND_FACTURA?>', 'boleta': '<?=SUNAT_SERIE_ND_BOLETA?>' }
 };

 function setActive(active, inactive) {
  active.style.setProperty('background', c, 'important');
  active.style.setProperty('color', '#000', 'important');
  active.style.setProperty('border-color', c, 'important');
  inactive.style.removeProperty('background');
  inactive.style.removeProperty('color');
  inactive.style.removeProperty('border-color');
 }

 function update(){
  var tipo   = tNC.checked ? 'credito' : 'debito';
  var opt    = sel.options[sel.selectedIndex];
  var tipDoc = opt ? (opt.dataset.tipo || '') : '';

  tipo === 'credito' ? setActive(lNC, lND) : setActive(lND, lNC);

  inf.innerHTML = tipo === 'credito'
   ? '<i class="bi bi-info-circle me-1"></i><strong>Crédito:</strong> reduce el importe del comprobante original (devoluciones, anulaciones).'
   : '<i class="bi bi-info-circle me-1"></i><strong>Débito:</strong> aumenta el importe del comprobante original (cobros adicionales).';

  pre.innerHTML = (tipDoc && seriesMap[tipo] && seriesMap[tipo][tipDoc])
   ? '<i class="bi bi-hash me-1"></i>Serie: <strong>' + seriesMap[tipo][tipDoc] + '</strong>'
   : '<i class="bi bi-hash me-1"></i>Serie asignada automáticamente al guardar.';
 }

 tNC.addEventListener('change', update);
 tND.addEventListener('change', update);
 sel.addEventListener('change', update);
 update();
})();
</script>
<?php require_once __DIR__.'/../includes/footer.php';

} else {
    go('pages/notas_credito.php');
}
