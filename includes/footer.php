 </div><!-- .pb -->
</div><!-- .mw -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Toggle sidebar en móvil y desktop
function sbT(){
 const sb=document.getElementById('sb');
 const ov=document.getElementById('sbOv');
 const mw=document.querySelector('.mw');
 
 // En móvil: slide in/out
 if(window.innerWidth <= 768) {
  sb.classList.toggle('open');
  ov.classList.toggle('open');
 } 
 // En desktop: colapsar/expandir
 else {
  sb.classList.toggle('collapsed');
  mw.classList.toggle('expanded');
 }
}

// Event listener para el botón
const toggleBtn = document.getElementById('sbToggleBtn');
if(toggleBtn) {
 toggleBtn.addEventListener('click', function(e) {
  e.preventDefault();
  sbT();
 });
}

Chart.defaults.color='#A0B0C0';Chart.defaults.borderColor='rgba(0,212,238,.08)';
// Activar tab por URL hash
document.addEventListener('DOMContentLoaded',()=>{
 if(location.hash){const el=document.querySelector(`[data-bs-target="${location.hash}"]`)||document.querySelector(`[href="${location.hash}"]`);if(el&&el.classList.contains('nav-link'))new bootstrap.Tab(el).show();}
});
</script>
<?php if(isset($xscript)) echo $xscript; ?>
</body></html>
