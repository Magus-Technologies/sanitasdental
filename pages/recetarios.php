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

// ── POST: guardar receta ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {
    $hc_id = (int)($_POST['hc_id'] ?? 0);
    $fecha_prescripcion = $_POST['fecha_prescripcion'] ?? date('Y-m-d');
    $valido_hasta = $_POST['valido_hasta'] ?? null;
    $indicaciones = trim($_POST['indicaciones_generales'] ?? '');
    $medicamentos = json_decode($_POST['medicamentos_json'] ?? '[]', true);
    
    if (empty($medicamentos)) {
        flash('error', 'Debe agregar al menos un medicamento');
    } else {
        $codigo = genCodigo('REC', 'recetas');
        
        // Insertar receta
        db()->prepare("INSERT INTO recetas(paciente_id,hc_id,codigo,fecha_prescripcion,valido_hasta,indicaciones_generales,doctor_id) VALUES(?,?,?,?,?,?,?)")
           ->execute([$paciente_id, $hc_id ?: null, $codigo, $fecha_prescripcion, $valido_hasta ?: null, $indicaciones, $_SESSION['uid']]);
        
        $receta_id = db()->lastInsertId();
        
        // Insertar medicamentos
        foreach ($medicamentos as $i => $med) {
            if (!empty($med['medicamento'])) {
                db()->prepare("INSERT INTO receta_medicamentos(receta_id,medicamento,inventario_id,numero_tomas,frecuencia,hora_sugerida,indicaciones,orden) VALUES(?,?,?,?,?,?,?,?)")
                   ->execute([$receta_id, $med['medicamento'], $med['inventario_id'] ?? null, $med['numero_tomas'] ?? 1, $med['frecuencia'] ?? '', $med['hora_sugerida'] ?? '', $med['indicaciones'] ?? '', $i + 1]);
            }
        }
        
        auditar('CREAR_RECETA', 'recetas', $receta_id);
        flash('ok', "Receta creada correctamente. Código: $codigo");
        go("pages/recetarios.php?paciente_id=$paciente_id");
    }
}

