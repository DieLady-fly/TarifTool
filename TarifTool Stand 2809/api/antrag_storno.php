<?php
// ============================================================
// api/antrag_storno.php  –  Mitglied storniert eigenen Antrag
// Storno ist nur möglich, wenn der Antrag bereits beim
// Arbeitgeber eingereicht wurde (Status: beantragt_ag).
// Im Gegensatz zu antrag_loeschen.php wird der Antrag NICHT
// gelöscht, sondern auf status='storno_buero' gesetzt und
// wie beim Büro-Storno dokumentiert (Audit-Log, storno_email_log,
// E-Mail an Arbeitgeber mit Büro in CC, Bestätigungsmail ans Mitglied).
// ============================================================
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/mitglieder_auth.php';
require_once __DIR__ . '/../_backend/mail_mitglieder.php';
require_once __DIR__ . '/../_backend/mailer.php';
require_once __DIR__ . '/../_backend/storno_helpers.php';
require_once __DIR__ . '/../_backend/ag_storno_doc.php';
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
$grund       = trim((string)($_POST['grund'] ?? ''));
$mitglied_id = (int)$mitglied['id'];
// Optionale Tage-Auswahl: nur diese Tage werden storniert, nicht
// zwangsläufig der gesamte Antrag. Ohne Angabe (Rückwärtskompatibilität
// mit dem bisherigen Frontend) werden weiterhin alle Tage storniert.
$raw_tage_gewaehlt = $_POST['tage'] ?? [];

if (!$antrag_id) json_err('Antrag-ID fehlt.');
if ($grund === '') json_err('Bitte einen Stornierungsgrund angeben.');

// Antrag laden - die eigentliche Berechtigungsprüfung erfolgt unten rein
// über mitglied_id. Absichtlich NICHT zusätzlich auf die Heim-TK des
// Mitglieds gefiltert: bei Mehrfachmitgliedschaft (ein Mitglied gehört
// mehreren TKs an) könnte der Antrag zu einer ANDEREN TK des Mitglieds
// gehören als dessen primärer/Heim-TK - eine zusätzliche tk_id-Bedingung
// hier würde solche eigenen Anträge fälschlich als "nicht gefunden" melden.
$stmt = db()->prepare(
    "SELECT * FROM antraege WHERE id = ? LIMIT 1"
);
$stmt->execute([$antrag_id]);
$antrag = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$antrag) {
    json_err('Antrag nicht gefunden.');
}

// Nur eigene Anträge dürfen storniert werden
if ((int)$antrag['mitglied_id'] !== $mitglied_id) {
    json_err('Sie haben keine Berechtigung diesen Antrag zu stornieren.', 403);
}

// TK des ANTRAGS selbst (nicht die Heim-TK des Mitglieds!) für TK-Name,
// Log-Eintrag und Empfänger-Ermittlung weiter unten - bei Mehrfach-
// mitgliedschaft kann ein Antrag zu einer anderen TK gehören als der
// primären TK des Mitglieds.
$tk_id = (int)$antrag['tk_id'];

// Storno ist möglich, sobald der Antrag beim Arbeitgeber eingereicht wurde -
// unabhängig davon, ob er bereits final genehmigt ist oder noch aussteht.
// Bei "ausstehend" und "freigabe_buero" (AG noch nicht angefragt) gibt es
// stattdessen die reguläre Löschfunktion (api/antrag_loeschen.php).
$stornierbare_status = ['beantragt_ag', 'genehmigt'];
if (!in_array($antrag['status'], $stornierbare_status, true)) {
    json_err('Eine Stornierung ist nur möglich, wenn der Antrag bereits beim Arbeitgeber eingereicht wurde.');
}

// Bereits vergangene Tage sind grundsätzlich nicht mehr stornierbar
// (Büro kontaktieren) - werden aber nur aus der Auswahl der stornierbaren
// Tage ausgeschlossen, statt die komplette Stornierung zu blockieren.
// Sonst wäre ein mehrtägiger, bereits beim AG beantragter/genehmigter
// Antrag gar nicht mehr stornierbar, sobald auch nur EIN Tag begonnen hat -
// obwohl die übrigen, noch in der Zukunft liegenden Tage weiterhin
// storniert werden können sollen.
$alle_tage_stmt = db()->prepare(
    "SELECT tag FROM antrag_tage
     WHERE antrag_id = ? AND status != 'storno_buero' AND tag >= CURDATE()
     ORDER BY tag"
);
$alle_tage_stmt->execute([$antrag_id]);
$alle_offenen_tage = array_column($alle_tage_stmt->fetchAll(PDO::FETCH_ASSOC), 'tag');

