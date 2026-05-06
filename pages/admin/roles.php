<?php
require_once __DIR__.'/../../includes/config.php';
requiereRol('admin');

$titulo = 'Permisos por Rol';
$pagina_activa = 'roles';

// Catálogo de módulos del sistema (clave => [etiqueta, icono, sección])
$modulos = [
    'dashboard'        => ['Dashboard',           'bi-grid-fill',                'Principal'],
    'pacientes'        => ['Pacientes',           'bi-people-fill',              'Atención'],
    'citas'            => ['Agenda / Citas',      'bi-calendar2-week-fill',      'Atención'],
    'historia_clinica' => ['Historia Clínica',    'bi-file-medical-fill',        'Atención'],
    'odontograma'      => ['Odontograma',         'bi-grid-3x3-gap-fill',        'Atención'],
    'tratamientos'     => ['Tratamientos',        'bi-clipboard2-pulse-fill',    'Clínica'],
    'facturacion'      => ['Facturación',         'bi-cash-coin',                'Clínica'],
    'inventario'       => ['Inventario',          'bi-box-seam-fill',            'Clínica'],
    'notificaciones'   => ['WhatsApp / Notif.',   'bi-whatsapp',                 'Clínica'],
    'turnos'           => ['Pantalla Turnos',     'bi-display',                  'Clínica'],
    'reportes'         => ['Reportes',            'bi-bar-chart-fill',           'Reportes'],
    'documentos'       => ['Series y Correlativos','bi-list-ol',                 'Documentos'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ap = $_POST['accion'] ?? '';
    if ($ap === 'guardar') {
        $perms = $_POST['perms'] ?? []; // array [rol_id => [modulo, modulo...]]
        try {
            db()->beginTransaction();
            // No tocar el rol admin (siempre tiene todo)
            db()->query("DELETE FROM rol_modulo WHERE rol_id != 1");
            $st = db()->prepare("INSERT INTO rol_modulo (rol_id, modulo) VALUES (?, ?)");
            foreach ($perms as $rolId => $mods) {
                $rolId = (int)$rolId;
                if ($rolId === 1) continue; // admin no se gestiona
                foreach ((array)$mods as $m) {
                    if (isset($modulos[$m])) $st->execute([$rolId, $m]);
                }
            }
            db()->commit();
            auditar('GUARDAR_PERMISOS', 'rol_modulo', 0);
            flash('ok', 'Permisos actualizados. Los usuarios verán los cambios al refrescar la página.');
        } catch (Throwable $e) {
            db()->rollBack();
            flash('error', 'Error al guardar: ' . $e->getMessage());
        }
        go('pages/admin/roles.php');
    }
}

// Cargar roles y permisos actuales
$roles = db()->query("SELECT id, nombre FROM roles ORDER BY id")->fetchAll();
$perms = [];
$st = db()->query("SELECT rol_id, modulo FROM rol_modulo");
foreach ($st->fetchAll() as $row) {
    $perms[(int)$row['rol_id']][$row['modulo']] = true;
}

// Agrupar módulos por sección
$secciones = [];
foreach ($modulos as $key => [$lbl, $ico, $sec]) {
    $secciones[$sec][$key] = ['lbl'=>$lbl, 'ico'=>$ico];
}

require_once __DIR__.'/../../includes/header.php';
?>

<div class="card mb-3">
 <div class="p-3" style="font-size:12px;color:var(--t2)">
  <i class="bi bi-info-circle me-1"></i>
  Marca los módulos que cada rol puede ver. <strong>El rol "admin" siempre tiene acceso completo</strong> y no se puede modificar aquí.
 </div>
</div>

<form method="POST">
 <input type="hidden" name="accion" value="guardar">

 <?php foreach ($secciones as $sec => $mods): ?>
 <div class="card mb-3">
  <div class="card-header"><span style="color:var(--t)"><i class="bi bi-folder me-1"></i><?=e($sec)?></span></div>
  <div class="table-responsive">
   <table class="table mb-0" style="table-layout:fixed">
    <thead>
     <tr>
      <th style="min-width:220px">Módulo</th>
      <?php foreach ($roles as $rol):
        $isAdmin = ((int)$rol['id'] === 1);
      ?>
       <th class="text-center" style="width:120px">
        <span class="badge <?=$isAdmin?'bb':($rol['nombre']==='doctor'?'bc':($rol['nombre']==='recepcion'?'ba':($rol['nombre']==='contador'?'bg':'bgr')))?>"><?=strtoupper(e($rol['nombre']))?></span>
        <?php if($isAdmin): ?><br><small style="color:var(--t2);font-size:9px">(siempre todo)</small><?php endif; ?>
       </th>
      <?php endforeach; ?>
     </tr>
    </thead>
    <tbody>
     <?php foreach ($mods as $key => $info): ?>
     <tr>
      <td>
       <i class="bi <?=e($info['ico'])?> me-2" style="color:var(--c)"></i>
       <strong><?=e($info['lbl'])?></strong>
       <br><small class="mon" style="font-size:10px;color:var(--t3)"><?=e($key)?></small>
      </td>
      <?php foreach ($roles as $rol):
        $rolId   = (int)$rol['id'];
        $isAdmin = $rolId === 1;
        $checked = $isAdmin || isset($perms[$rolId][$key]);
      ?>
       <td class="text-center">
        <input
         type="checkbox"
         name="perms[<?=$rolId?>][]"
         value="<?=e($key)?>"
         class="form-check-input"
         style="width:20px;height:20px;cursor:<?=$isAdmin?'not-allowed':'pointer'?>;<?=$isAdmin?'opacity:.5':''?>"
         <?=$checked?'checked':''?>
         <?=$isAdmin?'disabled':''?>>
       </td>
      <?php endforeach; ?>
     </tr>
     <?php endforeach; ?>
    </tbody>
   </table>
  </div>
 </div>
 <?php endforeach; ?>

 <div class="d-flex gap-2 justify-content-end mb-4">
  <button type="submit" class="btn btn-primary px-4"><i class="bi bi-floppy me-2"></i>Guardar permisos</button>
 </div>
</form>

<?php require_once __DIR__.'/../../includes/footer.php'; ?>
