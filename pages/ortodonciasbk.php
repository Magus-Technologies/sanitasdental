<?php
require_once __DIR__.'/../includes/config.php';
requiereLogin();

$paciente_id = (int)($_GET['paciente_id'] ?? 0);
$accion = $_GET['accion'] ?? 'lista';
$id = (int)($_GET['id'] ?? 0);

if (!$paciente_id) {
    flash('error', 'Paciente requerido');
    go('pages/pacientes.php');
}

// Obtener paciente
$ps = db()->prepare("SELECT * FROM pacientes WHERE id=?");
$ps->execute([$paciente_id]);
$pac = $ps->fetch();
if (!$pac) {
    flash('error', 'Paciente no encontrado');
    go('pages/pacientes.php');
}

// ── POST: guardar ortodoncia ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {
    $ei = (int)($_POST['id'] ?? 0);
    $hc_id = (int)($_POST['hc_id'] ?? 0);
    $tipo = $_POST['tipo'] ?? 'control';
    $fecha_atencion = $_POST['fecha_atencion'] ?? date('Y-m-d');
    $fecha_referencia = $_POST['fecha_referencia'] ?? null;
    $observaciones = trim($_POST['observaciones'] ?? '');
    $procedimientos = trim($_POST['procedimientos'] ?? '');
    $proximo_control = $_POST['proximo_control'] ?? null;
    
    // Procesar tipos de arco
    $tipos_arco = [];
    if (isset($_POST['arco_acero'])) $tipos_arco[] = 'acero';
    if (isset($_POST['arco_niti'])) $tipos_arco[] = 'niti';
    if (isset($_POST['arco_termico'])) $tipos_arco[] = 'termico';
    if (isset($_POST['arco_resorte'])) $tipos_arco[] = 'resorte';
    $tipo_arco_json = json_encode($tipos_arco);
    
    if ($ei) {
        // Actualizar
        db()->prepare("UPDATE ortodoncias SET hc_id=?,tipo=?,fecha_atencion=?,fecha_referencia=?,tipo_arco=?,observaciones=?,procedimientos=?,proximo_control=?,updated_at=NOW() WHERE id=?")
           ->execute([$hc_id ?: null, $tipo, $fecha_atencion, $fecha_referencia ?: null, $tipo_arco_json, $observaciones, $procedimientos, $proximo_control ?: null, $ei]);
        auditar('EDITAR_ORTODONCIA', 'ortodoncias', $ei);
        flash('ok', 'Control de ortodoncia actualizado');
    } else {
        // Crear
        db()->prepare("INSERT INTO ortodoncias(paciente_id,hc_id,tipo,fecha_atencion,fecha_referencia,tipo_arco,observaciones,procedimientos,proximo_control,doctor_id) VALUES(?,?,?,?,?,?,?,?,?,?)")
           ->execute([$paciente_id, $hc_id ?: null, $tipo, $fecha_atencion, $fecha_referencia ?: null, $tipo_arco_json, $observaciones, $procedimientos, $proximo_control ?: null, $_SESSION['uid']]);
        $nid = db()->lastInsertId();
        auditar('CREAR_ORTODONCIA', 'ortodoncias', $nid);
        flash('ok', 'Control de ortodoncia registrado');
    }
    
    go("pages/ortodoncias.php?paciente_id=$paciente_id");
}

