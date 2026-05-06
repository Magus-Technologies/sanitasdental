<?php
require_once __DIR__.'/../../includes/config.php';
requiereRol('admin');

$accion = $_GET['accion'] ?? 'lista';
$id     = (int)($_GET['id'] ?? 0);

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ap = $_POST['accion'] ?? '';

    // ── Guardar usuario (crear / editar) ──────────────────────
    if ($ap === 'guardar') {
        $editId  = (int)($_POST['id'] ?? 0);
        $nombre  = trim($_POST['nombre'] ?? '');
        $apells  = trim($_POST['apellidos'] ?? '');
        $email   = strtolower(trim($_POST['email'] ?? ''));
        $pass    = $_POST['password'] ?? '';
        $pass2   = $_POST['password2'] ?? '';
        $rol_id  = (int)($_POST['rol_id'] ?? 3);
        $activo  = isset($_POST['activo']) ? 1 : 0;

        $errores = [];

        // Validaciones
        if (!$nombre)   $errores[] = 'El nombre es obligatorio.';
        if (!$apells)   $errores[] = 'Los apellidos son obligatorios.';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL))
            $errores[] = 'Email inválido.';
        if (!$editId && empty($pass))
            $errores[] = 'La contraseña es obligatoria para nuevos usuarios.';
        if (!empty($pass) && strlen($pass) < 6)
            $errores[] = 'La contraseña debe tener al menos 6 caracteres.';
        if (!empty($pass) && $pass !== $pass2)
            $errores[] = 'Las contraseñas no coinciden.';

        // Verificar email único
        if (!$errores) {
            $chk = db()->prepare("SELECT id FROM usuarios WHERE email=? AND id!=?");
            $chk->execute([$email, $editId ?: 0]);
            if ($chk->fetchColumn()) $errores[] = "El email '$email' ya está registrado.";
        }

        if ($errores) {
            flash('error', implode('<br>', $errores));
            go('pages/admin/usuarios.php?accion='.($editId ? 'editar&id='.$editId : 'nuevo'));
        }

        $datos = [
            'rol_id'      => $rol_id,
            'nombre'      => $nombre,
            'apellidos'   => $apells,
            'dni'         => trim($_POST['dni'] ?? '') ?: null,
            'email'       => $email,
            'telefono'    => trim($_POST['telefono'] ?? '') ?: null,
            'especialidad'=> trim($_POST['especialidad'] ?? '') ?: null,
            'cmp'         => trim($_POST['cmp'] ?? '') ?: null,
            'activo'      => $activo,
        ];

        try {
            if ($editId) {
                // EDITAR
                $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($datos)));
                $vals = array_values($datos);
                if (!empty($pass)) {
                    $sets .= ',password=?';
                    $vals[] = password_hash($pass, PASSWORD_BCRYPT);
                }
                $vals[] = $editId;
                db()->prepare("UPDATE usuarios SET $sets, updated_at=NOW() WHERE id=?")->execute($vals);
                auditar('EDITAR_USUARIO', 'usuarios', $editId);
                flash('ok', 'Usuario actualizado correctamente.');
            } else {
                // CREAR
                $datos['password'] = password_hash($pass, PASSWORD_BCRYPT);
                $cols = implode(',', array_keys($datos));
                $phs  = implode(',', array_fill(0, count($datos), '?'));
                db()->prepare("INSERT INTO usuarios ($cols) VALUES ($phs)")->execute(array_values($datos));
                $newId = (int)db()->lastInsertId();
                auditar('CREAR_USUARIO', 'usuarios', $newId);
                flash('ok', 'Usuario creado correctamente.');
            }
            go('pages/admin/usuarios.php');
        } catch (\PDOException $e) {
            flash('error', 'Error al guardar: ' . $e->getMessage());
            go('pages/admin/usuarios.php?accion='.($editId ? 'editar&id='.$editId : 'nuevo'));
        }
    }

    // ── Toggle activo/inactivo ────────────────────────────────
    if ($ap === 'toggle') {
        $tid  = (int)($_POST['uid'] ?? 0);
        $curr = (int)db()->query("SELECT activo FROM usuarios WHERE id=$tid")->fetchColumn();
        $new  = $curr ? 0 : 1;
        db()->prepare("UPDATE usuarios SET activo=? WHERE id=?")->execute([$new, $tid]);
        auditar($new ? 'ACTIVAR_USUARIO' : 'DESACTIVAR_USUARIO', 'usuarios', $tid);
        flash('ok', 'Usuario '.($new ? 'activado' : 'desactivado').'.');
        go('pages/admin/usuarios.php');
    }

    // ── Cambiar contraseña rápida ──────────────────────────────
    if ($ap === 'reset_pass') {
        $uid  = (int)($_POST['uid'] ?? 0);
        $np   = $_POST['nueva_pass'] ?? '';
        $np2  = $_POST['nueva_pass2'] ?? '';
        if (strlen($np) < 6) { flash('error','Mínimo 6 caracteres.'); go('pages/admin/usuarios.php?accion=editar&id='.$uid); }
        if ($np !== $np2)    { flash('error','Las contraseñas no coinciden.'); go('pages/admin/usuarios.php?accion=editar&id='.$uid); }
        db()->prepare("UPDATE usuarios SET password=?, updated_at=NOW() WHERE id=?")->execute([password_hash($np, PASSWORD_BCRYPT), $uid]);
        auditar('RESET_PASSWORD', 'usuarios', $uid);
        flash('ok', 'Contraseña actualizada correctamente.');
        go('pages/admin/usuarios.php');
    }
}

