<?php
// ============================================================
// api/mitglieder_login.php  –  POST: Mitglieder-Authentifizierung
// Aktionen: login, aktivieren, pw_reset_request, pw_reset_ausfuehren
// ============================================================
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/mitglieder_auth.php';
require_once __DIR__ . '/../_backend/mail_mitglieder.php';
send_security_headers();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Nur POST erlaubt.', 405);
}

$action = trim($_POST['action'] ?? '');

switch ($action) {

    // ── Login ─────────────────────────────────────────────────────
    case 'login':
        if (!rate_limit_ok('mk_login')) {
            json_err('Zu viele Anmeldeversuche. Bitte 15 Minuten warten.', 429);
        }
        $email    = clean_email($_POST['email']    ?? '');
        $password = $_POST['password'] ?? '';
        if (!$email || !$password) {
            json_err('E-Mail und Passwort sind Pflichtfelder.');
        }
        $m = mitglied_login($email, $password);
        if (!$m) {
            // Ohne diesen Aufruf zählt rate_limit_ok('mk_login') oben nie
            // etwas hoch, da audit_log dafür sonst nirgends befüllt wird.
            audit('mk_login', null, "E-Mail-Hash: " . substr(hash('sha256', $email), 0, 16));
            json_err('E-Mail oder Passwort falsch, oder Konto nicht aktiviert.', 401);
        }
        mitglied_session_erstellen((int)$m['id'], (int)$m['tk_id']);
        json_ok(['vorname' => $m['vorname']]);
        break;

    // ── Einladung aktivieren ──────────────────────────────────────
    case 'aktivieren':
        $token    = trim($_POST['token']    ?? '');
        $password = $_POST['password']      ?? '';
        $pw2      = $_POST['password2']     ?? '';
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            json_err('Ungültiger Token.');
        }
        if ($fehler = password_ist_sicher($password)) {
            json_err($fehler);
        }
        if ($password !== $pw2) {
            json_err('Passwörter stimmen nicht überein.');
        }
        if (!mitglied_einladung_aktivieren($token, $password)) {
            json_err('Der Einladungslink ist ungültig oder abgelaufen.');
        }
        json_ok(['message' => 'Konto aktiviert. Sie können sich jetzt einloggen.']);
        break;

    // ── Passwort-Reset anfordern ──────────────────────────────────
    case 'pw_reset_request':
        $email = clean_email($_POST['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_err('Ungültige E-Mail-Adresse.');
        }
        $result = mitglied_pw_reset_token_erstellen($email);
        if ($result) {
            mail_pw_reset_mitglied($email, $result['vorname'], $result['token']);
        }
        json_ok(['message' => 'Falls ein Konto mit dieser E-Mail existiert, wurde eine E-Mail versendet.']);
        break;

    // ── Passwort-Reset ausführen ──────────────────────────────────
    case 'pw_reset_ausfuehren':
        $token    = trim($_POST['token']    ?? '');
        $password = $_POST['password']      ?? '';
        $pw2      = $_POST['password2']     ?? '';
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            json_err('Ungültiger Token.');
        }
        if ($fehler = password_ist_sicher($password)) {
            json_err($fehler);
        }
        if ($password !== $pw2) {
            json_err('Passwörter stimmen nicht überein.');
        }
        if (!mitglied_pw_reset_ausfuehren($token, $password)) {
            json_err('Der Reset-Link ist ungültig oder abgelaufen.');
        }
        json_ok(['message' => 'Passwort erfolgreich geändert.']);
        break;

    default:
        json_err('Unbekannte Aktion.', 400);
}
