<?php
require_once __DIR__.'/../includes/config.php';
requiereLogin();
$titulo='Reportes y Analítica'; $pagina_activa='rep';

$mes = $_GET['mes'] ?? date('Y-m');
// Validate format
if (!preg_match('/^\d{4}-\d{2}$/', $mes)) $mes = date('Y-m');
$y = (int)substr($mes, 0, 4);
$m = (int)substr($mes, 5, 2);

// ── KPIs del mes ────────────────────────────────────────────
$s = db()->prepare("SELECT COALESCE(SUM(total),0) FROM pagos WHERE MONTH(fecha)=? AND YEAR(fecha)=? AND estado='pagado'");
$s->execute([$m, $y]); $ing_mes = (float)$s->fetchColumn();

$s = db()->prepare("SELECT COUNT(*) FROM citas WHERE MONTH(fecha)=? AND YEAR(fecha)=? AND estado='atendido'");
$s->execute([$m, $y]); $cit_atend = (int)$s->fetchColumn();

$s = db()->prepare("SELECT COUNT(DISTINCT paciente_id) FROM citas WHERE MONTH(fecha)=? AND YEAR(fecha)=?");
$s->execute([$m, $y]); $pacs_mes = (int)$s->fetchColumn();

$s = db()->prepare("SELECT COUNT(*) FROM pacientes WHERE MONTH(created_at)=? AND YEAR(created_at)=?");
$s->execute([$m, $y]); $pacs_nuevos = (int)$s->fetchColumn();

// ── Ingresos por día del mes ─────────────────────────────────
$dias_labels = [];
$dias_valores = [];
// Use date arithmetic instead of cal_days_in_month (requires calendar extension)
$dias_en_mes = (int)date('t', mktime(0,0,0,$m,1,$y));
for ($d = 1; $d <= $dias_en_mes; $d++) {
    $fecha = sprintf('%04d-%02d-%02d', $y, $m, $d);
    $s = db()->prepare("SELECT COALESCE(SUM(total),0) FROM pagos WHERE DATE(fecha)=? AND estado='pagado'");
    $s->execute([$fecha]);
    $dias_labels[] = $d;
    $dias_valores[] = (float)$s->fetchColumn();
}

