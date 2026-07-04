<?php
sesion();
if(!isset($titulo)) $titulo='DentalSys-Magus';
If(!isset($pagina_activa)) $pagina_activa='';
?><!DOCTYPE html>
<html lang="es" id="htmlRoot">
<head>
<script>(function(){try{if(localStorage.getItem("dentsys_theme_v")!=="azul"){localStorage.setItem("dentsys_theme","light");localStorage.setItem("dentsys_theme_v","azul");}}catch(e){}var _t=localStorage.getItem("dentsys_theme")||"light";document.documentElement.setAttribute("data-theme",_t==="light"?"light":"");})();</script>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>DentalSys | Magus</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root{--bs-body-color:#E6EDF4;--bs-body-bg:#0F1B2B;--bs-emphasis-color:#F1F6FB;--bg:#0F1B2B;--bg2:#17283D;--bg3:#0B1521;--bg4:#1D3149;
--c:#00B8D4;--c2:#0E9CB8;--g:#10B981;--a:#F59E0B;--r:#EF4444;--b:#3B82F6;--p:#8B5CF6;
--t:#E6EDF4;--t2:#9FB0C2;--t3:#5E7186;
--bd:rgba(0,184,212,.16);--bd2:rgba(255,255,255,.07);--sw:256px}
*{box-sizing:border-box}
body{font-family:'Nunito',sans-serif;font-size:14px;background:var(--bg);color:var(--t);min-height:100vh;overflow-x:hidden}
::-webkit-scrollbar{width:4px;height:4px}::-webkit-scrollbar-track{background:var(--bg3)}::-webkit-scrollbar-thumb{background:var(--c2);border-radius:2px}
/* SIDEBAR */
.sb{position:fixed;top:0;left:0;width:var(--sw);height:100vh;background:var(--bg3);border-right:1px solid var(--bd);display:flex;flex-direction:column;z-index:1000;overflow-y:auto;transition:transform .28s cubic-bezier(.4,0,.2,1)}
.sb-brand{padding:16px 14px 14px;border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:10px;flex-shrink:0}
.sb-logo{width:34px;height:34px;background:var(--c);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:17px;color:#FFFFFF;font-weight:900;flex-shrink:0}
.sb-name{font-size:14px;font-weight:800;color:var(--t);line-height:1.1}
.sb-name small{display:block;font-size:9px;font-weight:600;color:var(--c);letter-spacing:2px;text-transform:uppercase;opacity:.75}
.sb-nav{padding:8px 8px;flex:1}
.sb-sec{font-size:9px;font-weight:700;letter-spacing:2px;color:var(--t3);text-transform:uppercase;padding:12px 8px 4px}
.sb-nav a{display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:7px;color:var(--t2);font-size:12.5px;font-weight:600;text-decoration:none;margin-bottom:1px;transition:all .14s;border:1px solid transparent;position:relative}
.sb-nav a:hover{background:rgba(0,184,212,.07);color:var(--t)}
.sb-nav a.act{background:rgba(0,184,212,.12);color:var(--c);border-color:rgba(0,184,212,.18)}
.sb-nav a i{font-size:15px;width:18px;text-align:center;flex-shrink:0}
.nb{background:var(--r);color:#fff;border-radius:9px;padding:1px 6px;font-size:9px;font-weight:700;margin-left:auto;line-height:16px}
.sb-foot{padding:12px 14px;border-top:1px solid var(--bd);flex-shrink:0}
.sb-uname{font-size:12px;font-weight:700;color:var(--t)}
.sb-urole{font-size:9px;color:var(--c);text-transform:uppercase;letter-spacing:1px}
.btn-out{background:transparent;border:1px solid var(--bd2);color:var(--t2);border-radius:7px;padding:5px 12px;font-size:11px;font-weight:700;text-decoration:none;display:block;text-align:center;margin-top:8px;transition:all .18s}
.btn-out:hover{border-color:var(--r);color:var(--r)}
/* MAIN */
.mw{margin-left:var(--sw);min-height:100vh;display:flex;flex-direction:column}
.tb{background:var(--bg3);border-bottom:1px solid var(--bd);height:56px;padding:0 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;flex-shrink:0}
.tb-title{font-size:15px;font-weight:700}
.pb{padding:22px;flex:1}
/* CARD */
.card{background:var(--bg2)!important;border:1px solid var(--bd2)!important;border-radius:10px!important}
.card-header{background:var(--bg3)!important;border-bottom:1px solid var(--bd2)!important;padding:11px 18px!important;font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:var(--t)!important;display:flex;align-items:center;justify-content:space-between;min-height:44px}
.card-header span,.card-header div,.card-header strong,.card-header small{color:var(--t)!important}
/* KPI */
.kpi{background:var(--bg2);border:1px solid var(--bd2);border-radius:10px;padding:16px 18px;position:relative;overflow:hidden}
.kpi-ico{width:40px;height:40px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:18px;margin-bottom:10px}
.kpi-v{font-family:'DM Mono',monospace;font-size:22px;font-weight:500;line-height:1}
.kpi-l{font-size:11px;font-weight:600;color:var(--t2);margin-top:3px;text-transform:uppercase;letter-spacing:.3px}
.kpi-s{position:absolute;bottom:0;left:0;right:0;height:3px;border-radius:0 0 10px 10px}
.kc .kpi-ico{background:rgba(0,184,212,.1);color:var(--c)}.kc .kpi-s{background:var(--c)}
.kg .kpi-ico{background:rgba(46,204,142,.1);color:var(--g)}.kg .kpi-s{background:var(--g)}
.ka .kpi-ico{background:rgba(245,166,35,.1);color:var(--a)}.ka .kpi-s{background:var(--a)}
.kr .kpi-ico{background:rgba(224,82,82,.1);color:var(--r)}.kr .kpi-s{background:var(--r)}
.kb .kpi-ico{background:rgba(91,168,245,.1);color:var(--b)}.kb .kpi-s{background:var(--b)}
/* BADGE */
.badge{padding:3px 9px;border-radius:5px;font-size:10px;font-weight:700;letter-spacing:.2px}
.bc{background:rgba(0,184,212,.14);color:var(--c);border:1px solid rgba(0,184,212,.28)}
.bg{background:rgba(46,204,142,.14);color:var(--g);border:1px solid rgba(46,204,142,.28)}
.ba{background:rgba(245,166,35,.14);color:var(--a);border:1px solid rgba(245,166,35,.28)}
.br{background:rgba(224,82,82,.14);color:var(--r);border:1px solid rgba(224,82,82,.28)}
.bb{background:rgba(91,168,245,.14);color:var(--b);border:1px solid rgba(91,168,245,.28)}
.bpu{background:rgba(139,92,246,.14);color:var(--p);border:1px solid rgba(139,92,246,.28)}
.bgr{background:rgba(160,176,192,.1);color:var(--t2);border:1px solid rgba(160,176,192,.18)}
/* BTN */
.btn{font-family:'Nunito',sans-serif;font-weight:700;font-size:12.5px;border-radius:7px;padding:7px 14px;transition:all .16s}
.btn-primary{background:var(--c)!important;border-color:var(--c)!important;color:#FFFFFF!important;font-weight:800}
.btn-primary:hover{background:var(--c2)!important;border-color:var(--c2)!important;box-shadow:0 4px 14px rgba(0,184,212,.3)!important}
.btn-dk{background:var(--bg3)!important;border:1px solid var(--bd2)!important;color:var(--t)!important}
.btn-dk:hover{border-color:var(--c)!important;color:var(--c)!important}
.btn-ok{background:transparent;border:1px solid rgba(46,204,142,.4);color:var(--g)}
.btn-ok:hover{background:rgba(46,204,142,.1);color:var(--g)}
.btn-del{background:transparent;border:1px solid rgba(224,82,82,.4);color:var(--r)}
.btn-del:hover{background:rgba(224,82,82,.1);color:var(--r)}
.btn-wa{background:rgba(37,211,102,.1);border:1px solid rgba(37,211,102,.35);color:#25D366}
.btn-wa:hover{background:rgba(37,211,102,.2);color:#25D366}
.btn-ico{width:32px;height:32px;padding:0!important;display:inline-flex;align-items:center;justify-content:center;border-radius:7px}
/* TABLE */
.table{font-size:13px;color:var(--t)!important}
.table thead th{background:var(--bg3)!important;color:var(--c)!important;font-size:10px!important;font-weight:700!important;text-transform:uppercase!important;letter-spacing:.8px!important;padding:10px 14px!important;border-bottom:2px solid var(--bd)!important;white-space:nowrap}
.table tbody td{background:var(--bg2)!important;color:var(--t)!important;padding:11px 14px!important;vertical-align:middle!important;border-bottom:1px solid var(--bd2)!important;font-size:13px!important}
.table tbody tr:nth-child(even) td{background:var(--bg4)!important}
.table tbody tr:hover td{background:rgba(0,184,212,.04)!important}
.table-responsive{border:1px solid var(--bd2);border-radius:10px;overflow:hidden}
/* FORM */
.form-control,.form-select{font-family:'Nunito',sans-serif;font-size:13px!important;background:var(--bg3)!important;border:1px solid var(--bd2)!important;border-radius:7px;color:var(--t)!important;padding:8px 12px}
.form-control:focus,.form-select:focus{background:rgba(0,184,212,.05)!important;border-color:var(--c)!important;box-shadow:0 0 0 3px rgba(0,184,212,.1)!important;color:var(--t)!important}
.form-control::placeholder{color:var(--t3)!important}
.form-select option,.form-control option,select option{background:var(--bg2)!important;color:var(--t)!important}
.form-select optgroup,.form-control optgroup,select optgroup{background:var(--bg2)!important;color:var(--t2)!important}
.form-label{font-size:11px;font-weight:700;color:#51607A;margin-bottom:4px;display:block}
.input-group-text{background:var(--bg3)!important;border:1px solid var(--bd2)!important;color:var(--c)!important;font-weight:700;font-size:13px}
/* ALERT */
.alert{border-radius:8px;font-size:13px;border-left-width:4px}
.alert-success{background:rgba(46,204,142,.1)!important;border-color:var(--g)!important;color:var(--g)!important}
.alert-danger{background:rgba(224,82,82,.1)!important;border-color:var(--r)!important;color:var(--r)!important}
.alert-warning{background:rgba(245,166,35,.1)!important;border-color:var(--a)!important;color:var(--a)!important}
.alert-info{background:rgba(91,168,245,.1)!important;border-color:var(--b)!important;color:var(--b)!important}
.btn-close{opacity:.55}
/* MISC */
.mon{font-family:'DM Mono',monospace;font-weight:500}
.ava{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--c2),var(--c));color:#FFFFFF;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;flex-shrink:0}
.text-muted,.text-secondary{color:var(--t2)!important}
small{color:var(--t2)}
hr{border-color:var(--bd2)!important}
.modal-content{background:var(--bg2)!important;border:1px solid var(--bd2)!important;border-radius:12px!important;color:var(--t)!important}
.modal-header{background:var(--bg3)!important;border-bottom:1px solid var(--bd2)!important}
.modal-title{color:var(--t)!important;font-weight:700!important}
.modal-footer{border-top:1px solid var(--bd2)!important}
.nav-tabs{border-bottom:1px solid var(--bd2)}
.nav-tabs .nav-link{color:var(--t2);font-size:12.5px;font-weight:700;border:none;border-bottom:2px solid transparent;border-radius:0;padding:8px 14px;background:transparent!important;transition:all .15s}
.nav-tabs .nav-link.active{color:var(--c)!important;border-bottom-color:var(--c)!important}
.list-group-item{background:var(--bg2)!important;border-color:var(--bd2)!important;color:var(--t)!important}
.form-check-input{background-color:var(--bg3)!important;border-color:var(--bd2)!important}
.form-check-input:checked{background-color:var(--c)!important;border-color:var(--c)!important}
.form-check-label{color:var(--t)!important}
.dropdown-menu{background:var(--bg2)!important;border:1px solid var(--bd2)!important;border-radius:8px}
.dropdown-item{color:var(--t)!important;font-size:12.5px}
.dropdown-item:hover{background:rgba(0,184,212,.08)!important;color:var(--c)!important}
/* ALERTS BAR */
.alert-bar{margin-bottom:4px;padding:10px 14px;border-radius:8px;display:flex;align-items:center;justify-content:space-between;gap:12px;font-size:13px}
.alert-bar-r{background:rgba(224,82,82,.1);border:1px solid rgba(224,82,82,.25);border-left:4px solid var(--r)}
.alert-bar-a{background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.25);border-left:4px solid var(--a)}
/* ══ RESPONSIVE / MOBILE ══════════════════════════════════════ */
.sb-toggle{background:none;border:none;font-size:22px;color:var(--c);padding:4px 8px;line-height:1;cursor:pointer;transition:all .2s}
.sb-toggle:hover{color:var(--c2);transform:scale(1.1)}
.sb-ov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:999;backdrop-filter:blur(2px)}

/* Sidebar colapsado en desktop */
.sb.collapsed{width:70px;overflow:visible}
.sb.collapsed .sb-name,.sb.collapsed .sb-sec,.sb.collapsed .nb,.sb.collapsed .sb-foot>div:first-child>div:last-child,.sb.collapsed .btn-out{display:none}
.sb.collapsed .sb-brand{justify-content:center;padding:16px 8px}
.sb.collapsed .sb-nav a{justify-content:center;padding:10px;font-size:0;position:relative}
.sb.collapsed .sb-nav a i{margin:0;font-size:15px}
.sb.collapsed .sb-nav a::after{content:attr(data-title);position:absolute;left:100%;top:50%;transform:translateY(-50%);background:var(--bg2);color:var(--t);padding:6px 12px;border-radius:6px;font-size:11px;white-space:nowrap;opacity:0;pointer-events:none;transition:opacity .2s;margin-left:8px;border:1px solid var(--bd);box-shadow:0 4px 12px rgba(0,0,0,.3);z-index:1000}
.sb.collapsed .sb-nav a:hover::after{opacity:1}
.sb.collapsed .sb-foot{padding:12px 8px;text-align:center}
.sb.collapsed .sb-foot .ava{margin:0 auto}
.mw.expanded{margin-left:70px}

/* TABLET (≤992px) */
@media(max-width:992px){
  :root{--sw:240px}
  .kpi-v{font-size:18px!important}
  .tb-title{font-size:13px}
  .pb{padding:16px}
}

/* MOBILE (≤768px) */
@media(max-width:768px){
  :root{--sw:260px}
  /* Sidebar slide-in */
  .sb{transform:translateX(-100%);box-shadow:4px 0 24px rgba(0,0,0,.5)}
  .sb.open{transform:translateX(0)}
  .sb.collapsed{width:260px;transform:translateX(-100%)}
  .sb.collapsed.open{transform:translateX(0)}
  .sb-ov.open{display:block}
  /* Main layout */
  .mw{margin-left:0}
  .mw.expanded{margin-left:0}
  .tb{padding:0 12px;height:52px}
  .pb{padding:12px}
  /* Tables: horizontal scroll */
  .table-responsive{overflow-x:auto;-webkit-overflow-scrolling:touch}
  .table td,.table th{white-space:nowrap;font-size:12px!important;padding:9px 10px!important}
  /* Cards */
  .card-header{padding:10px 14px!important;font-size:10px!important;flex-wrap:wrap;gap:6px}
  .card-header .btn{font-size:11px!important;padding:5px 10px!important}
  .p-4{padding:14px!important}
  .p-3{padding:10px!important}
  /* KPIs */
  .kpi{padding:12px 14px}
  .kpi-ico{width:34px;height:34px;font-size:15px;margin-bottom:6px}
  .kpi-v{font-size:18px!important}
  .kpi-l{font-size:10px!important}
  /* Buttons */
  .btn{font-size:12px;padding:7px 12px}
  .btn-ico{width:30px;height:30px}
  /* Nav tabs: scroll horizontal */
  .nav-tabs{overflow-x:auto;overflow-y:hidden;flex-wrap:nowrap;-webkit-overflow-scrolling:touch;scrollbar-width:none}
  .nav-tabs::-webkit-scrollbar{display:none}
  .nav-tabs .nav-link{white-space:nowrap;padding:8px 12px;font-size:11px}
  /* Modals full-screen on mobile */
  .modal-dialog{margin:8px;max-width:calc(100vw - 16px)}
  .modal-content{border-radius:10px!important}
  /* Forms */
  .form-control,.form-select{font-size:16px!important} /* prevent iOS zoom */
  /* Avatar smaller */
  .ava{width:32px;height:32px;font-size:12px}
  /* Topbar actions wrap */
  .tb>div:last-child{gap:6px!important}
  /* Badge in nav */
  .nb{font-size:9px;padding:1px 5px}
  /* Sidebar footer */
  .sb-foot{padding:10px 12px}
  /* Page body padding */
  .row{--bs-gutter-x:.75rem}
}

/* SMALL MOBILE (≤480px) */
@media(max-width:480px){
  .pb{padding:8px}
  .kpi-v{font-size:16px!important}
  .card-header{font-size:9px!important}
  .btn{font-size:11px;padding:6px 10px}
  .table td,.table th{font-size:11px!important;padding:7px 8px!important}
  /* Stack flex items */
  .d-flex.gap-3,.d-flex.gap-4{gap:8px!important}
  .tb-title{font-size:12px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
}
/* ══ GLOBAL WHITE TEXT OVERRIDE ══ */
body{color:var(--t)!important}
.card *{color:inherit}.card,.p-4,.p-3{color:var(--t)}
.card-header *{color:var(--t)!important}
.tab-content *,.modal-content *{color:inherit}
small,.small,.text-muted,.text-secondary{color:var(--t2)!important}
.text-dark,.text-body,.text-black{color:var(--t)!important}
.form-label{color:#51607A!important}.form-check-label{color:var(--t)!important}
.table td,.table th,.table td *,.table th *{color:var(--t)!important}
.nav-tabs .nav-link{color:var(--t2)!important}.nav-tabs .nav-link.active{color:var(--c)!important}
::placeholder{color:var(--t3)!important;opacity:1}
details summary{color:var(--c)}
.card .d-flex>span:not([class]){color:var(--t)!important}
.card p,.card li,.card strong,.card b{color:var(--t)}
.sb-sec{color:var(--t3)!important}
a:not([class]){color:var(--c)}

/* ══ MOBILE UTILITIES ══════════════════════════════════════ */
/* Scrollable horizontal tables */
.table-responsive{-webkit-overflow-scrolling:touch}
/* Touch-friendly buttons */
@media(hover:none){.btn:hover{opacity:.9}}
/* Full-width buttons on mobile */
@media(max-width:576px){
  .btn-block-xs{width:100%!important;margin-bottom:6px}
  .gap-xs-0{gap:0!important}
  /* Forms full width */
  .form-control,.form-select,.input-group{width:100%}
  /* Card padding reduce */
  .card>.p-4{padding:12px!important}
  /* KPI value font */
  .kpi-v{font-size:16px!important}
  /* Stack topbar actions vertically if too many */
  .tb>div:last-child .btn{font-size:11px;padding:5px 8px}
  /* Table: auto-scroll hint */
  .table-responsive::after{
    content:'→';
    position:absolute;right:8px;top:50%;
    color:rgba(0,184,212,.4);font-size:16px;
    pointer-events:none;
  }
  .table-responsive{position:relative}
}
/* Prevent table from breaking layout */
.table{min-width:unset}
.table td,.table th{word-break:break-word}
/* Scrollable nav-tabs */
.nav-tabs-scroll{overflow-x:auto;overflow-y:hidden;-webkit-overflow-scrolling:touch}
.nav-tabs-scroll .nav-tabs{flex-wrap:nowrap;min-width:max-content}
/* Mobile card actions */
@media(max-width:640px){
  .card-header{flex-direction:column;align-items:flex-start!important;gap:8px!important}
  .card-header .btn,.card-header a.btn{align-self:flex-end}
}
/* Sidebar: active indicator */
.sb-nav a.act::before{content:'';position:absolute;left:0;top:20%;bottom:20%;width:3px;background:var(--c);border-radius:0 2px 2px 0}
/* Bottom safe area for iOS */
@supports(padding-bottom:env(safe-area-inset-bottom)){
  .sb-foot{padding-bottom:calc(12px + env(safe-area-inset-bottom))}
}

/* LIGHT MODE */
[data-theme=light]{--bs-body-color:#1F2937;--bs-body-bg:#F8FAFC;--bs-emphasis-color:#111827;--bg:#F8FAFC;--bg2:#FFFFFF;--bg3:#F1F5F9;--bg4:#F8FAFC;--t:#1F2937;--t2:#5B6675;--t3:#94A3B8;--bd:rgba(0,184,212,.22);--bd2:#E5E7EB}
[data-theme=light] .sb{background:#1E3A5F!important;border-right:1px solid rgba(0,0,0,.10)}
[data-theme=light] .sb-brand{border-bottom:1px solid rgba(255,255,255,.08)}
[data-theme=light] .sb-foot{border-top:1px solid rgba(255,255,255,.08)}
[data-theme=light] .sb-nav a{color:#AEBED2!important}
[data-theme=light] .sb-name,[data-theme=light] .sb-uname{color:#FFFFFF!important}
[data-theme=light] .sb-sec{color:#6E8199!important}
[data-theme=light] .sb-nav a i{color:inherit!important}
[data-theme=light] .sb-nav a:hover{background:rgba(255,255,255,.07)!important;color:#FFFFFF!important}
[data-theme=light] .sb-nav a.act{background:#00B8D4!important;color:#FFFFFF!important;border-color:#00B8D4!important}
[data-theme=light] .sb-nav a.act i{color:#FFFFFF!important}
[data-theme=light] .tb{background:#FFFFFF;border-bottom:1px solid #E5E7EB;box-shadow:0 1px 4px rgba(15,23,42,.04)}
[data-theme=light] .card{background:#FFFFFF!important;border-color:#E5E7EB!important;box-shadow:0 1px 3px rgba(15,23,42,.05)}
[data-theme=light] .card-header{background:#FFFFFF!important;border-bottom:1px solid #EDF1F5!important}
[data-theme=light] .kpi{box-shadow:0 1px 3px rgba(15,23,42,.05)}
[data-theme=light] .table thead th{background:#F1F5F9!important}
[data-theme=light] .form-control,[data-theme=light] .form-select{background:#FFFFFF!important;border-color:#D8DEE6!important;color:#1F2937!important}
[data-theme=light] .form-select option,[data-theme=light] .form-control option,[data-theme=light] select option{background:#FFFFFF!important;color:#1F2937!important}
[data-theme=light] .btn-dk{background:#EEF2F6!important;border-color:#D8DEE6!important;color:#1F2937!important}
[data-theme=light] .mw *{color:var(--t)}
[data-theme=light] .mw small,[data-theme=light] .mw .text-muted{color:var(--t2)!important}
[data-theme=light] #themeBtn{color:#d4900a}
/* Safety: kill any stale mobile nav HTML */
nav#mobileBottomNav{display:none}
@media(max-width:768px){nav#mobileBottomNav{display:flex!important}}
/* Global text visibility */
body{color:var(--t)!important;background:var(--bg)!important}
.mw *{color:var(--t)}
.mw small,.mw .text-muted,.mw .text-secondary{color:var(--t2)!important}
</style>
<?php if(isset($xhead)) echo $xhead; ?>
<script>
function applyTheme(t){
  document.documentElement.setAttribute('data-theme',t==='light'?'light':'');
  var btn=document.getElementById('themeBtn');
  if(btn){btn.innerHTML=t==='light'?'&#9790;':'&#9728;';btn.title=t==='light'?'Modo oscuro':'Modo claro';}
  localStorage.setItem('dentsys_theme',t);
}
function toggleTheme(){
  var cur=localStorage.getItem('dentsys_theme')||'light';
  applyTheme(cur==='dark'?'light':'dark');
}
document.addEventListener('DOMContentLoaded',function(){
  var t=localStorage.getItem('dentsys_theme')||'light';
  var btn=document.getElementById('themeBtn');
  if(btn){btn.innerHTML=t==='light'?'&#9790;':'&#9728;';}
});
</script>
</head>
<body>
<div class="sb-ov" id="sbOv" onclick="sbT()"></div>
<nav class="sb" id="sb">
 <div class="sb-brand">
  <div class="sb-logo">🦷</div>
  <div class="sb-name">DentalSys-Magus<small>Clínica Odontológica</small></div>
 </div>
 <div class="sb-nav">
  <?php $r=getRol(); $p=$pagina_activa; ?>
  <?php if(puedeVer('dashboard')): ?>
  <div class="sb-sec">Principal</div>
  <a href="<?=BASE_URL?>/index.php" class="<?=$p==='dash'?'act':''?>"><i class="bi bi-grid-fill"></i>Dashboard</a>
  <?php endif; ?>

  <?php if(puedeVer('pacientes')||puedeVer('citas')||puedeVer('historia_clinica')||puedeVer('odontograma')): ?>
  <div class="sb-sec">Atención</div>
  <?php if(puedeVer('pacientes')): ?><a href="<?=BASE_URL?>/pages/pacientes.php" class="<?=$p==='pac'?'act':''?>"><i class="bi bi-people-fill"></i>Pacientes</a><?php endif; ?>
  <?php if(puedeVer('citas')): ?><a href="<?=BASE_URL?>/pages/citas.php" class="<?=$p==='citas'?'act':''?>"><i class="bi bi-calendar2-week-fill"></i>Agenda / Citas</a><?php endif; ?>
  <?php if(puedeVer('historia_clinica')): ?><a href="<?=BASE_URL?>/pages/historia_clinica.php" class="<?=$p==='hc'?'act':''?>"><i class="bi bi-file-medical-fill"></i>Historia Clínica</a><?php endif; ?>
  <?php if(puedeVer('odontograma')): ?><a href="<?=BASE_URL?>/pages/odontograma_lista.php" class="<?=$p==='odont'?'act':''?>"><i class="bi bi-grid-3x3-gap-fill"></i>Odontograma</a><?php endif; ?>
  <?php if(puedeVer('cefalometria')): ?><a href="<?=BASE_URL?>/pages/cefalometria.php" class="<?=$p==='cefalo'?'act':''?>"><i class="bi bi-rulers"></i>Cefalometría</a><?php endif; ?>
  <?php if(puedeVer('endodoncia')): ?><a href="<?=BASE_URL?>/pages/endodoncia.php" class="<?=$p==='endo'?'act':''?>"><i class="bi"><svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="vertical-align:-2px"><path d="M8 1.6c-1 0-1.5.45-2.6.45S3.55 1.6 2.7 2.1C1.45 2.85 1.05 4.3 1.05 5.75c0 1.5.45 2.75.9 4.2.3 1 .4 2.05.65 3.05.2.78.42 1.65 1.12 1.85.78.22 1.05-.85 1.18-1.55.2-.95.28-2 .58-2.85.1-.35.28-.75.85-.75s.75.4.85.75c.3.85.38 1.9.58 2.85.13.7.4 1.77 1.18 1.55.7-.2.92-1.07 1.12-1.85.25-1 .35-2.05.65-3.05.45-1.45.9-2.7.9-4.2 0-1.45-.4-2.9-1.65-3.65C12.6 1.6 11.7 2.05 10.6 2.05S9 1.6 8 1.6z"/></svg></i>Endodoncia</a><?php endif; ?>
  <?php if(puedeVer('google_calendar')): ?><a href="<?=BASE_URL?>/pages/google_calendar.php" class="<?=$p==='gcal'?'act':''?>"><i class="bi bi-google"></i>Google Calendar</a><?php endif; ?>
  <?php endif; ?>

  <?php if(puedeVer('tratamientos')||puedeVer('facturacion')||puedeVer('inventario')||puedeVer('notificaciones')||puedeVer('turnos')): ?>
  <div class="sb-sec">Clínica</div>
  <?php if(puedeVer('tratamientos')): ?><a href="<?=BASE_URL?>/pages/tratamientos.php" class="<?=$p==='trat'?'act':''?>"><i class="bi bi-clipboard2-pulse-fill"></i>Tratamientos</a><?php endif; ?>
  <?php if(puedeVer('presupuestos')): ?><a href="<?=BASE_URL?>/pages/presupuestos.php" class="<?=$p==='presup'?'act':''?>"><i class="bi bi-receipt"></i>Presupuestos</a><?php endif; ?>
  <?php if(puedeVer('facturacion')): ?><a href="<?=BASE_URL?>/pages/facturacion.php" class="<?=$p==='fact'?'act':''?>"><i class="bi bi-cash-coin"></i>Facturación</a><?php endif; ?>
  <?php if(puedeVer('facturacion')): ?><a href="<?=BASE_URL?>/pages/notas_credito.php" class="<?=$p==='notas'?'act':''?>" data-title="Notas Crédito/Débito"><i class="bi bi-file-earmark-minus"></i>Notas Créd./Déb.</a><?php endif; ?>
  <?php if(puedeVer('inventario')): ?>
  <a href="<?=BASE_URL?>/pages/inventario.php" class="<?=$p==='inv'?'act':''?>">
   <i class="bi bi-box-seam-fill"></i>Inventario
   <?php try{$si=db()->query("SELECT COUNT(*) FROM inventario WHERE stock_actual<=stock_minimo AND activo=1")->fetchColumn();if($si>0) echo "<span class='nb'>$si</span>";}catch(Exception $e){} ?>
  </a>
  <?php endif; ?>
  <?php if(puedeVer('notificaciones')): ?><a href="<?=BASE_URL?>/pages/notificaciones.php" class="<?=$p==='notif'?'act':''?>"><i class="bi bi-whatsapp"></i>WhatsApp / Notif.</a><?php endif; ?>
  <?php if(puedeVer('notificaciones')): ?><a href="<?=BASE_URL?>/pages/whatsapp_conexion.php" class="<?=$p==='wacon'?'act':''?>"><i class="bi bi-link-45deg"></i>Conexión WhatsApp</a><?php endif; ?>
  <?php if(puedeVer('turnos')): ?><a href="<?=BASE_URL?>/pages/turnos.php" class="<?=$p==='turnos'?'act':''?>"><i class="bi bi-display"></i>Pantalla Turnos</a><?php endif; ?>
  <?php endif; ?>

  <?php if(puedeVer('reportes')): ?>
  <div class="sb-sec">Reportes</div>
  <a href="<?=BASE_URL?>/pages/reportes.php" class="<?=$p==='rep'?'act':''?>"><i class="bi bi-bar-chart-fill"></i>Reportes</a>
  <?php endif; ?>

  <?php if(esRol('admin')): ?>
  <div class="sb-sec">Sistema</div>
  <a href="<?=BASE_URL?>/pages/admin/empresa.php" class="<?=$p==='empresa'?'act':''?>"><i class="bi bi-building-fill"></i>Empresa</a>
<a href="<?=BASE_URL?>/pages/admin/reservas.php" class="<?=$p==='reservas'?'act':''?>"><i class="bi bi-calendar-check-fill"></i>Reservas online</a>
<a href="<?=BASE_URL?>/pages/admin/reservas_online.php" class="<?=($p==='reservas_online'||$p==='reservas')?'act':''?>"><i class="bi bi-calendar2-check-fill"></i>Confirmar online</a>
  <a href="<?=BASE_URL?>/pages/admin/sedes.php" class="<?=$p==='sedes'?'act':''?>"><i class="bi bi-geo-alt-fill"></i>Sedes</a>
  <a href="<?=BASE_URL?>/pages/admin/documentos.php" class="<?=$p==='docs'?'act':''?>"><i class="bi bi-list-ol"></i>Series y Correlativos</a>
  <a href="<?=BASE_URL?>/pages/admin/usuarios.php" class="<?=$p==='usr'?'act':''?>"><i class="bi bi-person-badge-fill"></i>Usuarios / Roles</a>
  <a href="<?=BASE_URL?>/pages/admin/roles.php" class="<?=$p==='roles'?'act':''?>"><i class="bi bi-shield-lock-fill"></i>Permisos por Rol</a>
  <a href="<?=BASE_URL?>/pages/admin/configuracion.php" class="<?=$p==='cfg'?'act':''?>"><i class="bi bi-gear-fill"></i>Configuración</a>
  <a href="<?=BASE_URL?>/pages/admin/satisfaccion.php" class="<?=$p==='satisf'?'act':''?>"><i class="bi bi-star-fill"></i>Satisfacción</a>
  <a href="<?=BASE_URL?>/pages/admin/auditoria.php" class="<?=$p==='audit'?'act':''?>"><i class="bi bi-shield-check-fill"></i>Auditoría SIHCE</a>
  <?php endif; ?>
 </div>
 <div class="sb-foot">
  <?php $su=getUsr(); ?>
  <div class="d-flex align-items-center gap-2 mb-1">
   <div class="ava" style="width:30px;height:30px;font-size:11px"><?=strtoupper(substr($u['nombre']??'A',0,1))?></div>
   <div><div class="sb-uname"><?=e($u['nombre']??'')?></div><div class="sb-urole"><?=e(getRol())?></div></div>
  </div>
  <a href="<?=BASE_URL?>/logout.php" class="btn-out"><i class="bi bi-box-arrow-left me-1"></i>Cerrar sesión</a>
 </div>
</nav>
<div class="mw">
 <div class="tb">
  <div class="d-flex align-items-center gap-2">
   <button class="sb-toggle" id="sbToggleBtn"><i class="bi bi-list"></i></button>
   <span class="tb-title"><?=e($titulo??'')?></span>
   <?php include __DIR__.'/sede_switcher.php'; ?>
  </div>
  <div class="d-flex gap-2 align-items-center">
   <?php
   try{$ch=db()->query("SELECT COUNT(*) FROM citas WHERE fecha=CURDATE() AND estado='pendiente'")->fetchColumn();
   if($ch>0) echo "<a href='".BASE_URL."/pages/citas.php' class='badge ba' style='text-decoration:none;font-size:11px'>📅 $ch cita".($ch>1?'s':'')." pendiente".($ch>1?'s':'')."</a>";}catch(Exception $e){}
   echo '<button onclick="toggleTheme()" id="themeBtn" class="btn btn-dk btn-ico" title="Modo claro/oscuro" style="font-size:17px">☀️</button> ';
   if(isset($topbar_act)) echo $topbar_act;
   ?>
  </div>
 </div>
 <div class="pb">
  <?= popFlash() ?>
  <?php
  // ── ALERTAS AUTOMÁTICAS (vencidos + próximos 3 días) ──
  // (solo en módulos que lo necesiten — aquí solo si hay pagos, para dental es citas/historial)
  ?>
