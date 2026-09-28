<?php
// ============================================================
// api/antrag_loeschen.php  –  Mitglied löscht eigenen Antrag
// Löschbar sind Anträge in den Status "ausstehend" (noch gar nicht
// bearbeitet) und "freigabe_buero" (intern durch's Büro genehmigt,
// aber noch nicht beim Arbeitgeber beantragt). Sobald der Antrag
// beim Arbeitgeber eingereicht ist (beantragt_ag/genehmigt), ist
// stattdessen nur noch eine Stornierung möglich (api/antrag_storno.php).
// ============================================================
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/mitglieder_auth.php';
send_security_headers();
session_start_secure();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Nur POST erlaubt.', 405);
}

csrf_check();

$mitglied = mitglied_aus_session();
if (!$mitglied) {
    json_err('Nicht eingeloggt.', 401);
}

$antrag_id   = (int)($_POST['antrag_id'] ?? 0);
$mitglied_id = (int)$mitglied['id'];

if (!$antrag_id) json_err('Antrag-ID fehlt.');

// Antrag laden - die Berechtigungsprüfung erfolgt unten ausschließlich
// über mitglied_id. Absichtlich NICHT zusätzlich auf die Heim-TK des
// Mitglieds gefiltert: bei Mehrfachmitgliedschaft (ein Mitglied gehört
// mehreren TKs an) könnte der Antrag zu einer ANDEREN TK des Mitglieds
// gehören als dessen primärer/Heim-TK.
$stmt = db()->prepare(
    "SELECT id, status, mitglied_id, tk_id
     FROM antraege
     WHERE id = ? LIMIT 1"
);
$stmt->execute([$antrag_id]);
$antrag = $stmt->fetch();

if (!$antrag) {
    json_err('Antrag nicht gefunden.');
}

// Nur eigene Anträge dürfen gelöscht werden
if ((int)$antrag['mitglied_id'] !== $mitglied_id) {
    json_err('Sie haben keine Berechtigung diesen Antrag zu löschen.', 403);
}

// TK des ANTRAGS selbst (nicht die Heim-TK des Mitglieds) fürs Audit-Log.
$tk_id = (int)$antrag['tk_id'];

// Löschbare Status: "ausstehend" und "freigabe_buero" (siehe Kommentar
// oben). Ab "beantragt_ag" ist der Antrag beim Arbeitgeber und kann nur
// noch storniert, nicht mehr gelöscht werden.
$loeschbare_status = ['ausstehend', 'freigabe_buero'];
if (!in_array($antrag['status'], $loeschbare_status, true)) {
    json_err('Dieser Antrag kann in seinem aktuellen Status nicht mehr gelöscht werden. Bitte das Büro kontaktieren.');
}

// Eingetragene Tage in der Vergangenheit sind generell nicht mehr
// bearbeitbar - liegt auch nur einer der Tage dieses Antrags bereits in
// der Vergangenheit, ist ein Löschen nicht mehr möglich (Büro kontaktieren).
$past_stmt = db()->prepare(
    "SELECT COUNT(*) FROM antrag_tage WHERE antrag_id = ? AND tag < CURDATE()"
);
$past_stmt->execute([$antrag_id]);
if ((int)$past_stmt->fetchColumn() > 0) {
    json_err('Dieser Antrag betrifft bereits vergangene Tage und kann nicht mehr gelöscht werden. Bitte das Büro kontaktieren.');
}

// Details für's Audit-Log VOR dem Löschen einsammeln (danach sind die
// Zeilen weg) - Zeitraum/Veranstaltung/Airline statt nur nackter IDs,
// damit ein späterer Blick ins Log für das Büro auch tatsächlich
// nachvollziehbar ist, welcher Tag von wem gelöscht wurde.
$detail_stmt = db()->prepare(
    "SELECT a.airline, a.veranstaltung, MIN(at2.tag) AS von, MAX(at2.tag) AS bis
     FROM antraege a
     LEFT JOIN antrag_tage at2 ON at2.antrag_id = a.id
     WHERE a.id = ? GROUP BY a.id"
);
$detail_stmt->execute([$antrag_id]);
$details = $detail_stmt->fetch();
$zeitraum_txt = $details && $details['von']
    ? ($details['von'] === $details['bis'] ? $details['von'] : "{$details['von']} – {$details['bis']}")
    : '(kein Zeitraum)';

// Löschen: erst Tage, dann Antrag
db()->prepare("DELETE FROM antrag_tage WHERE antrag_id = ?")->execute([$antrag_id]);
db()->prepare("DELETE FROM antraege    WHERE id = ?")->execute([$antrag_id]);

// Eigener Aktionsname (nicht "antrag_geloescht" wie beim Büro-seitigen
// Löschen in api/buero.php) - so lässt sich im Audit-Log eindeutig
// zwischen Büro- und Mitglieder-Löschungen unterscheiden, z.B. für die
// Log-Ansicht "Gelöschte Anträge (Mitglieder)" im Büro-Panel.
audit('antrag_geloescht_mitglied', $antrag_id,
    "MitgliedID:{$mitglied_id} TK:{$tk_id} Status-vor-Löschung:{$antrag['status']} Airline:" . ($details['airline'] ?? '?') .
    ' Veranstaltung:' . ($details['veranstaltung'] ?? '?') .
    " Zeitraum:{$zeitraum_txt}");

json_ok();