if (is_array($raw_tage_gewaehlt) && count($raw_tage_gewaehlt) > 0) {
    $gewaehlt = array_intersect(array_map('strval', $raw_tage_gewaehlt), $alle_offenen_tage);
    if (empty($gewaehlt)) json_err('Die ausgewählten Tage gehören nicht zu diesem Antrag oder sind bereits storniert.');
} else {
    $gewaehlt = $alle_offenen_tage;
}
if (empty($gewaehlt)) json_err('Für diesen Antrag sind keine stornierbaren Tage mehr vorhanden.');

// mitglied_aus_session() liefert nur nachname_enc (verschlüsselt), keinen
// Klartext-Nachnamen - muss hier entschlüsselt werden. Vorher stand hier
// $mitglied['nachname'], das es gar nicht gibt - dadurch war der Nachname
// in $mitglied_name (und damit auch in der Storno-Mail) immer leer.
$mitglied_nachname = decrypt($mitglied['nachname_enc'] ?? '');
$mitglied_name = trim(($mitglied['vorname'] ?? '') . ' ' . $mitglied_nachname);
$akteur_label  = 'Mitglied:' . $mitglied_id . ($mitglied_name !== '' ? " ({$mitglied_name})" : '');

// Wird der komplette Antrag storniert (alle bisher offenen Tage in der
// Auswahl enthalten) oder nur ein Teil? Nur bei vollständiger Stornierung
// wechselt auch der Antragsstatus selbst auf storno_buero - bei einer
// Teil-Stornierung bleibt der bisherige Antragsstatus (z.B. "genehmigt")
// erhalten, nur die ausgewählten Tage werden storniert.
$vollstaendig = count(array_diff($alle_offenen_tage, $gewaehlt)) === 0;

db()->beginTransaction();
try {
    $tag_ph = implode(',', array_fill(0, count($gewaehlt), '?'));
    db()->prepare(
        "UPDATE antrag_tage
         SET status='storno_buero', storniert_am=NOW(), storniert_von=?, storno_grund=?
         WHERE antrag_id = ? AND tag IN ({$tag_ph}) AND status != 'storno_buero'"
    )->execute([$akteur_label, $grund, $antrag_id, ...$gewaehlt]);

    if ($vollstaendig) {
        db()->prepare(
            "UPDATE antraege
             SET status='storno_buero', notiz=?, entschieden_am=NOW(), entschieden_von=?
             WHERE id=? AND status != 'storno_buero'"
        )->execute([$grund, $akteur_label, $antrag_id]);
    }

    audit('antrag_storniert_mitglied', $antrag_id,
        "{$antrag['status']} → " . ($vollstaendig ? 'storno_buero (vollständig)' : 'storno_buero (teilweise: ' . implode(',', $gewaehlt) . ')') .
        " durch {$akteur_label} TK:{$tk_id} Grund: {$grund}");

    db()->commit();
} catch (Throwable $e) {
    db()->rollBack();
    error_log('antrag_storno Fehler: ' . $e->getMessage());
    json_err('Datenbankfehler beim Storno.', 500);
}

// ── Stornierte Tage für Mail/Log: nur die JETZT tatsächlich
//    stornierten (nicht evtl. bereits vorher stornierte) ────────
$tage_storniert = $gewaehlt;
sort($tage_storniert);

$aktion_id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
    mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
    mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));

// ── Storno-Log-Eintrag (ohne ereignis_id, da Einzelantrag ohne
//    Ereignisbezug) – damit Mitglieder-Stornos auch in der
//    globalen Storno-Log-Ansicht im Büro-Panel erscheinen ──────
$tk_name_stmt = db()->prepare('SELECT bezeichnung FROM tks WHERE id = ? LIMIT 1');
$tk_name_stmt->execute([$tk_id]);
$tk_name = $tk_name_stmt->fetchColumn() ?: null;

$storno_log_stmt = db()->prepare(
    "INSERT INTO storno_log
     (ereignis_id, storniert_von, grund, betroffene_tage, betroffene_antraege,
      storno_aktion_id, tk_id, tk_name, veranstaltung, ereignis_von, ereignis_bis)
     VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL)"
);
foreach ($tage_storniert as $tag) {
    $storno_log_stmt->execute([
        $akteur_label,
        $grund,
        $tag,
        json_encode([$antrag_id]),
        $aktion_id,
        $tk_id,
        $tk_name,
        $antrag['veranstaltung'] ?? null,
    ]);
}

// ════════════════════════════════════════════════════════════
// E-Mail an Arbeitgeber (Flugbetrieb), Büro in CC – analog
// zur Büro-Stornierung in api/buero.php
// ════════════════════════════════════════════════════════════
$email_gesendet = false;
$email_fehler   = '';

