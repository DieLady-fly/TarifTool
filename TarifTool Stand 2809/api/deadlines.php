<?php
// ============================================================
// api/deadlines.php  –  Gibt aktive Deadlines zurück
// Wird vom Mitglieder-Portal aufgerufen (kein Login nötig,
// da nur lesend und keine personenbezogenen Daten).
// ============================================================
require_once __DIR__ . '/../_backend/bootstrap.php';
send_security_headers();
header('Content-Type: application/json; charset=utf-8');

// Alle Deadlines laden die noch relevant sind:
// Zeitraum liegt in der Zukunft ODER Deadline wurde bereits überschritten
// (damit gesperrte Bereiche auch nach Deadline-Datum angezeigt werden)
$stmt = db()->query(
    "SELECT id, zeitraum_bis, deadline_am, notiz
     FROM deadlines
     WHERE zeitraum_bis >= CURDATE() - INTERVAL 1 MONTH
     ORDER BY zeitraum_bis ASC"
);
$rows = $stmt->fetchAll();

// Für jeden Eintrag: ist die Deadline bereits abgelaufen?
$now = new DateTimeImmutable();
foreach ($rows as &$r) {
    $dl = new DateTimeImmutable($r['deadline_am']);
    $r['gesperrt'] = $dl <= $now; // true = jetzt gesperrt
}
unset($r);

json_ok($rows);