// ── Top tratamientos ─────────────────────────────────────────
$top_trats = db()->query("
    SELECT pd.nombre_tratamiento, COUNT(*) AS cnt, SUM(pd.precio) AS total
    FROM plan_detalles pd
    JOIN planes_tratamiento pt ON pd.plan_id = pt.id
    WHERE pd.estado = 'completado'
    GROUP BY pd.nombre_tratamiento
    ORDER BY total DESC LIMIT 8
")->fetchAll();

// ── Top doctores ─────────────────────────────────────────────
$top_drs = db()->query("
    SELECT CONCAT(u.nombre,' ',u.apellidos) AS dr,
    COUNT(c.id) AS citas,
    COALESCE(SUM(pg.total),0) AS ingresos
    FROM usuarios u
    LEFT JOIN citas c ON c.doctor_id=u.id AND c.estado='atendido'
    LEFT JOIN pagos pg ON pg.cita_id=c.id AND pg.estado='pagado'
    WHERE u.rol_id=2 AND u.activo=1
    GROUP BY u.id ORDER BY ingresos DESC
")->fetchAll();

// ── Métodos de pago del mes ──────────────────────────────────
$s = db()->prepare("SELECT metodo, COUNT(*) AS cnt, SUM(total) AS total FROM pagos WHERE MONTH(fecha)=? AND YEAR(fecha)=? AND estado='pagado' GROUP BY metodo ORDER BY total DESC");
$s->execute([$m, $y]); $metodos = $s->fetchAll();

// ── Pacientes frecuentes ─────────────────────────────────────
$pacs_freq = db()->query("
    SELECT CONCAT(p.nombres,' ',p.apellido_paterno) AS pac,
    p.telefono, COUNT(c.id) AS visitas
    FROM pacientes p
    JOIN citas c ON c.paciente_id=p.id
    WHERE c.estado='atendido'
    GROUP BY p.id ORDER BY visitas DESC LIMIT 8
")->fetchAll();

// ── Citas por estado del mes ─────────────────────────────────
$s = db()->prepare("SELECT estado, COUNT(*) AS cnt FROM citas WHERE MONTH(fecha)=? AND YEAR(fecha)=? GROUP BY estado");
$s->execute([$m, $y]); $citas_estados = $s->fetchAll();

require_once __DIR__.'/../includes/header.php';
?>

<form method="GET" class="d-flex gap-2 mb-4 align-items-end flex-wrap row-gap-2">
    <div>
        <label class="form-label">Período</label>
        <input type="month" name="mes" class="form-control" value="<?= e($mes) ?>">
    </div>
    <button type="submit" class="btn btn-dk" style="margin-top:20px">Ver reporte</button>
    <a href="?" class="btn btn-dk" style="margin-top:20px">Este mes</a>
    <span class="ms-auto" style="color:var(--t2);font-size:13px;align-self:flex-end">
        <?= date('F Y', mktime(0,0,0,$m,1,$y)) ?>
    </span>
</form>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="kpi kg">
            <div class="kpi-ico"><i class="bi bi-cash-coin"></i></div>
            <div class="kpi-v mon" style="font-size:17px"><?= mon($ing_mes) ?></div>
            <div class="kpi-l">Ingresos del mes</div>
            <div class="kpi-s"></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="kpi kc">
            <div class="kpi-ico"><i class="bi bi-calendar-check-fill"></i></div>
            <div class="kpi-v"><?= $cit_atend ?></div>
            <div class="kpi-l">Citas atendidas</div>
            <div class="kpi-s"></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="kpi ka">
            <div class="kpi-ico"><i class="bi bi-people-fill"></i></div>
            <div class="kpi-v"><?= $pacs_mes ?></div>
            <div class="kpi-l">Pacientes atendidos</div>
            <div class="kpi-s"></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="kpi kb">
            <div class="kpi-ico"><i class="bi bi-person-plus-fill"></i></div>
            <div class="kpi-v"><?= $pacs_nuevos ?></div>
            <div class="kpi-l">Pacientes nuevos</div>
            <div class="kpi-s"></div>
        </div>
    </div>
</div>

<!-- Gráfico ingresos diarios -->
<div class="card mb-4">
    <div class="card-header">
        <span style="color:var(--t)"><i class="bi bi-graph-up me-2"></i>Ingresos diarios — <?= date('F Y', mktime(0,0,0,$m,1,$y)) ?></span>
    </div>
    <div class="p-4">
        <canvas id="chartDias" style="max-height:200px"></canvas>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Top tratamientos -->
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><span style="color:var(--t)"><i class="bi bi-clipboard2-pulse me-2"></i>Tratamientos más realizados</span></div>
            <div class="p-4">
                <?php if ($top_trats): ?>
                <canvas id="chartTrats" height="220"></canvas>
                <?php else: ?>
                <div class="text-center py-4" style="color:var(--t2)">
                    <i class="bi bi-clipboard2" style="font-size:32px;display:block;margin-bottom:8px"></i>
                    Sin tratamientos completados aún
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Métodos de pago -->
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><span style="color:var(--t)"><i class="bi bi-credit-card me-2"></i>Métodos de pago del mes</span></div>
            <div class="p-4">
                <?php if ($metodos):
                    $total_met = array_sum(array_column($metodos, 'total')); ?>
                <canvas id="chartMet" style="max-height:180px"></canvas>
                <div class="mt-3">
                    <?php foreach ($metodos as $met):
                        $pct = $total_met > 0 ? round($met['total'] / $total_met * 100) : 0; ?>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span style="color:var(--t);font-size:12px"><?= strtoupper($met['metodo']) ?></span>
                        <div class="d-flex gap-2 align-items-center">
                            <div style="width:80px;height:5px;background:var(--bg3);border-radius:3px">
                                <div style="width:<?= $pct ?>%;height:100%;background:var(--c);border-radius:3px"></div>
                            </div>
                            <span class="mon" style="color:var(--t);font-size:12px"><?= mon((float)$met['total']) ?></span>
                            <span style="color:var(--t2);font-size:11px">(<?= $met['cnt'] ?>)</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center py-4" style="color:var(--t2)">Sin pagos registrados este mes</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Top doctores -->
    <div class="col-12 col-lg-6">
        <div class="card">
            <div class="card-header"><span style="color:var(--t)"><i class="bi bi-person-badge me-2"></i>Productividad por doctor</span></div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr><th>Doctor</th><th>Citas atendidas</th><th>Ingresos generados</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($top_drs as $dr): ?>
                    <tr>
                        <td><strong><?= e($dr['dr']) ?></strong></td>
                        <td><span class="badge bc"><?= $dr['citas'] ?></span></td>
                        <td class="mon fw-bold" style="color:var(--c)"><?= mon((float)$dr['ingresos']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$top_drs): ?>
                    <tr><td colspan="3" class="text-center py-3" style="color:var(--t2)">Sin datos</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Pacientes frecuentes -->
    <div class="col-12 col-lg-6">
        <div class="card">
            <div class="card-header"><span style="color:var(--t)"><i class="bi bi-star-fill me-2"></i>Pacientes más frecuentes</span></div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr><th>Paciente</th><th>Teléfono</th><th>Visitas</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pacs_freq as $pf): ?>
                    <tr>
                        <td><strong><?= e($pf['pac']) ?></strong></td>
                        <td><small><?= e($pf['telefono'] ?? '—') ?></small></td>
                        <td><span class="badge bg"><?= $pf['visitas'] ?> visita<?= $pf['visitas'] > 1 ? 's' : '' ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$pacs_freq): ?>
                    <tr><td colspan="3" class="text-center py-3" style="color:var(--t2)">Sin datos</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
// Build chart data safely in PHP, output as JSON
$dias_json_labels = json_encode($dias_labels);
$dias_json_values = json_encode($dias_valores);
$trats_labels = json_encode(array_map(fn($t) => mb_substr($t['nombre_tratamiento'], 0, 28), $top_trats));
$trats_values = json_encode(array_map(fn($t) => (float)$t['total'], $top_trats));
$met_labels    = json_encode(array_map(fn($m) => strtoupper($m['metodo']), $metodos));
$met_values    = json_encode(array_map(fn($m) => (float)$m['total'], $metodos));
?>

<script>
// Chart.js v4 — ingresos diarios
new Chart(document.getElementById('chartDias'), {
    type: 'bar',
    data: {
        labels: <?= $dias_json_labels ?>,
        datasets: [{
            label: 'Ingresos S/',
            data: <?= $dias_json_values ?>,
            backgroundColor: 'rgba(0,212,238,.65)',
            borderRadius: 4,
            borderSkipped: false
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { callback: v => 'S/'+v, font: { size: 10 }, color: '#A0B0C0' }, grid: { color: 'rgba(255,255,255,.05)' } },
            x: { grid: { display: false }, ticks: { font: { size: 9 }, color: '#A0B0C0' } }
        }
    }
});

<?php if ($top_trats): ?>
// Tratamientos — horizontal bar (indexAxis: 'y' in Chart.js v3/v4)
new Chart(document.getElementById('chartTrats'), {
    type: 'bar',
    data: {
        labels: <?= $trats_labels ?>,
        datasets: [{
            label: 'S/ Total',
            data: <?= $trats_values ?>,
            backgroundColor: 'rgba(139,92,246,.7)',
            borderRadius: 4
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            x: { beginAtZero: true, ticks: { callback: v => 'S/'+v, font: { size: 9 }, color: '#A0B0C0' }, grid: { color: 'rgba(255,255,255,.05)' } },
            y: { ticks: { font: { size: 10 }, color: '#A0B0C0' } }
        }
    }
});
<?php endif; ?>

<?php if ($metodos): ?>
// Métodos de pago — doughnut
new Chart(document.getElementById('chartMet'), {
    type: 'doughnut',
    data: {
        labels: <?= $met_labels ?>,
        datasets: [{
            data: <?= $met_values ?>,
            backgroundColor: ['#00D4EE','#2ECC8E','#F5A623','#E05252','#8B5CF6','#5BA8F5'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                position: 'bottom',
                labels: { font: { size: 10 }, color: '#A0B0C0', padding: 12 }
            }
        },
        cutout: '62%'
    }
});
<?php endif; ?>
</script>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
