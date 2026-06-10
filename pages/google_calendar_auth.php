<?php
/**
 * Google Calendar OAuth Callback
 * URL: /dental/pages/google_calendar_auth.php
 * Google redirige aquí después del login
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/GoogleCalendarService.php';
requiereLogin();

$code  = $_GET['code']  ?? '';
$error = $_GET['error'] ?? '';
$state = $_GET['state'] ?? '';

// ── Error from Google ─────────────────────────────────────────────────────
if ($error) {
    flash('error', 'Acceso denegado a Google Calendar: ' . e($error));
    go('pages/google_calendar.php');
}

// ── Exchange code for token ───────────────────────────────────────────────
if ($code) {
    $tokenData = GoogleCalendarService::exchangeCode($code);
    if (!$tokenData) {
        flash('error', 'Error al obtener el token de Google. Intenta de nuevo.');
        go('pages/google_calendar.php');
    }

    $svc = new GoogleCalendarService((int)$_SESSION['uid']);
    $svc->saveToken($tokenData);

    // Try to get calendars and save the selected one
    $calendars = $svc->listCalendars();
    $_SESSION['gc_calendars'] = $calendars;
    $_SESSION['gc_token_tmp'] = $tokenData;

    if (count($calendars) > 1) {
        // Show calendar picker
        go('pages/google_calendar.php?accion=elegir_calendario');
    } else {
        flash('ok', '✅ Google Calendar conectado correctamente.');
        go('pages/google_calendar.php');
    }
}

flash('error', 'Parámetros inválidos en la respuesta de Google.');
go('pages/google_calendar.php');
