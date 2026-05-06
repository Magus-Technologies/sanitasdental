<?php
require_once __DIR__.'/../../includes/config.php';
require_once __DIR__.'/../../includes/config_sunat.php';
requiereRol('admin');

$titulo = 'Empresa';
$pagina_activa = 'empresa';

$emp = db()->query("SELECT * FROM empresa WHERE id=1")->fetch() ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ap = $_POST['accion'] ?? '';

    if ($ap === 'guardar') {
        $d = [
            'ruc'               => preg_replace('/\D/', '', $_POST['ruc'] ?? ''),
            'razon_social'      => trim($_POST['razon_social'] ?? ''),
            'nombre_comercial'  => trim($_POST['nombre_comercial'] ?? ''),
            'direccion'         => trim($_POST['direccion'] ?? ''),
            'ubigeo'            => trim($_POST['ubigeo'] ?? ''),
            'distrito'          => trim($_POST['distrito'] ?? ''),
            'provincia'         => trim($_POST['provincia'] ?? ''),
            'departamento'      => trim($_POST['departamento'] ?? ''),
            'telefono'          => trim($_POST['telefono'] ?? ''),
            'telefono2'         => trim($_POST['telefono2'] ?? ''),
            'email'             => trim($_POST['email'] ?? ''),
            'web'                => trim($_POST['web'] ?? ''),
            'igv'                => (float)($_POST['igv'] ?? 18.00),
            'moneda'             => trim($_POST['moneda'] ?? 'S/'),
            'color_primario'     => trim($_POST['color_primario'] ?? '#00d4ee'),
            'propaganda'         => trim($_POST['propaganda'] ?? ''),
            'pie_pagina'         => trim($_POST['pie_pagina'] ?? ''),
            'modo'               => in_array($_POST['modo'] ?? '', ['produccion','beta'], true) ? $_POST['modo'] : 'beta',
            'sunat_usuario_sol'  => trim($_POST['sunat_usuario_sol'] ?? ''),
            'sunat_clave_sol'    => trim($_POST['sunat_clave_sol'] ?? ''),
        ];

        if (strlen($d['ruc']) !== 11) {
            flash('error', 'El RUC debe tener exactamente 11 dígitos.');
            go('pages/admin/empresa.php');
        }
        if ($d['razon_social'] === '') {
            flash('error', 'La razón social es obligatoria.');
            go('pages/admin/empresa.php');
        }

        // Upload logo (opcional)
        if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $rel = subirArchivo($_FILES['logo'], 'empresa', ['jpg','jpeg','png','webp','svg']);
            if (!$rel) {
                flash('error', 'No se pudo subir el logo (formato/peso). Permitido: JPG, PNG, WEBP, SVG (máx 20MB).');
                go('pages/admin/empresa.php');
            }
            // Borrar logo anterior si existía
            if (!empty($emp['logo']) && file_exists(UPLOAD_PATH.$emp['logo'])) {
                @unlink(UPLOAD_PATH.$emp['logo']);
            }
            $d['logo'] = $rel;
        }

        $cols = array_keys($d);
        $sets = implode(',', array_map(fn($c) => "$c=?", $cols));
        $sql  = "UPDATE empresa SET $sets WHERE id=1";
        db()->prepare($sql)->execute(array_values($d));
        auditar('EDITAR_EMPRESA', 'empresa', 1);
        flash('ok', 'Datos de la empresa actualizados.');
        go('pages/admin/empresa.php');
    }

    if ($ap === 'quitar_logo') {
        if (!empty($emp['logo']) && file_exists(UPLOAD_PATH.$emp['logo'])) {
            @unlink(UPLOAD_PATH.$emp['logo']);
        }
        db()->prepare("UPDATE empresa SET logo=NULL WHERE id=1")->execute();
        auditar('QUITAR_LOGO_EMPRESA', 'empresa', 1);
        flash('ok', 'Logo eliminado.');
        go('pages/admin/empresa.php');
    }

    // Sube el .pem al API Laravel firmador
    if ($ap === 'subir_pem') {
        if (empty($emp['ruc']) || strlen($emp['ruc']) !== 11) {
            flash('error', 'Primero guarda un RUC válido (11 dígitos) antes de subir el certificado.');
            go('pages/admin/empresa.php');
        }
        // URL del API auto-detectada en config_sunat.php (local vs producción)
        $apiUrl = SUNAT_API_URL;
        if (empty($_FILES['pem']['name']) || $_FILES['pem']['error'] !== UPLOAD_ERR_OK) {
            flash('error', 'Selecciona un archivo .pem válido.');
            go('pages/admin/empresa.php');
        }
        $tmp = $_FILES['pem']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['pem']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'pem') {
            flash('error', 'Solo se acepta archivo .pem (convertido desde tu .pfx con OpenSSL).');
            go('pages/admin/empresa.php');
        }
        if ($_FILES['pem']['size'] > 512 * 1024) {
            flash('error', 'El certificado no debe superar 512 KB.');
            go('pages/admin/empresa.php');
        }

        $endpoint = rtrim($apiUrl, '/').'/guardar/certificado/'.$emp['ruc'];
        $cfile = curl_file_create($tmp, 'application/x-pem-file', $_FILES['pem']['name']);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ['certificado' => $cfile],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($res === false) {
            flash('error', "No se pudo conectar al API SUNAT ($endpoint): $err");
            go('pages/admin/empresa.php');
        }
        $j = json_decode($res, true);
        if (!is_array($j) || empty($j['estado'])) {
            $msg = $j['mensaje'] ?? "Respuesta HTTP $code: " . substr($res, 0, 200);
            flash('error', "El API rechazó el certificado: $msg");
            go('pages/admin/empresa.php');
        }

        // Marcar como subido
        db()->prepare("UPDATE empresa SET certificado_subido=1, certificado_fecha=NOW() WHERE id=1")->execute();
        auditar('SUBIR_CERTIFICADO_PEM', 'empresa', 1);
        flash('ok', '✅ Certificado .pem subido correctamente al API SUNAT (RUC '.$emp['ruc'].').');
        go('pages/admin/empresa.php');
    }
}