// ══════════════════════════════════════════════════════════════
// LISTA DE USUARIOS
// ══════════════════════════════════════════════════════════════
if ($accion === 'lista') {
    $titulo = 'Usuarios y Roles';
    $pagina_activa = 'usr';
    $buscar = trim($_GET['q'] ?? '');

    $w = 'WHERE 1=1'; $pm = [];
    if ($buscar) {
        $w .= ' AND (u.nombre LIKE ? OR u.apellidos LIKE ? OR u.email LIKE ? OR u.dni LIKE ?)';
        $b = "%$buscar%"; $pm = [$b,$b,$b,$b];
    }
    $st = db()->prepare("SELECT u.*, r.nombre AS rol FROM usuarios u JOIN roles r ON u.rol_id=r.id $w ORDER BY u.activo DESC, r.id, u.nombre");
    $st->execute($pm); $lista = $st->fetchAll();

    // Contadores por rol
    $stats = db()->query("SELECT r.nombre, COUNT(u.id) AS cnt FROM roles r LEFT JOIN usuarios u ON u.rol_id=r.id AND u.activo=1 GROUP BY r.id ORDER BY r.id")->fetchAll();

    $topbar_act = '<a href="?accion=nuevo" class="btn btn-primary"><i class="bi bi-person-plus me-2"></i>Nuevo usuario</a>';
    require_once __DIR__.'/../../includes/header.php';

    $rcls = ['admin'=>'br','doctor'=>'bc','recepcion'=>'bg','contador'=>'ba','paciente'=>'bgr'];
    $ricos = ['admin'=>'bi-shield-fill','doctor'=>'bi-person-badge-fill','recepcion'=>'bi-telephone-fill','contador'=>'bi-calculator-fill','paciente'=>'bi-person-heart'];
?>

<!-- Stats por rol -->
<div class="row g-3 mb-4">
<?php foreach ($stats as $st): ?>
<div class="col-6 col-md-2">
    <div class="kpi kc" style="padding:12px 14px">
        <div class="kpi-ico" style="width:34px;height:34px;font-size:15px;margin-bottom:8px">
            <i class="bi <?= $ricos[$st['nombre']] ?? 'bi-person' ?>"></i>
        </div>
        <div class="kpi-v" style="font-size:20px"><?= $st['cnt'] ?></div>
        <div class="kpi-l"><?= ucfirst($st['nombre']) ?></div>
        <div class="kpi-s"></div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Buscador -->
<div class="card mb-3 p-3">
    <form method="GET" class="d-flex gap-2 flex-wrap align-items-end">
        <div class="flex-fill" style="min-width:200px">
            <label class="form-label">Buscar usuario</label>
            <input type="text" name="q" class="form-control" placeholder="Nombre, email, DNI..." value="<?= e($buscar) ?>">
        </div>
        <div class="d-flex gap-2 align-items-end">
            <button type="submit" class="btn btn-dk"><i class="bi bi-search me-1"></i>Buscar</button>
            <?php if ($buscar): ?><a href="?" class="btn btn-dk">✕ Limpiar</a><?php endif; ?>
        </div>
        <small class="ms-auto mt-2" style="color:var(--t2)"><?= count($lista) ?> usuario<?= count($lista)!=1?'s':'' ?></small>
    </form>
</div>

<!-- Tabla de usuarios -->
<div class="card">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th>Usuario</th>
                    <th class="d-none d-md-table-cell">Email</th>
                    <th>Rol</th>
                    <th class="d-none d-lg-table-cell">Especialidad / CMP</th>
                    <th>Estado</th>
                    <th class="d-none d-md-table-cell">Último acceso</th>
                    <th style="width:100px">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($lista as $u): ?>
            <tr style="<?= !$u['activo'] ? 'opacity:.55' : '' ?>">
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="ava" style="width:36px;height:36px;font-size:13px;background:<?= ['admin'=>'linear-gradient(135deg,#E05252,#B91C1C)','doctor'=>'linear-gradient(135deg,#00D4EE,#0891B2)','recepcion'=>'linear-gradient(135deg,#2ECC8E,#059669)','contador'=>'linear-gradient(135deg,#F5A623,#D97706)','paciente'=>'linear-gradient(135deg,#8B5CF6,#6D28D9)'][$u['rol']] ?? 'linear-gradient(135deg,var(--c2),var(--c))' ?>">
                            <?= strtoupper(substr($u['nombre'],0,1)) ?>
                        </div>
                        <div>
                            <strong style="color:var(--t)"><?= e($u['nombre'].' '.$u['apellidos']) ?></strong>
                            <?php if ($u['dni']): ?>
                            <br><small style="color:var(--t2)">DNI: <?= e($u['dni']) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td class="d-none d-md-table-cell">
                    <small style="color:var(--t2)"><?= e($u['email']) ?></small>
                </td>
                <td>
                    <span class="badge <?= $rcls[$u['rol']] ?? 'bgr' ?>">
                        <i class="bi <?= $ricos[$u['rol']] ?? 'bi-person' ?> me-1"></i>
                        <?= ucfirst($u['rol']) ?>
                    </span>
                </td>
                <td class="d-none d-lg-table-cell">
                    <?php if ($u['especialidad']): ?><small style="color:var(--t)"><?= e($u['especialidad']) ?></small><?php endif; ?>
                    <?php if ($u['cmp']): ?><br><small style="color:var(--t2)"><?= e($u['cmp']) ?></small><?php endif; ?>
                    <?php if (!$u['especialidad'] && !$u['cmp']): ?><small style="color:var(--t3)">—</small><?php endif; ?>
                </td>
                <td>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="accion" value="toggle">
                        <input type="hidden" name="uid" value="<?= $u['id'] ?>">
                        <button type="submit" class="badge <?= $u['activo'] ? 'bg' : 'br' ?>" style="border:none;cursor:pointer;padding:5px 10px"
                            onclick="return confirm('<?= $u['activo'] ? 'Desactivar' : 'Activar' ?> a <?= e($u['nombre']) ?>?')">
                            <i class="bi bi-<?= $u['activo'] ? 'check-circle' : 'x-circle' ?> me-1"></i>
                            <?= $u['activo'] ? 'Activo' : 'Inactivo' ?>
                        </button>
                    </form>
                </td>
                <td class="d-none d-md-table-cell">
                    <small style="color:var(--t2)">
                        <?= $u['ultimo_acceso'] ? fDT($u['ultimo_acceso']) : '<span style="color:var(--t3)">Nunca</span>' ?>
                    </small>
                </td>
                <td>
                    <div class="d-flex gap-1">
                        <a href="?accion=editar&id=<?= $u['id'] ?>" class="btn btn-dk btn-ico" title="Editar">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <a href="?accion=ver&id=<?= $u['id'] ?>" class="btn btn-dk btn-ico" title="Ver perfil">
                            <i class="bi bi-eye"></i>
                        </a>
                        <?php if ($u['id'] !== ($_SESSION['uid'] ?? 0)): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('<?= $u['activo'] ? '¿Desactivar' : '¿Activar' ?> a <?= e($u['nombre'].' '.$u['apellidos']) ?>?')">
                            <input type="hidden" name="accion" value="toggle">
                            <input type="hidden" name="uid" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-ico <?= $u['activo'] ? 'btn-del' : 'btn-ok' ?>" title="<?= $u['activo'] ? 'Desactivar' : 'Activar' ?> usuario">
                                <i class="bi bi-<?= $u['activo'] ? 'slash-circle' : 'check-circle' ?>"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$lista): ?>
            <tr><td colspan="7" class="text-center py-5" style="color:var(--t2)">
                <i class="bi bi-people" style="font-size:36px;display:block;margin-bottom:10px"></i>
                No se encontraron usuarios
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__.'/../../includes/footer.php';

