<?php
/**
 * FICHA ENDODÓNTICA — Módulo completo
 * Tablas: endodoncia_fichas, endodoncia_odontometria,
 *         endodoncia_biomecanica, endodoncia_obturacion
 */
require_once __DIR__ . '/../includes/config.php';
requiereLogin();

$paciente_id = (int)($_GET['paciente_id'] ?? 0);
$accion      = $_GET['accion'] ?? 'lista';
$id          = (int)($_GET['id'] ?? 0);

if (!$paciente_id && $accion !== 'lista') {
    flash('error','Paciente requerido');
    go('pages/pacientes.php');
}

// Load patient
$pac = null;
if ($paciente_id) {
    $s = db()->prepare("SELECT * FROM pacientes WHERE id=?");
    $s->execute([$paciente_id]); $pac = $s->fetch();
    if (!$pac) { flash('error','Paciente no encontrado'); go('pages/pacientes.php'); }
}

// ── Helpers ───────────────────────────────────────────────────────────────
function saveFichaRows(int $fichaId, string $tabla, array $rows, array $cols): void {
    db()->prepare("DELETE FROM $tabla WHERE ficha_id=?")->execute([$fichaId]);
    if (!$rows) return;
    $phs  = implode(',', array_fill(0, count($cols)+1, '?'));
    $colStr = 'ficha_id,'.implode(',', $cols);
    $st = db()->prepare("INSERT INTO $tabla($colStr) VALUES($phs)");
    foreach ($rows as $row) {
        $vals = [$fichaId];
        foreach ($cols as $c) $vals[] = trim($row[$c] ?? '');
        $st->execute($vals);
    }
}

// ── POST handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ap = $_POST['accion'] ?? '';

    if ($ap === 'guardar') {
        $eid  = (int)($_POST['id'] ?? 0);
        $pid  = (int)($_POST['paciente_id'] ?? 0);
        $hcid = (int)($_POST['hc_id'] ?? 0) ?: null;

        $cb = fn(string $k) => isset($_POST[$k]) ? 1 : 0;
        $si = fn(string $k) => isset($_POST[$k]) ? (int)$_POST[$k] : null;

        $d = [
            'paciente_id'           => $pid,
            'hc_id'                 => $hcid,
            'doctor_id'             => (int)($_POST['doctor_id'] ?? $_SESSION['uid']),
            'pieza'                 => trim($_POST['pieza'] ?? ''),
            'caries_profunda'       => $si('caries_profunda'),
            'discromia'             => $si('discromia'),
            'fistula_intraoral'     => $si('fistula_intraoral'),
            'inflamacion_facial'    => $cb('inflamacion_facial'),
            'inflamacion_fondo'     => $cb('inflamacion_fondo'),
            'fractura_coronaria'    => $cb('fractura_coronaria'),
            'fractura_radicular'    => $cb('fractura_radicular'),
            'exposicion_pulpar'     => $si('exposicion_pulpar'),
            'presencia_corona'      => $cb('presencia_corona'),
            'presencia_poste'       => $cb('presencia_poste'),
            'movilidad_dentaria'    => $si('movilidad_dentaria'),
            'tratamiento_previo'    => $si('tratamiento_previo'),
            'cuerpos_extranos'      => $si('cuerpos_extranos'),
            'radiolucidez_apical'   => $cb('radiolucidez_apical'),
            'radiolucidez_medio'    => $cb('radiolucidez_medio'),
            'radiolucidez_cervical' => $cb('radiolucidez_cervical'),
            'dolor_provocado'       => $cb('dolor_provocado'),
            'dolor_espontaneo'      => $cb('dolor_espontaneo'),
            'dolor_localizado'      => $cb('dolor_localizado'),
            'dolor_difuso'          => $cb('dolor_difuso'),
            'dolor_temporario'      => $cb('dolor_temporario'),
            'dolor_permanente'      => $cb('dolor_permanente'),
            'intensidad'            => $_POST['intensidad'] ?: null,
            'calma_analgesicos'     => $si('calma_analgesicos'),
            'nocturno'              => $si('nocturno'),
            'percusion_vertical'    => $cb('percusion_vertical'),
            'percusion_horizontal'  => $cb('percusion_horizontal'),
            'prueba_termica'        => $cb('prueba_termica'),
            'asintomatico'          => $si('asintomatico'),
            'diagnostico_presuntivo'=> trim($_POST['diagnostico_presuntivo'] ?? ''),
            'fecha_diagnostico'     => $_POST['fecha_diagnostico'] ?: null,
            'acceso_endodontico'    => $si('acceso_endodontico'),
            'diagnostico_definitivo'=> trim($_POST['diagnostico_definitivo'] ?? ''),
            'fecha_inicio'          => $_POST['fecha_inicio'] ?: null,
            'biopulpectomia'        => $cb('biopulpectomia'),
            'necropulpectomia'      => $cb('necropulpectomia'),
            'retratamiento'         => $cb('retratamiento'),
            'proteccion_pulpar'     => $cb('proteccion_pulpar'),
            'apicogenesis'          => $cb('apicogenesis'),
            'apexificacion'         => $cb('apexificacion'),
            'cirugia_parendodontica'=> $cb('cirugia_parendodontica'),
            'clamp_numero'          => trim($_POST['clamp_numero'] ?? ''),
            'aislamiento_absoluto'  => $cb('aislamiento_absoluto'),
            'exeresis_pulpar'       => $cb('exeresis_pulpar'),
            'irrigante'             => trim($_POST['irrigante'] ?? ''),
            'remocion_obturacion'   => $cb('remocion_obturacion'),
            'med_antibiotico'       => $cb('med_antibiotico'),
            'med_hidroxido_calcio_a'=> $cb('med_hidroxido_calcio_a'),
            'med_paramonoclorofenol'=> $cb('med_paramonoclorofenol'),
            'med_eugenol'           => $cb('med_eugenol'),
            'med_cono_hidroxido'    => $cb('med_cono_hidroxido'),
            'med_cono_clorhexidina' => $cb('med_cono_clorhexidina'),
            'med_hidroxido_pmcfa'   => $cb('med_hidroxido_pmcfa'),
            'med_formocresol'       => $cb('med_formocresol'),
            'med_otro'              => trim($_POST['med_otro'] ?? ''),
            'medicacion_coadyuvante'=> trim($_POST['medicacion_coadyuvante'] ?? ''),
            'control_1ra_semana'    => $_POST['control_1ra_semana'] ?: null,
            'control_2da_semana'    => $_POST['control_2da_semana'] ?: null,
            'tratamiento_finalizado'=> $cb('tratamiento_finalizado'),
            'fecha_finalizacion'    => $_POST['fecha_finalizacion'] ?: null,
            'hora_finalizacion'     => $_POST['hora_finalizacion'] ?: null,
            'estado'                => $_POST['estado'] ?? 'borrador',
        ];

        if ($eid) {
            $sets = implode(',', array_map(fn($k)=>"$k=?", array_keys($d)));
            db()->prepare("UPDATE endodoncia_fichas SET $sets,updated_at=NOW() WHERE id=?")
               ->execute([...array_values($d), $eid]);
            auditar('EDITAR_ENDODONCIA','endodoncia_fichas',$eid);
        } else {
            $cols = implode(',', array_keys($d));
            $phs  = implode(',', array_fill(0, count($d), '?'));
            db()->prepare("INSERT INTO endodoncia_fichas($cols) VALUES($phs)")->execute(array_values($d));
            $eid  = (int)db()->lastInsertId();
            auditar('CREAR_ENDODONCIA','endodoncia_fichas',$eid);
        }

        // Save table rows
        $odom_rows = [];
        foreach (($_POST['odom_raices'] ?? []) as $i => $v) {
            if (!trim($v) && !trim($_POST['odom_conducto'][$i]??'')) continue;
            $odom_rows[] = ['n_raices'=>$v,'conducto'=>$_POST['odom_conducto'][$i]??'','referencia_tope'=>$_POST['odom_referencia'][$i]??'','long_radiografica'=>$_POST['odom_long_rx'][$i]??'','correccion'=>$_POST['odom_correccion'][$i]??'','long_trabajo'=>$_POST['odom_long_trabajo'][$i]??''];
        }
        saveFichaRows($eid,'endodoncia_odontometria',$odom_rows,['n_raices','conducto','referencia_tope','long_radiografica','correccion','long_trabajo']);

        $biom_rows = [];
        foreach (($_POST['biom_raices'] ?? []) as $i => $v) {
            if (!trim($v) && !trim($_POST['biom_conducto'][$i]??'')) continue;
            $biom_rows[] = ['n_raices'=>$v,'conducto'=>$_POST['biom_conducto'][$i]??'','instrumento_ajuste'=>$_POST['biom_ajuste'][$i]??'','instrumento_memoria'=>$_POST['biom_memoria'][$i]??'','tecnica_ibm'=>$_POST['biom_tecnica'][$i]??'','instrumento_inicial'=>$_POST['biom_inicial'][$i]??'','instrumento_final'=>$_POST['biom_final'][$i]??''];
        }
        saveFichaRows($eid,'endodoncia_biomecanica',$biom_rows,['n_raices','conducto','instrumento_ajuste','instrumento_memoria','tecnica_ibm','instrumento_inicial','instrumento_final']);

        $obtu_rows = [];
        foreach (($_POST['obtu_raices'] ?? []) as $i => $v) {
            if (!trim($v) && !trim($_POST['obtu_conducto'][$i]??'')) continue;
            $obtu_rows[] = ['n_raices'=>$v,'conducto'=>$_POST['obtu_conducto'][$i]??'','n_cono_principal'=>$_POST['obtu_cono'][$i]??'','tipo_tecnica'=>$_POST['obtu_tecnica'][$i]??'','material'=>$_POST['obtu_material'][$i]??''];
        }
        saveFichaRows($eid,'endodoncia_obturacion',$obtu_rows,['n_raices','conducto','n_cono_principal','tipo_tecnica','material']);

        // Controles dinámicos (fecha + observación)
        try {
            db()->prepare("DELETE FROM endodoncia_controles WHERE ficha_id=?")->execute([$eid]);
            $cf = $_POST['ctrl_fecha'] ?? []; $co = $_POST['ctrl_obs'] ?? []; $ord = 1;
            foreach ($cf as $i => $fe) {
                $fe = trim((string)$fe); $ob = trim((string)($co[$i] ?? ''));
                if ($fe==='' && $ob==='') continue;
                db()->prepare("INSERT INTO endodoncia_controles(ficha_id,fecha,observacion,orden) VALUES(?,?,?,?)")
                    ->execute([$eid, ($fe!==''?$fe:null), ($ob!==''?$ob:null), $ord++]);
            }
        } catch (Throwable $e) {}

        // Adjuntos por sección (un archivo por sección)
        try {
            foreach (['dolor','odontometria','biomecanica','obturacion'] as $sec) {
                if (!empty($_POST['quitar_adj_'.$sec])) {
                    $q=db()->prepare("SELECT ruta FROM endodoncia_adjuntos WHERE ficha_id=? AND seccion=?"); $q->execute([$eid,$sec]); $ru=$q->fetchColumn();
                    if ($ru!==false) { db()->prepare("DELETE FROM endodoncia_adjuntos WHERE ficha_id=? AND seccion=?")->execute([$eid,$sec]); $fp=__DIR__.'/../uploads/'.$ru; if($ru && is_file($fp)) @unlink($fp); }
                }
                if (!empty($_FILES['adj_'.$sec]['name'])) {
                    $ruta = subirArchivo($_FILES['adj_'.$sec],'endodoncia',['jpg','jpeg','png','webp','pdf']);
                    if ($ruta) {
                        $q=db()->prepare("SELECT ruta FROM endodoncia_adjuntos WHERE ficha_id=? AND seccion=?"); $q->execute([$eid,$sec]); $old=$q->fetchColumn();
                        if ($old!==false && $old) { $fp=__DIR__.'/../uploads/'.$old; if(is_file($fp)) @unlink($fp); }
                        db()->prepare("DELETE FROM endodoncia_adjuntos WHERE ficha_id=? AND seccion=?")->execute([$eid,$sec]);
                        db()->prepare("INSERT INTO endodoncia_adjuntos(ficha_id,seccion,nombre,ruta) VALUES(?,?,?,?)")
                            ->execute([$eid,$sec,$_FILES['adj_'.$sec]['name'],$ruta]);
                    }
                }
            }
        } catch (Throwable $e) {}

        flash('ok','Ficha endodóntica guardada.');
        go("pages/endodoncia.php?paciente_id=$pid&accion=ver&id=$eid");
    }

    if ($ap === 'eliminar') {
        $did = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM endodoncia_odontometria WHERE ficha_id=?")->execute([$did]);
        db()->prepare("DELETE FROM endodoncia_biomecanica WHERE ficha_id=?")->execute([$did]);
        db()->prepare("DELETE FROM endodoncia_obturacion WHERE ficha_id=?")->execute([$did]);
        try {
            $q=db()->prepare("SELECT ruta FROM endodoncia_adjuntos WHERE ficha_id=?"); $q->execute([$did]);
            foreach($q->fetchAll(PDO::FETCH_COLUMN) as $ru){ $fp=__DIR__.'/../uploads/'.$ru; if($ru && is_file($fp)) @unlink($fp); }
            db()->prepare("DELETE FROM endodoncia_adjuntos WHERE ficha_id=?")->execute([$did]);
            db()->prepare("DELETE FROM endodoncia_controles WHERE ficha_id=?")->execute([$did]);
        } catch (Throwable $e) {}
        db()->prepare("DELETE FROM endodoncia_fichas WHERE id=?")->execute([$did]);
        auditar('ELIMINAR_ENDODONCIA','endodoncia_fichas',$did);
        flash('ok','Ficha eliminada.');
        go("pages/endodoncia.php?paciente_id=$paciente_id");
    }
}

