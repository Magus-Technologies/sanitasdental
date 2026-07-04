<?php
/* ============================================================================
 * Vida OdontoFresh — Helper de WhatsApp (microservicio Baileys local)
 * ----------------------------------------------------------------------------
 * Habla con el microservicio (whatsapp-server.js) por HTTP en localhost.
 * No rompe el flujo del sistema si el micro está caído: devuelve false.
 *
 * Configuración (tabla configuracion):
 *   wa_url   = base del micro de ESTA clínica, ej: http://127.0.0.1:3031
 *   wa_token = token secreto (igual al WA_TOKEN del whatsapp-server.js)
 * ==========================================================================*/

if (!function_exists('wa_url')) {

    function wa_url(): string {
        $u = trim((string)getCfg('wa_url', 'http://127.0.0.1:3043'));
        return rtrim($u, '/');
    }
    function wa_token(): string { return trim((string)getCfg('wa_token', 'dental56543787')); }

    /** Normaliza teléfono. Perú: 9 dígitos -> 51XXXXXXXXX */
    function wa_tel(string $t): string {
        $n = preg_replace('/[^0-9]/', '', $t);
        if (strlen($n) === 9) $n = '51' . $n;
        return $n;
    }

    function wa_http(string $metodo, string $ruta, ?array $payload = null): array {
        $ch = curl_init(wa_url() . $ruta);
        $h  = ['Content-Type: application/json'];
        $tok = wa_token(); if ($tok !== '') $h[] = 'x-token: ' . $tok;
        $opt = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $h,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
        ];
        if ($metodo === 'POST') { $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = json_encode($payload ?: [], JSON_UNESCAPED_UNICODE); }
        curl_setopt_array($ch, $opt);
        $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        return ['code' => $code, 'body' => $res, 'err' => $err, 'json' => json_decode((string)$res, true)];
    }

    /** Envía un mensaje. Devuelve true/false (no lanza excepción). */
    function wa_enviar(string $telefono, string $mensaje): bool {
        $n = wa_tel($telefono);
        if ($n === '' || trim($mensaje) === '') return false;
        $r = wa_http('POST', '/send', ['numero' => $n, 'mensaje' => $mensaje]);
        return $r['code'] >= 200 && $r['code'] < 300 && !empty($r['json']['ok']);
    }

    /** Estado + QR del micro: { connected, qr, number } */
    function wa_estado(): array {
        $r = wa_http('GET', '/qr.json');
        if (is_array($r['json'])) return $r['json'];
        return ['connected' => false, 'qr' => null, 'error' => ($r['err'] ?: 'sin_respuesta')];
    }

    /** Desvincula el celular (fuerza nuevo QR). */
    function wa_logout(): bool {
        $r = wa_http('POST', '/logout', []);
        return $r['code'] >= 200 && $r['code'] < 300;
    }

    /** Reemplaza variables {nombre} {clinica} {fecha} {hora} {telefono} en una plantilla */
    function wa_plantilla(string $tpl, array $map): string {
        return str_replace(array_keys($map), array_values($map), $tpl);
    }
}