require_once __DIR__.'/../../includes/header.php';
?>
<form method="POST" enctype="multipart/form-data">
 <input type="hidden" name="accion" value="guardar">
 <div class="row g-4">

  <!-- Columna izquierda: Logo + identidad básica -->
  <div class="col-12 col-lg-4">
   <div class="card mb-4">
    <div class="card-header"><span style="color:var(--t)"><i class="bi bi-image me-1"></i>Logo</span></div>
    <div class="p-4 text-center">
     <?php if (!empty($emp['logo'])): ?>
      <img src="<?=BASE_URL?>/uploads/<?=e($emp['logo'])?>" alt="Logo" style="max-width:100%;max-height:160px;background:#fff;padding:8px;border-radius:8px;border:1px solid var(--bd2)">
      <div class="mt-2"><small style="color:var(--t2);word-break:break-all;font-size:11px"><?=e($emp['logo'])?></small></div>
     <?php else: ?>
      <div class="d-flex align-items-center justify-content-center" style="height:160px;background:var(--bg3);border-radius:8px;border:2px dashed var(--bd2);color:var(--t2)">
       <div><i class="bi bi-image" style="font-size:42px;display:block;margin-bottom:6px"></i>Sin logo</div>
      </div>
     <?php endif; ?>

     <label class="form-label mt-3 d-block text-start">Subir logo (JPG, PNG, WEBP, SVG · máx 20MB)</label>
     <input type="file" name="logo" class="form-control" accept="image/png,image/jpeg,image/webp,image/svg+xml" id="logoFile">
     <div id="logoPreview" class="mt-2"></div>

     <?php if (!empty($emp['logo'])): ?>
      <button type="button" class="btn btn-del btn-sm w-100 mt-3"
       onclick="if(confirm('¿Eliminar el logo actual?')){document.getElementById('frmQuitar').submit();}">
       <i class="bi bi-trash me-1"></i>Quitar logo actual
      </button>
     <?php endif; ?>
    </div>
   </div>

   <div class="card">
    <div class="card-header"><span style="color:var(--t)"><i class="bi bi-palette me-1"></i>Apariencia</span></div>
    <div class="p-4">
     <div class="mb-3">
      <label class="form-label">Color primario</label>
      <div class="d-flex gap-2 align-items-center">
       <input type="color" name="color_primario" value="<?=e($emp['color_primario'] ?? '#00d4ee')?>" class="form-control form-control-color" style="width:60px;padding:4px">
       <input type="text" id="colorTxt" value="<?=e($emp['color_primario'] ?? '#00d4ee')?>" class="form-control" readonly>
      </div>
     </div>
     <div class="mb-3">
      <label class="form-label">Moneda</label>
      <input type="text" name="moneda" value="<?=e($emp['moneda'] ?? 'S/')?>" class="form-control" maxlength="10">
     </div>
     <div>
      <label class="form-label">IGV (%)</label>
      <input type="number" name="igv" value="<?=e($emp['igv'] ?? '18.00')?>" step="0.01" min="0" max="100" class="form-control">
     </div>
    </div>
   </div>
  </div>

  <!-- Columna derecha: Datos fiscales y contacto -->
  <div class="col-12 col-lg-8">
   <div class="card mb-4">
    <div class="card-header"><span style="color:var(--t)"><i class="bi bi-building me-1"></i>Datos fiscales</span></div>
    <div class="p-4">
     <div class="row g-3 align-items-end">
      <div class="col-12 col-md-5">
       <label class="form-label">RUC * (11 dígitos)</label>
       <div class="input-group">
        <input type="text" id="rucInp" name="ruc" value="<?=e($emp['ruc'] ?? '')?>" maxlength="11" class="form-control" inputmode="numeric" required>
        <button type="button" class="btn btn-primary" id="btnBuscarRuc"><i class="bi bi-search me-1"></i>Buscar SUNAT</button>
       </div>
       <small id="rucMsg" style="font-size:11px"></small>
      </div>
      <div class="col-12 col-md-7">
       <label class="form-label">Modo SUNAT</label>
       <select name="modo" class="form-select">
        <option value="beta"       <?=($emp['modo'] ?? 'beta')==='beta'?'selected':''?>>BETA (pruebas)</option>
        <option value="produccion" <?=($emp['modo'] ?? '')==='produccion'?'selected':''?>>PRODUCCIÓN (real)</option>
       </select>
      </div>
      <div class="col-12 col-md-7">
       <label class="form-label">Razón social *</label>
       <input type="text" name="razon_social" value="<?=e($emp['razon_social'] ?? '')?>" class="form-control" required>
      </div>
      <div class="col-12 col-md-5">
       <label class="form-label">Nombre comercial</label>
       <input type="text" name="nombre_comercial" value="<?=e($emp['nombre_comercial'] ?? '')?>" class="form-control">
      </div>
     </div>
    </div>
   </div>

   <div class="card mb-4">
    <div class="card-header"><span style="color:var(--t)"><i class="bi bi-geo-alt me-1"></i>Dirección</span></div>
    <div class="p-4">
     <div class="row g-3">
      <div class="col-12">
       <label class="form-label">Dirección</label>
       <input type="text" name="direccion" value="<?=e($emp['direccion'] ?? '')?>" class="form-control" placeholder="Av. Principal 123">
      </div>
      <div class="col-12 col-md-3">
       <label class="form-label">Ubigeo</label>
       <input type="text" name="ubigeo" value="<?=e($emp['ubigeo'] ?? '')?>" maxlength="6" class="form-control" placeholder="150101">
      </div>
      <div class="col-12 col-md-3">
       <label class="form-label">Departamento</label>
       <input type="text" name="departamento" value="<?=e($emp['departamento'] ?? '')?>" class="form-control">
      </div>
      <div class="col-12 col-md-3">
       <label class="form-label">Provincia</label>
       <input type="text" name="provincia" value="<?=e($emp['provincia'] ?? '')?>" class="form-control">
      </div>
      <div class="col-12 col-md-3">
       <label class="form-label">Distrito</label>
       <input type="text" name="distrito" value="<?=e($emp['distrito'] ?? '')?>" class="form-control">
      </div>
     </div>
    </div>
   </div>

   <div class="card mb-4">
    <div class="card-header"><span style="color:var(--t)"><i class="bi bi-telephone me-1"></i>Contacto</span></div>
    <div class="p-4">
     <div class="row g-3">
      <div class="col-12 col-md-4">
       <label class="form-label">Teléfono principal</label>
       <input type="text" name="telefono" value="<?=e($emp['telefono'] ?? '')?>" class="form-control">
      </div>
      <div class="col-12 col-md-4">
       <label class="form-label">Teléfono 2</label>
       <input type="text" name="telefono2" value="<?=e($emp['telefono2'] ?? '')?>" class="form-control">
      </div>
      <div class="col-12 col-md-4">
       <label class="form-label">Email</label>
       <input type="email" name="email" value="<?=e($emp['email'] ?? '')?>" class="form-control">
      </div>
      <div class="col-12">
       <label class="form-label">Sitio web</label>
       <input type="url" name="web" value="<?=e($emp['web'] ?? '')?>" class="form-control" placeholder="https://...">
      </div>
     </div>
    </div>
   </div>

   <!-- Configuración SUNAT -->
   <div class="card mb-4">
    <div class="card-header"><span style="color:var(--t)"><i class="bi bi-shield-lock me-1"></i>Configuración SUNAT (firma electrónica)</span></div>
    <div class="p-4">
     <div class="row g-3">
      <div class="col-12 col-md-6">
       <label class="form-label">Usuario SOL</label>
       <input type="text" name="sunat_usuario_sol" value="<?=e($emp['sunat_usuario_sol'] ?? '')?>" class="form-control" maxlength="45" placeholder="Ej: TUUSER01">
       <small style="color:var(--t2);font-size:11px">Para BETA usa <code>MODDATOS</code></small>
      </div>
      <div class="col-12 col-md-6">
       <label class="form-label">Clave SOL</label>
       <input type="password" name="sunat_clave_sol" value="<?=e($emp['sunat_clave_sol'] ?? '')?>" class="form-control" maxlength="45" placeholder="••••••••">
       <small style="color:var(--t2);font-size:11px">Para BETA usa <code>MODDATOS</code></small>
      </div>
     </div>
    </div>
   </div>

   <!-- Certificado .pem (formulario aparte porque sube al API, no a la BD) -->
   <div class="card mb-4">
    <div class="card-header">
     <span style="color:var(--t)"><i class="bi bi-file-earmark-lock me-1"></i>Certificado digital (.pem)</span>
     <?php if (!empty($emp['certificado_subido'])): ?>
      <span class="badge bg" title="Subido el <?=e($emp['certificado_fecha'] ?? '')?>">✅ CARGADO</span>
     <?php else: ?>
      <span class="badge br">❌ SIN CERTIFICADO</span>
     <?php endif; ?>
    </div>
    <div class="p-4">
     <?php if (!empty($emp['certificado_subido'])): ?>
      <div class="alert" style="background:rgba(46,204,142,.08);border:1px solid rgba(46,204,142,.3);color:var(--t);padding:10px 14px;border-radius:6px;font-size:12px;margin-bottom:14px">
       <i class="bi bi-check-circle-fill me-1" style="color:var(--g)"></i>
       Hay un certificado vinculado al RUC <strong><?=e($emp['ruc'] ?? '—')?></strong> en el API SUNAT
       (subido el <?=e($emp['certificado_fecha'] ?? '—')?>). Puedes reemplazarlo subiendo uno nuevo.
      </div>
     <?php endif; ?>
     <small style="color:var(--t2);font-size:11px;display:block;margin-bottom:10px">
      <i class="bi bi-info-circle me-1"></i>El archivo se envía directamente al API Laravel firmador
      (<code>POST /guardar/certificado/<?=e($emp['ruc'] ?? '{RUC}')?></code>) y se guarda allí, no en este servidor.
      <br>Si tu certificado original es <code>.pfx</code>, conviértelo con:
      <code>openssl pkcs12 -in cert.pfx -out cert.pem -nodes</code>
     </small>
     <div class="d-flex gap-2 align-items-center flex-wrap">
      <button type="button" class="btn btn-primary" id="btnSelectPem">
       <i class="bi bi-cloud-upload me-1"></i><?=!empty($emp['certificado_subido']) ? 'Reemplazar certificado' : 'Subir certificado .pem'?>
      </button>
      <small id="pemFileName" style="color:var(--t2);font-size:11px"></small>
     </div>
    </div>
   </div>

   <div class="card mb-4">
    <div class="card-header"><span style="color:var(--t)"><i class="bi bi-file-text me-1"></i>Textos del comprobante</span></div>
    <div class="p-4">
     <div class="mb-3">
      <label class="form-label">Propaganda / Slogan <small style="color:var(--t2)">(aparece en el PDF)</small></label>
      <input type="text" name="propaganda" value="<?=e($emp['propaganda'] ?? '')?>" class="form-control" maxlength="250" placeholder="Ej: Tu sonrisa es nuestra prioridad.">
     </div>
     <div>
      <label class="form-label">Pie de página del PDF</label>
      <textarea name="pie_pagina" class="form-control" rows="3" placeholder="Texto legal o información extra que va al final de cada comprobante."><?=e($emp['pie_pagina'] ?? '')?></textarea>
     </div>
    </div>
   </div>

   <div class="d-flex gap-2 justify-content-end">
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-floppy me-2"></i>Guardar cambios</button>
   </div>
  </div>
 </div>
