 </div><!-- .pb -->
</div><!-- .mw -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Toggle sidebar
function sbT(){
  var sb=document.getElementById('sb');
  var ov=document.getElementById('sbOv');
  var mw=document.querySelector('.mw');
  if(window.innerWidth<=768){
    sb.classList.toggle('open');
    if(ov) ov.classList.toggle('open');
  } else {
    sb.classList.toggle('collapsed');
    if(mw) mw.classList.toggle('expanded');
  }
}
var toggleBtn=document.getElementById('sbToggleBtn');
if(toggleBtn) toggleBtn.addEventListener('click',function(e){e.preventDefault();sbT();});

if(typeof Chart!=='undefined'){
  Chart.defaults.color='#A0B0C0';
  Chart.defaults.borderColor='rgba(0,212,238,.08)';
}

document.addEventListener('DOMContentLoaded',function(){
  if(location.hash){
    var el=document.querySelector('[data-bs-target="'+location.hash+'"]')||document.querySelector('[href="'+location.hash+'"]');
    if(el&&el.classList.contains('nav-link')) new bootstrap.Tab(el).show();
  }
});
</script>
<?php if(isset($xscript)) echo $xscript; ?>

<script>
/* ── MOBILE BOTTOM NAV ──────────────────────────────────────────
   ONLY runs on screens <= 768px. Uses JS to build and append nav
   so it NEVER appears as static HTML on desktop.
   ──────────────────────────────────────────────────────────── */
(function(){
  // Hard guard: skip entirely on desktop
  if(window.innerWidth > 768) return;

  // Remove any stale nav that might exist from old footer versions
  var old = document.getElementById('mobileBottomNav');
  if(old) old.parentNode.removeChild(old);

  var links = [
    <?php if(puedeVer('dashboard')): ?>
    {href:'<?=BASE_URL?>/index.php', icon:'bi-grid-fill', label:'Inicio', act:<?=$pagina_activa==='dash'?'true':'false'?>},
    <?php endif; ?>
    <?php if(puedeVer('pacientes')): ?>
    {href:'<?=BASE_URL?>/pages/pacientes.php', icon:'bi-people-fill', label:'Pacientes', act:<?=$pagina_activa==='pac'?'true':'false'?>},
    <?php endif; ?>
    <?php if(puedeVer('citas')): ?>
    {href:'<?=BASE_URL?>/pages/citas.php', icon:'bi-calendar2-week-fill', label:'Agenda', act:<?=$pagina_activa==='citas'?'true':'false'?>},
    <?php endif; ?>
    <?php if(puedeVer('facturacion')): ?>
    {href:'<?=BASE_URL?>/pages/facturacion.php', icon:'bi-receipt', label:'Cobros', act:<?=$pagina_activa==='fact'?'true':'false'?>},
    <?php endif; ?>
    {href:'#', icon:'bi-list', label:'Men\u00fa', act:false, menu:true}
  ];

  var nav = document.createElement('nav');
  nav.id  = 'mobileBottomNav';
  nav.setAttribute('style', [
    'display:flex','position:fixed','bottom:0','left:0','right:0','z-index:9999',
    'background:#0E1621','border-top:1px solid rgba(0,212,238,.15)',
    'padding:6px 0 calc(6px + env(safe-area-inset-bottom))',
    'justify-content:space-around','align-items:center'
  ].join(';'));

  links.forEach(function(l){
    var a = document.createElement('a');
    a.href = l.href;
    if(l.menu) a.onclick = function(e){
      e.preventDefault();
      var btn = document.getElementById('sbToggleBtn');
      if(btn) btn.click();
    };
    a.setAttribute('style', [
      'display:flex','flex-direction:column','align-items:center','gap:2px',
      'color:'+(l.act ? '#00D4EE' : 'rgba(160,176,192,.8)'),
      'text-decoration:none','font-size:9px','font-weight:700',
      'padding:4px 8px','min-width:52px','text-align:center',
      'font-family:Nunito,sans-serif','text-transform:uppercase','letter-spacing:.3px'
    ].join(';'));
    a.innerHTML = '<i class="bi '+l.icon+'" style="font-size:18px;display:block;margin-bottom:1px"></i>'+l.label;
    nav.appendChild(a);
  });

  document.body.appendChild(nav);

  // Add bottom padding to page content
  var pb = document.querySelector('.pb');
  if(pb) pb.style.setProperty('padding-bottom','72px','important');
})();
</script>

</body></html>
