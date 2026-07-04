<?php
/* ============================================================================
 * Vida OdontoFresh — Recordatorios de WhatsApp (cron)
 * ----------------------------------------------------------------------------
 * Envía por WhatsApp (microservicio Baileys local, vía wa_notify.php):
 *   - Recordatorio de cita: un día antes y/o el mismo día.
 *   - Saludo de cumpleaños: el día del cumpleaños del paciente.
 * No duplica: registra en la tabla `notificaciones`.
 *
 * INSTALAR EN CRON (ejecuta cada día, ej. 9:00 am):
 *   crontab -e
 *   0 9 * * *  /usr/bin/php /ruta/a/dental/cron_recordatorios_wa.php >> /var/log/odonto_wa.log 2>&1
 * Ajusta /ruta/a/dental/ a la ruta real de esta clínica.
 * ==========================================================================*/
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/wa_notify.php';

$clinica   = getCfg('clinica_nombre', 'la clínica');
$telCli    = getCfg('clinica_telefono', '');
$tplCita   = getCfg('plantilla_wa_cita', 'Estimado(a) *{nombre}*, le recordamos su cita en *{clinica}* el *{fecha}* a las *{hora}*. Ante consultas: {telefono}');
$tplCumple = getCfg('plantilla_wa_cumple', '¡Feliz cumpleaños, *{nombre}*! 🎉 De parte de *{clinica}* te deseamos un gran día. 🦷');

$autoCita   = getCfg('wa_auto_cita', '1') === '1';
$autoCumple = getCfg('wa_auto_cumple', '1') === '1';
$ofs1d      = getCfg('wa_cita_1dia', '1') === '1';
$ofsHoy     = getCfg('wa_cita_hoy', '1') === '1';

function wn_yaEnviado(string $refTipo, int $refId, bool $porAno = false): bool {
    $sql = "SELECT COUNT(*) FROM notificaciones WHERE referencia_tipo=? AND referencia_id=? AND estado='enviado'";
    if ($porAno) $sql .= " AND YEAR(created_at)=YEAR(CURDATE())";
    $q = db()->prepare($sql); $q->execute([$refTipo, $refId]);
    return (int)$q->fetchColumn() > 0;
}
function wn_log(string $refTipo, int $refId, string $tel, string $msg, bool $ok): void {
    db()->prepare("INSERT INTO notificaciones(tipo,destinatario,asunto,mensaje,estado,referencia_tipo,referencia_id) VALUES('whatsapp',?,?,?,?,?,?)")
        ->execute([$tel, 'WhatsApp automático', $msg, $ok ? 'enviado' : 'error', $refTipo, $refId]);
}

$envios = 0; $errores = 0;

/* ---- Recordatorios de cita ---- */
if ($autoCita) {
    $jobs = [];
    if ($ofs1d)  $jobs['cita_1d']  = "DATE_ADD(CURDATE(),INTERVAL 1 DAY)";
    if ($ofsHoy) $jobs['cita_hoy'] = "CURDATE()";
    foreach ($jobs as $refTipo => $fechaSql) {
        $cs = db()->query("SELECT c.id, c.fecha, c.hora_inicio, CONCAT(p.nombres,' ',p.apellido_paterno) pac, p.telefono
                           FROM citas c JOIN pacientes p ON c.paciente_id=p.id
                           WHERE c.fecha=$fechaSql AND c.estado IN('pendiente','confirmado')
                             AND p.telefono IS NOT NULL AND p.telefono<>''")->fetchAll();
        foreach ($cs as $c) {
            if (wn_yaEnviado($refTipo, (int)$c['id'])) continue;
            $msg = wa_plantilla($tplCita, [
                '{nombre}'   => $c['pac'],
                '{clinica}'  => $clinica,
                '{fecha}'    => fDate($c['fecha']),
                '{hora}'     => substr($c['hora_inicio'], 0, 5),
                '{telefono}' => $telCli,
            ]);
            $ok = wa_enviar($c['telefono'], $msg);
            wn_log($refTipo, (int)$c['id'], $c['telefono'], $msg, $ok);
            $ok ? $envios++ : $errores++;
        }
    }
}

/* ---- Saludos de cumpleaños ---- */
if ($autoCumple) {
    $ps = db()->query("SELECT id, CONCAT(nombres,' ',apellido_paterno) pac, telefono FROM pacientes
                       WHERE activo=1 AND deleted_at IS NULL
                         AND telefono IS NOT NULL AND telefono<>''
                         AND fecha_nacimiento IS NOT NULL
                         AND MONTH(fecha_nacimiento)=MONTH(CURDATE()) AND DAY(fecha_nacimiento)=DAY(CURDATE())")->fetchAll();
    foreach ($ps as $p) {
        if (wn_yaEnviado('cumple', (int)$p['id'], true)) continue;
        $msg = wa_plantilla($tplCumple, ['{nombre}' => $p['pac'], '{clinica}' => $clinica, '{telefono}' => $telCli]);
        $ok = wa_enviar($p['telefono'], $msg);
        wn_log('cumple', (int)$p['id'], $p['telefono'], $msg, $ok);
        $ok ? $envios++ : $errores++;
    }
}

echo date('Y-m-d H:i') . " — Recordatorios/cumpleaños enviados: $envios | Errores: $errores\n";