if ($accion === 'lista') {
    // Listar recetas del paciente
    $recetas = db()->prepare("SELECT r.*, CONCAT(u.nombre,' ',u.apellidos) AS doctor, hc.numero_hc 
                              FROM recetas r 
                              LEFT JOIN usuarios u ON r.doctor_id = u.id 
                              LEFT JOIN historias_clinicas hc ON r.hc_id = hc.id 
                              WHERE r.paciente_id = ? 
                              ORDER BY r.fecha_prescripcion DESC");
    $recetas->execute([$paciente_id]);
    $recetas = $recetas->fetchAll();
    
    $titulo = 'Recetarios — ' . $pac['nombres'] . ' ' . $pac['apellido_paterno'];
    $pagina_activa = 'pac';
    $topbar_act = '<a href="?accion=nueva&paciente_id=' . $paciente_id . '" class="btn btn-primary"><i class="bi bi-plus me-1"></i>Nueva receta</a>
    <a href="' . BASE_URL . '/pages/pacientes.php?accion=ver&id=' . $paciente_id . '" class="btn btn-dk btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver</a>';
    
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
        <span><i class="bi bi-prescription2 me-2"></i>Historial de Recetas (<?= count($recetas) ?>)</span>
    </div>
    
    <?php if ($recetas): ?>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Fecha</th>
                        <th>Historia Clínica</th>
                        <th>Doctor</th>
                        <th>Estado</th>
                        <th>Válida hasta</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recetas as $r): ?>
                        <tr>
                            <td class="mon" style="color:var(--c);font-size:11px"><?= e($r['codigo']) ?></td>
                            <td><?= fDate($r['fecha_prescripcion']) ?></td>
                            <td><?= $r['numero_hc'] ? '<span class="badge bc">' . e($r['numero_hc']) . '</span>' : '—' ?></td>
                            <td><small><?= e($r['doctor'] ?? '—') ?></small></td>
                            <td>
                                <?php
                                $estado_class = ['activa' => 'bg', 'vencida' => 'ba', 'anulada' => 'br'][$r['estado']] ?? 'bgr';
                                ?>
                                <span class="badge <?= $estado_class ?>"><?= strtoupper($r['estado']) ?></span>
                            </td>
                            <td><?= $r['valido_hasta'] ? fDate($r['valido_hasta']) : '—' ?></td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a href="?accion=ver&id=<?= $r['id'] ?>&paciente_id=<?= $paciente_id ?>" class="btn btn-dk btn-ico" title="Ver"><i class="bi bi-eye"></i></a>
                                    <a href="?accion=imprimir&id=<?= $r['id'] ?>&paciente_id=<?= $paciente_id ?>" class="btn btn-ico bc" title="Imprimir"><i class="bi bi-printer"></i></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="p-5 text-center" style="color:var(--t2)">
            <i class="bi bi-prescription2" style="font-size:48px;display:block;margin-bottom:16px"></i>
            <h3 style="font-size:16px;margin-bottom:8px">Sin recetas registradas</h3>
            <p style="font-size:14px;margin-bottom:20px">Este paciente aún no tiene recetas médicas.</p>
            <a href="?accion=nueva&paciente_id=<?= $paciente_id ?>" class="btn btn-primary">
                <i class="bi bi-plus me-2"></i>Crear primera receta
            </a>
        </div>
    <?php endif; ?>
</div>

<?php
    require_once __DIR__ . '/../includes/footer.php';

} elseif ($accion === 'nueva') {
    // Obtener historias clínicas del paciente
    $hcs = db()->prepare("SELECT id, numero_hc, fecha_apertura FROM historias_clinicas WHERE paciente_id = ? ORDER BY fecha_apertura DESC");
    $hcs->execute([$paciente_id]);
    $hcs = $hcs->fetchAll();
    
    // Obtener medicamentos del inventario
    $meds = db()->query("SELECT id, codigo, nombre FROM inventario WHERE activo = 1 ORDER BY nombre")->fetchAll();
    
    $titulo = 'Nueva Receta — ' . $pac['nombres'] . ' ' . $pac['apellido_paterno'];
    $pagina_activa = 'pac';
    
    // CSS para estética de receta médica
    $xhead = '<style>
    .receta-form {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        border: 2px solid #dee2e6;
        border-radius: 12px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }
    .receta-form .form-control,
    .receta-form .form-select {
        background: #ffffff !important;
        color: #1e293b !important;
        border: 2px solid #cbd5e1 !important;
    }
    .receta-form .form-control::placeholder {
        color: #94a3b8 !important;
    }
    .receta-header {
        background: linear-gradient(135deg, var(--c) 0%, #0891b2 100%);
        color: white;
        padding: 20px;
        border-radius: 10px 10px 0 0;
        text-align: center;
    }
    .medicamento-item {
        background: white;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 10px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    .medicamento-item:hover {
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        transform: translateY(-1px);
        transition: all 0.2s ease;
    }
    .medicamento-item .form-label {
        color: #0f172a !important;
        font-weight: 700 !important;
        font-size: 13px !important;
    }
    .medicamento-item .form-control,
    .medicamento-item .form-select {
        background: #ffffff !important;
        color: #1e293b !important;
        border: 2px solid #cbd5e1 !important;
    }
    .medicamento-item .form-control::placeholder {
        color: #94a3b8 !important;
    }
    .btn-remove-med {
        background: #dc3545;
        border: none;
        color: white;
        border-radius: 50%;
        width: 30px;
        height: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    </style>';
    
    require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-xl-10">
        <form method="POST" id="fReceta">
            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="medicamentos_json" id="medicamentosJson" value="[]">
            
            <div class="receta-form">
                <div class="receta-header">
                    <h2 style="margin:0;font-size:24px"><i class="bi bi-prescription2 me-2"></i>RECETA MÉDICA</h2>
                    <p style="margin:8px 0 0;opacity:0.9"><?= getCfg('clinica_nombre', 'Clínica Dental') ?></p>
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
                    
                    <!-- Datos de la receta -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label" style="color:#0f172a;font-weight:700;font-size:14px">Historia Clínica</label>
                            <select name="hc_id" class="form-select">
                                <option value="">— Sin vincular —</option>
                                <?php foreach ($hcs as $hc): ?>
                                    <option value="<?= $hc['id'] ?>"><?= e($hc['numero_hc']) ?> (<?= fDate($hc['fecha_apertura']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" style="color:#0f172a;font-weight:700;font-size:14px">Fecha de prescripción</label>
                            <input type="date" name="fecha_prescripcion" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" style="color:#1e293b;font-weight:600;font-size:14px">Válida hasta</label>
                            <input type="date" name="valido_hasta" class="form-control" value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
                        </div>
                    </div>
                    
                    <!-- Panel de medicamentos -->
                    <div class="row g-3">
                        <div class="col-md-8">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 style="margin:0;color:#1e293b"><i class="bi bi-capsule me-2"></i>Medicamentos Prescritos</h5>
                                <button type="button" class="btn btn-primary btn-sm" onclick="agregarMedicamento()">
                                    <i class="bi bi-plus me-1"></i>Agregar medicamento
                                </button>
                            </div>
                            
                            <div id="medicamentosList">
                                <!-- Los medicamentos se agregan dinámicamente aquí -->
                            </div>
                            
                            <div class="mt-3">
                                <label class="form-label" style="color:#1e40af;font-weight:700;font-size:14px">Indicaciones generales</label>
                                <textarea name="indicaciones_generales" class="form-control" rows="3" placeholder="Indicaciones adicionales para el paciente..."></textarea>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="card p-3" style="background:#f8f9fa">
                                <h6><i class="bi bi-info-circle me-2"></i>Medicamentos Agregados</h6>
                                <div id="medicamentosResumen" style="font-size:12px;color:var(--t2)">
                                    Ningún medicamento agregado
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Botones -->
                    <div class="d-flex gap-2 justify-content-end mt-4 pt-3" style="border-top:1px solid #dee2e6">
                        <a href="?paciente_id=<?= $paciente_id ?>" class="btn btn-dk">Cancelar</a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-prescription2 me-2"></i>Generar Receta
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
let medicamentos = [];
let medicamentoCounter = 0;
let medicamentosInventario = <?=json_encode($meds)?>;

function buscarMedicamentos(medId, valor) {
    const lista = document.getElementById(`lista_med_${medId}`);
    
    if (!valor.trim()) {
        lista.style.display = 'none';
        return;
    }
    
    const filtro = valor.toLowerCase();
    const resultados = medicamentosInventario.filter(m => 
        m.nombre.toLowerCase().includes(filtro) || 
        m.codigo.toLowerCase().includes(filtro)
    );
    
    if (resultados.length === 0) {
        lista.innerHTML = '<div style="padding:10px;color:#94a3b8;font-size:12px">No hay medicamentos</div>';
        lista.style.display = 'block';
        return;
    }
    
    lista.innerHTML = resultados.map(m => `
        <div style="padding:10px;border-bottom:1px solid #e2e8f0;cursor:pointer;color:#1e293b;font-size:13px" 
             onmouseover="this.style.background='#f1f5f9'" 
             onmouseout="this.style.background='white'"
             onclick="seleccionarMedicamento('${medId}', '${m.nombre.replace(/'/g, "\\'")}', ${m.id})">
            <strong>${m.codigo}</strong> - ${m.nombre}
        </div>
    `).join('');
    
    lista.style.display = 'block';
}

function seleccionarMedicamento(id, nombre, inventario_id) {
    if (nombre) {
        const med = medicamentos.find(m => m.id === id);
        if (med) {
            med.medicamento = nombre;
            med.inventario_id = inventario_id || null;
            
            // Limpiar búsqueda
            document.getElementById(`buscar_med_${id}`).value = '';
            document.getElementById(`lista_med_${id}`).style.display = 'none';
            
            renderMedicamentos();
            updateResumen();
            saveData();
        }
    }
}

function agregarMedicamento() {
    medicamentoCounter++;
    const id = 'med_' + medicamentoCounter;
    
    const medicamento = {
        id: id,
        medicamento: '',
        numero_tomas: 1,
        frecuencia: '',
        hora_sugerida: '',
        indicaciones: '',
        inventario_id: null
    };
    
    medicamentos.push(medicamento);
    renderMedicamentos();
    updateResumen();
}

function eliminarMedicamento(id) {
    medicamentos = medicamentos.filter(m => m.id !== id);
    renderMedicamentos();
    updateResumen();
}

function renderMedicamentos() {
    const container = document.getElementById('medicamentosList');
    
    if (medicamentos.length === 0) {
        container.innerHTML = '<div class="text-center p-4" style="color:var(--t2)"><i class="bi bi-capsule" style="font-size:36px;display:block;margin-bottom:8px"></i>Haz clic en "Agregar medicamento" para comenzar</div>';
        return;
    }
    
    container.innerHTML = medicamentos.map(med => `
        <div class="medicamento-item" data-id="${med.id}">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <h6 style="margin:0;color:#1e40af"><i class="bi bi-capsule me-2"></i>Medicamento</h6>
                <button type="button" class="btn-remove-med" onclick="eliminarMedicamento('${med.id}')" title="Eliminar">
                    <i class="bi bi-x"></i>
                </button>
            </div>
            
            <div class="row g-2">
                <div class="col-12">
                    <label class="form-label" style="color:#1e293b;font-weight:600">Medicamento *</label>
                    <div class="position-relative">
                        <input type="text" class="form-control" id="buscar_med_${med.id}" placeholder="Buscar medicamento..." 
                               onkeyup="buscarMedicamentos('${med.id}', this.value)" autocomplete="off">
                        <div id="lista_med_${med.id}" class="position-absolute w-100" style="top:100%;left:0;background:white;border:1px solid #cbd5e1;border-top:none;border-radius:0 0 6px 6px;max-height:200px;overflow-y:auto;z-index:1000;display:none;box-shadow:0 4px 6px rgba(0,0,0,0.1)"></div>
                    </div>
                    <input type="text" class="form-control mt-2" placeholder="O escribe el nombre manualmente..." 
                           value="${med.medicamento}" onchange="updateMedicamento('${med.id}', 'medicamento', this.value)">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" style="color:#1e293b;font-weight:600">N° Tomas</label>
                    <input type="number" class="form-control" min="1" value="${med.numero_tomas}" 
                           onchange="updateMedicamento('${med.id}', 'numero_tomas', this.value)">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" style="color:#1e293b;font-weight:600">Frecuencia</label>
                    <select class="form-select" onchange="updateMedicamento('${med.id}', 'frecuencia', this.value)">
                        <option value="">—</option>
                        <option value="cada_8h" ${med.frecuencia === 'cada_8h' ? 'selected' : ''}>Cada 8 horas</option>
                        <option value="cada_12h" ${med.frecuencia === 'cada_12h' ? 'selected' : ''}>Cada 12 horas</option>
                        <option value="cada_24h" ${med.frecuencia === 'cada_24h' ? 'selected' : ''}>Cada 24 horas</option>
                        <option value="segun_necesidad" ${med.frecuencia === 'segun_necesidad' ? 'selected' : ''}>Según necesidad</option>
                    </select>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label" style="color:#1e293b;font-weight:600">Hora sugerida</label>
                    <input type="text" class="form-control" placeholder="Ej: 8:00, 16:00, 24:00" 
                           value="${med.hora_sugerida}" onchange="updateMedicamento('${med.id}', 'hora_sugerida', this.value)">
                </div>
                <div class="col-12">
                    <label class="form-label" style="color:#1e293b;font-weight:600">Indicaciones específicas</label>
                    <textarea class="form-control" rows="2" placeholder="Tomar con alimentos, antes de dormir, etc..." 
                              onchange="updateMedicamento('${med.id}', 'indicaciones', this.value)">${med.indicaciones}</textarea>
                </div>
            </div>
        </div>
    `).join('');
}

function updateMedicamento(id, field, value) {
    const med = medicamentos.find(m => m.id === id);
    if (med) {
        med[field] = value;
        updateResumen();
        saveData();
    }
}

function seleccionarMedicamento(id, nombre, inventario_id) {
    if (nombre) {
        const med = medicamentos.find(m => m.id === id);
        if (med) {
            med.medicamento = nombre;
            med.inventario_id = inventario_id || null;
            renderMedicamentos();
            updateResumen();
            saveData();
        }
    }
}

function filtrarMedicamentos(inputId, selectId) {
    const input = document.getElementById(inputId);
    const select = document.getElementById(selectId);
    const filtro = input.value.toLowerCase();
    
    for (let i = 0; i < select.options.length; i++) {
        const option = select.options[i];
        const texto = option.text.toLowerCase();
        option.style.display = texto.includes(filtro) ? '' : 'none';
    }
}

function updateResumen() {
    const resumen = document.getElementById('medicamentosResumen');
    
    if (medicamentos.length === 0) {
        resumen.innerHTML = 'Ningún medicamento agregado';
        return;
    }
    
    const html = medicamentos.map(med => {
        const nombre = med.medicamento || 'Sin nombre';
        const tomas = med.numero_tomas || 1;
        const freq = med.frecuencia || 'Sin frecuencia';
        return `<div style="margin-bottom:8px;padding:6px;background:white;border-radius:4px;border-left:3px solid var(--c)">
            <strong style="color:#1e293b">${nombre}</strong><br>
            <small style="color:#1e40af;font-weight:600">${tomas} toma(s) - ${freq}</small>
        </div>`;
    }).join('');
    
    resumen.innerHTML = html;
}

function saveData() {
    document.getElementById('medicamentosJson').value = JSON.stringify(medicamentos);
}

// Inicializar vacío (sin medicamentos)
document.addEventListener('DOMContentLoaded', () => {
    // No agregar medicamento automáticamente
    renderMedicamentos();
    saveData();
});
</script>

<?php
    require_once __DIR__ . '/../includes/footer.php';

} elseif ($accion === 'ver' && $id) {
    // Ver receta
    $receta = db()->prepare("SELECT r.*, CONCAT(u.nombre,' ',u.apellidos) AS doctor FROM recetas r LEFT JOIN usuarios u ON r.doctor_id = u.id WHERE r.id = ? AND r.paciente_id = ?");
    $receta->execute([$id, $paciente_id]);
    $receta = $receta->fetch();
    
    if (!$receta) {
        flash('error', 'Receta no encontrada');
        go("pages/recetarios.php?paciente_id=$paciente_id");
    }
    
    $medicamentos = db()->prepare("SELECT * FROM receta_medicamentos WHERE receta_id = ? ORDER BY orden");
    $medicamentos->execute([$id]);
    $medicamentos = $medicamentos->fetchAll();
    
    $titulo = 'Receta — ' . $receta['codigo'];
    $pagina_activa = 'pac';
    $topbar_act = '<a href="?accion=imprimir&id='.$id.'&paciente_id='.$paciente_id.'" class="btn btn-primary btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</a>
    <a href="?paciente_id='.$paciente_id.'" class="btn btn-dk btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver</a>';
    
    require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header" style="background:linear-gradient(135deg,var(--c) 0%,#0891b2 100%);color:white;padding:20px">
                <h2 style="margin:0;font-size:24px"><i class="bi bi-prescription2 me-2"></i>RECETA MÉDICA</h2>
                <p style="margin:8px 0 0;opacity:0.9"><?=getCfg('clinica_nombre','Clínica Dental')?></p>
            </div>
            
            <div class="p-4">
                <!-- Datos del paciente -->
                <div class="card p-3 mb-4" style="background:#f8f9fa">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <strong>Paciente:</strong> <?=e($pac['nombres'].' '.$pac['apellido_paterno'].' '.($pac['apellido_materno']??''))?><br>
                            <strong>DNI:</strong> <?=e($pac['dni']??'—')?><br>
                            <strong>Edad:</strong> <?=$pac['fecha_nacimiento']?edad($pac['fecha_nacimiento']):'—'?>
                        </div>
                        <div class="col-md-6">
                            <strong>Código:</strong> <?=e($receta['codigo'])?><br>
                            <strong>Fecha:</strong> <?=fDate($receta['fecha_prescripcion'])?><br>
                            <strong>Válida hasta:</strong> <?=$receta['valido_hasta']?fDate($receta['valido_hasta']):'Sin límite'?>
                        </div>
                    </div>
                </div>
                
                <!-- Medicamentos -->
                <h5 style="color:#1e40af;margin-bottom:16px"><i class="bi bi-capsule me-2"></i>Medicamentos Prescritos</h5>
                <?php if($medicamentos): ?>
                    <div class="table-responsive mb-4">
                        <table class="table table-sm">
                            <thead style="background:#f8f9fa">
                                <tr>
                                    <th>Medicamento</th>
                                    <th>Tomas</th>
                                    <th>Frecuencia</th>
                                    <th>Hora</th>
                                    <th>Indicaciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($medicamentos as $m): ?>
                                <tr>
                                    <td><strong><?=e($m['medicamento'])?></strong></td>
                                    <td><?=$m['numero_tomas']?></td>
                                    <td><?=e($m['frecuencia']??'—')?></td>
                                    <td><?=e($m['hora_sugerida']??'—')?></td>
                                    <td><small><?=e($m['indicaciones']??'—')?></small></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                
                <!-- Indicaciones generales -->
                <?php if($receta['indicaciones_generales']): ?>
                <div class="card p-3 mb-4" style="background:#f0f9ff;border-left:4px solid var(--c)">
                    <h6 style="color:#1e40af;margin-bottom:8px">Indicaciones Generales</h6>
                    <p style="margin:0;color:var(--t)"><?=nl2br(e($receta['indicaciones_generales']))?></p>
                </div>
                <?php endif; ?>
                
                <!-- Doctor -->
                <div style="margin-top:40px;padding-top:20px;border-top:1px solid #dee2e6">
                    <p style="margin:0;color:var(--t2);font-size:12px">Prescrito por: <strong><?=e($receta['doctor']??'—')?></strong></p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
    require_once __DIR__ . '/../includes/footer.php';

} elseif ($accion === 'imprimir' && $id) {
    // Imprimir receta
    $receta = db()->prepare("SELECT r.*, CONCAT(u.nombre,' ',u.apellidos) AS doctor FROM recetas r LEFT JOIN usuarios u ON r.doctor_id = u.id WHERE r.id = ? AND r.paciente_id = ?");
    $receta->execute([$id, $paciente_id]);
    $receta = $receta->fetch();
    
    if (!$receta) {
        flash('error', 'Receta no encontrada');
        go("pages/recetarios.php?paciente_id=$paciente_id");
    }
    
    $medicamentos = db()->prepare("SELECT * FROM receta_medicamentos WHERE receta_id = ? ORDER BY orden");
    $medicamentos->execute([$id]);
    $medicamentos = $medicamentos->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Receta <?=e($receta['codigo'])?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; color: #333; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #00d4ee; padding-bottom: 15px; }
        .header h1 { margin: 0; color: #00d4ee; }
        .header p { margin: 5px 0; color: #666; }
        .patient-info { background: #f8f9fa; padding: 15px; margin-bottom: 20px; border-radius: 5px; }
        .patient-info div { margin: 5px 0; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        table th { background: #f8f9fa; padding: 10px; text-align: left; border-bottom: 2px solid #dee2e6; }
        table td { padding: 10px; border-bottom: 1px solid #dee2e6; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #dee2e6; text-align: center; font-size: 12px; color: #666; }
        @media print { body { margin: 0; } }
    </style>
</head>
<body>
    <div class="header">
        <h1>RECETA MÉDICA</h1>
        <p><?=getCfg('clinica_nombre','Clínica Dental')?></p>
    </div>
    
    <div class="patient-info">
        <div><strong>Paciente:</strong> <?=e($pac['nombres'].' '.$pac['apellido_paterno'].' '.($pac['apellido_materno']??''))?></div>
        <div><strong>DNI:</strong> <?=e($pac['dni']??'—')?></div>
        <div><strong>Código Receta:</strong> <?=e($receta['codigo'])?></div>
        <div><strong>Fecha:</strong> <?=fDate($receta['fecha_prescripcion'])?></div>
        <div><strong>Válida hasta:</strong> <?=$receta['valido_hasta']?fDate($receta['valido_hasta']):'Sin límite'?></div>
    </div>
    
    <h3>Medicamentos Prescritos</h3>
    <table>
        <thead>
            <tr>
                <th>Medicamento</th>
                <th>Tomas</th>
                <th>Frecuencia</th>
                <th>Hora</th>
                <th>Indicaciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($medicamentos as $m): ?>
            <tr>
                <td><strong><?=e($m['medicamento'])?></strong></td>
                <td><?=$m['numero_tomas']?></td>
                <td><?=e($m['frecuencia']??'—')?></td>
                <td><?=e($m['hora_sugerida']??'—')?></td>
                <td><?=e($m['indicaciones']??'—')?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <?php if($receta['indicaciones_generales']): ?>
    <h3>Indicaciones Generales</h3>
    <p><?=nl2br(e($receta['indicaciones_generales']))?></p>
    <?php endif; ?>
    
    <div class="footer">
        <p>Prescrito por: <?=e($receta['doctor']??'—')?></p>
        <p>Impreso: <?=date('d/m/Y H:i')?></p>
    </div>
    
    <script>window.print();</script>
</body>
</html>
<?php
}
?>