<?php
// ============================================================
// api/status.php  –  Öffentlicher Daten-Endpunkt für die Statusseite
// Liefert JSON. Zugriff NUR über einen exakten, gültigen Token
// (siehe status.php im Root) – kein Login, aber auch keine Auflistung
// oder Teil-/Präfix-Suche möglich.
// ============================================================
require_once __DIR__ . '/../_backend/bootstrap.php';
send_security_headers();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$token = trim($_GET['token'] ?? '');

// Format zuerst prüfen (spart bei offensichtlichem Unsinn die DB-Anfrage)
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    json_err('Ungültiger Link.', 404);
}

// Rate-Limiting pro IP – bremst automatisiertes Durchprobieren zusätzlich
// zur ohnehin praktisch nicht erratbaren 256-Bit-Tokenlänge.
if (!rate_limit_ok('status_abfrage')) {
    json_err('Zu viele Anfragen. Bitte später erneut versuchen.', 429);
}
audit('status_abfrage', null, null);

// Nur die für die Anzeige nötigen Felder – keine E-Mail, kein Nachname,
// keine internen IDs.
$stmt = db()->prepare(
    "SELECT airline, position, flugzeugmuster, veranstaltung,
            zeitraum_von, zeitraum_bis, status, notiz,
            erstellt_am, entschieden_am
     FROM antraege
     WHERE token = ?
     LIMIT 1"
);
$stmt->execute([$token]);
$row = $stmt->fetch();

if (!$row) {
    // Bewusst derselbe generische Fehler wie bei falschem Format –
    // ein Angreifer soll nicht unterscheiden können, ob der Token nur
    // falsch formatiert oder "richtig formatiert, aber unbekannt" ist.
    json_err('Antrag nicht gefunden.', 404);
}

json_ok($row);