// ── LISTA ─────────────────────────────────────────────────────────────────
if ($accion === 'lista') {
    $esGlobal = !$paciente_id;   // sin paciente seleccionado => lista global
    if ($esGlobal) {
        $fichas = db()->query("SELECT f.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac,p.codigo AS cod_pac,CONCAT(u.nombre,' ',u.apellidos) AS doctor
                                FROM endodoncia_fichas f
                                JOIN pacientes p ON f.paciente_id=p.id
                                LEFT JOIN usuarios u ON f.doctor_id=u.id
                                ORDER BY f.created_at DESC")->fetchAll();
        $titulo  = "Fichas Endodónticas";
        $pagina_activa = 'endo';
        $topbar_act = '<a href="'.BASE_URL.'/pages/pacientes.php" class="btn btn-primary"><i class="bi bi-people me-1"></i>Ir a Pacientes</a>';
    } else {
        $fichas = db()->prepare("SELECT f.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac,p.codigo AS cod_pac,CONCAT(u.nombre,' ',u.apellidos) AS doctor
                                  FROM endodoncia_fichas f
                                  JOIN pacientes p ON f.paciente_id=p.id
                                  LEFT JOIN usuarios u ON f.doctor_id=u.id
                                  WHERE f.paciente_id=? ORDER BY f.created_at DESC");
        $fichas->execute([$paciente_id]); $fichas=$fichas->fetchAll();
        $titulo="Ficha Endodóntica — ".$pac['nombres'].' '.$pac['apellido_paterno'];
        $pagina_activa='pac';
        $topbar_act='<a href="?accion=nuevo&paciente_id='.$paciente_id.'" class="btn btn-primary"><i class="bi bi-plus me-1"></i>Nueva ficha</a>
        <a href="'.BASE_URL.'/pages/pacientes.php?accion=ver&id='.$paciente_id.'" class="btn btn-dk btn-sm"><i class="bi bi-person me-1"></i>Paciente</a>';
    }
    require_once __DIR__.'/../includes/header.php';
?>
<div class="pb">
<?=popFlash()?>
<?php if(!$esGlobal): ?>
<div class="d-flex align-items-center gap-3 mb-3 p-3" style="background:var(--bg2);border:1px solid var(--bd2);border-radius:10px">
  <div style="width:40px;height:40px;border-radius:50%;background:rgba(239,68,68,.15);border:2px solid rgba(239,68,68,.3);display:flex;align-items:center;justify-content:center;font-size:18px">🦷</div>
  <div>
    <div style="font-weight:700;font-size:15px;color:var(--t)"><?=e($pac['nombres'].' '.$pac['apellido_paterno'])?></div>
    <div style="font-size:12px;color:var(--t2)"><?=e($pac['codigo'])?> &bull; <?=$pac['fecha_nacimiento']?edad($pac['fecha_nacimiento']):'—'?></div>
  </div>
</div>
<?php endif; ?>
<?php if(!$fichas): ?>
<div class="card p-5 text-center" style="color:var(--t2)">
  <i class="bi bi-file-medical" style="font-size:36px;display:block;margin-bottom:10px"></i>
  <?php if($esGlobal): ?>
    No hay fichas endodónticas registradas.<br>
    <span style="font-size:13px">Abre un paciente desde <strong>Pacientes</strong> y usa el ícono de endodoncia para crear una.</span>
  <?php else: ?>
    No hay fichas endodónticas para este paciente.
  <?php endif; ?>
</div>
<?php else: ?>
<div class="card">
  <div class="table-responsive"><table class="table mb-0">
    <thead><tr><th>Fecha</th><?php if($esGlobal): ?><th>Paciente</th><?php endif; ?><th>Pieza</th><th>Doctor</th><th>Diagnóstico</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach($fichas as $f): $pid=(int)$f['paciente_id']; ?>
    <tr>
      <td style="color:var(--t)"><?=fDate($f['created_at'],'d/m/Y')?></td>
      <?php if($esGlobal): ?><td style="color:var(--t)"><a href="?paciente_id=<?=$pid?>" style="color:var(--c);text-decoration:none"><?=e($f['pac'])?></a><br><small style="color:var(--t3)"><?=e($f['cod_pac']??'')?></small></td><?php endif; ?>
      <td><strong style="color:var(--c)"><?=e($f['pieza']??'—')?></strong></td>
      <td style="color:var(--t2);font-size:12px"><?=e($f['doctor']??'—')?></td>
      <td style="font-size:12px;color:var(--t2)"><?=e(mb_substr($f['diagnostico_presuntivo']??'—',0,40))?></td>
      <td><span class="badge <?=$f['estado']==='completado'?'bg':'bgr'?>"><?=$f['estado']==='completado'?'✓ Completado':'✎ Borrador'?></span></td>
      <td><div class="d-flex gap-1">
        <a href="?accion=ver&id=<?=$f['id']?>&paciente_id=<?=$pid?>" class="btn btn-dk btn-ico btn-sm"><i class="bi bi-eye"></i></a>
        <a href="?accion=editar&id=<?=$f['id']?>&paciente_id=<?=$pid?>" class="btn btn-dk btn-ico btn-sm"><i class="bi bi-pencil"></i></a>
        <a href="?accion=pdf&id=<?=$f['id']?>&paciente_id=<?=$pid?>" class="btn btn-ico btn-sm" style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#ef4444" target="_blank"><i class="bi bi-filetype-pdf"></i></a>
        <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar esta ficha?')">
          <input type="hidden" name="accion" value="eliminar">
          <input type="hidden" name="id" value="<?=$f['id']?>">
          <button class="btn btn-del btn-ico btn-sm"><i class="bi bi-trash"></i></button>
        </form>
      </div></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
</div>
<?php
    require_once __DIR__.'/../includes/footer.php';

// ── NUEVO / EDITAR ────────────────────────────────────────────────────────
} elseif (in_array($accion, ['nuevo','editar'])) {
    $f = ['id'=>0,'hc_id'=>0,'doctor_id'=>$_SESSION['uid'],'pieza'=>'','caries_profunda'=>null,'discromia'=>null,'fistula_intraoral'=>null,'inflamacion_facial'=>0,'inflamacion_fondo'=>0,'fractura_coronaria'=>0,'fractura_radicular'=>0,'exposicion_pulpar'=>null,'presencia_corona'=>0,'presencia_poste'=>0,'movilidad_dentaria'=>null,'tratamiento_previo'=>null,'cuerpos_extranos'=>null,'radiolucidez_apical'=>0,'radiolucidez_medio'=>0,'radiolucidez_cervical'=>0,'dolor_provocado'=>0,'dolor_espontaneo'=>0,'dolor_localizado'=>0,'dolor_difuso'=>0,'dolor_temporario'=>0,'dolor_permanente'=>0,'intensidad'=>null,'calma_analgesicos'=>null,'nocturno'=>null,'percusion_vertical'=>0,'percusion_horizontal'=>0,'prueba_termica'=>0,'asintomatico'=>null,'diagnostico_presuntivo'=>'','fecha_diagnostico'=>'','acceso_endodontico'=>null,'diagnostico_definitivo'=>'','fecha_inicio'=>'','biopulpectomia'=>0,'necropulpectomia'=>0,'retratamiento'=>0,'proteccion_pulpar'=>0,'apicogenesis'=>0,'apexificacion'=>0,'cirugia_parendodontica'=>0,'clamp_numero'=>'','aislamiento_absoluto'=>0,'exeresis_pulpar'=>0,'irrigante'=>'','remocion_obturacion'=>0,'med_antibiotico'=>0,'med_hidroxido_calcio_a'=>0,'med_paramonoclorofenol'=>0,'med_eugenol'=>0,'med_cono_hidroxido'=>0,'med_cono_clorhexidina'=>0,'med_hidroxido_pmcfa'=>0,'med_formocresol'=>0,'med_otro'=>'','medicacion_coadyuvante'=>'','control_1ra_semana'=>'','control_2da_semana'=>'','tratamiento_finalizado'=>0,'fecha_finalizacion'=>'','hora_finalizacion'=>'','estado'=>'borrador'];
    $odom_rows = []; $biom_rows = []; $obtu_rows = [];
    $adjs = []; $controles = [];
    if ($accion==='editar' && $id) {
        $s=db()->prepare("SELECT * FROM endodoncia_fichas WHERE id=?"); $s->execute([$id]); $f=$s->fetch()?:$f;
        $s=db()->prepare("SELECT * FROM endodoncia_odontometria WHERE ficha_id=?"); $s->execute([$id]); $odom_rows=$s->fetchAll();
        $s=db()->prepare("SELECT * FROM endodoncia_biomecanica WHERE ficha_id=?"); $s->execute([$id]); $biom_rows=$s->fetchAll();
        $s=db()->prepare("SELECT * FROM endodoncia_obturacion WHERE ficha_id=?"); $s->execute([$id]); $obtu_rows=$s->fetchAll();
        try { $s=db()->prepare("SELECT * FROM endodoncia_adjuntos WHERE ficha_id=?"); $s->execute([$id]); foreach($s->fetchAll() as $r) $adjs[$r['seccion']]=$r; } catch (Throwable $e) {}
        try { $s=db()->prepare("SELECT * FROM endodoncia_controles WHERE ficha_id=? ORDER BY orden,id"); $s->execute([$id]); $controles=$s->fetchAll(); } catch (Throwable $e) {}
        if (!$controles) { // sembrar desde los 2 controles antiguos si existen
            if (!empty($f['control_1ra_semana'])) $controles[]=['fecha'=>$f['control_1ra_semana'],'observacion'=>''];
            if (!empty($f['control_2da_semana'])) $controles[]=['fecha'=>$f['control_2da_semana'],'observacion'=>''];
        }
    }
    // Helper: bloque de archivo adjunto por sección (uno por sección)
    $adjUI = function(string $sec, string $label) use ($adjs) {
        $a = $adjs[$sec] ?? null; ?>
        <div class="mt-2" style="background:var(--bg3);border:1px dashed var(--bd2);border-radius:8px;padding:8px 10px">
          <div style="font-size:11px;color:var(--t2);margin-bottom:5px"><i class="bi bi-paperclip"></i> Archivo adjunto — <?=e($label)?></div>
          <?php if($a): ?>
            <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
              <a href="<?=BASE_URL?>/uploads/<?=e($a['ruta'])?>" target="_blank" class="btn btn-dk btn-sm"><i class="bi bi-eye me-1"></i><?=e($a['nombre']?:'Ver archivo')?></a>
              <label class="d-flex align-items-center gap-1" style="font-size:12px;color:#e05252;cursor:pointer"><input type="checkbox" name="quitar_adj_<?=$sec?>" value="1"> Quitar</label>
            </div>
            <div style="font-size:11px;color:var(--t3);margin-bottom:3px">Para reemplazarlo, elige otro:</div>
          <?php endif; ?>
          <input type="file" name="adj_<?=$sec?>" accept="image/*,application/pdf" class="form-control form-control-sm">
        </div>
        <?php
    };
    $hcs     = db()->prepare("SELECT id,numero_hc FROM historias_clinicas WHERE paciente_id=? ORDER BY fecha_apertura DESC"); $hcs->execute([$paciente_id]); $hcs=$hcs->fetchAll();
    $doctors = db()->query("SELECT id,CONCAT(nombre,' ',apellidos) AS nm,cmp FROM usuarios WHERE rol_id=2 AND activo=1 ORDER BY nombre")->fetchAll();

    $titulo = ($accion==='nuevo'?'Nueva':'Editar')." Ficha Endodóntica";
    $pagina_activa='pac';
    $topbar_act='<a href="?paciente_id='.$paciente_id.'" class="btn btn-dk btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver</a>';
    require_once __DIR__.'/../includes/header.php';

    // Helper
    $yn = function(string $name, ?int $val, string $label='') use ($f) {
        $v = $f[$name] ?? null;
        echo '<div class="d-flex align-items-center gap-3">';
        if ($label) echo '<span style="font-size:12px;color:var(--t);min-width:160px">'.$label.'</span>';
        echo '<label class="d-flex align-items-center gap-1" style="cursor:pointer;font-size:12px"><input type="radio" name="'.$name.'" value="1" '.($v===1?'checked':'').'> Sí</label>';
        echo '<label class="d-flex align-items-center gap-1" style="cursor:pointer;font-size:12px"><input type="radio" name="'.$name.'" value="0" '.($v===0?'checked':'').'> No</label>';
        echo '</div>';
    };
    $chk = function(string $name, string $label) use ($f) {
        $v = (int)($f[$name] ?? 0);
        echo '<label class="d-flex align-items-center gap-2" style="cursor:pointer;font-size:12px"><input type="checkbox" name="'.$name.'" value="1" '.($v?'checked':'').'> '.$label.'</label>';
    };
?>
<style>
.ficha-section{background:var(--bg2);border:1px solid var(--bd2);border-radius:8px;padding:14px;margin-bottom:14px}
.ficha-section-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--c);margin-bottom:10px;border-bottom:1px solid var(--bd2);padding-bottom:6px}
.yn-row{display:flex;align-items:center;justify-content:space-between;padding:4px 0;border-bottom:1px solid rgba(255,255,255,.03)}
.yn-row:last-child{border-bottom:none}
.yn-label{font-size:12px;color:var(--t);flex:1}
.yn-btns{display:flex;gap:12px;flex-shrink:0}
.yn-btns label{display:flex;align-items:center;gap:4px;font-size:12px;cursor:pointer;color:var(--t2)}
.tbl-endo th{font-size:10px;text-transform:uppercase;color:var(--t2);padding:4px 6px;background:var(--bg3)}
.tbl-endo td{padding:3px 4px}
.tbl-endo input{background:var(--bg3);border:1px solid var(--bd2);border-radius:4px;padding:3px 6px;font-size:11px;color:var(--t);width:100%}
</style>

<div class="pb">
<form method="POST" enctype="multipart/form-data">
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?=$f['id']?>">
  <input type="hidden" name="paciente_id" value="<?=$paciente_id?>">

  <!-- Paciente + Doctor -->
  <div class="row g-3 mb-3">
    <div class="col-12 col-md-4">
      <label class="form-label">Historia Clínica</label>
      <select name="hc_id" class="form-select">
        <option value="">— Sin HC —</option>
        <?php foreach($hcs as $h): ?><option value="<?=$h['id']?>" <?=$f['hc_id']==$h['id']?'selected':''?>><?=e($h['numero_hc'])?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 col-md-4">
      <label class="form-label">Doctor(a)</label>
      <select name="doctor_id" class="form-select">
        <?php foreach($doctors as $dr): ?><option value="<?=$dr['id']?>" <?=$f['doctor_id']==$dr['id']?'selected':''?>><?=e($dr['nm'].($dr['cmp']?' — '.$dr['cmp']:''))?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 col-md-2">
      <label class="form-label">Pieza dental</label>
      <input type="text" name="pieza" class="form-control" value="<?=e($f['pieza']??'')?>" placeholder="Ej: 36">
    </div>
    <div class="col-12 col-md-2">
      <label class="form-label">Estado</label>
      <select name="estado" class="form-select">
        <option value="borrador" <?=($f['estado']??'')==='borrador'?'selected':''?>>Borrador</option>
        <option value="completado" <?=($f['estado']??'')==='completado'?'selected':''?>>Completado</option>
      </select>
    </div>
  </div>

  <!-- EXAMEN CLÍNICO RADIOGRÁFICO -->
  <div class="ficha-section">
    <div class="ficha-section-title">Examen Clínico Radiográfico</div>
    <div class="row g-2">
      <div class="col-12 col-md-6">
        <?php
        $items_yn = [
            ['caries_profunda','Caries profunda'],
            ['discromia','Discromiía'],
            ['fistula_intraoral','Fístula Intraoral'],
            ['exposicion_pulpar','Exposición Pulpar'],
            ['movilidad_dentaria','Movilidad Dentaria'],
            ['tratamiento_previo','Tratamiento Endodóntico Previo'],
            ['cuerpos_extranos','Presencia de cuerpos extraños'],
        ];
        foreach($items_yn as [$nm,$lbl]): ?>
        <div class="yn-row">
          <span class="yn-label"><?=$lbl?></span>
          <div class="yn-btns">
            <label><input type="radio" name="<?=$nm?>" value="1" <?=($f[$nm]??null)===1?'checked':''?>> Sí</label>
            <label><input type="radio" name="<?=$nm?>" value="0" <?=($f[$nm]??null)===0?'checked':''?>> No</label>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="col-12 col-md-6">
        <div style="font-size:11px;color:var(--t2);margin-bottom:6px">Inflamación Fondo de Surco:</div>
        <div class="d-flex gap-3 mb-2">
          <?php $chk('inflamacion_facial','Facial'); ?>&nbsp;<?php $chk('inflamacion_fondo','Fondo de Surco'); ?>
        </div>
        <div style="font-size:11px;color:var(--t2);margin-bottom:6px">Fractura:</div>
        <div class="d-flex gap-3 mb-2">
          <?php $chk('fractura_coronaria','Coronaria'); ?>&nbsp;<?php $chk('fractura_radicular','Radicular'); ?>
        </div>
        <div style="font-size:11px;color:var(--t2);margin-bottom:6px">Presencia de:</div>
        <div class="d-flex gap-3 mb-2">
          <?php $chk('presencia_corona','Corona'); ?>&nbsp;<?php $chk('presencia_poste','Poste'); ?>
        </div>
        <div style="font-size:11px;color:var(--t2);margin-bottom:6px">Radiolucidez:</div>
        <div class="d-flex gap-3">
          <?php $chk('radiolucidez_apical','Apical'); ?>&nbsp;<?php $chk('radiolucidez_medio','Medio'); ?>&nbsp;<?php $chk('radiolucidez_cervical','Cervical'); ?>
        </div>
      </div>
    </div>
  </div>

  <!-- TIPO DE DOLOR -->
  <div class="ficha-section">
    <div class="ficha-section-title">Tipo de Dolor</div>
    <div class="row g-3">
      <div class="col-12 col-md-6">
        <div class="d-flex flex-wrap gap-3 mb-2">
          <?php $chk('dolor_provocado','Provocado'); ?><?php $chk('dolor_espontaneo','Espontáneo'); ?>
        </div>
        <div class="d-flex flex-wrap gap-3 mb-2">
          <?php $chk('dolor_localizado','Localizado'); ?><?php $chk('dolor_difuso','Difuso'); ?>
        </div>
        <div class="d-flex flex-wrap gap-3 mb-2">
          <?php $chk('dolor_temporario','Temporario'); ?><?php $chk('dolor_permanente','Permanente'); ?>
        </div>
        <?php $adjUI('dolor','Tipo de dolor'); ?>
        <div class="d-flex flex-wrap gap-3 mb-3">
          <?php foreach(['leve'=>'Leve','moderado'=>'Moderado','severo'=>'Severo'] as $v=>$l): ?>
          <label class="d-flex align-items-center gap-1" style="cursor:pointer;font-size:12px">
            <input type="radio" name="intensidad" value="<?=$v?>" <?=($f['intensidad']??'')===$v?'checked':''?>> <?=$l?>
          </label>
          <?php endforeach; ?>
        </div>
        <div class="yn-row"><span class="yn-label">Calma con anelgésicos</span><div class="yn-btns"><label><input type="radio" name="calma_analgesicos" value="1" <?=($f['calma_analgesicos']??null)===1?'checked':''?>> Sí</label><label><input type="radio" name="calma_analgesicos" value="0" <?=($f['calma_analgesicos']??null)===0?'checked':''?>> No</label></div></div>
        <div class="yn-row"><span class="yn-label">Nocturno</span><div class="yn-btns"><label><input type="radio" name="nocturno" value="1" <?=($f['nocturno']??null)===1?'checked':''?>> Sí</label><label><input type="radio" name="nocturno" value="0" <?=($f['nocturno']??null)===0?'checked':''?>> No</label></div></div>
        <div class="yn-row"><span class="yn-label">Asintomático</span><div class="yn-btns"><label><input type="radio" name="asintomatico" value="1" <?=($f['asintomatico']??null)===1?'checked':''?>> Sí</label><label><input type="radio" name="asintomatico" value="0" <?=($f['asintomatico']??null)===0?'checked':''?>> No</label></div></div>
      </div>
      <div class="col-12 col-md-6">
        <div style="font-size:11px;color:var(--t2);margin-bottom:6px">Percusión:</div>
        <div class="d-flex gap-3 mb-3">
          <?php $chk('percusion_vertical','Vertical'); ?>&nbsp;<?php $chk('percusion_horizontal','Horizontal'); ?>&nbsp;<?php $chk('prueba_termica','Prueba térmica'); ?>
        </div>
        <label class="form-label">Diagnóstico presuntivo</label>
        <textarea name="diagnostico_presuntivo" class="form-control" rows="2"><?=e($f['diagnostico_presuntivo']??'')?></textarea>
        <label class="form-label mt-2">Fecha</label>
        <input type="date" name="fecha_diagnostico" class="form-control" value="<?=e($f['fecha_diagnostico']??'')?>">
      </div>
    </div>
  </div>

  <!-- INICIO DEL PROCEDIMIENTO -->
  <div class="ficha-section">
    <div class="ficha-section-title">Inicio del Procedimiento</div>
    <div class="row g-3">
      <div class="col-12 col-md-3">
        <div style="font-size:12px;color:var(--t2);margin-bottom:6px">Acceso endodóntico:</div>
        <div class="d-flex gap-3">
          <label class="d-flex align-items-center gap-1" style="cursor:pointer;font-size:12px"><input type="radio" name="acceso_endodontico" value="1" <?=($f['acceso_endodontico']??null)===1?'checked':''?>> Sí</label>
          <label class="d-flex align-items-center gap-1" style="cursor:pointer;font-size:12px"><input type="radio" name="acceso_endodontico" value="0" <?=($f['acceso_endodontico']??null)===0?'checked':''?>> No</label>
        </div>
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label">Diagnóstico Definitivo</label>
        <input type="text" name="diagnostico_definitivo" class="form-control" value="<?=e($f['diagnostico_definitivo']??'')?>">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Fecha</label>
        <input type="date" name="fecha_inicio" class="form-control" value="<?=e($f['fecha_inicio']??'')?>">
      </div>
    </div>
  </div>

  <!-- TRATAMIENTO REALIZADO -->
  <div class="ficha-section">
    <div class="ficha-section-title">Tratamiento Realizado</div>
    <div class="row g-2 mb-3">
      <div class="col-6 col-md-3"><?php $chk('biopulpectomia','Biopulpectomía'); ?></div>
      <div class="col-6 col-md-3"><?php $chk('necropulpectomia','Necropulpectomía'); ?></div>
      <div class="col-6 col-md-3"><?php $chk('retratamiento','Re-tratamiento'); ?></div>
      <div class="col-6 col-md-3"><?php $chk('proteccion_pulpar','Protección Pulpar Directa'); ?></div>
      <div class="col-6 col-md-3"><?php $chk('apicogenesis','Apicogénesis'); ?></div>
      <div class="col-6 col-md-3"><?php $chk('apexificacion','Apexificación'); ?></div>
      <div class="col-6 col-md-3"><?php $chk('cirugia_parendodontica','Cir. Parendodóntica'); ?></div>
      <div class="col-6 col-md-3"><?php $chk('aislamiento_absoluto','Aislamiento absoluto'); ?></div>
      <div class="col-6 col-md-3"><?php $chk('exeresis_pulpar','Exéresis Pulpar'); ?></div>
      <div class="col-6 col-md-3"><?php $chk('remocion_obturacion','Remoción obturación previa'); ?></div>
      <div class="col-6 col-md-3">
        <label class="form-label" style="font-size:11px">Clamp N°</label>
        <input type="text" name="clamp_numero" class="form-control form-control-sm" value="<?=e($f['clamp_numero']??'')?>">
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label" style="font-size:11px">Irrigante</label>
        <input type="text" name="irrigante" class="form-control form-control-sm" value="<?=e($f['irrigante']??'')?>">
      </div>
    </div>

    <!-- Tabla 1: Odontometría -->
    <div style="font-size:11px;font-weight:700;color:var(--t2);text-transform:uppercase;margin-bottom:6px">1. Odontometría — Conductometría (Longitud de Trabajo)</div>
    <div class="table-responsive mb-3">
      <table class="table tbl-endo mb-1"><thead><tr>
        <th>N° Raíces</th><th>Conducto</th><th>Ref. Tope</th><th>Long. Rx (mm)</th><th>Corrección</th><th>Long. Trabajo</th><th></th>
      </tr></thead><tbody id="odom_tbody">
        <?php
        $odom_show = $odom_rows ?: [array_fill_keys(['n_raices','conducto','referencia_tope','long_radiografica','correccion','long_trabajo'],''),array_fill_keys(['n_raices','conducto','referencia_tope','long_radiografica','correccion','long_trabajo'],''),array_fill_keys(['n_raices','conducto','referencia_tope','long_radiografica','correccion','long_trabajo'],'')];
        foreach($odom_show as $row): ?>
        <tr>
          <td><input type="text" name="odom_raices[]" value="<?=e($row['n_raices']??'')?>"></td>
          <td><input type="text" name="odom_conducto[]" value="<?=e($row['conducto']??'')?>"></td>
          <td><input type="text" name="odom_referencia[]" value="<?=e($row['referencia_tope']??'')?>"></td>
          <td><input type="number" step="0.01" name="odom_long_rx[]" value="<?=e($row['long_radiografica']??'')?>"></td>
          <td><input type="number" step="0.01" name="odom_correccion[]" value="<?=e($row['correccion']??'')?>"></td>
          <td><input type="number" step="0.01" name="odom_long_trabajo[]" value="<?=e($row['long_trabajo']??'')?>"></td>
          <td><button type="button" onclick="this.closest('tr').remove()" class="btn btn-del btn-ico" style="width:22px;height:22px;font-size:10px"><i class="bi bi-x"></i></button></td>
        </tr>
        <?php endforeach; ?>
      </tbody></table>
      <button type="button" onclick="addRow('odom')" class="btn btn-dk btn-sm"><i class="bi bi-plus me-1"></i>Fila</button>
      <?php $adjUI('odontometria','Odontometría'); ?>
    </div>

    <!-- Tabla 2: Preparación Biomecánica -->
    <div style="font-size:11px;font-weight:700;color:var(--t2);text-transform:uppercase;margin-bottom:6px">2. Preparación Biomecánica</div>
    <div class="row g-2 mb-2">
      <div class="col-12 col-md-4"><label class="form-label" style="font-size:11px">Técnica de IBM</label><input type="text" name="biom_tecnica_gral" class="form-control form-control-sm" value="<?=e($_POST['biom_tecnica_gral']??'')?>"></div>
    </div>
    <div class="table-responsive mb-3">
      <table class="table tbl-endo mb-1"><thead><tr>
        <th>N° Raíces</th><th>Conducto</th><th>Instr. Ajuste Apical</th><th>Instr. Memoria</th><th>Técnica IBM</th><th>Instr. Inicial</th><th>Instr. Final</th><th></th>
      </tr></thead><tbody id="biom_tbody">
        <?php
        $biom_show = $biom_rows ?: array_fill(0,3,array_fill_keys(['n_raices','conducto','instrumento_ajuste','instrumento_memoria','tecnica_ibm','instrumento_inicial','instrumento_final'],''));
        foreach($biom_show as $row): ?>
        <tr>
          <td><input type="text" name="biom_raices[]" value="<?=e($row['n_raices']??'')?>"></td>
          <td><input type="text" name="biom_conducto[]" value="<?=e($row['conducto']??'')?>"></td>
          <td><input type="text" name="biom_ajuste[]" value="<?=e($row['instrumento_ajuste']??'')?>"></td>
          <td><input type="text" name="biom_memoria[]" value="<?=e($row['instrumento_memoria']??'')?>"></td>
          <td><input type="text" name="biom_tecnica[]" value="<?=e($row['tecnica_ibm']??'')?>"></td>
          <td><input type="text" name="biom_inicial[]" value="<?=e($row['instrumento_inicial']??'')?>"></td>
          <td><input type="text" name="biom_final[]" value="<?=e($row['instrumento_final']??'')?>"></td>
          <td><button type="button" onclick="this.closest('tr').remove()" class="btn btn-del btn-ico" style="width:22px;height:22px;font-size:10px"><i class="bi bi-x"></i></button></td>
        </tr>
        <?php endforeach; ?>
      </tbody></table>
      <button type="button" onclick="addRow('biom')" class="btn btn-dk btn-sm"><i class="bi bi-plus me-1"></i>Fila</button>
      <?php $adjUI('biomecanica','Preparación Biomecánica'); ?>
    </div>

    <!-- Medicación Intraconducto -->
    <div style="font-size:11px;font-weight:700;color:var(--t2);text-transform:uppercase;margin-bottom:6px">Medicación Intraconducto</div>
    <div class="row g-2 mb-3">
      <?php $meds=[['med_antibiotico','Antibiótico antiinflamatorio'],['med_hidroxido_calcio_a','Hidróxido de calcio anestésico'],['med_paramonoclorofenol','Paramonoclorofenol alcanforado'],['med_eugenol','Eugenol'],['med_cono_hidroxido','Cono de hidróxido de calcio'],['med_cono_clorhexidina','Cono de clorhexidina'],['med_hidroxido_pmcfa','Hidróxido de calcio y PMCFA'],['med_formocresol','Formocresol']];
      foreach($meds as [$nm,$lbl]): ?>
      <div class="col-6 col-md-4">
        <label class="d-flex align-items-center gap-2" style="background:var(--bg3);border:1px solid var(--bd2);border-radius:5px;padding:5px 8px;cursor:pointer;font-size:11px">
          <input type="checkbox" name="<?=$nm?>" value="1" <?=(int)($f[$nm]??0)?'checked':''?>>
          <?=$lbl?>
        </label>
      </div>
      <?php endforeach; ?>
      <div class="col-12 col-md-6">
        <label class="form-label" style="font-size:11px">Otra medicación</label>
        <input type="text" name="med_otro" class="form-control form-control-sm" value="<?=e($f['med_otro']??'')?>">
      </div>
    </div>

    <!-- Tabla 3: Obturación -->
    <div style="font-size:11px;font-weight:700;color:var(--t2);text-transform:uppercase;margin-bottom:6px">3. Obturación de Conductos</div>
    <div class="table-responsive mb-2">
      <table class="table tbl-endo mb-1"><thead><tr>
        <th>N° Raíces</th><th>Conducto</th><th>N° Cono Principal</th><th>Tipo de Técnica</th><th>Material</th><th></th>
      </tr></thead><tbody id="obtu_tbody">
        <?php
        $obtu_show = $obtu_rows ?: array_fill(0,3,array_fill_keys(['n_raices','conducto','n_cono_principal','tipo_tecnica','material'],''));
        foreach($obtu_show as $row): ?>
        <tr>
          <td><input type="text" name="obtu_raices[]" value="<?=e($row['n_raices']??'')?>"></td>
          <td><input type="text" name="obtu_conducto[]" value="<?=e($row['conducto']??'')?>"></td>
          <td><input type="text" name="obtu_cono[]" value="<?=e($row['n_cono_principal']??'')?>"></td>
          <td><input type="text" name="obtu_tecnica[]" value="<?=e($row['tipo_tecnica']??'')?>"></td>
          <td><input type="text" name="obtu_material[]" value="<?=e($row['material']??'')?>"></td>
          <td><button type="button" onclick="this.closest('tr').remove()" class="btn btn-del btn-ico" style="width:22px;height:22px;font-size:10px"><i class="bi bi-x"></i></button></td>
        </tr>
        <?php endforeach; ?>
      </tbody></table>
      <button type="button" onclick="addRow('obtu')" class="btn btn-dk btn-sm"><i class="bi bi-plus me-1"></i>Fila</button>
      <?php $adjUI('obturacion','Obturación de Conductos'); ?>
    </div>
  </div>

  <!-- CIERRE -->
  <div class="ficha-section">
    <div class="ficha-section-title">Cierre y Controles</div>
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label">Medicación Coadyuvante</label>
        <textarea name="medicacion_coadyuvante" class="form-control" rows="2"><?=e($f['medicacion_coadyuvante']??'')?></textarea>
      </div>
      <div class="col-12">
        <label class="form-label">Controles</label>
        <div id="ctrlBox">
          <?php $rowsCtrl = $controles ?: [['fecha'=>'','observacion'=>'']]; foreach($rowsCtrl as $c): ?>
          <div class="ctrl-row" style="background:var(--bg3);border:1px solid var(--bd2);border-radius:8px;padding:10px;margin-bottom:8px">
            <div class="d-flex gap-2 align-items-start">
              <div style="flex:0 0 170px"><label style="font-size:11px;color:var(--t2)">Fecha de control</label><input type="date" name="ctrl_fecha[]" class="form-control form-control-sm" value="<?=e($c['fecha']??'')?>"></div>
              <div style="flex:1"><label style="font-size:11px;color:var(--t2)">Observación</label><textarea name="ctrl_obs[]" class="form-control form-control-sm" rows="2" placeholder="Observación del control..."><?=e($c['observacion']??'')?></textarea></div>
              <button type="button" class="btn btn-del btn-ico btn-sm" style="margin-top:18px" onclick="this.closest('.ctrl-row').remove()"><i class="bi bi-trash"></i></button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-dk btn-sm" onclick="addCtrl()"><i class="bi bi-plus me-1"></i>Agregar control</button>
        <script>
        function addCtrl(){
          var d=document.createElement('div'); d.className='ctrl-row';
          d.style.cssText='background:var(--bg3);border:1px solid var(--bd2);border-radius:8px;padding:10px;margin-bottom:8px';
          d.innerHTML='<div class="d-flex gap-2 align-items-start">'+
            '<div style="flex:0 0 170px"><label style="font-size:11px;color:var(--t2)">Fecha de control</label><input type="date" name="ctrl_fecha[]" class="form-control form-control-sm"></div>'+
            '<div style="flex:1"><label style="font-size:11px;color:var(--t2)">Observación</label><textarea name="ctrl_obs[]" class="form-control form-control-sm" rows="2" placeholder="Observación del control..."></textarea></div>'+
            '<button type="button" class="btn btn-del btn-ico btn-sm" style="margin-top:18px" onclick="this.closest(\'.ctrl-row\').remove()"><i class="bi bi-trash"></i></button>'+
            '</div>';
          document.getElementById('ctrlBox').appendChild(d);
        }
        </script>
      </div>
      <div class="col-12"><hr style="border-color:var(--bd2)"><label class="d-flex align-items-center gap-2" style="cursor:pointer;font-weight:600"><?php $chk('tratamiento_finalizado','Tratamiento Finalizado de Endodoncia'); ?></label></div>
      <div class="col-12 col-md-4"><label class="form-label">Fecha finalización</label><input type="date" name="fecha_finalizacion" class="form-control" value="<?=e($f['fecha_finalizacion']??'')?>"></div>
      <div class="col-12 col-md-4"><label class="form-label">Hora</label><input type="time" name="hora_finalizacion" class="form-control" value="<?=e($f['hora_finalizacion']??'')?>"></div>
    </div>
  </div>

  <div class="d-flex gap-2 justify-content-end mb-4">
    <a href="?paciente_id=<?=$paciente_id?>" class="btn btn-dk">Cancelar</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-floppy me-2"></i>Guardar Ficha</button>
  </div>
</form>
</div>

<script>
const ODOM_TEMPLATE = `<tr><td><input type="text" name="odom_raices[]"></td><td><input type="text" name="odom_conducto[]"></td><td><input type="text" name="odom_referencia[]"></td><td><input type="number" step="0.01" name="odom_long_rx[]"></td><td><input type="number" step="0.01" name="odom_correccion[]"></td><td><input type="number" step="0.01" name="odom_long_trabajo[]"></td><td><button type="button" onclick="this.closest('tr').remove()" class="btn btn-del btn-ico" style="width:22px;height:22px;font-size:10px"><i class="bi bi-x"></i></button></td></tr>`;
const BIOM_TEMPLATE = `<tr><td><input type="text" name="biom_raices[]"></td><td><input type="text" name="biom_conducto[]"></td><td><input type="text" name="biom_ajuste[]"></td><td><input type="text" name="biom_memoria[]"></td><td><input type="text" name="biom_tecnica[]"></td><td><input type="text" name="biom_inicial[]"></td><td><input type="text" name="biom_final[]"></td><td><button type="button" onclick="this.closest('tr').remove()" class="btn btn-del btn-ico" style="width:22px;height:22px;font-size:10px"><i class="bi bi-x"></i></button></td></tr>`;
const OBTU_TEMPLATE = `<tr><td><input type="text" name="obtu_raices[]"></td><td><input type="text" name="obtu_conducto[]"></td><td><input type="text" name="obtu_cono[]"></td><td><input type="text" name="obtu_tecnica[]"></td><td><input type="text" name="obtu_material[]"></td><td><button type="button" onclick="this.closest('tr').remove()" class="btn btn-del btn-ico" style="width:22px;height:22px;font-size:10px"><i class="bi bi-x"></i></button></td></tr>`;
function addRow(type) {
  const t = {odom:ODOM_TEMPLATE,biom:BIOM_TEMPLATE,obtu:OBTU_TEMPLATE}[type];
  document.getElementById(type+'_tbody').insertAdjacentHTML('beforeend',t);
  // Apply tbl-endo input styles
  document.querySelectorAll('#'+type+'_tbody input').forEach(function(i){
    i.style.cssText='background:var(--bg3);border:1px solid var(--bd2);border-radius:4px;padding:3px 6px;font-size:11px;color:var(--t);width:100%';
  });
}
</script>
<?php
    require_once __DIR__.'/../includes/footer.php';

// ── VER ───────────────────────────────────────────────────────────────────
} elseif ($accion === 'ver' && $id) {
    $f   = db()->prepare("SELECT ef.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac,p.fecha_nacimiento,p.sexo,p.codigo AS cod_pac,CONCAT(u.nombre,' ',u.apellidos) AS doctor,u.cmp,u.firma_imagen,hc.numero_hc FROM endodoncia_fichas ef JOIN pacientes p ON ef.paciente_id=p.id LEFT JOIN usuarios u ON ef.doctor_id=u.id LEFT JOIN historias_clinicas hc ON ef.hc_id=hc.id WHERE ef.id=?");
    $f->execute([$id]); $f=$f->fetch();
    if(!$f){ flash('error','Ficha no encontrada'); go("pages/endodoncia.php?paciente_id=$paciente_id"); }
    $titulo="Ficha Endodóntica — ".$f['pac'];
    $pagina_activa='pac';
    $topbar_act='<a href="?paciente_id='.$paciente_id.'" class="btn btn-dk btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver</a>
    <a href="?accion=editar&id='.$id.'&paciente_id='.$paciente_id.'" class="btn btn-dk btn-sm"><i class="bi bi-pencil me-1"></i>Editar</a>
    <a href="?accion=pdf&id='.$id.'&paciente_id='.$paciente_id.'" class="btn btn-primary btn-sm" target="_blank"><i class="bi bi-filetype-pdf me-1"></i>PDF</a>';
    require_once __DIR__.'/../includes/header.php';
    $si = fn(?int $v) => $v===1?'<span style="color:#10b981">Sí</span>':($v===0?'<span style="color:#ef4444">No</span>':'<span style="color:var(--t3)">—</span>');
    $ch = fn(int $v,string $l) => $v ? '<span class="badge bg" style="font-size:10px">'.$l.'</span>' : '';
?>
<div class="pb row g-3">
  <div class="col-12 col-md-6">
    <div class="card p-3 mb-3">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t2);margin-bottom:8px">Paciente</div>
      <div style="font-size:16px;font-weight:800;color:var(--t)"><?=e($f['pac'])?></div>
      <div style="font-size:12px;color:var(--t2)">HC: <?=e($f['numero_hc']??'—')?> &bull; Pieza: <strong style="color:var(--c)"><?=e($f['pieza']??'—')?></strong></div>
      <div style="font-size:12px;color:var(--t2)">Dr. <?=e($f['doctor']??'—')?> <?=$f['cmp']?'(CMP: '.e($f['cmp']).')':''?></div>
    </div>
  </div>
  <div class="col-12">
    <a href="?accion=pdf&id=<?=$id?>&paciente_id=<?=$paciente_id?>" class="btn btn-primary" target="_blank">
      <i class="bi bi-filetype-pdf me-2"></i>Ver / Imprimir Ficha PDF Completa
    </a>
  </div>
