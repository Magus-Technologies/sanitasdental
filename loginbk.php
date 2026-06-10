<?php
require_once __DIR__.'/includes/config.php';
sesion(); if(estaLogueado()) go('index.php');
$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 $em=trim($_POST['email']??''); $pw=$_POST['password']??'';
 if($em&&$pw){
  $s=db()->prepare("SELECT u.*,r.nombre as rol FROM usuarios u JOIN roles r ON u.rol_id=r.id WHERE u.email=? AND u.activo=1");
  $s->execute([$em]); $u=$s->fetch();
  if($u&&password_verify($pw,$u['password'])){
   $_SESSION['uid']=$u['id'];
   $_SESSION['usr']=['id'=>$u['id'],'nombre'=>$u['nombre'].' '.$u['apellidos'],'email'=>$u['email']];
   $_SESSION['rol']=$u['rol'];
   db()->prepare("UPDATE usuarios SET ultimo_acceso=NOW() WHERE id=?")->execute([$u['id']]);
   auditar('LOGIN','usuarios',$u['id']);
   go('index.php');
  } else $err='Credenciales incorrectas.';
 } else $err='Completa todos los campos.';
}
?><!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Acceso-DentalSys | Magus</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
*{box-sizing:border-box}body{font-family:'Nunito',sans-serif;min-height:100vh;background:radial-gradient(ellipse at 20% 50%,#0D2137 0%,#060F1A 60%);display:flex;align-items:center;justify-content:center;padding:20px;overflow:hidden}
.canvas{position:fixed;inset:0;opacity:.18;pointer-events:none}
.box{background:rgba(20,32,48,.96);border:1px solid rgba(0,212,238,.18);border-radius:16px;padding:44px 38px;width:100%;max-width:400px;box-shadow:0 30px 70px rgba(0,0,0,.6);position:relative;z-index:1}
.logo-w{text-align:center;margin-bottom:30px}
.logo-c{width:68px;height:68px;background:rgba(0,212,238,.12);border:2px solid rgba(0,212,238,.28);border-radius:14px;display:inline-flex;align-items:center;justify-content:center;font-size:32px;margin-bottom:10px}
.brand{font-size:22px;font-weight:800;color:#E8EDF2}.sub{font-size:10px;color:#00D4EE;letter-spacing:3px;text-transform:uppercase;opacity:.8}
.lbl{font-size:10px;font-weight:700;color:#6BC5D3;letter-spacing:1px;text-transform:uppercase;display:block;margin-bottom:5px}
.fw{position:relative;margin-bottom:16px}
.fw i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#00D4EE;opacity:.45;font-size:14px}
.fi{width:100%;background:rgba(0,212,238,.06);border:1px solid rgba(0,212,238,.18);border-radius:8px;padding:10px 12px 10px 38px;color:#E8EDF2;font-family:'Nunito',sans-serif;font-size:13px;outline:none;transition:all .2s}
.fi:focus{border-color:#00D4EE;background:rgba(0,212,238,.1);box-shadow:0 0 0 3px rgba(0,212,238,.1)}
.fi::placeholder{color:rgba(160,176,192,.4)}
.btn-in{width:100%;padding:11px;background:#00D4EE;border:none;border-radius:8px;color:#060F1A;font-family:'Nunito',sans-serif;font-size:13px;font-weight:800;cursor:pointer;transition:all .2s;margin-top:6px}
@media(max-width:480px){.box{padding:28px 20px;margin:8px}.brand{font-size:18px}.logo-c{width:56px;height:56px;font-size:26px}}
.btn-in:hover{background:#00B8CC;box-shadow:0 6px 20px rgba(0,212,238,.35)}
.err{background:rgba(224,82,82,.1);border:1px solid rgba(224,82,82,.3);border-left:3px solid #E05252;padding:9px 13px;border-radius:6px;color:#E05252;font-size:12px;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.hint{text-align:center;font-size:11px;color:rgba(160,176,192,.35);margin-top:18px}
</style></head><body>
<canvas class="canvas" id="cv"></canvas>
<div class="box">
 <div class="logo-w"><div class="logo-c">🦷</div><div class="brand">DentalSys | Magus</div><div class="sub">Sistema de Gestión Clínica</div></div>
 <?php if($err): ?><div class="err"><i class="bi bi-exclamation-triangle-fill"></i><?=e($err)?></div><?php endif; ?>
 <form method="POST">
  <label class="lbl">Correo electrónico</label>
  <div class="fw"><input type="email" name="email" class="fi" value="<?=e($_POST['email']??'')?>" placeholder="usuario@clinica.com" required autofocus><i class="bi bi-envelope"></i></div>
  <label class="lbl">Contraseña</label>
  <div class="fw"><input type="password" name="password" class="fi" placeholder="••••••••" required><i class="bi bi-lock"></i></div>
  <button type="submit" class="btn-in"><i class="bi bi-box-arrow-in-right me-2"></i>Ingresar al sistema</button>
 </form>
 <div class="hint">© <?=date('Y')?> Todos los derechos reservados por <a target="_blank" href="https://magustechnologies.com/"><strong>MAGUS TECHNOLOGIES</strong></a></div>
 <div class="hint">Compatible SIHCE-MINSA</div>

<script>
const cv=document.getElementById('cv'),ctx=cv.getContext('2d');
cv.width=window.innerWidth;cv.height=window.innerHeight;
const pts=Array.from({length:60},()=>({x:Math.random()*cv.width,y:Math.random()*cv.height,vx:(Math.random()-.5)*.3,vy:(Math.random()-.5)*.3}));
function draw(){ctx.clearRect(0,0,cv.width,cv.height);pts.forEach(p=>{p.x+=p.vx;p.y+=p.vy;if(p.x<0||p.x>cv.width)p.vx*=-1;if(p.y<0||p.y>cv.height)p.vy*=-1;ctx.beginPath();ctx.arc(p.x,p.y,1.5,0,Math.PI*2);ctx.fillStyle='#00D4EE';ctx.fill();pts.forEach(q=>{const d=Math.hypot(p.x-q.x,p.y-q.y);if(d<100){ctx.beginPath();ctx.moveTo(p.x,p.y);ctx.lineTo(q.x,q.y);ctx.strokeStyle=`rgba(0,212,238,${.3*(1-d/100)})`;ctx.lineWidth=.5;ctx.stroke()}})});requestAnimationFrame(draw)}
draw();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
