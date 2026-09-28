<?php
// ============================================================
// api/meine_tage.php
// Gibt alle Antragstage des eingeloggten Mitglieds zurück.
// Filter: nur mitglied_id (aus serverseitiger Session) - bewusst NICHT
// zusätzlich nach TK gefiltert, damit auch Tage aus einer eventuellen
// Zweit-/Dritt-TK (Mehrfachmitgliedschaft, siehe mitglied_tk_ids())
// im gemeinsamen Kalender erscheinen. tk_kuerzel wird mitgeliefert,
// damit das Frontend bei mehreren TKs erkennbar machen kann, zu
// welcher TK ein Tag gehört.
// ============================================================
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/mitglieder_auth.php';
send_security_headers();
header('Content-Type: application/json; charset=utf-8');

$mitglied = mitglied_aus_session();
if (!$mitglied) {
    json_err('Nicht eingeloggt.', 401);
}

$mitglied_id = (int)$mitglied['id'];

$stmt = db()->prepare(
    "SELECT
        at.tag,
        CASE
            WHEN at.status = 'storno_buero' THEN 'storno_buero'
            WHEN a.status IN ('genehmigt','abgelehnt','abgelehnt_ag','storno_buero')
            THEN a.status
            ELSE at.status
        END AS status,
        a.veranstaltung,
        e.bezeichnung AS ereignis_bezeichnung,
        a.id AS antrag_id,
        a.tk_id,
        t.kuerzel AS tk_kuerzel
     FROM antrag_tage at
     JOIN antraege a ON a.id = at.antrag_id
     LEFT JOIN ereignisse e ON e.id = at.ereignis_id
     LEFT JOIN tks t ON t.id = a.tk_id
     WHERE a.mitglied_id = ?
     ORDER BY at.tag ASC"
);
$stmt->execute([$mitglied_id]);
$tage = $stmt->fetchAll();

json_ok($tage);