if ($accion === 'lista') {
    // Listar ortodoncias del paciente
    $ortodoncias = db()->prepare("SELECT o.*, CONCAT(u.nombre,' ',u.apellidos) AS doctor, hc.numero_hc 
                                  FROM ortodoncias o 
                                  LEFT JOIN usuarios u ON o.doctor_id = u.id 
                                  LEFT JOIN historias_clinicas hc ON o.hc_id = hc.id 
                                  WHERE o.paciente_id = ? 
                                  ORDER BY o.fecha_atencion DESC");
    $ortodoncias->execute([$paciente_id]);
    $ortodoncias = $ortodoncias->fetchAll();
    
    $titulo = 'Ortodoncia — ' . $pac['nombres'] . ' ' . $pac['apellido_paterno'];
    $pagina_activa = 'pac';
    $topbar_act = '<a href="?accion=nuevo&paciente_id=' . $paciente_id . '" class="btn btn-primary"><i class="bi bi-plus me-1"></i>Nuevo control</a>
    <a href="' . BASE_URL . '/pages/pacientes.php?accion=ver&id=' . $paciente_id . '" class="btn btn-dk btn-sm"><i class="bi bi-person me-1"></i>Paciente</a>';
    
    require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 mb-3">
    <div class="col-12">
        <div class="card p-3 d-flex flex-row align-items-center gap-3 flex-wrap">
            <div class="ava" style="width:44px;height:44px;font-size:18px;flex-shrink:0"><?= strtoupper(substr($pac['nombres'], 0, 1)) ?></div>
            <div>
                <strong style="font-size:15px;color:var(--t)"><?= e($pac['nombres'] . ' ' . $pac['apellido_paterno'] . ' ' . ($pac['apellido_materno'] ?? '')) ?></strong>
                <div style="font-size:12px;color:var(--t2)"><?= e($pac['codigo']) ?> · <?= $pac['fecha_nacimiento'] ? edad($pac['fecha_nacimiento']) : '—' ?> · DNI: <?= e($pac['dni'] ?? '—') ?></div>
            </div>
            <?php if ($pac['alergias']): ?>
                <span class="badge br ms-2">⚠️ ALÉRGICO: <?= e($pac['alergias']) ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-grid-3x2-gap me-2"></i>Tratamiento de Ortodoncia</span>
    </div>
    
    <?php if ($ortodoncias): ?>
        <div class="p-4">
            <!-- Vista en árbol -->
            <div class="ortodoncia-tree">
                <?php
                $instalacion = array_filter($ortodoncias, fn($o) => $o['tipo'] === 'instalacion');
                $controles = array_filter($ortodoncias, fn($o) => $o['tipo'] === 'control');
                ?>
                
                <?php if ($instalacion): ?>
                    <?php $inst = reset($instalacion); ?>
                    <div class="tree-node tree-root">
                        <div class="tree-content">
                            <div class="d-flex align-items-center gap-3">
                                <div class="tree-icon" style="background:#10B981">
                                    <i class="bi bi-grid-3x2-gap"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <h6 style="margin:0;color:var(--t)">🦷 Instalación de Ortodoncia</h6>
                                    <small style="color:var(--t2)">
                                        <?= fDate($inst['fecha_atencion']) ?> 
                                        <?php if ($inst['doctor']): ?>· Dr. <?= e($inst['doctor']) ?><?php endif; ?>
                                    </small>
                                </div>
                                <div>
                                    <a href="?accion=ver&id=<?= $inst['id'] ?>&paciente_id=<?= $paciente_id ?>" class="btn btn-dk btn-sm">Ver</a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($controles): ?>
                    <div class="tree-children">
                        <?php foreach ($controles as $i => $ctrl): ?>
                            <div class="tree-node">
                                <div class="tree-line"></div>
                                <div class="tree-content">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="tree-icon" style="background:#06B6D4">
                                            <i class="bi bi-check-circle"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <h6 style="margin:0;color:var(--t)">Control #<?= count($controles) - $i ?></h6>
                                            <small style="color:var(--t2)">
                                                <?= fDate($ctrl['fecha_atencion']) ?>
                                                <?php if ($ctrl['proximo_control']): ?>
                                                    · Próximo: <?= fDate($ctrl['proximo_control']) ?>
                                                <?php endif; ?>
                                            </small>
                                            <?php if ($ctrl['procedimientos']): ?>
                                                <div style="font-size:11px;color:var(--t2);margin-top:2px">
                                                    <?= e(substr($ctrl['procedimientos'], 0, 80)) ?><?= strlen($ctrl['procedimientos']) > 80 ? '...' : '' ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <a href="?accion=ver&id=<?= $ctrl['id'] ?>&paciente_id=<?= $paciente_id ?>" class="btn btn-dk btn-sm">Ver</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="p-5 text-center" style="color:var(--t2)">
            <i class="bi bi-grid-3x2-gap" style="font-size:48px;display:block;margin-bottom:16px"></i>
            <h3 style="font-size:16px;margin-bottom:8px">Sin tratamiento de ortodoncia</h3>
            <p style="font-size:14px;margin-bottom:20px">Este paciente aún no tiene registros de ortodoncia.</p>
            <a href="?accion=nuevo&paciente_id=<?= $paciente_id ?>&tipo=instalacion" class="btn btn-primary me-2">
                <i class="bi bi-plus me-2"></i>Registrar instalación
            </a>
            <a href="?accion=nuevo&paciente_id=<?= $paciente_id ?>&tipo=control" class="btn btn-dk">
                <i class="bi bi-plus me-2"></i>Registrar control
            </a>
        </div>
    <?php endif; ?>
</div>

<style>
.ortodoncia-tree {
    position: relative;
}

.tree-node {
    position: relative;
    margin-bottom: 15px;
}

.tree-root .tree-content {
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
    border: 2px solid #06B6D4;
    border-radius: 12px;
    padding: 20px;
}

.tree-children {
    margin-left: 30px;
    position: relative;
}

.tree-children::before {
    content: '';
    position: absolute;
    left: -15px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #e2e8f0;
}

.tree-line {
    position: absolute;
    left: -15px;
    top: 25px;
    width: 15px;
    height: 2px;
    background: #e2e8f0;
}

.tree-content {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 15px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.tree-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 18px;
    flex-shrink: 0;
}
</style>

<?php
    require_once __DIR__ . '/../includes/footer.php';

} elseif (in_array($accion, ['nuevo', 'editar'])) {
    $ortodoncia = ['id' => 0, 'tipo' => $_GET['tipo'] ?? 'control', 'fecha_atencion' => date('Y-m-d'), 'fecha_referencia' => '', 'tipo_arco' => '[]', 'observaciones' => '', 'procedimientos' => '', 'proximo_control' => '', 'hc_id' => 0];
    
    if ($accion === 'editar' && $id) {
        $s = db()->prepare("SELECT * FROM ortodoncias WHERE id=? AND paciente_id=?");
        $s->execute([$id, $paciente_id]);
        $ortodoncia = $s->fetch() ?: $ortodoncia;
    }
    
    // Obtener historias clínicas del paciente
    $hcs = db()->prepare("SELECT id, numero_hc, fecha_apertura FROM historias_clinicas WHERE paciente_id = ? ORDER BY fecha_apertura DESC");
    $hcs->execute([$paciente_id]);
    $hcs = $hcs->fetchAll();
    
    $tipos_arco = json_decode($ortodoncia['tipo_arco'] ?? '[]', true) ?: [];
    
    $titulo = ($accion === 'nuevo' ? 'Nuevo ' : 'Editar ') . ($ortodoncia['tipo'] === 'instalacion' ? 'Instalación' : 'Control') . ' de Ortodoncia';
    $pagina_activa = 'pac';
    
    require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-xl-8">
        <form method="POST">
            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="id" value="<?= $ortodoncia['id'] ?>">
            
            <div class="card mb-4">
                <div class="card-header">
                    <span style="color:var(--t)">
                        <i class="bi bi-grid-3x2-gap me-2"></i>
                        <?= $ortodoncia['tipo'] === 'instalacion' ? 'Instalación' : 'Control' ?> de Ortodoncia
                    </span>
                </div>
                
                <div class="p-4">
                    <!-- Datos del paciente -->
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <div class="card p-3" style="background:#f8f9fa">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <strong>Paciente:</strong> <?= e($pac['nombres'] . ' ' . $pac['apellido_paterno'] . ' ' . ($pac['apellido_materno'] ?? '')) ?><br>
                                        <strong>DNI:</strong> <?= e($pac['dni'] ?? '—') ?><br>
                                        <strong>Edad:</strong> <?= $pac['fecha_nacimiento'] ? edad($pac['fecha_nacimiento']) : '—' ?>
                                    </div>
                                    <div class="col-md-6">
                                        <strong>Código:</strong> <?= e($pac['codigo']) ?><br>
                                        <strong>Teléfono:</strong> <?= e($pac['telefono'] ?? '—') ?><br>
                                        <strong>Fecha:</strong> <?= date('d/m/Y') ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Datos del control -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label">Tipo de registro</label>
                            <select name="tipo" class="form-select" required>
                                <option value="instalacion" <?= $ortodoncia['tipo'] === 'instalacion' ? 'selected' : '' ?>>Instalación</option>
                                <option value="control" <?= $ortodoncia['tipo'] === 'control' ? 'selected' : '' ?>>Control</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Historia Clínica</label>
                            <select name="hc_id" class="form-select">
                                <option value="">— Sin vincular —</option>
                                <?php foreach ($hcs as $hc): ?>
                                    <option value="<?= $hc['id'] ?>" <?= $ortodoncia['hc_id'] == $hc['id'] ? 'selected' : '' ?>>
                                        <?= e($hc['numero_hc']) ?> (<?= fDate($hc['fecha_apertura']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Fecha de atención *</label>
                            <input type="date" name="fecha_atencion" class="form-control" value="<?= $ortodoncia['fecha_atencion'] ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Fecha de referencia</label>
                            <input type="date" name="fecha_referencia" class="form-control" value="<?= $ortodoncia['fecha_referencia'] ?>">
                        </div>
                    </div>
                    
                    <!-- Tipo de arco -->
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <label class="form-label">Tipo de arco utilizado</label>
                            <div class="row g-2">
                                <div class="col-6 col-md-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="arco_acero" id="arco_acero" <?= in_array('acero', $tipos_arco) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="arco_acero">Acero</label>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="arco_niti" id="arco_niti" <?= in_array('niti', $tipos_arco) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="arco_niti">Niti</label>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="arco_termico" id="arco_termico" <?= in_array('termico', $tipos_arco) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="arco_termico">Térmico</label>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="arco_resorte" id="arco_resorte" <?= in_array('resorte', $tipos_arco) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="arco_resorte">Resorte</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Observaciones y procedimientos -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Observaciones/Motivo</label>
                            <textarea name="observaciones" class="form-control" rows="4" placeholder="Observaciones del tratamiento..."><?= e($ortodoncia['observaciones']) ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Procedimientos realizados</label>
                            <textarea name="procedimientos" class="form-control" rows="4" placeholder="Detalle de los procedimientos realizados..."><?= e($ortodoncia['procedimientos']) ?></textarea>
                        </div>
                    </div>
                    
                    <!-- Próximo control -->
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Próximo control</label>
                            <input type="date" name="proximo_control" class="form-control" value="<?= $ortodoncia['proximo_control'] ?>">
                        </div>
                    </div>
                    
                    <!-- Botones -->
                    <div class="d-flex gap-2 justify-content-end mt-4 pt-3" style="border-top:1px solid #dee2e6">
                        <a href="?paciente_id=<?= $paciente_id ?>" class="btn btn-dk">Cancelar</a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-floppy me-2"></i><?= $accion === 'nuevo' ? 'Registrar' : 'Actualizar' ?>
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php
    require_once __DIR__ . '/../includes/footer.php';

} elseif ($accion === 'ver' && $id) {
    // Ver detalle de ortodoncia
    $ortodoncia = db()->prepare("SELECT o.*, CONCAT(u.nombre,' ',u.apellidos) AS doctor, hc.numero_hc 
                                 FROM ortodoncias o 
                                 LEFT JOIN usuarios u ON o.doctor_id = u.id 
                                 LEFT JOIN historias_clinicas hc ON o.hc_id = hc.id 
                                 WHERE o.id = ? AND o.paciente_id = ?");
    $ortodoncia->execute([$id, $paciente_id]);
    $ortodoncia = $ortodoncia->fetch();
    
    if (!$ortodoncia) {
        flash('error', 'Registro de ortodoncia no encontrado');
        go("pages/ortodoncias.php?paciente_id=$paciente_id");
    }
    
    $tipos_arco = json_decode($ortodoncia['tipo_arco'] ?? '[]', true) ?: [];
    
    $titulo = ($ortodoncia['tipo'] === 'instalacion' ? 'Instalación' : 'Control') . ' de Ortodoncia';
    $pagina_activa = 'pac';
    $topbar_act = '<a href="?paciente_id='.$paciente_id.'" class="btn btn-dk btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver</a>';
    
    require_once __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-12 col-xl-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span style="color:var(--t)">
                    <i class="bi bi-grid-3x2-gap me-2"></i>
                    <?= $ortodoncia['tipo'] === 'instalacion' ? 'Instalación' : 'Control' ?> de Ortodoncia
                </span>
                <div class="d-flex gap-2">
                    <a href="?accion=editar&id=<?= $id ?>&paciente_id=<?= $paciente_id ?>" class="btn btn-dk btn-sm"><i class="bi bi-pencil me-1"></i>Editar</a>
                </div>
            </div>
            
            <div class="p-4">
                <!-- Datos del paciente -->
                <div class="card p-3 mb-4" style="background:#f8f9fa">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <strong>Paciente:</strong> <?= e($pac['nombres'] . ' ' . $pac['apellido_paterno'] . ' ' . ($pac['apellido_materno'] ?? '')) ?><br>
                            <strong>DNI:</strong> <?= e($pac['dni'] ?? '—') ?><br>
                            <strong>Edad:</strong> <?= $pac['fecha_nacimiento'] ? edad($pac['fecha_nacimiento']) : '—' ?>
                        </div>
                        <div class="col-md-6">
                            <strong>Código Paciente:</strong> <?= e($pac['codigo']) ?><br>
                            <strong>Fecha de atención:</strong> <?= fDate($ortodoncia['fecha_atencion']) ?><br>
                            <?php if ($ortodoncia['fecha_referencia']): ?>
                            <strong>Fecha de referencia:</strong> <?= fDate($ortodoncia['fecha_referencia']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Detalles del registro -->
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="card p-3">
                            <h6 style="color:#1e40af;margin-bottom:12px"><i class="bi bi-info-circle me-2"></i>Información del Registro</h6>
                            <div class="row g-2">
                                <div class="col-6">
                                    <small style="color:var(--t2);font-size:11px">Tipo</small>
                                    <div style="font-size:13px;color:var(--t)">
                                        <?= $ortodoncia['tipo'] === 'instalacion' ? 'Instalación' : 'Control' ?>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <small style="color:var(--t2);font-size:11px">Historia Clínica</small>
                                    <div style="font-size:13px;color:var(--t)">
                                        <?= $ortodoncia['numero_hc'] ? e($ortodoncia['numero_hc']) : '—' ?>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <small style="color:var(--t2);font-size:11px">Doctor</small>
                                    <div style="font-size:13px;color:var(--t)">
                                        <?= e($ortodoncia['doctor'] ?? '—') ?>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <small style="color:var(--t2);font-size:11px">Fecha de registro</small>
                                    <div style="font-size:13px;color:var(--t)">
                                        <?= fDate($ortodoncia['created_at']) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card p-3">
                            <h6 style="color:#1e40af;margin-bottom:12px"><i class="bi bi-gear me-2"></i>Tipo de Arco</h6>
                            <?php if ($tipos_arco): ?>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php foreach ($tipos_arco as $tipo): ?>
                                        <?php 
                                        $badge_class = [
                                            'acero' => 'bg-secondary',
                                            'niti' => 'bg-info',
                                            'termico' => 'bg-warning',
                                            'resorte' => 'bg-success'
                                        ][$tipo] ?? 'bg-light text-dark';
                                        ?>
                                        <span class="badge <?= $badge_class ?>" style="font-size:11px">
                                            <?= ucfirst($tipo) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div style="color:var(--t2);font-size:13px">No se especificó tipo de arco</div>
                            <?php endif; ?>
                            
                            <?php if ($ortodoncia['proximo_control']): ?>
                            <div class="mt-3 pt-3" style="border-top:1px solid #dee2e6">
                                <small style="color:var(--t2);font-size:11px">Próximo control</small>
                                <div style="font-size:13px;color:var(--t);font-weight:600">
                                    <?= fDate($ortodoncia['proximo_control']) ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Observaciones -->
                <?php if ($ortodoncia['observaciones']): ?>
                <div class="card p-3 mb-4">
                    <h6 style="color:#1e40af;margin-bottom:12px"><i class="bi bi-chat-left-text me-2"></i>Observaciones/Motivo</h6>
                    <div style="color:var(--t);font-size:13px;line-height:1.6">
                        <?= nl2br(e($ortodoncia['observaciones'])) ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Procedimientos -->
                <?php if ($ortodoncia['procedimientos']): ?>
                <div class="card p-3">
                    <h6 style="color:#1e40af;margin-bottom:12px"><i class="bi bi-clipboard-check me-2"></i>Procedimientos Realizados</h6>
                    <div style="color:var(--t);font-size:13px;line-height:1.6">
                        <?= nl2br(e($ortodoncia['procedimientos'])) ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php
    require_once __DIR__ . '/../includes/footer.php';
}
?>