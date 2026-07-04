<?php
require_once __DIR__.'/includes/config.php';
sesion(); if(estaLogueado()) go('index.php');
$err='';
// Rate limit: max 5 attempts per 10 min
if(!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts']=0;
if(!isset($_SESSION['login_locked_until'])) $_SESSION['login_locked_until']=0;
if($_SESSION['login_locked_until']>time()){
 $wait=ceil(($_SESSION['login_locked_until']-time())/60);
 $err='Demasiados intentos. Espera '.$wait.' minuto(s).';
}
if($_SERVER['REQUEST_METHOD']==='POST'){
 $em=trim($_POST['email']??''); $pw=$_POST['password']??'';
 if($em&&$pw){
  $s=db()->prepare("SELECT u.*,r.nombre as rol FROM usuarios u JOIN roles r ON u.rol_id=r.id WHERE u.email=? AND u.activo=1");
  $s->execute([$em]); $u=$s->fetch();
  if($u&&password_verify($pw,$u['password'])){
   session_regenerate_id(true); // prevent session fixation
   $_SESSION['login_attempts']=0; $_SESSION['login_locked_until']=0;
   $_SESSION['uid']=$u['id'];
   $_SESSION['usr']=['id'=>$u['id'],'nombre'=>$u['nombre'].' '.$u['apellidos'],'email'=>$u['email']];
   $_SESSION['rol']=$u['rol'];
   $_SESSION['rol_id']=(int)$u['rol_id'];
   db()->prepare("UPDATE usuarios SET ultimo_acceso=NOW() WHERE id=?")->execute([$u['id']]);
   auditar('LOGIN','usuarios',$u['id']);
   go('index.php');
  } else {
   $_SESSION['login_attempts']++;
   if($_SESSION['login_attempts']>=5){
    $_SESSION['login_locked_until']=time()+600; // 10 min
    $_SESSION['login_attempts']=0;
    $err='Cuenta bloqueada por 10 minutos por múltiples intentos.';
   } else $err='Credenciales incorrectas. Intento '.$_SESSION['login_attempts'].'/5.';
  }
 } else $err='Completa todos los campos.';
}
?><!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Acceso — DentalSys | Magus</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root{--tq:#06B6D4;--bl:#2563EB;--ink:#1F2937;--mut:#6B7280}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Nunito',sans-serif;min-height:100vh;background:#E7EBF1;display:flex;align-items:center;justify-content:center;padding:24px}
.wrap{position:relative;display:flex;width:100%;max-width:940px;min-height:560px;background:#fff;border-radius:24px;overflow:hidden;box-shadow:0 24px 64px rgba(15,30,55,.20)}
.left{position:relative;flex:0 0 45%;padding:40px 36px;color:#fff;display:flex;flex-direction:column;background:linear-gradient(150deg,rgba(8,18,32,.93),rgba(12,30,52,.80)),url('assets/img/dental_mobile.png') center/cover;overflow:hidden}
.left>*{position:relative;z-index:1}
.dots{position:absolute!important;inset:auto 0 0 0;height:120px;background-image:radial-gradient(rgba(6,182,212,.5) 1px,transparent 1.4px);background-size:14px 14px;opacity:.4;z-index:0!important;-webkit-mask-image:linear-gradient(to top,#000,transparent);mask-image:linear-gradient(to top,#000,transparent)}
.l-top{margin-top:7%}
.l-logo{height:46px;width:auto;max-width:74%;margin-bottom:18px;display:block}
.l-brand{font-size:23px;font-weight:800;letter-spacing:.3px}
.l-sub{font-size:10px;letter-spacing:2.5px;text-transform:uppercase;color:#7FE3F2;margin-top:4px}
.l-div{width:46px;height:3px;background:var(--tq);border-radius:3px;margin:16px 0 14px}
.l-tag{font-size:14px;line-height:1.55;color:#CBD8E6;max-width:250px}
.l-tooth{margin:auto auto 2px;display:block;width:140px;filter:drop-shadow(0 6px 24px rgba(6,182,212,.45))}
.l-foot{font-size:10.5px;color:#90A4B8;margin-top:18px;line-height:1.7}
.l-foot a{color:#9FE6F2;text-decoration:none;font-weight:700}
.curve{position:absolute;top:0;bottom:0;left:calc(45% - 148px);width:150px;height:100%;z-index:2;pointer-events:none;filter:drop-shadow(0 0 7px rgba(34,211,238,.4))}
.right{position:relative;z-index:1;flex:1;background:#fff;padding:46px 40px;display:flex;flex-direction:column;justify-content:center}
.r-inner{width:100%;max-width:360px;margin:0 auto}
.r-ico{width:72px;height:72px;border-radius:50%;background:#fff;box-shadow:0 8px 22px rgba(6,182,212,.16),0 0 0 1px #EEF3F7;display:flex;align-items:center;justify-content:center;margin:0 auto 16px}
.r-ico img{max-width:56px;max-height:50px;width:auto;height:auto;object-fit:contain}
.r-title{text-align:center;font-size:26px;font-weight:800;color:var(--ink)}
.r-desc{text-align:center;font-size:13.5px;color:var(--mut);margin-top:3px;margin-bottom:24px}
.lbl{display:block;font-size:13px;font-weight:700;color:var(--ink);margin-bottom:6px}
.fw{position:relative;margin-bottom:16px}
.fw>i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#9AA7B5;font-size:14px}
.fi{width:100%;background:#F7F9FB;border:1.5px solid #E3E8EE;border-radius:11px;padding:12px 14px 12px 42px;color:var(--ink);font-family:inherit;font-size:14px;outline:none;transition:.18s}
.fi:focus{border-color:var(--tq);background:#fff;box-shadow:0 0 0 4px rgba(6,182,212,.12)}
.fi::placeholder{color:#A9B4C0}
.eye{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:#9AA7B5;cursor:pointer;font-size:15px;padding:4px;line-height:0}
.btn-in{width:100%;padding:13px;background:linear-gradient(135deg,var(--tq),var(--bl));border:none;border-radius:11px;color:#fff;font-family:inherit;font-size:14.5px;font-weight:800;cursor:pointer;transition:.2s;display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 10px 22px rgba(37,99,235,.26);margin-top:4px}
.btn-in:hover{filter:brightness(1.05);box-shadow:0 14px 28px rgba(37,99,235,.36)}
.err{background:#FEF2F2;border:1px solid #FBD5D5;border-left:3px solid #EF4444;padding:10px 13px;border-radius:8px;color:#B91C1C;font-size:12.5px;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.r-foot{text-align:center;font-size:11.5px;color:#94A3B3;margin-top:22px;padding-top:16px;border-top:1px solid #EEF1F5;display:flex;align-items:center;justify-content:center;gap:7px;flex-wrap:wrap}
.r-foot b{color:#475569;font-weight:800}
.r-foot .sep{color:#C7D0DA}
@media(max-width:820px){
 body{padding:0;background:#0C1B2E}
 .wrap{flex-direction:column;max-width:none;width:100%;min-height:100vh;border-radius:0;box-shadow:none}
 .left{flex:none;padding:26px 26px 46px}
 .l-top{margin-top:4px}
 .l-logo{height:42px;max-width:60%;margin-bottom:12px}
 .l-brand{font-size:21px}
 .l-tag,.l-tooth,.dots,.l-foot{display:none}
 .curve{display:none}
 .right{position:relative;z-index:2;flex:1;justify-content:flex-start;margin-top:-24px;border-radius:24px 24px 0 0;padding:0 24px 30px}
 .r-inner{padding-top:6px}
 .r-ico{margin-top:-38px}
}
</style></head><body>
<div class="wrap">
 <div class="left">
   <div class="l-top">
     <img class="l-logo" src="assets/img/logo_magus.png" alt="MAGUS">
     <div class="l-brand">DentalSys | Magus</div>
     <div class="l-sub">Sistema de Gestión Clínica</div>
     <div class="l-div"></div>
     <div class="l-tag">Solución integral para la gestión de tu clínica dental.</div>
   </div>
   <img class="l-tooth" src="assets/img/tooth_left.png" alt="">
   <div class="l-foot">&copy; <?=date('Y')?> Magus Technologies &middot; Todos los derechos reservados<br>Desarrollado por <a target="_blank" href="https://magustechnologies.com/">MAGUS TECHNOLOGIES</a></div>
   <div class="dots"></div>
 </div>
 <svg class="curve" viewBox="0 0 150 1000" preserveAspectRatio="none" aria-hidden="true">
   <path d="M150,0 L112,0 C48,300 70,690 24,1000 L150,1000 Z" fill="#fff"/>
   <path d="M112,0 C48,300 70,690 24,1000" fill="none" stroke="#22D3EE" stroke-width="2" vector-effect="non-scaling-stroke" opacity=".85"/>
 </svg>
 <div class="right">
  <div class="r-inner">
   <div class="r-ico"><?php $logoEmp = empresa('logo', true); if($logoEmp): ?><img src="<?=e($logoEmp)?>" alt="Logo"><?php else: ?><img src="assets/img/tooth_icon.png" alt=""><?php endif; ?></div>
   <div class="r-title">&iexcl;Bienvenido!</div>
   <div class="r-desc">Inicia sesión para continuar</div>
   <?php if($err): ?><div class="err"><i class="bi bi-exclamation-triangle-fill"></i><?=e($err)?></div><?php endif; ?>
   <form method="POST">
     <label class="lbl">Correo electrónico</label>
     <div class="fw"><i class="bi bi-envelope"></i><input type="email" name="email" class="fi" value="<?=e($_POST['email']??'')?>" placeholder="usuario@clinica.com" required autofocus></div>
     <label class="lbl">Contraseña</label>
     <div class="fw"><i class="bi bi-lock"></i><input type="password" name="password" id="pw" class="fi" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;" required><button type="button" class="eye" id="eye" aria-label="Mostrar u ocultar contraseña"><i class="bi bi-eye"></i></button></div>
     <button type="submit" class="btn-in"><i class="bi bi-box-arrow-in-right"></i> Ingresar al sistema</button>
   </form>
   <div class="r-foot">Compatible con <b>SUNAT</b> <span class="sep">&middot;</span> <b>SIHCE-MINSA</b></div>
  </div>
 </div>
</div>
<script>
var eye=document.getElementById('eye'),pw=document.getElementById('pw');
if(eye){eye.addEventListener('click',function(){var s=pw.type==='password';pw.type=s?'text':'password';eye.innerHTML='<i class="bi bi-eye'+(s?'-slash':'')+'"></i>';});}
</script>
</body></html>