</form>

<?php if (!empty($emp['logo'])): ?>
<form method="POST" id="frmQuitar" style="display:none">
 <input type="hidden" name="accion" value="quitar_logo">
</form>
<?php endif; ?>

<!-- Form independiente para subir el .pem al API SUNAT -->
<form method="POST" enctype="multipart/form-data" id="frmPem" style="display:none">
 <input type="hidden" name="accion" value="subir_pem">
 <input type="file" name="pem" id="pemFile" accept=".pem">
</form>

<script>
(function () {
 const API = '<?=BASE_URL?>/includes/api_documento.php';

 // Sincroniza el preview del color con el input text
 const colorInp = document.querySelector('input[name="color_primario"]');
 const colorTxt = document.getElementById('colorTxt');
 if (colorInp && colorTxt) colorInp.addEventListener('input', () => colorTxt.value = colorInp.value);

 // Solo dígitos en RUC
 const rucInp = document.getElementById('rucInp');
 rucInp.addEventListener('input', () => rucInp.value = rucInp.value.replace(/\D/g, ''));

 // Preview del logo antes de subirlo
 const logoFile = document.getElementById('logoFile');
 const preview  = document.getElementById('logoPreview');
 logoFile.addEventListener('change', () => {
  preview.innerHTML = '';
  const f = logoFile.files[0];
  if (!f) return;
  if (!/^image\//.test(f.type)) { preview.innerHTML = '<small style="color:#e05252">Archivo inválido.</small>'; return; }
  const r = new FileReader();
  r.onload = e => preview.innerHTML = '<img src="'+e.target.result+'" style="max-width:100%;max-height:120px;background:#fff;padding:6px;border-radius:8px;border:1px solid var(--bd2);margin-top:6px">';
  r.readAsDataURL(f);
 });

 // Buscar por RUC en SUNAT
 const btn   = document.getElementById('btnBuscarRuc');
 const msgEl = document.getElementById('rucMsg');
 const setMsg = (txt, ok) => { msgEl.textContent = txt; msgEl.style.color = ok ? '#2ecc71' : '#e05252'; };

 btn.addEventListener('click', async () => {
  const doc = rucInp.value.trim();
  if (doc.length !== 11) { setMsg('El RUC debe tener 11 dígitos.', false); return; }
  const orig = btn.innerHTML;
  btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Consultando...';
  setMsg('Consultando SUNAT...', true);
  try {
   const r = await fetch(API + '?doc=' + doc, { credentials:'same-origin', headers:{'Accept':'application/json'} });
   const txt = await r.text();
   let j; try { j = JSON.parse(txt); } catch(e){ setMsg('Respuesta inesperada (HTTP '+r.status+').', false); return; }
   if (!r.ok || !j.ok) { setMsg(j.msg || 'No se encontró el RUC.', false); return; }
   document.querySelector('input[name="razon_social"]').value     = j.data.razon_social || '';
   if (j.data.direccion)    document.querySelector('input[name="direccion"]').value    = j.data.direccion;
   if (j.data.distrito)     document.querySelector('input[name="distrito"]').value     = j.data.distrito;
   if (j.data.provincia)    document.querySelector('input[name="provincia"]').value    = j.data.provincia;
   if (j.data.departamento) document.querySelector('input[name="departamento"]').value = j.data.departamento;
   setMsg('✓ ' + j.data.razon_social + (j.data.estado ? ' · ' + j.data.estado : ''), true);
  } catch (err) {
   setMsg('Error de red: ' + err.message, false);
  } finally {
   btn.disabled = false; btn.innerHTML = orig;
  }
 });

 rucInp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); btn.click(); } });

 // Subida de certificado .pem (form independiente)
 const btnPem    = document.getElementById('btnSelectPem');
 const pemFile   = document.getElementById('pemFile');
 const pemName   = document.getElementById('pemFileName');
 const frmPem    = document.getElementById('frmPem');
 if (btnPem && pemFile && frmPem) {
  btnPem.addEventListener('click', () => pemFile.click());
  pemFile.addEventListener('change', () => {
   if (!pemFile.files[0]) return;
   const f = pemFile.files[0];
   if (!/\.pem$/i.test(f.name)) {
    alert('Solo se acepta archivo .pem');
    pemFile.value = '';
    return;
   }
   if (f.size > 512 * 1024) {
    alert('El certificado no debe superar 512 KB.');
    pemFile.value = '';
    return;
   }
   pemName.textContent = '📄 ' + f.name + ' (' + Math.round(f.size/1024) + ' KB)';
   if (confirm('¿Subir el certificado "' + f.name + '" al API SUNAT para el RUC actual?\n\nReemplazará cualquier certificado anterior.')) {
    frmPem.submit();
   } else {
    pemFile.value = '';
    pemName.textContent = '';
   }
  });
 }
})();
</script>

<?php require_once __DIR__.'/../../includes/footer.php'; ?>
