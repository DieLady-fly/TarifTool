<?php
// ============================================================
// api/meine_antraege.php
// GET: Gibt alle Anträge des eingeloggten Mitglieds zurück,
//      gruppiert mit Tage-Array pro Antrag.
// ============================================================
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/mitglieder_auth.php';
send_security_headers();
session_start_secure();
header('Content-Type: application/json; charset=utf-8');

$mitglied = mitglied_aus_session();
if (!$mitglied) {
    json_err('Nicht eingeloggt.', 401);
}

$antraege = mitglied_antraege_laden((int)$mitglied['id']);

json_ok($antraege);