// ══════════════════════════════════════════════════════════════
// VER PERFIL DE USUARIO
// ══════════════════════════════════════════════════════════════
} elseif ($accion === 'ver' && $id) {
    $st = db()->prepare("SELECT u.*, r.nombre AS rol FROM usuarios u JOIN roles r ON u.rol_id=r.id WHERE u.id=?");
    $st->execute([$id]); $usr = $st->fetch();
    if (!$usr) { flash('error','Usuario no encontrado'); go('pages/admin/usuarios.php'); }

    // Actividad reciente
    $actividad = db()->prepare("SELECT * FROM auditoria WHERE usuario_id=? ORDER BY created_at DESC LIMIT 15");
    $actividad->execute([$id]); $actividad = $actividad->fetchAll();

    // Citas atendidas si es doctor
    $citas_cnt = 0;
    if ($usr['rol'] === 'doctor') {
        $s = db()->query("SELECT COUNT(*) FROM citas WHERE doctor_id=$id AND estado='atendido'");
        $citas_cnt = (int)$s->fetchColumn();
    }

    $titulo = $usr['nombre'].' '.$usr['apellidos'];
    $pagina_activa = 'usr';
    $rcls = ['admin'=>'br','doctor'=>'bc','recepcion'=>'bg','contador'=>'ba','paciente'=>'bgr'];
    $topbar_act = '<a href="?accion=editar&id='.$id.'" class="btn btn-primary btn-sm"><i class="bi bi-pencil me-1"></i>Editar</a>';
    require_once __DIR__.'/../../includes/header.php';
?>
<div class="row g-4">
    <div class="col-12 col-lg-4">
        <!-- Perfil card -->
        <div class="card mb-4">
            <div class="p-4 text-center" style="border-bottom:1px solid var(--bd2)">
                <div class="ava mx-auto mb-3" style="width:72px;height:72px;font-size:28px">
                    <?= strtoupper(substr($usr['nombre'],0,1)) ?>
                </div>
                <h2 style="font-size:17px;font-weight:800;margin:0;color:var(--t)"><?= e($usr['nombre'].' '.$usr['apellidos']) ?></h2>
                <p style="color:var(--t2);font-size:12px;margin:4px 0"><?= e($usr['email']) ?></p>
                <span class="badge <?= $rcls[$usr['rol']] ?? 'bgr' ?>" style="font-size:12px;padding:5px 12px">
                    <?= strtoupper($usr['rol']) ?>
                </span>
                <span class="badge <?= $usr['activo'] ? 'bg' : 'br' ?> ms-1">
                    <?= $usr['activo'] ? 'ACTIVO' : 'INACTIVO' ?>
                </span>
            </div>
            <div class="p-4" style="font-size:13px">
                <?php $info = [
                    ['bi-card-text','DNI', $usr['dni']??'—'],
                    ['bi-phone','Teléfono', $usr['telefono']??'—'],
                    ['bi-award','CMP', $usr['cmp']??'—'],
                    ['bi-briefcase','Especialidad', $usr['especialidad']??'—'],
                    ['bi-calendar','Registro', fDate($usr['created_at'])],
                    ['bi-clock','Último acceso', $usr['ultimo_acceso'] ? fDT($usr['ultimo_acceso']) : 'Nunca'],
                ];
                foreach ($info as [$ico,$lbl,$val]): ?>
                <div class="d-flex gap-2 mb-2 align-items-start">
                    <i class="bi <?= $ico ?>" style="color:var(--c);flex-shrink:0;margin-top:2px"></i>
                    <div>
                        <small style="color:var(--t2);display:block"><?= $lbl ?></small>
                        <span style="color:var(--t);font-weight:600"><?= e($val) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if ($usr['rol'] === 'doctor'): ?>
                <div class="mt-3 p-3 rounded text-center" style="background:rgba(0,212,238,.08);border:1px solid rgba(0,212,238,.2)">
                    <div style="font-size:24px;font-weight:800;color:var(--c);font-family:'DM Mono',monospace"><?= $citas_cnt ?></div>
                    <small style="color:var(--t2)">Citas atendidas</small>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Reset password rápido -->
        <div class="card mb-4">
            <div class="card-header"><span style="color:var(--t)"><i class="bi bi-key me-1"></i>Cambiar contraseña</span></div>
            <form method="POST" class="p-4">
                <input type="hidden" name="accion" value="reset_pass">
                <input type="hidden" name="uid" value="<?= $id ?>">
                <div class="mb-3">
                    <label class="form-label">Nueva contraseña</label>
                    <input type="password" name="nueva_pass" class="form-control" placeholder="Mínimo 6 caracteres" required minlength="6">
                </div>
                <div class="mb-3">
                    <label class="form-label">Confirmar contraseña</label>
                    <input type="password" name="nueva_pass2" class="form-control" placeholder="Repetir contraseña" required>
                </div>
                <button type="submit" class="btn btn-dk w-100" onclick="return confirm('¿Cambiar contraseña de <?= e($usr['nombre']) ?>?')">
                    <i class="bi bi-key me-2"></i>Actualizar contraseña
                </button>
            </form>
        </div>

        <!-- Toggle estado -->
        <form method="POST">
            <input type="hidden" name="accion" value="toggle">
            <input type="hidden" name="uid" value="<?= $id ?>">
            <button type="submit" class="btn <?= $usr['activo'] ? 'btn-del' : 'btn-ok' ?> w-100"
                onclick="return confirm('<?= $usr['activo'] ? 'Desactivar' : 'Activar' ?> usuario?')">
                <i class="bi bi-<?= $usr['activo'] ? 'person-x' : 'person-check' ?> me-2"></i>
                <?= $usr['activo'] ? 'Desactivar usuario' : 'Activar usuario' ?>
            </button>
        </form>
    </div>

    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header"><span style="color:var(--t)"><i class="bi bi-clock-history me-2"></i>Actividad reciente</span></div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead><tr><th>Fecha/Hora</th><th>Acción</th><th>Tabla</th><th class="d-none d-md-table-cell">IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($actividad as $a): ?>
                    <tr>
                        <td><small class="mon" style="color:var(--t)"><?= fDT($a['created_at']) ?></small></td>
                        <td>
                            <span class="badge <?=
                                str_contains($a['accion'],'LOGIN') ? 'bg' :
                                (str_contains($a['accion'],'CREAR') ? 'bc' :
                                (str_contains($a['accion'],'EDITAR') ? 'ba' :
                                (str_contains($a['accion'],'ELIMINAR')||str_contains($a['accion'],'ANULAR') ? 'br' : 'bgr'))) ?>">
                                <?= e($a['accion']) ?>
                            </span>
                        </td>
                        <td><small style="color:var(--t2)"><?= e($a['tabla']??'—') ?></small></td>
                        <td class="d-none d-md-table-cell"><small style="color:var(--t3)"><?= e($a['ip']??'—') ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$actividad): ?>
                    <tr><td colspan="4" class="text-center py-4" style="color:var(--t2)">Sin actividad registrada</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__.'/../../includes/footer.php';