</div>
<?php
    require_once __DIR__.'/../includes/footer.php';

// ── PDF ───────────────────────────────────────────────────────────────────
} elseif ($accion === 'pdf' && $id) {
    $f = db()->prepare("SELECT ef.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac,p.fecha_nacimiento,p.sexo,p.codigo AS cod_pac,CONCAT(u.nombre,' ',u.apellidos) AS doctor_nm,u.cmp,u.firma_imagen,hc.numero_hc FROM endodoncia_fichas ef JOIN pacientes p ON ef.paciente_id=p.id LEFT JOIN usuarios u ON ef.doctor_id=u.id LEFT JOIN historias_clinicas hc ON ef.hc_id=hc.id WHERE ef.id=?");
    $f->execute([$id]); $f=$f->fetch();
    if(!$f){ die('Ficha no encontrada'); }
    $odom = db()->prepare("SELECT * FROM endodoncia_odontometria WHERE ficha_id=?"); $odom->execute([$id]); $odom=$odom->fetchAll();
    $biom = db()->prepare("SELECT * FROM endodoncia_biomecanica WHERE ficha_id=?"); $biom->execute([$id]); $biom=$biom->fetchAll();
    $obtu = db()->prepare("SELECT * FROM endodoncia_obturacion WHERE ficha_id=?"); $obtu->execute([$id]); $obtu=$obtu->fetchAll();
    $adjuntos = []; try { $s=db()->prepare("SELECT * FROM endodoncia_adjuntos WHERE ficha_id=?"); $s->execute([$id]); foreach($s->fetchAll() as $r) $adjuntos[$r['seccion']]=$r; } catch (Throwable $e) {}
    $controlesV = []; try { $s=db()->prepare("SELECT * FROM endodoncia_controles WHERE ficha_id=? ORDER BY orden,id"); $s->execute([$id]); $controlesV=$s->fetchAll(); } catch (Throwable $e) {}
    if (!$controlesV) { if(!empty($f['control_1ra_semana']))$controlesV[]=['fecha'=>$f['control_1ra_semana'],'observacion'=>'']; if(!empty($f['control_2da_semana']))$controlesV[]=['fecha'=>$f['control_2da_semana'],'observacion'=>'']; }
    // Imagen/enlace del adjunto de una sección, ajustado para verse pequeño en el PDF
    $adjImg = function(string $sec) use ($adjuntos) {
        if (empty($adjuntos[$sec])) return '';
        $a=$adjuntos[$sec]; $ext=strtolower(pathinfo((string)$a['ruta'],PATHINFO_EXTENSION));
        $url=BASE_URL.'/uploads/'.$a['ruta'];
        if (in_array($ext,['jpg','jpeg','png','webp'])) return '<img src="'.htmlspecialchars($url).'" style="max-width:100%;max-height:200px;object-fit:contain;display:block;margin:0 auto">';
        return '<a href="'.htmlspecialchars($url).'" target="_blank" style="font-size:9px;color:#0a8aa0">&#128206; Ver archivo</a>';
    };

    // ── Identidad de la empresa (logo + nombre desde la tabla `empresa`) ──
    $emp      = empresa();
    $logoRel  = $emp['logo'] ?? '';
    $logoUrl  = $logoRel ? BASE_URL.'/uploads/'.ltrim($logoRel,'/') : '';
    $clinica  = $emp['nombre_comercial'] ?: ($emp['razon_social'] ?: getCfg('clinica_nombre','Clínica Odontológica'));
    $firmaUrl = !empty($f['firma_imagen']) ? BASE_URL.'/uploads/'.ltrim($f['firma_imagen'],'/') : '';

    $edad_pac = $f['fecha_nacimiento'] ? edad($f['fecha_nacimiento']) : '—';
    $sexo_ini = strtoupper(substr((string)($f['sexo'] ?? ''),0,1));
    header('Content-Type: text/html; charset=utf-8');

    // Helpers de marcado
    $box  = fn($v) => '('.(((int)$v)===1 ? '<b>X</b>' : '&nbsp;&nbsp;').')';          // casilla ( ) / (X)
    $sino = function($v){                                                              // radio Sí/No/—
        $isSi = ($v !== null && (int)$v === 1);
        $isNo = ($v !== null && (int)$v === 0);
        return 'SÍ ('.($isSi?'<b>X</b>':'&nbsp;&nbsp;').') &nbsp; NO ('.($isNo?'<b>X</b>':'&nbsp;&nbsp;').')';
    };
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Ficha Endod&oacute;ntica — <?=e($f['pac'])?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;font-size:10px;color:#111;background:#fff;padding:18px}
.page{max-width:780px;margin:0 auto}
/* Encabezado con logo */
.header{display:flex;align-items:center;gap:14px;border-bottom:2px solid #0a8aa0;padding-bottom:8px;margin-bottom:8px}
.header .logo{display:flex;align-items:center;justify-content:center;min-width:90px}
.header .logo img{max-height:70px;max-width:160px}
.header .ident{flex:1;text-align:center}
.header .ident .name{font-size:18px;font-weight:800;color:#0a8aa0;letter-spacing:.5px;line-height:1.1}
.header .ident .sub{font-size:11px;font-weight:700;letter-spacing:3px;color:#333;margin-top:2px}
.header .meta{min-width:90px;text-align:right;font-size:8.5px;color:#666}
.ficha-title{font-size:15px;font-weight:800;text-align:center;letter-spacing:1px;text-decoration:underline;margin:8px 0 10px}
.datos{display:flex;flex-wrap:wrap;gap:6px 18px;margin-bottom:10px;font-size:10.5px}
.datos .d{border-bottom:1px solid #888;padding:0 4px 2px;flex:1;min-width:140px}
.section-title{font-size:11px;font-weight:800;text-transform:uppercase;text-decoration:underline;margin:12px 0 6px;color:#0a3d4a}
.cols{display:flex;gap:18px}
.col{flex:1}
.row-yn{display:flex;justify-content:space-between;align-items:center;padding:2px 0;border-bottom:1px dotted #d8d8d8;font-size:10px}
.row-yn .lbl{flex:1}
.row-yn .ans{min-width:120px;text-align:right;white-space:nowrap}
.line{font-size:10px;margin:3px 0}
.fill{border-bottom:1px solid #999;display:inline-block;min-width:120px;padding:0 4px}
.dotline{border-bottom:1px dotted #555;display:inline-block;min-width:380px;padding:0 4px;font-weight:600}
.rxbox{border:1px solid #bbb;border-radius:3px;min-height:78px;display:flex;align-items:center;justify-content:center;font-size:8px;color:#999;text-align:center;padding:4px}
.chk-list{display:flex;flex-wrap:wrap;gap:4px 14px;font-size:10px;margin:4px 0}
.chk-list span{white-space:nowrap}
table.tbl{width:100%;border-collapse:collapse;margin:4px 0 6px;font-size:9px}
table.tbl th{background:#eef5f7;border:1px solid #b9c9cf;padding:4px 5px;text-align:center;font-size:8.5px;font-weight:700}
table.tbl td{border:1px solid #c5c5c5;padding:5px;height:20px}
.med-tbl{width:100%;border-collapse:collapse;font-size:9px;margin-top:4px}
.med-tbl td,.med-tbl th{border:1px solid #c5c5c5;padding:3px 6px}
.med-tbl th{background:#eef5f7;text-align:center}
.sign{display:flex;gap:24px;margin-top:14px}
.sign .b{flex:1;text-align:center}
.sign .b img{max-height:54px;max-width:200px;display:block;margin:0 auto 2px}
.sign .b .ln{border-top:1px solid #111;padding-top:4px;font-size:9.5px}
.proc{border:1px solid #d8d8d8;border-radius:4px;padding:8px;margin-top:8px}
.page-break{page-break-before:always;padding-top:14px}
@media print{body{padding:8px}.no-print{display:none}.page-break{page-break-before:always}}
</style>
</head>
<body>
<div class="page">

<!-- ============ PÁGINA 1 ============ -->
<div class="header">
  <div class="logo">
    <?php if($logoUrl): ?><img src="<?=e($logoUrl)?>" alt="Logo"><?php else: ?>
      <div style="font-size:22px;font-weight:800;color:#0a8aa0">🦷</div>
    <?php endif; ?>
  </div>
  <div class="ident">
    <div class="name"><?=e($clinica)?></div>
    <div class="sub">CONSULTORIO ODONTOL&Oacute;GICO</div>
  </div>
  <div class="meta">
    <?php if(!empty($emp['ruc'])): ?>RUC: <?=e($emp['ruc'])?><br><?php endif; ?>
    <?php if(!empty($emp['telefono'])): ?>Tel: <?=e($emp['telefono'])?><br><?php endif; ?>
    Impreso: <?=date('d/m/Y')?>
  </div>
</div>

<div class="ficha-title">FICHA ENDOD&Oacute;NTICA</div>

<div class="datos">
  <span class="d">H.C. N&deg; <strong><?=e($f['numero_hc']??'')?></strong></span>
  <span class="d" style="flex:2">Paciente: <strong><?=e($f['pac'])?></strong></span>
  <span class="d">Edad: <strong><?=$edad_pac?></strong></span>
  <span class="d">Sexo: M (<?=$sexo_ini==='M'?'<b>X</b>':'&nbsp;&nbsp;'?>) &nbsp; F (<?=$sexo_ini==='F'?'<b>X</b>':'&nbsp;&nbsp;'?>)</span>
  <span class="d">Pieza: <strong><?=e($f['pieza']??'')?></strong></span>
</div>

<div class="section-title">Examen Cl&iacute;nico Radiogr&aacute;fico</div>
<div class="cols">
  <div class="col">
    <?php
    $items=[['caries_profunda','Caries profunda'],['discromia','Discromi&iacute;a'],['fistula_intraoral','F&iacute;stula Intraoral'],['exposicion_pulpar','Exposici&oacute;n Pulpar'],['movilidad_dentaria','Movilidad Dentaria'],['tratamiento_previo','Tratamiento Endod&oacute;ntico Previo'],['cuerpos_extranos','Presencia de cuerpos extra&ntilde;os']];
    foreach($items as [$k,$l]): ?>
      <div class="row-yn"><span class="lbl"><?=$l?></span><span class="ans"><?=$sino($f[$k]??null)?></span></div>
    <?php endforeach; ?>
  </div>
  <div class="col">
    <div class="line">Inflamaci&oacute;n Fondo de Surco <?=$box($f['inflamacion_fondo'])?> &nbsp; Facial <?=$box($f['inflamacion_facial'])?></div>
    <div class="line">Fractura coronaria <?=$box($f['fractura_coronaria'])?> &nbsp; Radicular <?=$box($f['fractura_radicular'])?></div>
    <div class="line">Presencia de Corona <?=$box($f['presencia_corona'])?> &nbsp; Poste <?=$box($f['presencia_poste'])?></div>
    <div class="line">Radiolucidez: Apical <?=$box($f['radiolucidez_apical'])?> &nbsp; Medio <?=$box($f['radiolucidez_medio'])?> &nbsp; Cervical <?=$box($f['radiolucidez_cervical'])?></div>
    <div class="rxbox" style="margin-top:6px">Rx. Periapical de Diagn&oacute;stico</div>
  </div>
</div>

<div class="section-title">Tipo de Dolor</div>
<div class="cols">
  <div class="col">
    <div class="chk-list">
      <span>Provocado <?=$box($f['dolor_provocado'])?></span>
      <span>Espont&aacute;neo <?=$box($f['dolor_espontaneo'])?></span>
    </div>
    <div class="chk-list">
      <span>Localizado <?=$box($f['dolor_localizado'])?></span>
      <span>Difuso <?=$box($f['dolor_difuso'])?></span>
    </div>
    <div class="chk-list">
      <span>Temporario <?=$box($f['dolor_temporario'])?></span>
      <span>Permanente <?=$box($f['dolor_permanente'])?></span>
    </div>
    <div class="chk-list">
      <span>Leve <?=$box($f['intensidad']==='leve'?1:0)?></span>
      <span>Moderado <?=$box($f['intensidad']==='moderado'?1:0)?></span>
      <span>Severo <?=$box($f['intensidad']==='severo'?1:0)?></span>
    </div>
  </div>
  <div class="col">
    <div class="row-yn"><span class="lbl">Calma con los analg&eacute;sicos</span><span class="ans"><?=$sino($f['calma_analgesicos']??null)?></span></div>
    <div class="row-yn"><span class="lbl">Nocturno</span><span class="ans"><?=$sino($f['nocturno']??null)?></span></div>
    <div class="row-yn"><span class="lbl">Asintom&aacute;tico</span><span class="ans"><?=$sino($f['asintomatico']??null)?></span></div>
    <div class="line" style="margin-top:4px">Percusi&oacute;n vertical <?=$box($f['percusion_vertical'])?> &nbsp; Horizontal <?=$box($f['percusion_horizontal'])?></div>
    <div class="line">Prueba t&eacute;rmica <?=$box($f['prueba_termica'])?></div>
  </div>
</div>
<?php $im=$adjImg('dolor'); if($im!==''): ?>
<div class="cols" style="margin-top:6px"><div class="col" style="flex:1"><div style="font-size:8px;color:#999;margin-bottom:2px">Adjunto &mdash; Tipo de dolor:</div><div class="rxbox" style="min-height:60px"><?=$im?></div></div><div class="col" style="flex:2"></div></div>
<?php endif; ?>
<div class="line" style="margin-top:6px">Diagn&oacute;stico presuntivo: <span class="fill" style="min-width:420px"><?=e($f['diagnostico_presuntivo']??'')?></span></div>

<div class="line" style="margin-top:10px">Odont&oacute;logo(a): <span class="dotline" style="min-width:430px"><?=e($f['doctor_nm']??'')?></span></div>

<div class="proc">
  <div style="font-weight:800;font-size:11px;text-decoration:underline;margin-bottom:6px">INICIO DEL PROCEDIMIENTO</div>
  <div class="line">Acceso endod&oacute;ntico <?=$sino($f['acceso_endodontico']??null)?> &nbsp;&nbsp; Diagn&oacute;stico Definitivo: <span class="fill" style="min-width:260px"><?=e($f['diagnostico_definitivo']??'')?></span></div>
  <div class="line" style="margin-top:10px">Odont&oacute;logo(a): <span class="dotline" style="min-width:430px"><?=e($f['doctor_nm']??'')?></span></div>
</div>

<!-- ============ PÁGINA 2 ============ -->
<div class="page-break">
<div class="header">
  <div class="logo">
    <?php if($logoUrl): ?><img src="<?=e($logoUrl)?>" alt="Logo"><?php else: ?><div style="font-size:22px;font-weight:800;color:#0a8aa0">🦷</div><?php endif; ?>
  </div>
  <div class="ident">
    <div class="name"><?=e($clinica)?></div>
    <div class="sub">CONSULTORIO ODONTOL&Oacute;GICO</div>
  </div>
  <div class="meta">Paciente: <?=e($f['pac'])?></div>
</div>

<div class="section-title">Tratamiento Realizado</div>
<div class="chk-list">
  <span>Biopulpectom&iacute;a <?=$box($f['biopulpectomia'])?></span>
  <span>Necropulpectom&iacute;a <?=$box($f['necropulpectomia'])?></span>
  <span>Re-tratamiento <?=$box($f['retratamiento'])?></span>
  <span>Protecci&oacute;n Pulpar Directa <?=$box($f['proteccion_pulpar'])?></span>
</div>
<div class="chk-list">
  <span>Apicog&eacute;nesis <?=$box($f['apicogenesis'])?></span>
  <span>Apexificaci&oacute;n <?=$box($f['apexificacion'])?></span>
  <span>Cirug&iacute;a Parendod&oacute;ntica <?=$box($f['cirugia_parendodontica'])?></span>
</div>
<div class="chk-list">
  <span>Ex&eacute;resis Pulpar <?=$box($f['exeresis_pulpar'])?></span>
  <span>Aislamiento absoluto <?=$box($f['aislamiento_absoluto'])?></span>
  <span>Remoci&oacute;n de obturaci&oacute;n endod&oacute;ntica previa <?=$box($f['remocion_obturacion'])?></span>
</div>
<div class="line">Clamp N&deg;: <span class="fill" style="min-width:70px"><?=e($f['clamp_numero']??'')?></span> &nbsp;&nbsp; Irrigante: <span class="fill" style="min-width:200px"><?=e($f['irrigante']??'')?></span></div>

<div class="cols" style="margin-top:10px">
  <div class="col" style="flex:3">
    <div class="section-title" style="margin-top:0">1. Odontometr&iacute;a (Conductometr&iacute;a) — Longitud de Trabajo</div>
    <table class="tbl">
      <thead><tr><th>N&deg; ra&iacute;ces</th><th>Conducto</th><th>Referencia del tope inicial u oclusal</th><th>Long. radiogr&aacute;fica del diente menos 2mm</th><th>Correcci&oacute;n + - "x" mm</th><th>Long. de trabajo</th></tr></thead>
      <tbody>
        <?php for($i=0;$i<3;$i++): $r=$odom[$i]??[]; ?>
        <tr><td><?=e($r['n_raices']??'')?></td><td><?=e($r['conducto']??'')?></td><td><?=e($r['referencia_tope']??'')?></td><td><?=e($r['long_radiografica']??'')?></td><td><?=e($r['correccion']??'')?></td><td><?=e($r['long_trabajo']??'')?></td></tr>
        <?php endfor; ?>
      </tbody>
    </table>
  </div>
  <div class="col" style="flex:1"><div class="rxbox" style="height:100%"><?php $im=$adjImg('odontometria'); echo $im!==''?$im:'Rx. de<br>Odontometr&iacute;a'; ?></div></div>
</div>

<div class="cols">
  <div class="col" style="flex:3">
    <div class="section-title" style="margin-top:0">2. Preparaci&oacute;n Biomec&aacute;nica</div>
    <table class="tbl">
      <thead><tr><th>N&deg; ra&iacute;ces</th><th>Conducto</th><th>Instrumento de ajuste apical inicial</th><th>Instrumento memoria</th><th>T&eacute;cnica IBM</th><th>Instr. Inicial</th><th>Instr. Final</th></tr></thead>
      <tbody>
        <?php for($i=0;$i<3;$i++): $r=$biom[$i]??[]; ?>
        <tr><td><?=e($r['n_raices']??'')?></td><td><?=e($r['conducto']??'')?></td><td><?=e($r['instrumento_ajuste']??'')?></td><td><?=e($r['instrumento_memoria']??'')?></td><td><?=e($r['tecnica_ibm']??'')?></td><td><?=e($r['instrumento_inicial']??'')?></td><td><?=e($r['instrumento_final']??'')?></td></tr>
        <?php endfor; ?>
      </tbody>
    </table>
  </div>
  <div class="col" style="flex:1"><div class="rxbox" style="height:100%"><?php $im=$adjImg('biomecanica'); echo $im!==''?$im:'Rx. de<br>Conometr&iacute;a'; ?></div></div>
</div>

<table class="med-tbl">
  <thead><tr><th style="width:10%">S&iacute;/No</th><th style="width:40%">Medicaci&oacute;n Intraconducto</th><th style="width:10%">S&iacute;/No</th><th style="width:40%">Medicaci&oacute;n Intraconducto</th></tr></thead>
  <tbody>
    <tr><td align="center"><?=$box($f['med_antibiotico'])?></td><td>Antibi&oacute;tico antinflamatorio</td><td align="center"><?=$box($f['med_cono_hidroxido'])?></td><td>Cono de hidr&oacute;xido de calcio</td></tr>
    <tr><td align="center"><?=$box($f['med_hidroxido_calcio_a'])?></td><td>Hidr&oacute;xido de calcio anest&eacute;sico</td><td align="center"><?=$box($f['med_cono_clorhexidina'])?></td><td>Cono de clorhexidina</td></tr>
    <tr><td align="center"><?=$box($f['med_paramonoclorofenol'])?></td><td>Paramonoclorofenol alcanforado</td><td align="center"><?=$box($f['med_hidroxido_pmcfa'])?></td><td>Hidr&oacute;xido de calcio y PMCFA</td></tr>
    <tr><td align="center"><?=$box($f['med_eugenol'])?></td><td>Eugenol</td><td align="center"><?=$box($f['med_formocresol'])?></td><td>Formocresol</td></tr>
    <tr><td align="center"><?=$box($f['med_otro']?1:0)?></td><td colspan="3">Otra medicaci&oacute;n: <?=e($f['med_otro']??'')?></td></tr>
  </tbody>
</table>

<div class="cols" style="margin-top:8px">
  <div class="col" style="flex:3">
    <div class="section-title" style="margin-top:0">3. Obturaci&oacute;n de Conductos</div>
    <table class="tbl">
      <thead><tr><th>N&deg; ra&iacute;ces</th><th>Conducto</th><th>N&deg; de Cono Principal</th><th>Tipo de T&eacute;cnica</th><th>Material de Obturaci&oacute;n</th></tr></thead>
      <tbody>
        <?php for($i=0;$i<3;$i++): $r=$obtu[$i]??[]; ?>
        <tr><td><?=e($r['n_raices']??'')?></td><td><?=e($r['conducto']??'')?></td><td><?=e($r['n_cono_principal']??'')?></td><td><?=e($r['tipo_tecnica']??'')?></td><td><?=e($r['material']??'')?></td></tr>
        <?php endfor; ?>
      </tbody>
    </table>
  </div>
  <div class="col" style="flex:1"><div class="rxbox" style="height:100%"><?php $im=$adjImg('obturacion'); echo $im!==''?$im:'Rx.<br>Obturaci&oacute;n'; ?></div></div>
</div>

<div class="line" style="margin-top:6px">MEDICACI&Oacute;N COADYUVANTE: <span class="fill" style="min-width:430px"><?=e($f['medicacion_coadyuvante']??'')?></span></div>
<div class="line" style="margin-top:6px">CONTROLES:</div>
<?php if($controlesV): foreach($controlesV as $c): ?>
<div class="line" style="margin-left:12px">&bull; <?=fDate($c['fecha']??'')?><?=trim((string)($c['observacion']??''))!==''?' &mdash; '.e($c['observacion']):''?></div>
<?php endforeach; else: ?>
<div class="line" style="margin-left:12px">&mdash;</div>
<?php endif; ?>

<?php /* Los adjuntos se muestran en su sección correspondiente (casillas Rx. y Tipo de dolor). */ ?>


<div style="margin-top:10px;font-weight:800;font-size:11px">TRATAMIENTO FINALIZADO DE ENDODONCIA:</div>
<div class="line" style="margin-top:4px">&bull; Odont&oacute;logo(a): <span class="fill" style="min-width:320px"><?=e($f['doctor_nm']??'')?></span></div>
<div class="line">&bull; Hora: <span class="fill" style="min-width:120px"><?=e($f['hora_finalizacion']??'')?></span></div>
<div class="line">&bull; Fecha: <span class="fill" style="min-width:120px"><?=fDate($f['fecha_finalizacion']??'')?></span></div>

<div class="sign" style="margin-top:18px;justify-content:center">
  <div class="b" style="flex:0 0 300px">
    <?php if($firmaUrl): ?><img src="<?=e($firmaUrl)?>" alt="Firma"><?php else: ?><div style="height:48px"></div><?php endif; ?>
    <div class="ln">Firma del Odont&oacute;logo(a)<br><span style="font-size:9px;color:#555"><?=e($f['doctor_nm']??'')?><?=!empty($f['cmp'])?' · CMP: '.e($f['cmp']):''?></span></div>
  </div>
</div>
</div><!-- page-break -->
</div><!-- page -->

<div class="no-print" style="text-align:center;margin-top:20px">
  <button onclick="window.print()" style="padding:10px 28px;background:#111A26;color:#00D4EE;border:1px solid #00D4EE;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer">🖨️ Imprimir / Guardar PDF</button>
  <button onclick="history.back()" style="margin-left:10px;padding:10px 20px;background:#1E2D40;color:#A0B0C0;border:1px solid #334155;border-radius:6px;font-size:13px;cursor:pointer">← Volver</button>
</div>
</body></html>
<?php
    exit;
}
