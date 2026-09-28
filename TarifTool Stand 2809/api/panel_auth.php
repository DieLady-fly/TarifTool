<?php
// ============================================================
// api/panel_auth.php
// Token-basierte Aktivierung und Passwort-Reset für Panel-Benutzer.
// Endpunkte:
//   POST action=aktivieren    – Konto per Einladungstoken aktivieren
//   POST action=pw_reset      – Neues Passwort per Reset-Token setzen
// ============================================================
require_once __DIR__ . '/../_backend/bootstrap.php';
send_security_headers();
header('Content-Type: application/json');

$action = trim($_POST['action'] ?? '');
$token  = trim($_POST['token']  ?? '');
$pw     = $_POST['password']  ?? '';
$pw2    = $_POST['password2'] ?? '';

// Token-Format prüfen (64 Hex-Zeichen)
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    json_err('Ungültiger Token.');
}
if ($pw !== $pw2) {
    json_err('Die Passwörter stimmen nicht überein.');
}

// ── Konto aktivieren ─────────────────────────────────────────
if ($action === 'aktivieren') {
    $stmt = db()->prepare(
        "SELECT id, username FROM panel_users
         WHERE einladung_token = ? AND einladung_ablauf > NOW() AND aktiv = 1
         LIMIT 1"
    );
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        json_err('Der Einladungslink ist ungültig oder abgelaufen.');
    }
    // Policy erst hier prüfen (statt vor der Token-Prüfung), da wir ab
    // hier den Benutzernamen kennen und damit den "Passwort = Benutzername"-
    // Fall mit ausschließen können.
    if ($fehler = password_ist_sicher($pw, [$user['username']])) {
        json_err($fehler);
    }
    $hash = password_hash($pw, PASSWORD_DEFAULT);

    db()->prepare(
        "UPDATE panel_users
         SET password_hash    = ?,
             einladung_token  = NULL,
             einladung_ablauf = NULL
         WHERE id = ?"
    )->execute([$hash, $user['id']]);

    json_ok();
}

// ── Passwort zurücksetzen ─────────────────────────────────────
if ($action === 'pw_reset') {
    $stmt = db()->prepare(
        "SELECT id, username FROM panel_users
         WHERE pw_reset_token = ? AND pw_reset_ablauf > NOW() AND aktiv = 1
         LIMIT 1"
    );
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        json_err('Der Reset-Link ist ungültig oder abgelaufen.');
    }
    if ($fehler = password_ist_sicher($pw, [$user['username']])) {
        json_err($fehler);
    }
    $hash = password_hash($pw, PASSWORD_DEFAULT);

    db()->prepare(
        "UPDATE panel_users
         SET password_hash   = ?,
             pw_reset_token  = NULL,
             pw_reset_ablauf = NULL
         WHERE id = ?"
    )->execute([$hash, $user['id']]);

    json_ok();
}

json_err('Unbekannte Aktion.');