// ══════════════════════════════════════════════════════════════
// FORMULARIO CREAR / EDITAR
// ══════════════════════════════════════════════════════════════
} elseif (in_array($accion, ['nuevo','editar'])) {
    $usr = ['id'=>0,'rol_id'=>3,'nombre'=>'','apellidos'=>'','dni'=>'','email'=>'','telefono'=>'','especialidad'=>'','cmp'=>'','activo'=>1];
    if ($accion === 'editar' && $id) {
        $s = db()->prepare("SELECT * FROM usuarios WHERE id=?");
        $s->execute([$id]); $usr = $s->fetch() ?: $usr;
    }
    $roles   = db()->query("SELECT * FROM roles ORDER BY id")->fetchAll();
    $titulo  = $accion === 'nuevo' ? 'Nuevo Usuario' : 'Editar: '.$usr['nombre'].' '.$usr['apellidos'];
    $pagina_activa = 'usr';
    $topbar_act = '<a href="?" class="btn btn-dk btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver</a>';
    require_once __DIR__.'/../../includes/header.php';

    // Descripción de roles
    $rol_desc = [
        1 => ['icon'=>'bi-shield-fill','color'=>'var(--r)','desc'=>'Acceso total al sistema. Gestión de usuarios, configuración y auditoría.'],
        2 => ['icon'=>'bi-person-badge-fill','color'=>'var(--c)','desc'=>'Acceso a historia clínica, odontograma, tratamientos y citas.'],
        3 => ['icon'=>'bi-telephone-fill','color'=>'var(--g)','desc'=>'Registro de pacientes, gestión de citas y cobros.'],
        4 => ['icon'=>'bi-calculator-fill','color'=>'var(--a)','desc'=>'Acceso a caja, pagos y reportes financieros.'],
        5 => ['icon'=>'bi-person-heart','color'=>'var(--p)','desc'=>'Portal de pacientes (solo ver sus propias citas e historial).'],
    ];
?>
<div class="row justify-content-center">
<div class="col-12 col-xl-9">
<form method="POST" id="fUsuario" novalidate>
    <input type="hidden" name="accion" value="guardar">
    <input type="hidden" name="id" value="<?= $usr['id'] ?>">

    <div class="row g-4">
        <!-- Columna principal -->
        <div class="col-12 col-lg-8">

            <!-- Datos personales -->
            <div class="card mb-4">
                <div class="card-header"><span style="color:var(--t)"><i class="bi bi-person me-2"></i>Datos personales</span></div>
                <div class="p-4">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label">Nombres *</label>
                            <input type="text" name="nombre" class="form-control" value="<?= e($usr['nombre']) ?>" required placeholder="Ej: Carlos Alberto">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Apellidos *</label>
                            <input type="text" name="apellidos" class="form-control" value="<?= e($usr['apellidos']) ?>" required placeholder="Ej: Mendoza Ríos">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">DNI <small style="color:var(--t2)">(8 dígitos)</small></label>
                            <div class="input-group">
                                <input type="text" id="dniInp" name="dni" class="form-control" value="<?= e($usr['dni']??'') ?>" maxlength="8" placeholder="12345678" inputmode="numeric">
                                <button type="button" class="btn btn-dk" id="btnBuscarDni" title="Consultar RENIEC"><i class="bi bi-search"></i></button>
                            </div>
                            <small id="dniMsg" style="font-size:11px"></small>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Teléfono</label>
                            <input type="text" name="telefono" class="form-control" value="<?= e($usr['telefono']??'') ?>" placeholder="987654321">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Estado</label>
                            <div class="p-2 rounded d-flex align-items-center gap-2" style="background:var(--bg3);border:1px solid var(--bd2);height:40px">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" name="activo" id="ckActivo" <?= $usr['activo'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="ckActivo" id="lblActivo" style="color:var(--t)">
                                        <?= $usr['activo'] ? '✅ Usuario activo' : '❌ Inactivo' ?>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-8">
                            <label class="form-label">Email * <small style="color:var(--t2)">(usado para iniciar sesión)</small></label>
                            <input type="email" name="email" class="form-control" value="<?= e($usr['email']) ?>" required placeholder="usuario@clinica.com">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Especialidad <small style="color:var(--t2)">(si aplica)</small></label>
                            <input type="text" name="especialidad" class="form-control" value="<?= e($usr['especialidad']??'') ?>" placeholder="Ortodoncia">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">CMP / Código profesional</label>
                            <input type="text" name="cmp" class="form-control" value="<?= e($usr['cmp']??'') ?>" placeholder="CMP-12345">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contraseña -->
            <div class="card mb-4">
                <div class="card-header"><span style="color:var(--t)"><i class="bi bi-key me-2"></i>Contraseña <?= $accion==='editar' ? '<small style="color:var(--t2);font-weight:400;text-transform:none">(dejar vacío para no cambiar)</small>' : '' ?></span></div>
                <div class="p-4">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label">Contraseña <?= $accion==='nuevo' ? '*' : '' ?></label>
                            <div class="input-group">
                                <input type="password" name="password" id="passInp" class="form-control" placeholder="Mínimo 6 caracteres" <?= $accion==='nuevo' ? 'required minlength="6"' : 'minlength="6"' ?>>
                                <button type="button" class="btn btn-dk" onclick="togglePass('passInp',this)"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Confirmar contraseña <?= $accion==='nuevo' ? '*' : '' ?></label>
                            <div class="input-group">
                                <input type="password" name="password2" id="pass2Inp" class="form-control" placeholder="Repetir contraseña" <?= $accion==='nuevo' ? 'required' : '' ?>>
                                <button type="button" class="btn btn-dk" onclick="togglePass('pass2Inp',this)"><i class="bi bi-eye"></i></button>
                            </div>
                            <div id="passMatch" style="font-size:11px;margin-top:4px"></div>
                        </div>
                        <?php if ($accion === 'nuevo'): ?>
                        <div class="col-12">
                            <div class="p-3 rounded" style="background:rgba(0,212,238,.06);border:1px solid rgba(0,212,238,.15);font-size:12px;color:var(--t2)">
                                💡 <strong style="color:var(--c)">Contraseña segura:</strong> usa al menos 8 caracteres, mayúsculas, números y símbolos.
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Columna: selección de rol -->
        <div class="col-12 col-lg-4">
            <div class="card mb-4" style="position:sticky;top:70px">
                <div class="card-header"><span style="color:var(--t)"><i class="bi bi-shield me-2"></i>Rol del usuario *</span></div>
                <div class="p-4">
                    <?php foreach ($roles as $r):
                        $rd = $rol_desc[$r['id']] ?? ['icon'=>'bi-person','color'=>'var(--t2)','desc'=>''];
                    ?>
                    <label class="d-block mb-2" style="cursor:pointer">
                        <div class="d-flex align-items-start gap-3 p-3 rounded" style="border:2px solid var(--bd2);transition:all .15s" id="rol-card-<?= $r['id'] ?>">
                            <input type="radio" name="rol_id" value="<?= $r['id'] ?>" <?= $usr['rol_id']==$r['id'] ? 'checked' : '' ?>
                                onchange="seleccionarRol(<?= $r['id'] ?>)" style="margin-top:3px;accent-color:<?= $rd['color'] ?>">
                            <div>
                                <div class="d-flex align-items-center gap-2">
                                    <i class="bi <?= $rd['icon'] ?>" style="color:<?= $rd['color'] ?>;font-size:16px"></i>
                                    <strong style="color:var(--t)"><?= ucfirst($r['nombre']) ?></strong>
                                </div>
                                <small style="color:var(--t2);font-size:11px;line-height:1.3;display:block;margin-top:3px"><?= $rd['desc'] ?></small>
                            </div>
                        </div>
                    </label>
                    <?php endforeach; ?>

                    <!-- Panel condicional para doctor -->
                    <div id="panelDoctor" style="display:<?= $usr['rol_id']==2 ? 'block':'none' ?>;margin-top:12px;padding:12px;background:rgba(0,212,238,.06);border:1px solid rgba(0,212,238,.2);border-radius:8px;font-size:12px;color:var(--t2)">
                        <i class="bi bi-info-circle me-1" style="color:var(--c)"></i>
                        Para doctores completa <strong style="color:var(--t)">Especialidad</strong> y <strong style="color:var(--t)">CMP</strong> en los datos personales.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Botones -->
    <div class="d-flex gap-2 justify-content-between align-items-center flex-wrap">
        <?php if ($accion === 'editar'): ?>
        <a href="?accion=ver&id=<?= $id ?>" class="btn btn-dk"><i class="bi bi-eye me-1"></i>Ver perfil</a>
        <?php else: ?>
        <div></div>
        <?php endif; ?>
        <div class="d-flex gap-2">
            <a href="?" class="btn btn-dk">Cancelar</a>
            <button type="submit" class="btn btn-primary px-4" id="btnGuardar">
                <i class="bi bi-floppy me-2"></i><?= $accion==='nuevo' ? 'Crear usuario' : 'Guardar cambios' ?>
            </button>
        </div>
    </div>
</form>
</div>
</div>

<?php
$xscript = <<<'JSEOF'
<script>
// Toggle visibilidad contraseña
function togglePass(id, btn) {
    const inp = document.getElementById(id);
    const isPass = inp.type === 'password';
    inp.type = isPass ? 'text' : 'password';
    btn.innerHTML = isPass ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
}

// Validar que contraseñas coincidan en tiempo real
const p1 = document.getElementById('passInp');
const p2 = document.getElementById('pass2Inp');
const pm = document.getElementById('passMatch');
function checkPass() {
    if (!p2.value) { pm.textContent=''; return; }
    if (p1.value === p2.value) {
        pm.innerHTML='<span style="color:var(--g)">✓ Contraseñas coinciden</span>';
    } else {
        pm.innerHTML='<span style="color:var(--r)">✗ No coinciden</span>';
    }
}
p1.addEventListener('input', checkPass);
p2.addEventListener('input', checkPass);

// Toggle activo label
document.getElementById('ckActivo').addEventListener('change', function() {
    document.getElementById('lblActivo').textContent = this.checked ? '✅ Usuario activo' : '❌ Inactivo';
});

// Seleccionar rol - highlight card
function seleccionarRol(id) {
    document.querySelectorAll('[id^="rol-card-"]').forEach(c => {
        c.style.borderColor = 'var(--bd2)';
        c.style.background = 'transparent';
    });
    const card = document.getElementById('rol-card-'+id);
    if (card) {
        card.style.borderColor = 'var(--c)';
        card.style.background = 'rgba(0,212,238,.06)';
    }
    document.getElementById('panelDoctor').style.display = id === 2 ? 'block' : 'none';
}

// Inicializar al cargar
document.addEventListener('DOMContentLoaded', () => {
    const checked = document.querySelector('input[name="rol_id"]:checked');
    if (checked) seleccionarRol(parseInt(checked.value));
});

// Buscador DNI con RENIEC (autocompleta nombres y apellidos)
(function(){
    const dniInp = document.getElementById('dniInp');
    const btn    = document.getElementById('btnBuscarDni');
    const msgEl  = document.getElementById('dniMsg');
    if (!dniInp || !btn) return;

    const setMsg = (txt, ok) => { msgEl.textContent = txt; msgEl.style.color = ok ? '#2ecc71' : '#e05252'; };
    dniInp.addEventListener('input', () => { dniInp.value = dniInp.value.replace(/\D/g,''); });
    dniInp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); btn.click(); } });

    btn.addEventListener('click', async () => {
        const doc = dniInp.value.trim();
        if (doc.length !== 8) { setMsg('Ingrese 8 dígitos.', false); dniInp.focus(); return; }
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        setMsg('Consultando RENIEC...', true);
        try {
            const r = await fetch('BASEURL/includes/api_documento.php?doc=' + doc, { credentials:'same-origin' });
            const txt = await r.text();
            let j; try { j = JSON.parse(txt); } catch(e){ setMsg('Respuesta inesperada (HTTP '+r.status+').', false); return; }
            if (!r.ok || !j.ok || j.tipo !== 'dni') { setMsg(j.msg || 'No encontrado.', false); return; }
            document.querySelector('input[name="nombre"]').value    = j.data.nombres || '';
            document.querySelector('input[name="apellidos"]').value = ((j.data.apellido_paterno||'') + ' ' + (j.data.apellido_materno||'')).trim();
            setMsg('✓ ' + [j.data.nombres, j.data.apellido_paterno, j.data.apellido_materno].filter(Boolean).join(' '), true);
        } catch (err) {
            setMsg('Error de red: ' + err.message, false);
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    });
})();

// Validación del form antes de enviar
document.getElementById('fUsuario').addEventListener('submit', function(e) {
    const p = document.getElementById('passInp').value;
    const p2 = document.getElementById('pass2Inp').value;
    if (p && p !== p2) {
        e.preventDefault();
        alert('Las contraseñas no coinciden.');
        return false;
    }
    if (p && p.length < 6) {
        e.preventDefault();
        alert('La contraseña debe tener al menos 6 caracteres.');
        return false;
    }
    const btn = document.getElementById('btnGuardar');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass me-2"></i>Guardando...';
});
</script>
JSEOF;
$xscript = str_replace('BASEURL', BASE_URL, $xscript);
require_once __DIR__.'/../../includes/footer.php';
}