// ── Kontakt beim Arbeitgeber (Flugbetrieb) ermitteln - die Tabelle
//    flugbetrieb_kontakte ist über "airline" verknüpft, NICHT über
//    tk_id (siehe api/buero.php, wo dieselbe Tabelle für den Büro-Storno
//    genauso über airline abgefragt wird).
//    ALLE aktiven Kontakte der Airline (wie beim Büro-Storno) - früher
//    ging die Mail hier nur an den ersten Kontakt (LIMIT 1).
$ks = db()->prepare(
    'SELECT bezeichnung, email
     FROM flugbetrieb_kontakte
     WHERE airline = ? AND aktiv = 1
     ORDER BY id'
);
$ks->execute([$antrag['airline']]);
$kontakte = array_values(array_filter($ks->fetchAll(PDO::FETCH_ASSOC), fn($k) => trim((string)$k['email']) !== ''));
$kontakt = $kontakte ? [
    'email'       => implode(', ', array_map(
        fn($k) => trim((string)$k['bezeichnung']) !== '' ? "{$k['bezeichnung']} <{$k['email']}>" : $k['email'],
        $kontakte
    )),
    'bezeichnung' => implode(', ', array_column($kontakte, 'bezeichnung')),
] : null;

// CC = Büro-Adresse (gleiche Einstellung wie bei der Büro-Stornierung)
$cc_email = get_einstellung(db(), 'storno_email_cc');

$tage_sorted = $tage_storniert;
sort($tage_sorted);

$mgl = [[
    'vorname'  => $antrag['vorname'],
    'nachname' => decrypt($antrag['nachname_enc'] ?? ''),
]];
$codes_mail = !empty($antrag['freistellungscode']) ? [$antrag['freistellungscode']] : [];

$subject = build_storno_email_subject($mgl, $tage_sorted);
$body    = build_storno_email_body($mgl, $tage_sorted, $mitglied_name ?: $akteur_label, $codes_mail);

$log_status = 'fehler';
$log_fehler = '';

// LH-Storno-Formular (DOC) anhängen, falls für die Airline aktiv
$storno_anhaenge = [];
if ($kontakt) {
    try {
        if (ag_storno_doc_aktiv(db(), (string)$antrag['airline'])) {
            $storno_anhaenge = ag_storno_doc_dateien_bauen(
                ag_storno_doc_eintraege_laden(db(), [$antrag_id => $tage_storniert]),
                MAIL_FROM_NAME, $grund, (string)$cc_email
            );
            foreach ($storno_anhaenge as &$sa) {
                $sa['mime'] = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            }
            unset($sa);
        }
    } catch (Throwable $ex) {
        error_log('antrag_storno: Storno-DOC fehlgeschlagen: ' . $ex->getMessage());
        $storno_anhaenge = [];
    }
}

if ($kontakt && $kontakt['email']) {
    try {
        $ok = mail_storno_flugbetrieb(
            $kontakt['email'],
            $cc_email,
            $mitglied_name ?: $akteur_label,
            MAIL_FROM,
            $subject,
            $body,
            $storno_anhaenge
        );
        if ($ok) {
            $email_gesendet = true;
            $log_status     = 'gesendet';
        } else {
            $log_fehler = 'mail() gab false zurück.';
        }
    } catch (Throwable $ex) {
        $log_fehler = $ex->getMessage();
    }
} else {
    $log_fehler = 'Kein aktiver Arbeitgeber-Kontakt für Airline "' . $antrag['airline'] . '" hinterlegt.';
}
foreach ($storno_anhaenge as $sa) @unlink($sa['path']);

db()->prepare(
    'INSERT INTO storno_email_log
       (storno_aktion_id, gesendet_am, gesendet_von,
        from_email, to_email, to_bezeichnung, cc_email,
        subject, body, tk_id, tk_name, status, fehler_meldung)
     VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    $aktion_id,
    $akteur_label,
    MAIL_FROM,
    $kontakt['email']          ?? '',
    $kontakt['bezeichnung']    ?? '',
    $cc_email,
    $subject,
    $body,
    $tk_id,
    $tk_name ?? '',
    $log_status,
    $log_fehler ?: null,
]);

if (!$email_gesendet) $email_fehler = $log_fehler;

// ── Bestätigungsmail ans Mitglied (wie bei storno_buero üblich) ──
$vc_email = decrypt($antrag['vc_email_enc']);
$notiz_text = $vollstaendig
    ? 'Sie haben Ihre Freistellung storniert.'
    : 'Sie haben einen Teil Ihrer Freistellung storniert (' . count($tage_storniert) . ' von ' . count($alle_offenen_tage) . ' Tag(en)). Die übrigen Tage bleiben unverändert bestehen.';
mail_ergebnis($vc_email, $antrag['vorname'], 'storno_buero',
    $notiz_text . ($grund ? ' Grund: ' . $grund : ''),
    $antrag['veranstaltung'] ?? null,
    $antrag['freistellungscode'] ?? null,
    $tage_storniert);

json_ok([
    'id'              => $antrag_id,
    'status'          => $vollstaendig ? 'storno_buero' : $antrag['status'],
    'vollstaendig'    => $vollstaendig,
    'tage_storniert'  => $tage_storniert,
    'email_gesendet'  => $email_gesendet,
    'email_fehler'    => $email_fehler,
]);
