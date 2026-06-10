<?php
/**
 * CEFALOMETRÍA — Módulo completo
 * Análisis cefalométrico de Steiner con motor matemático integrado
 * Tablas: cefalometria_estudios, cefalometria_imagenes, cefalometria_puntos,
 *         cefalometria_resultados, cefalometria_diagnostico
 */
require_once __DIR__ . '/../includes/config.php';
requiereLogin();
requiereModulo('cefalometria');

$accion = $_GET['accion'] ?? 'lista';
$id     = (int)($_GET['id'] ?? 0);
$pac_id = (int)($_GET['paciente_id'] ?? 0);

// ── POST handlers ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ap = $_POST['accion'] ?? '';

    // Crear estudio
    if ($ap === 'crear_estudio') {
        $pid   = (int)($_POST['paciente_id'] ?? 0);
        $hcid  = (int)($_POST['hc_id'] ?? 0) ?: null;
        $fecha = $_POST['fecha'] ?: date('Y-m-d');
        $titulo= trim($_POST['titulo'] ?? 'Análisis Cefalométrico');
        $notas = trim($_POST['notas'] ?? '');
        db()->prepare("INSERT INTO cefalometria_estudios(paciente_id,hc_id,titulo,fecha,doctor_id,notas) VALUES(?,?,?,?,?,?)")
           ->execute([$pid,$hcid,$titulo,$fecha,$_SESSION['uid'],$notas]);
        $eid = db()->lastInsertId();
        auditar('CREAR_CEFALO','cefalometria_estudios',$eid);
        // Upload imagen principal
        if (!empty($_FILES['imagen']['name'])) {
            $ruta = subirArchivo($_FILES['imagen'],'cefalometria',['jpg','jpeg','png','webp']);
            if ($ruta) {
                db()->prepare("INSERT INTO cefalometria_imagenes(estudio_id,paciente_id,tipo,archivo,principal,fecha) VALUES(?,?,?,?,1,?)")
                   ->execute([$eid,$pid,$_POST['tipo_imagen']??'teleradiografia',$ruta,$fecha]);
            }
        }
        flash('ok','Estudio creado. Ahora sube la radiografía y marca los puntos.');
        go("pages/cefalometria.php?accion=editor&id=$eid");
    }

    // Guardar puntos (AJAX o form)
    if ($ap === 'guardar_puntos') {
        $eid    = (int)($_POST['estudio_id'] ?? 0);
        $puntos = json_decode($_POST['puntos_json'] ?? '[]', true);
        if ($eid && is_array($puntos)) {
            foreach ($puntos as $pt) {
                $nombre = strtoupper(trim($pt['nombre'] ?? ''));
                $x      = (float)($pt['x'] ?? 0);
                $y      = (float)($pt['y'] ?? 0);
                if (!$nombre) continue;
                db()->prepare("INSERT INTO cefalometria_puntos(estudio_id,imagen_id,punto,x,y)
                               VALUES(?,?,?,?,?)
                               ON DUPLICATE KEY UPDATE x=VALUES(x),y=VALUES(y),imagen_id=VALUES(imagen_id)")
                   ->execute([$eid,(int)($pt['imagen_id']??0),$nombre,$x,$y]);
            }
            // Recalculate after saving points
            recalcularMedidas($eid);
            header('Content-Type: application/json');
            echo json_encode(['ok'=>true]);
            exit;
        }
    }

    // Guardar diagnóstico
    if ($ap === 'guardar_diagnostico') {
        $eid = (int)($_POST['estudio_id'] ?? 0);
        $d   = [
            'clase_esqueletal'    => $_POST['clase_esqueletal']    ?? 'Indeterminado',
            'patron_vertical'     => $_POST['patron_vertical']     ?? 'Indeterminado',
            'perfil_facial'       => $_POST['perfil_facial']       ?? 'Indeterminado',
            'posicion_maxilar'    => $_POST['posicion_maxilar']    ?? 'Indeterminado',
            'posicion_mandibular' => $_POST['posicion_mandibular'] ?? 'Indeterminado',
            'inclinacion_inc_sup' => $_POST['inclinacion_inc_sup'] ?? 'Indeterminado',
            'inclinacion_inc_inf' => $_POST['inclinacion_inc_inf'] ?? 'Indeterminado',
            'resumen'             => trim($_POST['resumen']        ?? ''),
            'plan_tratamiento'    => trim($_POST['plan_tratamiento']?? ''),
        ];
        db()->prepare("INSERT INTO cefalometria_diagnostico(estudio_id,clase_esqueletal,patron_vertical,perfil_facial,posicion_maxilar,posicion_mandibular,inclinacion_inc_sup,inclinacion_inc_inf,resumen,plan_tratamiento)
                       VALUES(?,?,?,?,?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE clase_esqueletal=VALUES(clase_esqueletal),patron_vertical=VALUES(patron_vertical),perfil_facial=VALUES(perfil_facial),posicion_maxilar=VALUES(posicion_maxilar),posicion_mandibular=VALUES(posicion_mandibular),inclinacion_inc_sup=VALUES(inclinacion_inc_sup),inclinacion_inc_inf=VALUES(inclinacion_inc_inf),resumen=VALUES(resumen),plan_tratamiento=VALUES(plan_tratamiento),updated_at=NOW()")
           ->execute([$eid,$d['clase_esqueletal'],$d['patron_vertical'],$d['perfil_facial'],$d['posicion_maxilar'],$d['posicion_mandibular'],$d['inclinacion_inc_sup'],$d['inclinacion_inc_inf'],$d['resumen'],$d['plan_tratamiento']]);
        db()->prepare("UPDATE cefalometria_estudios SET estado='completado' WHERE id=?")->execute([$eid]);
        auditar('DIAGNOSTICO_CEFALO','cefalometria_estudios',$eid);
        flash('ok','Diagnóstico guardado correctamente.');
        go("pages/cefalometria.php?accion=ver&id=$eid");
    }

    // Eliminar estudio
    if ($ap === 'eliminar') {
        $eid = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM cefalometria_puntos WHERE estudio_id=?")->execute([$eid]);
        db()->prepare("DELETE FROM cefalometria_resultados WHERE estudio_id=?")->execute([$eid]);
        db()->prepare("DELETE FROM cefalometria_diagnostico WHERE estudio_id=?")->execute([$eid]);
        db()->prepare("DELETE FROM cefalometria_imagenes WHERE estudio_id=?")->execute([$eid]);
        db()->prepare("DELETE FROM cefalometria_estudios WHERE id=?")->execute([$eid]);
        auditar('ELIMINAR_CEFALO','cefalometria_estudios',$eid);
        flash('ok','Estudio eliminado.');
        go('pages/cefalometria.php');
    }

    // Upload imagen adicional
    if ($ap === 'subir_imagen') {
        $eid = (int)($_POST['estudio_id'] ?? 0);
        $pid = (int)($_POST['paciente_id'] ?? 0);
        if (!empty($_FILES['imagen']['name'])) {
            $ruta = subirArchivo($_FILES['imagen'],'cefalometria',['jpg','jpeg','png','webp']);
            if ($ruta) {
                $tipo = $_POST['tipo_imagen'] ?? 'teleradiografia';
                $prin = isset($_POST['principal']) ? 1 : 0;
                if ($prin) db()->prepare("UPDATE cefalometria_imagenes SET principal=0 WHERE estudio_id=?")->execute([$eid]);
                db()->prepare("INSERT INTO cefalometria_imagenes(estudio_id,paciente_id,tipo,archivo,principal,fecha) VALUES(?,?,?,?,?,?)")
                   ->execute([$eid,$pid,$tipo,$ruta,$prin,date('Y-m-d')]);
                flash('ok','Imagen subida correctamente.');
            }
        }
        go("pages/cefalometria.php?accion=editor&id=$eid");
    }
}

// ── Motor matemático ───────────────────────────────────────────────────────
function recalcularMedidas(int $eid): void {
    // Load points
    $st = db()->prepare("SELECT punto,x,y FROM cefalometria_puntos WHERE estudio_id=?");
    $st->execute([$eid]);
    $raw = $st->fetchAll();
    $pts = [];
    foreach ($raw as $r) $pts[$r['punto']] = ['x'=>(float)$r['x'],'y'=>(float)$r['y']];

    $medidas = calcularMedidasCefalometricas($pts);
    db()->prepare("DELETE FROM cefalometria_resultados WHERE estudio_id=?")->execute([$eid]);
    $ins = db()->prepare("INSERT INTO cefalometria_resultados(estudio_id,medida,valor,normal_min,normal_max,interpretacion) VALUES(?,?,?,?,?,?)");
    foreach ($medidas as $nombre => $m) {
        $interp = 'normal';
        if ($m['valor'] !== null) {
            if ($m['valor'] < $m['min']) $interp = 'disminuido';
            elseif ($m['valor'] > $m['max']) $interp = 'aumentado';
        }
        $ins->execute([$eid,$nombre,$m['valor'],$m['min'],$m['max'],$interp]);
    }
    // Auto-diagnóstico
    generarDiagnosticoAuto($eid,$medidas);
}

function distPts(array $A, array $B): float {
    return sqrt(($B['x']-$A['x'])**2 + ($B['y']-$A['y'])**2);
}

function anglePts(array $A, array $V, array $B): ?float {
    // Angle at vertex V between rays V->A and V->B
    $ax=$A['x']-$V['x']; $ay=$A['y']-$V['y'];
    $bx=$B['x']-$V['x']; $by=$B['y']-$V['y'];
    $dot = $ax*$bx + $ay*$by;
    $ma  = sqrt($ax**2+$ay**2);
    $mb  = sqrt($bx**2+$by**2);
    if ($ma==0||$mb==0) return null;
    $cos = max(-1.0, min(1.0, $dot/($ma*$mb)));
    return round(acos($cos)*(180/M_PI),1);
}

function calcularMedidasCefalometricas(array $p): array {
    $r = [];
    $def = function(string $n, ?float $v, float $min, float $max) use (&$r) {
        $r[$n] = ['valor'=>$v!==null?round($v,1):null,'min'=>$min,'max'=>$max];
    };

    // ── Análisis esqueletal (Steiner) ──────────────────────────────────
    // SNA: S-N-A (posición maxilar respecto a base craneal)
    $def('SNA', isset($p['S'],$p['N'],$p['A']) ? anglePts($p['S'],$p['N'],$p['A']) : null, 80, 84);
    // SNB: S-N-B (posición mandibular)
    $def('SNB', isset($p['S'],$p['N'],$p['B']) ? anglePts($p['S'],$p['N'],$p['B']) : null, 78, 82);
    // ANB: diferencia SNA-SNB (relación maxilo-mandibular)
    if (isset($r['SNA']['valor'],$r['SNB']['valor']) && $r['SNA']['valor']!==null && $r['SNB']['valor']!==null)
        $def('ANB', $r['SNA']['valor'] - $r['SNB']['valor'], 0, 4);
    else $def('ANB', null, 0, 4);
    // Wits appraisal: distancia AO-BO sobre plano oclusal
    $def('Wits', null, -1, 1); // requiere punto O (plano oclusal)

    // ── Patrón vertical ────────────────────────────────────────────────
    // FMA (Frankfort Mandibular Angle): plano de Frankfort / plano mandibular
    $def('FMA', isset($p['Po'],$p['Or'],$p['Go'],$p['Me']) ?
        anglePts($p['Po'],$p['Or'],$p['Me']) : null, 22, 28);
    // Plano mandibular SN-GoGn
    $def('SN-GoGn', isset($p['S'],$p['N'],$p['Go'],$p['Gn']) ?
        anglePts($p['S'],$p['N'],$p['Gn']) : null, 28, 36);
    // Eje facial (Ba-Na / Pt-Gn) — Ricketts
    $def('Eje_facial', null, 87, 95);
    // Altura facial anterior total (N-Me)
    $def('N-Me', isset($p['N'],$p['Me']) ? distPts($p['N'],$p['Me']) : null, 105, 125);
    // Altura facial posterior (S-Go)
    $def('S-Go', isset($p['S'],$p['Go']) ? distPts($p['S'],$p['Go']) : null, 70, 85);
    // Índice facial (S-Go/N-Me * 100)
    if ($r['S-Go']['valor']!==null && $r['N-Me']['valor']!==null && $r['N-Me']['valor']>0)
        $def('Indice_facial', round($r['S-Go']['valor']/$r['N-Me']['valor']*100,1), 62, 65);
    else $def('Indice_facial', null, 62, 65);

    // ── Posición dental ────────────────────────────────────────────────
    // IMPA: Inclinación del incisivo inferior (plano mandibular)
    $def('IMPA', isset($p['Ii'],$p['Ia'],$p['Go'],$p['Me']) ?
        anglePts($p['Ii'],$p['Ia'],$p['Me']) : null, 87, 93);
    // I-NA: Inclinación incisivo superior / NA
    $def('I-NA_ang', isset($p['Is'],$p['Ia'],$p['N'],$p['A']) ?
        anglePts($p['Is'],$p['N'],$p['A']) : null, 20, 28);
    // I-NB: Inclinación incisivo inferior / NB
    $def('I-NB_ang', isset($p['Ii'],$p['N'],$p['B']) ?
        anglePts($p['Ii'],$p['N'],$p['B']) : null, 25, 30);
    // Interincisivo (ángulo entre incisivos)
    $def('Interincisivo', null, 125, 135);
    // Distancia I-NA (mm)
    $def('I-NA_mm', isset($p['Is'],$p['N'],$p['A']) ?
        round(distPts($p['Is'],$p['N'])*0.35,1) : null, 2, 4);
    // Distancia I-NB (mm)
    $def('I-NB_mm', isset($p['Ii'],$p['N'],$p['B']) ?
        round(distPts($p['Ii'],$p['N'])*0.35,1) : null, 2, 4);

    // ── Tejidos blandos ────────────────────────────────────────────────
    // Ángulo Z de Merrifield (Frankfort / línea de perfil)
    $def('Angulo_Z', null, 75, 85);
    // Labio superior a línea E
    $def('Ls-E', null, -4, 0);
    // Labio inferior a línea E
    $def('Li-E', null, -2, 2);

    // ── Relaciones ────────────────────────────────────────────────────
    // Longitud base craneal anterior (S-N)
    $def('S-N', isset($p['S'],$p['N']) ? distPts($p['S'],$p['N']) : null, 65, 75);
    // Co-A (longitud maxilar - McNamara)
    $def('Co-A', isset($p['Co'],$p['A']) ? distPts($p['Co'],$p['A']) : null, 85, 95);
    // Co-Gn (longitud mandibular - McNamara)
    $def('Co-Gn', isset($p['Co'],$p['Gn']) ? distPts($p['Co'],$p['Gn']) : null, 120, 130);
    // Overjet
    $def('Overjet', null, 1, 3);
    // Overbite
    $def('Overbite', null, 1, 3);

    return $r;
}

function generarDiagnosticoAuto(int $eid, array $medidas): void {
    $v = function(string $k) use ($medidas): ?float {
        return $medidas[$k]['valor'] ?? null;
    };

    // Clase esqueletal por ANB
    $anb = $v('ANB');
    $clase = 'Indeterminado';
    if ($anb !== null) {
        if ($anb >= 0 && $anb <= 4)     $clase = 'Clase I';
        elseif ($anb > 4)               $clase = 'Clase II';
        elseif ($anb < 0)               $clase = 'Clase III';
    }

    // Patrón vertical por FMA
    $fma = $v('FMA');
    $patron = 'Indeterminado';
    if ($fma !== null) {
        if ($fma >= 22 && $fma <= 28)   $patron = 'Normal';
        elseif ($fma > 28)              $patron = 'Hiperdivergente';
        elseif ($fma < 22)              $patron = 'Hipodivergente';
    }

    // Posición maxilar por SNA
    $sna = $v('SNA');
    $maxilar = 'Indeterminado';
    if ($sna !== null) {
        if ($sna >= 80 && $sna <= 84)   $maxilar = 'Normal';
        elseif ($sna > 84)              $maxilar = 'Prognatismo';
        elseif ($sna < 80)              $maxilar = 'Retrognatismo';
    }

    // Posición mandibular por SNB
    $snb = $v('SNB');
    $mandib = 'Indeterminado';
    if ($snb !== null) {
        if ($snb >= 78 && $snb <= 82)   $mandib = 'Normal';
        elseif ($snb > 82)              $mandib = 'Prognatismo';
        elseif ($snb < 78)              $mandib = 'Retrognatismo';
    }

    // Incisivos
    $ina  = $v('I-NA_ang');
    $inb  = $v('I-NB_ang');
    $inc_sup = 'Indeterminado';
    $inc_inf = 'Indeterminado';
    if ($ina!==null) {
        if ($ina>=20&&$ina<=28)   $inc_sup='Normal';
        elseif ($ina>28)          $inc_sup='Proinclinado';
        else                      $inc_sup='Retroinclinado';
    }
    if ($inb!==null) {
        if ($inb>=25&&$inb<=30)   $inc_inf='Normal';
        elseif ($inb>30)          $inc_inf='Proinclinado';
        else                      $inc_inf='Retroinclinado';
    }

    // Perfil (basado en ANB y tejidos blandos)
    $perfil = 'Indeterminado';
    if ($anb!==null) {
        if ($anb>=0&&$anb<=4)    $perfil='Recto';
        elseif ($anb>4)          $perfil='Convexo';
        elseif ($anb<0)          $perfil='Cóncavo';
    }

    // Resumen automático
    $resumen = implode('. ', array_filter([
        $anb!==null  ? "ANB=$anb° → $clase"         : null,
        $fma!==null  ? "FMA=$fma° → Patrón $patron"  : null,
        $sna!==null  ? "SNA=$sna° → Maxilar $maxilar" : null,
        $snb!==null  ? "SNB=$snb° → Mandíbula $mandib": null,
        $ina!==null  ? "Inc.sup $inc_sup"             : null,
        $inb!==null  ? "Inc.inf $inc_inf"             : null,
    ]));

    db()->prepare("INSERT INTO cefalometria_diagnostico(estudio_id,clase_esqueletal,patron_vertical,perfil_facial,posicion_maxilar,posicion_mandibular,inclinacion_inc_sup,inclinacion_inc_inf,resumen)
                   VALUES(?,?,?,?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE clase_esqueletal=VALUES(clase_esqueletal),patron_vertical=VALUES(patron_vertical),perfil_facial=VALUES(perfil_facial),posicion_maxilar=VALUES(posicion_maxilar),posicion_mandibular=VALUES(posicion_mandibular),inclinacion_inc_sup=VALUES(inclinacion_inc_sup),inclinacion_inc_inf=VALUES(inclinacion_inc_inf),resumen=VALUES(resumen),updated_at=NOW()")
       ->execute([$eid,$clase,$patron,$perfil,$maxilar,$mandib,$inc_sup,$inc_inf,$resumen]);
}

// ══════════════════════════════════════════════════════════════════════════════
// VISTA: LISTA
// ══════════════════════════════════════════════════════════════════════════════
// Descarga imagen del estudio con puntos marcados
if ($accion === 'descargar_imagen' && $id) {
    $estudio2 = db()->prepare('SELECT ce.*,CONCAT(p.nombres,\' \',p.apellido_paterno) AS pac FROM cefalometria_estudios ce JOIN pacientes p ON ce.paciente_id=p.id WHERE ce.id=?');
    $estudio2->execute([$id]); $estudio2=$estudio2->fetch();
    if(!$estudio2){ flash('error','Estudio no encontrado'); go('pages/cefalometria.php'); }
    $img2 = db()->prepare('SELECT * FROM cefalometria_imagenes WHERE estudio_id=? AND principal=1 LIMIT 1');
    $img2->execute([$id]); $img2=$img2->fetch();
    $puntos2 = db()->prepare('SELECT punto,x,y FROM cefalometria_puntos WHERE estudio_id=?');
    $puntos2->execute([$id]); $puntos2=$puntos2->fetchAll();
    $pts_js = json_encode(array_combine(array_column($puntos2,'punto'), array_map(fn($p)=>['x'=>(float)$p['x'],'y'=>(float)$p['y']],$puntos2)));
    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html><html><head><meta charset='UTF-8'>
<title>Cefalometria - <?=e($estudio2['pac'])?></title>
<style>body{margin:0;background:#111A26;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;font-family:Arial,sans-serif}
.info{color:#00D4EE;padding:10px 16px;font-size:13px;font-weight:700}
.btns{display:flex;gap:10px;margin:10px}
.btn{padding:8px 20px;border-radius:6px;cursor:pointer;font-weight:700;border:none;font-size:13px}
.btn-dl{background:#00D4EE;color:#050E18}
.btn-back{background:#1E2D40;color:#A0B0C0;border:1px solid #334155}
canvas{max-width:98vw;border-radius:8px}
</style></head><body>
<div class='info'>&#129462; Cefalometr&#237;a &mdash; <?=e($estudio2['pac'])?> &mdash; <?=fDate($estudio2['fecha'])?></div>
<div class='btns'>
  <button class='btn btn-dl' onclick='doDownload()'>&#11015; Descargar PNG</button>
  <button class='btn btn-back' onclick='history.back()'>&#8592; Volver</button>
</div>
<canvas id='c'></canvas>
<script>
const IMG_SRC = <?=json_encode(BASE_URL.'/uploads/'.($img2?$img2['archivo']:''))?> ;
const POINTS  = <?=$pts_js?> ;
const PAC     = <?=json_encode($estudio2['pac']??'')?> ;
const FECHA   = <?=json_encode(fDate($estudio2['fecha']??''))?> ;
const COLORS  = {S:'#00D4EE',N:'#00D4EE',Ba:'#00D4EE',Ar:'#00D4EE',A:'#f59e0b',ENA:'#f59e0b',ENP:'#f59e0b',Or:'#f59e0b',Po:'#f59e0b',B:'#ef4444',Pog:'#ef4444',Gn:'#ef4444',Me:'#ef4444',Go:'#ef4444',Co:'#ef4444',Is:'#10b981',Ia:'#10b981',Ii:'#10b981',IsA:'#10b981',Ls:'#ec4899',Li:'#ec4899'};
const LINES   = [{pts:['S','N'],c:'rgba(0,212,238,.6)'},{pts:['N','A'],c:'rgba(245,158,11,.6)'},{pts:['N','B'],c:'rgba(239,68,68,.6)'},{pts:['Go','Gn'],c:'rgba(239,68,68,.5)'},{pts:['N','Pog'],c:'rgba(139,92,246,.5)'},{pts:['S','Go'],c:'rgba(100,150,200,.4)'},{pts:['N','Me'],c:'rgba(100,200,100,.4)'}];
const canvas = document.getElementById('c');
const ctx    = canvas.getContext('2d');
const img    = new Image();
img.crossOrigin = 'anonymous';
img.onload = function(){
  canvas.width  = Math.min(img.naturalWidth, window.innerWidth*0.95);
  canvas.height = Math.round(canvas.width * img.naturalHeight / img.naturalWidth);
  const scale   = canvas.width / img.naturalWidth;
  // Background
  ctx.fillStyle='#111A26'; ctx.fillRect(0,0,canvas.width,canvas.height);
  // Image
  ctx.drawImage(img,0,0,canvas.width,canvas.height);
  // Lines
  LINES.forEach(function(l){
    var A=POINTS[l.pts[0]],B=POINTS[l.pts[1]];
    if(!A||!B) return;
    ctx.beginPath(); ctx.moveTo(A.x*scale,A.y*scale); ctx.lineTo(B.x*scale,B.y*scale);
    ctx.strokeStyle=l.c; ctx.lineWidth=1.5; ctx.setLineDash([4,3]); ctx.stroke(); ctx.setLineDash([]);
  });
  // Points
  Object.entries(POINTS).forEach(function([name,pt]){
    var color = COLORS[name]||'#ffffff';
    var x=pt.x*scale, y=pt.y*scale, r=6;
    ctx.beginPath(); ctx.arc(x,y,r,0,Math.PI*2); ctx.strokeStyle=color; ctx.lineWidth=1.5; ctx.stroke();
    ctx.beginPath(); ctx.arc(x,y,r*0.4,0,Math.PI*2); ctx.fillStyle=color; ctx.fill();
    ctx.font='bold 9px monospace'; ctx.fillStyle='#fff'; ctx.fillText(name,x+r+2,y-r);
  });
  // Watermark
  ctx.fillStyle='rgba(0,212,238,0.8)'; ctx.font='bold 12px Arial';
  ctx.fillText('Cefalometria | '+PAC+' | '+FECHA, 10, 20);
  ctx.fillStyle='rgba(160,176,192,0.6)'; ctx.font='10px monospace';
  var npts=Object.keys(POINTS).length;
  ctx.fillText(npts+' puntos | DentalSys-Magus', 10, canvas.height-8);
};
img.src = IMG_SRC;
function doDownload(){
  var link=document.createElement('a');
  var safe=(PAC||'paciente').replace(/[^a-zA-Z0-9]/g,'_').toLowerCase();
  link.download='cefalometria_'+safe+'_'+(FECHA||'').replace(/\//g,'-')+'.png';
  link.href=canvas.toDataURL('image/png',1.0);
  link.click();
}
// Auto-download after 800ms
setTimeout(doDownload, 800);
</script></body></html>
<?php exit;
}

if ($accion === 'lista') {
    $q   = trim($_GET['q'] ?? '');
    $w   = ''; $pm = [];
    if ($q) { $w = "AND (CONCAT(p.nombres,' ',p.apellido_paterno) LIKE ? OR p.codigo LIKE ?)"; $b="%$q%"; $pm=[$b,$b]; }

    $estudios = db()->prepare("
        SELECT ce.*, CONCAT(p.nombres,' ',p.apellido_paterno) AS pac,
               p.codigo AS cod_pac, CONCAT(u.nombre,' ',u.apellidos) AS doctor,
               ci.archivo AS img_principal,
               (SELECT COUNT(*) FROM cefalometria_puntos WHERE estudio_id=ce.id) AS n_puntos
        FROM cefalometria_estudios ce
        JOIN pacientes p ON ce.paciente_id=p.id
        LEFT JOIN usuarios u ON ce.doctor_id=u.id
        LEFT JOIN cefalometria_imagenes ci ON ci.estudio_id=ce.id AND ci.principal=1
        WHERE p.activo=1 $w
        ORDER BY ce.fecha DESC, ce.id DESC LIMIT 80
    ");
    $estudios->execute($pm);
    $estudios = $estudios->fetchAll();

    $titulo = 'Cefalometría'; $pagina_activa = 'cefalo';
    $topbar_act = '<a href="?accion=nuevo" class="btn btn-primary"><i class="bi bi-plus me-1"></i>Nuevo estudio</a>';
    require_once __DIR__ . '/../includes/header.php';
?>
<style>
.cefalo-card{background:var(--bg2);border:1px solid var(--bd2);border-radius:10px;padding:0;overflow:hidden;transition:border-color .15s}
.cefalo-card:hover{border-color:rgba(0,212,238,.35)}
.cefalo-thumb{width:80px;height:80px;object-fit:cover;display:block}
.cefalo-thumb-ph{width:80px;height:80px;background:var(--bg3);display:flex;align-items:center;justify-content:center;font-size:28px;flex-shrink:0}
.estado-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700}
.estado-completado{background:rgba(16,185,129,.15);color:#10b981;border:1px solid rgba(16,185,129,.3)}
.estado-borrador{background:rgba(245,158,11,.15);color:#f59e0b;border:1px solid rgba(245,158,11,.3)}
</style>

<div class="pb">
  <?=popFlash()?>

  <!-- Search -->
  <div class="card mb-3 p-3">
    <form method="GET" class="d-flex gap-2 flex-wrap align-items-end">
      <div class="flex-fill" style="min-width:200px">
        <input type="text" name="q" class="form-control" placeholder="Buscar por paciente o código..." value="<?=e($q)?>">
      </div>
      <button class="btn btn-dk">&#128269;</button>
      <?php if($q): ?><a href="?" class="btn btn-dk">&#10005;</a><?php endif; ?>
      <small class="ms-auto" style="color:var(--t2);align-self:center"><?=count($estudios)?> estudio(s)</small>
    </form>
  </div>

  <?php if (!$estudios): ?>
  <div class="card p-5 text-center" style="color:var(--t2)">
    <i class="bi bi-rulers" style="font-size:40px;display:block;margin-bottom:12px"></i>
    No hay estudios cefalométricos aún.<br>
    <a href="?accion=nuevo" class="btn btn-primary mt-3"><i class="bi bi-plus me-1"></i>Crear primer estudio</a>
  </div>
  <?php else: ?>
  <div class="row g-2">
  <?php foreach($estudios as $e): ?>
  <div class="col-12 col-md-6 col-xl-4">
    <div class="cefalo-card">
      <div class="d-flex">
        <!-- Thumbnail -->
        <div class="flex-shrink-0">
          <?php if($e['img_principal']): ?>
          <img src="<?=BASE_URL?>/uploads/<?=e($e['img_principal'])?>"
               class="cefalo-thumb" alt="Rx">
          <?php else: ?>
          <div class="cefalo-thumb-ph">&#129462;</div>
          <?php endif; ?>
        </div>
        <!-- Info -->
        <div class="p-2 flex-fill" style="min-width:0">
          <div class="d-flex align-items-start justify-content-between gap-1 mb-1">
            <div>
              <div style="font-weight:700;font-size:13px;color:var(--t)"><?=e($e['pac'])?></div>
              <div style="font-size:11px;color:var(--t2)"><?=e($e['cod_pac'])?> &bull; Dr. <?=e($e['doctor']??'—')?></div>
            </div>
            <span class="estado-badge estado-<?=$e['estado']?>"><?=$e['estado']==='completado'?'✓ Listo':'✎ Borrador'?></span>
          </div>
          <div style="font-size:11px;color:var(--t2);margin-bottom:6px">
            <?=fDate($e['fecha'])?> &bull;
            <span style="color:<?=$e['n_puntos']>=5?'#10b981':'#f59e0b'?>"><?=$e['n_puntos']?> puntos</span>
          </div>
          <div class="d-flex gap-1 flex-wrap">
            <a href="?accion=editor&id=<?=$e['id']?>" class="btn btn-primary btn-sm" style="font-size:10px">
              <i class="bi bi-pencil-square me-1"></i>Editor
            </a>
            <a href="?accion=ver&id=<?=$e['id']?>" class="btn btn-dk btn-sm" style="font-size:10px">
              <i class="bi bi-eye me-1"></i>Ver
            </a>
            <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este estudio?')">
              <input type="hidden" name="accion" value="eliminar">
              <input type="hidden" name="id" value="<?=$e['id']?>">
              <button class="btn btn-del btn-ico btn-sm"><i class="bi bi-trash"></i></button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php
    require_once __DIR__ . '/../includes/footer.php';

// ══════════════════════════════════════════════════════════════════════════════
// VISTA: NUEVO ESTUDIO
// ══════════════════════════════════════════════════════════════════════════════
} elseif ($accion === 'nuevo') {
    $titulo = 'Nuevo Estudio Cefalométrico'; $pagina_activa = 'cefalo';
    $topbar_act = '<a href="?" class="btn btn-dk btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver</a>';
    // Load patients
    $pacs = db()->query("SELECT id,codigo,nombres,apellido_paterno FROM pacientes WHERE activo=1 ORDER BY apellido_paterno,nombres LIMIT 500")->fetchAll();
    require_once __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center"><div class="col-12 col-lg-7">
<form method="POST" enctype="multipart/form-data">
  <input type="hidden" name="accion" value="crear_estudio">
  <div class="card mb-4">
    <div class="card-header"><span style="color:var(--t)">&#129462; Nuevo Estudio Cefalométrico</span></div>
    <div class="p-4"><div class="row g-3">

      <div class="col-12">
        <label class="form-label">Paciente *</label>
        <select name="paciente_id" class="form-select" required>
          <option value="">— Seleccionar paciente —</option>
          <?php foreach($pacs as $p): ?>
          <option value="<?=$p['id']?>" <?=($pac_id==$p['id'])?'selected':''?>><?=e($p['codigo'].' - '.$p['nombres'].' '.$p['apellido_paterno'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-8">
        <label class="form-label">Título del estudio</label>
        <input type="text" name="titulo" class="form-control" value="Análisis Cefalométrico" placeholder="Ej: Análisis Steiner Pre-tratamiento">
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Fecha *</label>
        <input type="date" name="fecha" class="form-control" value="<?=date('Y-m-d')?>" required>
      </div>

      <div class="col-12">
        <hr style="border-color:var(--bd2);margin:4px 0">
        <label class="form-label">Telerradiografía lateral *</label>
        <input type="file" name="imagen" class="form-control" accept="image/*" required>
        <small style="color:var(--t2)">JPG, PNG o WebP. Será la imagen principal para marcar puntos.</small>
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label">Tipo de imagen</label>
        <select name="tipo_imagen" class="form-select">
          <option value="teleradiografia">Telerradiografía lateral</option>
          <option value="opg">OPG / Panorámica</option>
          <option value="foto_perfil">Fotografía de perfil</option>
          <option value="foto_frontal">Fotografía frontal</option>
        </select>
      </div>

      <div class="col-12">
        <label class="form-label">Observaciones iniciales</label>
        <textarea name="notas" class="form-control" rows="2" placeholder="Motivo del análisis, notas previas..."></textarea>
      </div>

    </div></div>
  </div>
  <div class="d-flex gap-2 justify-content-end">
    <a href="?" class="btn btn-dk">Cancelar</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-arrow-right me-2"></i>Crear y marcar puntos</button>
  </div>
</form>
</div></div>
<?php
    require_once __DIR__ . '/../includes/footer.php';

// ══════════════════════════════════════════════════════════════════════════════
// VISTA: EDITOR (marcar puntos + ver resultados)
// ══════════════════════════════════════════════════════════════════════════════
} elseif ($accion === 'editor' && $id) {
    $estudio = db()->prepare("SELECT ce.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac,p.codigo AS cod_pac
                               FROM cefalometria_estudios ce JOIN pacientes p ON ce.paciente_id=p.id WHERE ce.id=?");
    $estudio->execute([$id]); $estudio = $estudio->fetch();
    if (!$estudio) { flash('error','Estudio no encontrado'); go('pages/cefalometria.php'); }

    $imagenes = db()->prepare("SELECT * FROM cefalometria_imagenes WHERE estudio_id=? ORDER BY principal DESC");
    $imagenes->execute([$id]); $imagenes = $imagenes->fetchAll();

    $puntos_db = db()->prepare("SELECT punto,x,y FROM cefalometria_puntos WHERE estudio_id=?");
    $puntos_db->execute([$id]); $puntos_raw = $puntos_db->fetchAll();
    $puntos_js = [];
    foreach ($puntos_raw as $pt) $puntos_js[$pt['punto']] = ['x'=>(float)$pt['x'],'y'=>(float)$pt['y']];

    $resultados = db()->prepare("SELECT * FROM cefalometria_resultados WHERE estudio_id=? ORDER BY medida");
    $resultados->execute([$id]); $resultados = $resultados->fetchAll();

    $diagnostico = db()->prepare("SELECT * FROM cefalometria_diagnostico WHERE estudio_id=?");
    $diagnostico->execute([$id]); $diagnostico = $diagnostico->fetch();

    $img_principal = null;
    foreach ($imagenes as $img) { if ($img['principal']) { $img_principal = $img; break; } }
    if (!$img_principal && $imagenes) $img_principal = $imagenes[0];

    $titulo = 'Editor — '.$estudio['pac']; $pagina_activa = 'cefalo';
    $topbar_act = '<a href="?" class="btn btn-dk btn-sm"><i class="bi bi-arrow-left me-1"></i>Lista</a>
    <a href="?accion=ver&id='.$id.'" class="btn btn-dk btn-sm"><i class="bi bi-eye me-1"></i>Ver resultados</a>';
    $xhead = '<style>
#cefaloCanvas{cursor:crosshair;display:block;max-width:100%}
.cefalo-editor-wrap{position:relative;overflow:hidden;background:#0A1220;border-radius:8px;border:1px solid var(--bd2)}
.pt-panel{background:var(--bg2);border:1px solid var(--bd2);border-radius:8px;height:100%;overflow-y:auto}
.pt-btn{display:flex;align-items:center;gap:6px;padding:5px 8px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;transition:background .12s;border:1px solid transparent;width:100%;text-align:left;background:transparent;color:var(--t2)}
.pt-btn:hover{background:var(--bg3);color:var(--t)}
.pt-btn.active{background:rgba(0,212,238,.15);border-color:rgba(0,212,238,.4);color:var(--c)}
.pt-btn.done{color:#10b981}
.pt-btn.done .pt-dot{background:#10b981}
.pt-dot{width:10px;height:10px;border-radius:50%;background:var(--bd2);flex-shrink:0}
.result-row{display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:12px}
.result-normal{color:#10b981}.result-aumentado{color:#f59e0b}.result-disminuido{color:#3b82f6}
.diag-chip{display:inline-flex;align-items:center;padding:4px 12px;border-radius:14px;font-size:11px;font-weight:700}
.diag-I{background:rgba(16,185,129,.15);color:#10b981;border:1px solid rgba(16,185,129,.3)}
.diag-II{background:rgba(245,158,11,.15);color:#f59e0b;border:1px solid rgba(245,158,11,.3)}
.diag-III{background:rgba(239,68,68,.15);color:#ef4444;border:1px solid rgba(239,68,68,.3)}
.diag-N{background:var(--bg3);color:var(--t2);border:1px solid var(--bd2)}
.zoom-ctrl{position:absolute;top:8px;right:8px;display:flex;flex-direction:column;gap:3px;z-index:10}
.zoom-btn{width:28px;height:28px;background:rgba(0,0,0,.6);border:1px solid rgba(255,255,255,.2);border-radius:4px;color:#fff;font-size:14px;display:flex;align-items:center;justify-content:center;cursor:pointer}
.zoom-btn:hover{background:rgba(0,212,238,.3)}
</style>';
    require_once __DIR__ . '/../includes/header.php';
?>
<div class="pb">
<?=popFlash()?>

<div class="row g-3">
  <!-- LEFT: Point panel -->
  <div class="col-12 col-lg-3" style="order:2;order:1" id="panelLeft">
    <div class="pt-panel p-2">
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--t2);padding:4px 6px 6px">
        Puntos cefalométricos
      </div>

      <!-- Groups -->
      <?php
      $grupos = [
        'Base craneal' => ['S'=>'Silla turca','N'=>'Nasion','Ba'=>'Basion','Ar'=>'Articular'],
        'Maxilar'      => ['A'=>'Punto A','ENA'=>'Espina nasal ant.','ENP'=>'Espina nasal post.','Or'=>'Orbitale','Po'=>'Porion'],
        'Mandíbula'    => ['B'=>'Punto B','Pog'=>'Pogonion','Gn'=>'Gnation','Me'=>'Mentón','Go'=>'Gonion','Co'=>'Cóndilo'],
        'Incisivos'    => ['Is'=>'Inc. sup (corona)','Ia'=>'Inc. inf (corona)','Ii'=>'Inc. inf (ápice)','IsA'=>'Inc. sup (ápice)'],
        'Tejidos blandos'=>['Ls'=>'Labio superior','Li'=>'Labio inferior','Pog\''=>'Pogonion blando','Cm'=>'Columela'],
      ];
      foreach ($grupos as $gname => $gpts): ?>
      <div style="font-size:10px;color:var(--t3);padding:4px 6px 2px;text-transform:uppercase;letter-spacing:.5px;margin-top:4px"><?=$gname?></div>
      <?php foreach ($gpts as $pk => $pdesc): ?>
      <button class="pt-btn <?=isset($puntos_js[$pk])?'done':''?>" id="btn_<?=htmlspecialchars($pk)?>" onclick="selectPoint('<?=htmlspecialchars($pk)?>')">
        <span class="pt-dot"></span>
        <span style="font-family:monospace;font-size:11px;min-width:32px"><?=e($pk)?></span>
        <span style="font-size:10px;font-weight:400"><?=e($pdesc)?></span>
        <?php if(isset($puntos_js[$pk])): ?><i class="bi bi-check ms-auto" style="font-size:10px"></i><?php endif; ?>
      </button>
      <?php endforeach; ?>
      <?php endforeach; ?>

      <div class="mt-3 p-2" style="border-top:1px solid var(--bd2)">
        <button onclick="undoLast()" class="btn btn-dk btn-sm w-100 mb-1">
          <i class="bi bi-arrow-counterclockwise me-1"></i>Deshacer último
        </button>
        <button onclick="clearAllPoints()" class="btn btn-del btn-sm w-100">
          <i class="bi bi-trash me-1"></i>Limpiar todo
        </button>
      </div>
    </div>
  </div>

  <!-- CENTER: Canvas -->
  <div class="col-12 col-lg-6" style="order:1;order:2">
    <!-- Toolbar -->
    <div class="d-flex gap-2 align-items-center mb-2 flex-wrap">
      <div style="background:rgba(0,212,238,.1);border:1px solid rgba(0,212,238,.3);border-radius:6px;padding:4px 10px;font-size:11px;font-weight:700;color:var(--c)" id="selectedPointLabel">
        &#128072; Selecciona un punto y haz clic en la imagen
      </div>
      <div class="ms-auto d-flex gap-1">
        <button onclick="toggleLines()" class="btn btn-dk btn-sm" id="btnLines" title="Mostrar/ocultar líneas">
          <i class="bi bi-diagram-2"></i>
        </button>
        <button onclick="downloadCanvas()" class="btn btn-dk btn-sm" title="Descargar imagen con puntos marcados">
          <i class="bi bi-download"></i>
        </button>
        <button onclick="savePoints()" class="btn btn-primary btn-sm px-3">
          <i class="bi bi-floppy me-1"></i>Guardar
        </button>
      </div>
    </div>

    <!-- Canvas wrapper -->
    <?php if ($img_principal): ?>
    <div class="cefalo-editor-wrap" id="canvasWrap">
      <canvas id="cefaloCanvas"></canvas>
      <div class="zoom-ctrl">
        <div class="zoom-btn" onclick="zoomIn()">+</div>
        <div class="zoom-btn" onclick="zoomReset()">&#8635;</div>
        <div class="zoom-btn" onclick="zoomOut()">&#8722;</div>
      </div>
    </div>
    <div class="d-flex justify-content-between mt-1" style="font-size:10px;color:var(--t3)">
      <span id="coordsLabel">x: — y: —</span>
      <span id="zoomLabel">Zoom: 100%</span>
    </div>
    <?php else: ?>
    <div class="card p-4 text-center" style="color:var(--t2)">
      <i class="bi bi-image" style="font-size:40px;display:block;margin-bottom:10px"></i>
      No hay imagen principal. Sube una radiografía.
    </div>
    <?php endif; ?>

    <!-- Upload more images -->
    <div class="card mt-3 p-3">
      <div style="font-size:11px;font-weight:700;color:var(--t2);margin-bottom:8px">IMÁGENES DEL ESTUDIO</div>
      <div class="d-flex gap-2 flex-wrap mb-2">
        <?php foreach($imagenes as $img): ?>
        <img src="<?=BASE_URL?>/uploads/<?=e($img['archivo'])?>"
             style="width:60px;height:60px;object-fit:cover;border-radius:5px;border:2px solid <?=$img['principal']?'var(--c)':'var(--bd2)'?>;cursor:pointer"
             onclick="setMainImage('<?=BASE_URL?>/uploads/<?=e($img['archivo'])?>',<?=$img['id']?>)"
             title="<?=e($img['tipo'])?>">
        <?php endforeach; ?>
      </div>
      <form method="POST" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap align-items-end">
        <input type="hidden" name="accion" value="subir_imagen">
        <input type="hidden" name="estudio_id" value="<?=$id?>">
        <input type="hidden" name="paciente_id" value="<?=$estudio['paciente_id']?>">
        <div class="flex-fill"><input type="file" name="imagen" class="form-control form-control-sm" accept="image/*"></div>
        <select name="tipo_imagen" class="form-select form-select-sm" style="width:auto">
          <option value="teleradiografia">Teleradiografía</option>
          <option value="opg">OPG</option>
          <option value="foto_perfil">Foto perfil</option>
          <option value="foto_frontal">Foto frontal</option>
        </select>
        <button class="btn btn-dk btn-sm">Subir</button>
      </form>
    </div>
  </div>

  <!-- RIGHT: Results -->
  <div class="col-12 col-lg-3" style="order:3">
    <div class="pt-panel p-2">
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--t2);padding:4px 6px 6px">
        Resultados <span id="resultCount" style="color:var(--c)"></span>
      </div>

      <!-- Diagnóstico rápido -->
      <?php if($diagnostico): ?>
      <div style="background:var(--bg3);border-radius:6px;padding:8px;margin-bottom:8px">
        <div style="font-size:10px;color:var(--t2);margin-bottom:4px">DIAGNÓSTICO AUTO</div>
        <span class="diag-chip diag-<?=substr(str_replace(['Clase ','Indeterminado'],['','N'],$diagnostico['clase_esqueletal']),0,3)?>"><?=$diagnostico['clase_esqueletal']?></span>
        <div style="font-size:10px;color:var(--t2);margin-top:4px"><?=e(mb_substr($diagnostico['resumen']??'',0,80))?></div>
      </div>
      <?php endif; ?>

      <!-- Measurements table -->
      <div id="resultsPanel">
      <?php foreach($resultados as $res): ?>
      <div class="result-row">
        <span style="font-family:monospace;font-size:11px;min-width:70px;color:var(--t)"><?=e($res['medida'])?></span>
        <span style="font-weight:700;min-width:40px;text-align:right" class="result-<?=$res['interpretacion']?>"><?=$res['valor']??'—'?></span>
        <span style="color:var(--t3);font-size:10px"><?=$res['normal_min']?>–<?=$res['normal_max']?></span>
        <span style="margin-left:auto;font-size:9px" class="result-<?=$res['interpretacion']?>"><?=$res['interpretacion']?></span>
      </div>
      <?php endforeach; ?>
      <?php if(!$resultados): ?><div style="color:var(--t3);font-size:12px;padding:8px">Marca al menos 5 puntos y guarda para ver resultados.</div><?php endif; ?>
      </div>

      <div class="mt-3" style="border-top:1px solid var(--bd2);padding-top:10px">
        <a href="?accion=ver&id=<?=$id?>" class="btn btn-primary btn-sm w-100">
          <i class="bi bi-clipboard-data me-1"></i>Ver diagnóstico completo
        </a>
      </div>
    </div>
  </div>

</div><!-- /row -->
</div><!-- /pb -->

<script>
// ─── STATE ────────────────────────────────────────────────────────────────
const ESTUDIO_ID = <?=$id?>;
const IMAGEN_ID  = <?=$img_principal?$img_principal['id']:0?>;
let   imgSrc     = <?=$img_principal?json_encode(BASE_URL.'/uploads/'.$img_principal['archivo']):'null'?>;

const POINT_COLORS = {
  'S':'#00D4EE','N':'#00D4EE','Ba':'#00D4EE','Ar':'#00D4EE',
  'A':'#f59e0b','ENA':'#f59e0b','ENP':'#f59e0b','Or':'#f59e0b','Po':'#f59e0b',
  'B':'#ef4444','Pog':'#ef4444','Gn':'#ef4444','Me':'#ef4444','Go':'#ef4444','Co':'#ef4444',
  'Is':'#10b981','Ia':'#10b981','Ii':'#10b981','IsA':'#10b981',
  'Ls':'#ec4899','Li':'#ec4899',
};
const LINES = [
  {pts:['S','N'],color:'rgba(0,212,238,.5)',label:'SN'},
  {pts:['N','A'],color:'rgba(245,158,11,.5)',label:'NA'},
  {pts:['N','B'],color:'rgba(239,68,68,.5)',label:'NB'},
  {pts:['N','Pog'],color:'rgba(139,92,246,.5)',label:'NPog'},
  {pts:['Go','Gn'],color:'rgba(239,68,68,.4)',label:'GoGn'},
  {pts:['Go','Me'],color:'rgba(239,68,68,.3)'},
  {pts:['A','B'],color:'rgba(245,158,11,.3)'},
  {pts:['S','Go'],color:'rgba(100,150,200,.3)'},
  {pts:['N','Me'],color:'rgba(100,200,100,.3)'},
];

let points = <?=json_encode($puntos_js)?>;
let selectedPoint = null;
let showLines = true;
let zoom = 1, panX = 0, panY = 0;
let isPanning = false, lastMX = 0, lastMY = 0;
let img = null;
let history_stack = [];

const canvas = document.getElementById('cefaloCanvas');
const ctx    = canvas.getContext('2d');
const wrap   = document.getElementById('canvasWrap');

// Load image
function loadImage(src) {
  img = new Image();
  img.onload = function() {
    canvas.width  = wrap.clientWidth || 600;
    canvas.height = Math.round(canvas.width * img.naturalHeight / img.naturalWidth);
    wrap.style.height = canvas.height + 'px';
    zoom = 1; panX = 0; panY = 0;
    render();
  };
  img.src = src;
}
if (imgSrc) loadImage(imgSrc);

window.setMainImage = function(src, imgId) {
  imgSrc = src;
  window._currentImgId = imgId;
  loadImage(src);
};

// ─── RENDER ──────────────────────────────────────────────────────────────
function render() {
  ctx.save();
  ctx.clearRect(0,0,canvas.width,canvas.height);
  ctx.setTransform(zoom, 0, 0, zoom, panX, panY);
  if (img) ctx.drawImage(img, 0, 0, canvas.width/zoom, canvas.height/zoom);

  // Draw lines
  if (showLines) {
    LINES.forEach(function(line) {
      const A = points[line.pts[0]], B = points[line.pts[1]];
      if (!A || !B) return;
      ctx.beginPath();
      ctx.moveTo(A.x, A.y);
      ctx.lineTo(B.x, B.y);
      ctx.strokeStyle = line.color;
      ctx.lineWidth   = 1.5/zoom;
      ctx.setLineDash([4/zoom,3/zoom]);
      ctx.stroke();
      ctx.setLineDash([]);
      if (line.label) {
        const mx=(A.x+B.x)/2, my=(A.y+B.y)/2;
        ctx.font = `bold ${10/zoom}px Nunito,sans-serif`;
        ctx.fillStyle = line.color.replace(/[\d.]+\)$/,'0.9)');
        ctx.fillText(line.label, mx+3/zoom, my-3/zoom);
      }
    });
  }

  // Draw points
  Object.entries(points).forEach(function([name, pt]) {
    const color = POINT_COLORS[name] || '#ffffff';
    const r = 6/zoom;
    // Outer ring
    ctx.beginPath(); ctx.arc(pt.x, pt.y, r, 0, Math.PI*2);
    ctx.strokeStyle = color; ctx.lineWidth = 1.5/zoom; ctx.stroke();
    // Fill
    ctx.beginPath(); ctx.arc(pt.x, pt.y, r*0.4, 0, Math.PI*2);
    ctx.fillStyle = color; ctx.fill();
    // Label
    ctx.font = `bold ${9/zoom}px monospace`;
    ctx.fillStyle = '#fff';
    ctx.fillText(name, pt.x + r + 2/zoom, pt.y - r);
  });

  // Selected point cursor
  if (selectedPoint && points[selectedPoint]) {
    const pt = points[selectedPoint];
    const color = POINT_COLORS[selectedPoint] || '#00D4EE';
    ctx.beginPath(); ctx.arc(pt.x, pt.y, 10/zoom, 0, Math.PI*2);
    ctx.strokeStyle = color; ctx.lineWidth = 2/zoom;
    ctx.setLineDash([3/zoom,2/zoom]); ctx.stroke(); ctx.setLineDash([]);
  }

  ctx.restore();
}

// ─── INTERACTION ─────────────────────────────────────────────────────────
function canvasCoords(e) {
  const rect = canvas.getBoundingClientRect();
  const cx   = (e.clientX - rect.left) / zoom - panX/zoom;
  const cy   = (e.clientY - rect.top)  / zoom - panY/zoom;
  return {x: Math.round(cx*10)/10, y: Math.round(cy*10)/10};
}

canvas.addEventListener('mousedown', function(e) {
  if (e.button === 1 || e.altKey) { isPanning=true; lastMX=e.clientX; lastMY=e.clientY; return; }
  if (selectedPoint && e.button===0) {
    const c = canvasCoords(e);
    history_stack.push({name: selectedPoint, old: points[selectedPoint] ? {...points[selectedPoint]} : null});
    points[selectedPoint] = c;
    markDone(selectedPoint);
    render();
  }
});

canvas.addEventListener('mousemove', function(e) {
  if (isPanning) {
    panX += e.clientX - lastMX; panY += e.clientY - lastMY;
    lastMX=e.clientX; lastMY=e.clientY;
    render(); return;
  }
  const c = canvasCoords(e);
  document.getElementById('coordsLabel').textContent = 'x: '+Math.round(c.x)+' y: '+Math.round(c.y);
});

canvas.addEventListener('mouseup', function() { isPanning = false; });
canvas.addEventListener('contextmenu', function(e) { e.preventDefault(); });

canvas.addEventListener('wheel', function(e) {
  e.preventDefault();
  const delta = e.deltaY > 0 ? 0.9 : 1.1;
  zoom = Math.max(0.3, Math.min(5, zoom*delta));
  document.getElementById('zoomLabel').textContent = 'Zoom: '+Math.round(zoom*100)+'%';
  render();
}, {passive:false});

// Touch support
let lastDist = 0;
canvas.addEventListener('touchstart', function(e) {
  if (e.touches.length===2) lastDist = Math.hypot(e.touches[0].clientX-e.touches[1].clientX, e.touches[0].clientY-e.touches[1].clientY);
  else if (e.touches.length===1 && selectedPoint) {
    e.preventDefault();
    const rect=canvas.getBoundingClientRect();
    const cx=(e.touches[0].clientX-rect.left)/zoom-panX/zoom;
    const cy=(e.touches[0].clientY-rect.top)/zoom-panY/zoom;
    points[selectedPoint]={x:Math.round(cx*10)/10,y:Math.round(cy*10)/10};
    markDone(selectedPoint); render();
  }
},{passive:false});
canvas.addEventListener('touchmove', function(e) {
  if (e.touches.length===2) {
    const d=Math.hypot(e.touches[0].clientX-e.touches[1].clientX,e.touches[0].clientY-e.touches[1].clientY);
    zoom=Math.max(0.3,Math.min(5,zoom*(d/lastDist)));
    lastDist=d; render();
  }
},{passive:false});

// ─── CONTROLS ─────────────────────────────────────────────────────────────
window.selectPoint = function(name) {
  selectedPoint = name;
  document.querySelectorAll('.pt-btn').forEach(b => b.classList.remove('active'));
  const btn = document.getElementById('btn_'+name);
  if (btn) btn.classList.add('active');
  document.getElementById('selectedPointLabel').textContent = '📍 Marcando: '+name+' — haz clic en la imagen';
};

function markDone(name) {
  const btn = document.getElementById('btn_'+name);
  if (btn) { btn.classList.add('done'); btn.classList.remove('active'); }
  updateResultCount();
}

function updateResultCount() {
  const n = Object.keys(points).length;
  const el = document.getElementById('resultCount');
  if (el) el.textContent = '('+n+' puntos)';
}
updateResultCount();

window.undoLast = function() {
  if (!history_stack.length) return;
  const last = history_stack.pop();
  if (last.old) points[last.name] = last.old;
  else delete points[last.name];
  const btn = document.getElementById('btn_'+last.name);
  if (btn && !last.old) btn.classList.remove('done');
  render();
};

window.clearAllPoints = function() {
  if (!confirm('¿Borrar todos los puntos?')) return;
  points = {}; history_stack = [];
  document.querySelectorAll('.pt-btn').forEach(b=>b.classList.remove('done','active'));
  render();
};

window.toggleLines = function() {
  showLines = !showLines;
  document.getElementById('btnLines').style.color = showLines ? 'var(--c)' : 'var(--t2)';
  render();
};

window.zoomIn    = function() { zoom=Math.min(5,zoom*1.2); document.getElementById('zoomLabel').textContent='Zoom: '+Math.round(zoom*100)+'%'; render(); };
window.zoomOut   = function() { zoom=Math.max(0.3,zoom/1.2); document.getElementById('zoomLabel').textContent='Zoom: '+Math.round(zoom*100)+'%'; render(); };
window.zoomReset = function() { zoom=1; panX=0; panY=0; document.getElementById('zoomLabel').textContent='Zoom: 100%'; render(); };

// ─── SAVE ─────────────────────────────────────────────────────────────────
window.savePoints = async function() {
  const imgId = window._currentImgId || IMAGEN_ID;
  const arr   = Object.entries(points).map(([k,v])=>({nombre:k,x:v.x,y:v.y,imagen_id:imgId}));
  const fd    = new FormData();
  fd.append('accion','guardar_puntos');
  fd.append('estudio_id',ESTUDIO_ID);
  fd.append('puntos_json',JSON.stringify(arr));
  const btn = event ? event.target : document.querySelector('[onclick="savePoints()"]');
  if (btn) { btn.disabled=true; btn.textContent='Guardando...'; }
  try {
    const res  = await fetch('cefalometria.php', {method:'POST',body:fd});
    const data = await res.json();
    if (data.ok) {
      location.reload(); // reload to show updated results
    }
  } catch(e) {
    alert('Error al guardar');
    if (btn) { btn.disabled=false; btn.innerHTML='<i class="bi bi-floppy me-1"></i>Guardar'; }
  }
};


// Download canvas as PNG with points marked
window.downloadCanvas = function() {
  var tmpCanvas = document.createElement('canvas');
  tmpCanvas.width  = canvas.width;
  tmpCanvas.height = canvas.height;
  var tmpCtx = tmpCanvas.getContext('2d');

  // Dark background matching the editor
  tmpCtx.fillStyle = '#111A26';
  tmpCtx.fillRect(0, 0, tmpCanvas.width, tmpCanvas.height);

  // Copy current canvas (image + points + lines)
  tmpCtx.drawImage(canvas, 0, 0);

  // Patient + date watermark top-left
  var pac   = <?=json_encode($estudio['pac']??'')?>;
  var fecha = <?=json_encode(fDate($estudio['fecha']??''))?>;
  tmpCtx.fillStyle = 'rgba(0,212,238,0.9)';
  tmpCtx.font = 'bold 13px Arial, sans-serif';
  tmpCtx.fillText('\uD83E\uDDB7 Cefalometria | ' + pac + ' | ' + fecha, 12, 22);

  // Point count watermark bottom-left
  var nPts = Object.keys(points).length;
  tmpCtx.fillStyle = 'rgba(160,176,192,0.75)';
  tmpCtx.font = '10px monospace';
  tmpCtx.fillText(nPts + ' puntos | DentalSys-Magus', 12, tmpCanvas.height - 8);

  // Trigger download
  var link = document.createElement('a');
  var safeName = (pac || 'paciente').replace(/[^a-zA-Z0-9]/g, '_').toLowerCase();
  var safeFecha = (fecha || '').replace(/\//g, '-');
  link.download = 'cefalometria_' + safeName + '_' + safeFecha + '.png';
  link.href = tmpCanvas.toDataURL('image/png', 1.0);
  link.click();
};

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
  if (e.key==='z'&&(e.ctrlKey||e.metaKey)) { undoLast(); e.preventDefault(); }
  if (e.key==='s'&&(e.ctrlKey||e.metaKey)) { savePoints(); e.preventDefault(); }
});
</script>
<?php
    require_once __DIR__ . '/../includes/footer.php';

// ══════════════════════════════════════════════════════════════════════════════
// VISTA: VER (resultados + diagnóstico)
// ══════════════════════════════════════════════════════════════════════════════
} elseif ($accion === 'ver' && $id) {
    $estudio = db()->prepare("SELECT ce.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac,p.codigo AS cod_pac,CONCAT(u.nombre,' ',u.apellidos) AS doctor
                               FROM cefalometria_estudios ce JOIN pacientes p ON ce.paciente_id=p.id LEFT JOIN usuarios u ON ce.doctor_id=u.id WHERE ce.id=?");
    $estudio->execute([$id]); $estudio = $estudio->fetch();
    if (!$estudio) { flash('error','No encontrado'); go('pages/cefalometria.php'); }

    $resultados = db()->prepare("SELECT * FROM cefalometria_resultados WHERE estudio_id=? AND valor IS NOT NULL ORDER BY medida");
    $resultados->execute([$id]); $resultados = $resultados->fetchAll();
    // Group results
    $grupos_res = [
        'Análisis Esqueletal' => ['SNA','SNB','ANB','Wits'],
        'Patrón Vertical'     => ['FMA','SN-GoGn','Eje_facial','N-Me','S-Go','Indice_facial'],
        'Posición Dental'     => ['IMPA','I-NA_ang','I-NB_ang','Interincisivo','I-NA_mm','I-NB_mm'],
        'Tejidos Blandos'     => ['Angulo_Z','Ls-E','Li-E'],
        'Relaciones'          => ['S-N','Co-A','Co-Gn','Overjet','Overbite'],
    ];
    $res_map = [];
    foreach ($resultados as $r) $res_map[$r['medida']] = $r;

    $diag = db()->prepare("SELECT * FROM cefalometria_diagnostico WHERE estudio_id=?");
    $diag->execute([$id]); $diag = $diag->fetch();

    $imagenes = db()->prepare("SELECT * FROM cefalometria_imagenes WHERE estudio_id=? ORDER BY principal DESC");
    $imagenes->execute([$id]); $imagenes = $imagenes->fetchAll();
    $img_principal = null;
    foreach ($imagenes as $img) { if ($img['principal']) { $img_principal = $img; break; } }

    $titulo = 'Cefalometría — '.$estudio['pac']; $pagina_activa = 'cefalo';
    $topbar_act = '<a href="?" class="btn btn-dk btn-sm"><i class="bi bi-arrow-left me-1"></i>Lista</a>
    <a href="?accion=editor&id='.$id.'" class="btn btn-primary btn-sm"><i class="bi bi-pencil-square me-1"></i>Editor</a>
    <a href="?accion=descargar_imagen&id='.$id.'" class="btn btn-dk btn-sm"><i class="bi bi-download me-1"></i>Descargar</a>';
    require_once __DIR__ . '/../includes/header.php';
?>
<style>
.result-table tr td:first-child{font-family:monospace;font-size:12px;font-weight:700;color:var(--t)}
.result-table tr td:nth-child(2){font-weight:700;font-size:14px;text-align:right}
.bar-wrap{height:6px;background:var(--bg3);border-radius:3px;overflow:hidden;min-width:60px}
.bar-fill{height:100%;border-radius:3px;transition:width .4s}
.interp-normal{color:#10b981}.interp-aumentado{color:#f59e0b}.interp-disminuido{color:#3b82f6}
.diag-box{background:var(--bg2);border:1px solid var(--bd2);border-radius:8px;padding:12px}
.diag-row{display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:12px}
</style>

<div class="pb">

<div class="row g-3 mb-3">
  <!-- Patient info -->
  <div class="col-12 col-md-6">
    <div class="card p-3">
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--t2);margin-bottom:8px">Paciente</div>
      <div style="font-size:16px;font-weight:800;color:var(--t)"><?=e($estudio['pac'])?></div>
      <div style="font-size:12px;color:var(--t2)"><?=e($estudio['cod_pac'])?> &bull; <?=fDate($estudio['fecha'])?> &bull; Dr. <?=e($estudio['doctor']??'—')?></div>
      <div style="font-size:12px;color:var(--t2);margin-top:4px"><?=e($estudio['titulo'])?></div>
      <?php if($estudio['notas']): ?>
      <div style="font-size:11px;color:var(--t2);margin-top:6px;padding-top:6px;border-top:1px solid var(--bd2)"><?=e($estudio['notas'])?></div>
      <?php endif; ?>
    </div>
  </div>
  <!-- Thumbnail -->
  <div class="col-12 col-md-6">
    <?php if($img_principal): ?>
    <img src="<?=BASE_URL?>/uploads/<?=e($img_principal['archivo'])?>"
         style="width:100%;max-height:180px;object-fit:contain;border-radius:8px;background:#0A1220;border:1px solid var(--bd2)">
    <?php endif; ?>
  </div>
</div>

<!-- Diagnóstico -->
<?php if($diag): ?>
<div class="card mb-3">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-clipboard2-pulse" style="color:var(--c)"></i>
    <span style="font-weight:700">Diagnóstico Cefalométrico</span>
    <a href="?accion=editor&id=<?=$id?>" class="btn btn-dk btn-sm ms-auto" style="font-size:10px">Editar diagnóstico</a>
  </div>
  <div class="p-4">
    <!-- Summary chips -->
    <div class="d-flex flex-wrap gap-2 mb-3">
      <?php
      $chips = [
        $diag['clase_esqueletal'],
        $diag['patron_vertical'],
        $diag['perfil_facial'].' (perfil)',
        $diag['posicion_maxilar'].' (maxilar)',
        $diag['posicion_mandibular'].' (mandíbula)',
        $diag['inclinacion_inc_sup'].' (inc. sup)',
        $diag['inclinacion_inc_inf'].' (inc. inf)',
      ];
      $chip_colors = [
        'Clase I'=>'#10b981','Clase II'=>'#f59e0b','Clase III'=>'#ef4444',
        'Normal'=>'#10b981','Hiperdivergente'=>'#f59e0b','Hipodivergente'=>'#3b82f6',
        'Convexo'=>'#f59e0b','Cóncavo'=>'#3b82f6','Recto'=>'#10b981',
        'Prognatismo'=>'#f59e0b','Retrognatismo'=>'#3b82f6',
        'Proinclinado'=>'#f59e0b','Retroinclinado'=>'#3b82f6',
        'Indeterminado'=>'#607080',
      ];
      foreach ($chips as $chip):
        $word = explode(' ',$chip)[0];
        $c = $chip_colors[$word] ?? '#607080';
      ?>
      <span style="background:<?=$c?>22;color:<?=$c?>;border:1px solid <?=$c?>44;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:700"><?=e($chip)?></span>
      <?php endforeach; ?>
    </div>
    <?php if($diag['resumen']): ?>
    <div style="background:var(--bg3);border-radius:6px;padding:10px 12px;font-size:12px;color:var(--t);margin-bottom:10px">
      <strong style="color:var(--c)">Resumen:</strong> <?=e($diag['resumen'])?>
    </div>
    <?php endif; ?>
    <?php if($diag['plan_tratamiento']): ?>
    <div style="background:rgba(0,212,238,.05);border:1px solid rgba(0,212,238,.2);border-radius:6px;padding:10px 12px;font-size:12px;color:var(--t)">
      <strong style="color:var(--c)">Plan de tratamiento:</strong> <?=nl2br(e($diag['plan_tratamiento']))?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- Measurements -->
<div class="row g-3 mb-3">
  <?php foreach($grupos_res as $gname => $gkeys): ?>
  <div class="col-12 col-md-6">
    <div class="card h-100">
      <div class="card-header"><span style="font-weight:700;font-size:12px"><?=$gname?></span></div>
      <div class="p-2">
        <table class="table mb-0 result-table">
          <thead>
            <tr style="font-size:10px;color:var(--t3)">
              <th>Medida</th><th style="text-align:right">Valor</th><th>Normal</th><th></th><th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($gkeys as $mk):
            $r = $res_map[$mk] ?? null;
            if (!$r) continue;
            // Bar position
            $pct = 50;
            if ($r['normal_min']!==null && $r['normal_max']!==null && $r['valor']!==null) {
                $range = $r['normal_max'] - $r['normal_min'];
                if ($range > 0) $pct = min(100, max(0, (($r['valor']-$r['normal_min'])/$range)*100));
            }
            $barColor = $r['interpretacion']==='normal' ? '#10b981' : ($r['interpretacion']==='aumentado' ? '#f59e0b' : '#3b82f6');
          ?>
          <tr>
            <td><?=e($mk)?></td>
            <td class="interp-<?=$r['interpretacion']?>"><?=$r['valor']?></td>
            <td style="color:var(--t3);font-size:11px"><?=$r['normal_min']?>–<?=$r['normal_max']?></td>
            <td style="min-width:60px">
              <div class="bar-wrap">
                <div class="bar-fill" style="width:<?=$pct?>%;background:<?=$barColor?>"></div>
              </div>
            </td>
            <td style="font-size:10px" class="interp-<?=$r['interpretacion']?>"><?=$r['interpretacion']?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Diagnóstico form -->
<div class="card">
  <div class="card-header"><span style="font-weight:700">Ajustar Diagnóstico</span></div>
  <div class="p-4">
  <form method="POST">
    <input type="hidden" name="accion" value="guardar_diagnostico">
    <input type="hidden" name="estudio_id" value="<?=$id?>">
    <div class="row g-3">
      <?php
      $campos_diag = [
        'clase_esqueletal'     => ['Clase Esqueletal',       ['Clase I','Clase II','Clase III','Indeterminado']],
        'patron_vertical'      => ['Patrón Vertical',        ['Normal','Hiperdivergente','Hipodivergente','Indeterminado']],
        'perfil_facial'        => ['Perfil Facial',          ['Recto','Convexo','Cóncavo','Indeterminado']],
        'posicion_maxilar'     => ['Posición Maxilar',       ['Normal','Prognatismo','Retrognatismo','Indeterminado']],
        'posicion_mandibular'  => ['Posición Mandibular',    ['Normal','Prognatismo','Retrognatismo','Indeterminado']],
        'inclinacion_inc_sup'  => ['Inclinación Inc. Sup.',  ['Normal','Proinclinado','Retroinclinado','Indeterminado']],
        'inclinacion_inc_inf'  => ['Inclinación Inc. Inf.',  ['Normal','Proinclinado','Retroinclinado','Indeterminado']],
      ];
      foreach ($campos_diag as $fname => [$flabel, $fopts]):
        $fval = $diag[$fname] ?? 'Indeterminado';
      ?>
      <div class="col-12 col-md-4">
        <label class="form-label" style="font-size:11px"><?=$flabel?></label>
        <select name="<?=$fname?>" class="form-select form-select-sm">
          <?php foreach($fopts as $fo): ?>
          <option value="<?=e($fo)?>" <?=$fval===$fo?'selected':''?>><?=e($fo)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endforeach; ?>
      <div class="col-12 col-md-6">
        <label class="form-label" style="font-size:11px">Resumen clínico</label>
        <textarea name="resumen" class="form-control form-control-sm" rows="3"><?=e($diag['resumen']??'')?></textarea>
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label" style="font-size:11px">Plan de tratamiento</label>
        <textarea name="plan_tratamiento" class="form-control form-control-sm" rows="3"><?=e($diag['plan_tratamiento']??'')?></textarea>
      </div>
    </div>
    <div class="d-flex gap-2 justify-content-end mt-3">
      <button type="submit" class="btn btn-primary px-4"><i class="bi bi-floppy me-2"></i>Guardar diagnóstico</button>
    </div>
  </form>
  </div>
</div>

</div><!-- /pb -->
<?php
    require_once __DIR__ . '/../includes/footer.php';
}
