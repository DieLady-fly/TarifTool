<?php
// ============================================================
// api/antrag.php  –  POST: Freistellungsantrag einreichen
// Ergänzt: mitglied_id wird verknüpft wenn Mitglied existiert
// ============================================================
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/mitglieder_auth.php';
require_once __DIR__ . '/../_backend/storno_helpers.php'; // get_einstellung()
send_security_headers();
session_start_secure();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Nur POST erlaubt.', 405);
}

csrf_check();

// ── Nebenaktion: Zusatz-Veranstaltungstypen für eine Airline ──
// Wird vom Mitglieder-Portal aufgerufen um das Dropdown zu befüllen.
if (($_POST['action'] ?? '') === 'get_veranstaltungen_fuer_airline') {
    $gvfa_airline = trim($_POST['airline'] ?? '');
    if (!$gvfa_airline) json_err('Airline fehlt.');
    $gvfa_stmt = db()->prepare(
        "SELECT veranstaltung, label, uw_key, sort_order
           FROM airline_veranstaltungen
          WHERE airline = ? AND aktiv = 1
          ORDER BY sort_order, label"
    );
    $gvfa_stmt->execute([$gvfa_airline]);
    json_ok(['zusatz_typen' => $gvfa_stmt->fetchAll()]);
    exit;
}

// ── Mitglieder-Session: Stammdaten automatisch befüllen ───────
// Wenn ein eingeloggtes Mitglied den Antrag stellt, werden
// vorname, nachname, tk_id und vc_email aus der Session geholt
// und müssen nicht im Formular mitgeschickt werden.
$mk = mitglied_aus_session();
if ($mk) {
    // Eingeloggtes Mitglied → Daten aus DB
    $mk_row = db()->prepare(
        "SELECT vc_email_enc, airline, position, flugzeugmuster
         FROM mitglieder WHERE id = ? LIMIT 1"
    );
    $mk_row->execute([$mk['id']]);
    $mk_data = $mk_row->fetch();
    if ($mk_data) {
        $_POST['vorname']        = $mk['vorname'];
        $_POST['nachname']       = decrypt($mk['nachname_enc']);
        // TK-Auswahl: Ein Mitglied kann seit der Mehrfachmitgliedschafts-
        // Umstellung mehreren TKs angehören (siehe mitglied_tk_ids()).
        // Gehört es nur einer TK an, bleibt es wie bisher automatisch die
        // Heim-TK. Bei mehreren TKs MUSS das Portal-Formular die TK explizit
        // mitschicken ("Für welche TK?") - ein aus der Session geratener
        // Standardwert würde sonst stillschweigend die falsche TK wählen.
        $mk_erlaubte_tks = mitglied_tk_ids((int)$mk['id']);
        if (count($mk_erlaubte_tks) > 1) {
            $mk_gewaehlte_tk = (int)($_POST['tk_id'] ?? 0);
            if (!$mk_gewaehlte_tk || !in_array($mk_gewaehlte_tk, $mk_erlaubte_tks, true)) {
                json_err('Bitte eine Tarifkommission auswählen.');
            }
            $_POST['tk_id'] = $mk_gewaehlte_tk;
        } else {
            $_POST['tk_id'] = $mk['tk_id'];
        }
        $_POST['vc_email']       = decrypt($mk_data['vc_email_enc']);
        $_POST['airline']        = $mk_data['airline']        ?? $_POST['airline']        ?? '';
        $_POST['position']       = $mk_data['position']       ?? $_POST['position']       ?? '';
        $_POST['flugzeugmuster'] = $mk_data['flugzeugmuster'] ?? $_POST['flugzeugmuster'] ?? '';
    }
} elseif (isset($_SESSION['panel_user']) && !empty($_POST['mitglied_id_override'])) {
    // Büro reicht Antrag für ein Mitglied ein → mitglied_id verknüpfen
    $mid = (int)$_POST['mitglied_id_override'];
    $mk_row = db()->prepare(
        "SELECT vorname, nachname_enc, tk_id, vc_email_enc, airline, position, flugzeugmuster
         FROM mitglieder WHERE id = ? AND aktiv = 1 LIMIT 1"
    );
    $mk_row->execute([$mid]);
    $mk_data = $mk_row->fetch();
    if ($mk_data) {
        $_POST['vorname']        = $mk_data['vorname'];
        $_POST['nachname']       = decrypt($mk_data['nachname_enc']);
        $_POST['tk_id']          = $mk_data['tk_id'];
        $_POST['vc_email']       = decrypt($mk_data['vc_email_enc']);
        $_POST['airline']        = $mk_data['airline']        ?: ($_POST['airline']        ?? '');
        $_POST['position']       = $mk_data['position']       ?: ($_POST['position']       ?? '');
        $_POST['flugzeugmuster'] = $mk_data['flugzeugmuster'] ?: ($_POST['flugzeugmuster'] ?? '');
        // mitglied_id für spätere Verknüpfung merken
        $_POST['_mitglied_id_forced'] = $mid;
    }
}

// Büro-Direktanlage: Anträge, die das Büro selbst über antrag_neu.php
// anlegt, gelten als bereits intern genehmigt (status 'freigabe_buero'
// statt 'ausstehend'). Das Büro hat sie ja gerade selbst geprüft/erfasst.
// WICHTIG: hängt direkt am "Büro reicht mit mitglied_id_override ein"-Fall,
// NICHT am Erfolg des optionalen mitglied_id-Verknüpfungs-Lookups oben
// (der z.B. leer bleibt, wenn das Mitglied nicht aktiv=1 ist) – sonst
// würde der Antrag lautlos auf 'ausstehend' zurückfallen.
$buero_direktanlage = !$mk && isset($_SESSION['panel_user']) && !empty($_POST['mitglied_id_override']);

// TEMPORÄRE DIAGNOSE – wird unten in die JSON-Antwort gepackt, damit sie
// im Browser (Netzwerk-Tab) sichtbar ist, auch ohne Zugriff aufs Server-Log.
$debug_info = [
    'mk_gesetzt'             => $mk ? true : false,
    'mk_mitglied_id'         => $mk['id'] ?? null,
    'panel_user'             => $_SESSION['panel_user'] ?? null,
    'mitglied_id_override'   => $_POST['mitglied_id_override'] ?? null,
    'mk_data_gefunden'       => isset($mk_data) && $mk_data ? true : false,
    'buero_direktanlage'     => $buero_direktanlage,
];

// ── Rate-Limiting ─────────────────────────────────────────────
if (!rate_limit_ok('antrag')) {
    json_err('Zu viele Anträge. Bitte eine Stunde warten.', 429);
}

// ── Stammdaten einlesen ───────────────────────────────────────
$vorname  = clean($_POST['vorname']        ?? '');
$nachname = clean($_POST['nachname']       ?? '');
$tk_id    = (int)($_POST['tk_id']          ?? 0);
$vc_email = clean_email($_POST['vc_email'] ?? '');
$airline  = clean($_POST['airline']        ?? '');
$position = clean($_POST['position']       ?? '');
$muster   = clean($_POST['flugzeugmuster'] ?? '');

// ── Stammdaten validieren ─────────────────────────────────────
$erlaubte_pos = ['CPT', 'SFO', 'FO'];

if (!$vorname || !$nachname)   { json_err('Vor- und Nachname sind Pflichtfelder.'); }
if (!$tk_id)                   { json_err('Bitte eine Tarifkommission wählen.'); }
if (!filter_var($vc_email, FILTER_VALIDATE_EMAIL)) { json_err('Ungültige E-Mail-Adresse.'); }

// Airline/Position/Muster: Pflichtfeld für Mitglieder-Login,
// für Büro/Admin optional (können in Stammdaten fehlen)
if (isset($_SESSION['panel_user'])) {
    // Büro: leere Felder mit Platzhalter füllen damit INSERT nicht fehlschlägt
    if (!$airline)  $airline  = 'k.A.';
    if (!$position || !in_array($position, $erlaubte_pos, true)) $position = 'CPT';
    if (!$muster)   $muster   = 'k.A.';
} else {
    if (!$airline)                                    { json_err('Airline ist ein Pflichtfeld.'); }
    if (!in_array($position, $erlaubte_pos, true))    { json_err('Ungültige Position.'); }
    if (!$muster)                                     { json_err('Flugzeugmuster ist ein Pflichtfeld.'); }
}

// ── Termine einlesen & validieren ─────────────────────────────
$erlaubte_veran = ['Verhandlung', 'TK Sitzung', 'sonstige'];
// Airline-spezifische Zusatz-Typen laden (aus airline_veranstaltungen)
// Bei eingeloggtem Mitglied: Airline aus TK-Zuordnung (tks.airline ist Klartext,
// mitglieder.airline ist verschlüsselt). POST-Wert als Fallback.
$airline_fuer_validierung = $airline;
if ($mk) {
    $av_tk_stmt = db()->prepare(
        "SELECT DISTINCT t.airline FROM tks t
          JOIN mitglied_tks mt ON mt.tk_id = t.id
         WHERE mt.mitglied_id = ? AND t.airline IS NOT NULL AND t.airline != ''
         UNION
         SELECT t.airline FROM tks t
         WHERE t.id = ? AND t.airline IS NOT NULL AND t.airline != ''"
    );
    $av_tk_stmt->execute([$mk['id'], $mk['tk_id']]);
    $mk_airlines = $av_tk_stmt->fetchAll(PDO::FETCH_COLUMN);
    // Zusatz-Typen für alle Airlines des Mitglieds laden
    if (!empty($mk_airlines)) {
        $av_ph = implode(',', array_fill(0, count($mk_airlines), '?'));
        $av_stmt = db()->prepare(
            "SELECT veranstaltung FROM airline_veranstaltungen
              WHERE airline IN ({$av_ph}) AND aktiv = 1"
        );
        $av_stmt->execute($mk_airlines);
        foreach ($av_stmt->fetchAll(PDO::FETCH_COLUMN) as $av) {
            if (!in_array($av, $erlaubte_veran, true)) {
                $erlaubte_veran[] = $av;
            }
        }
    }
} elseif ($airline_fuer_validierung) {
    // Büro-Direktanlage: Airline aus POST
    $av_stmt = db()->prepare(
        "SELECT veranstaltung FROM airline_veranstaltungen
          WHERE airline = ? AND aktiv = 1"
    );
    $av_stmt->execute([$airline_fuer_validierung]);
    foreach ($av_stmt->fetchAll(PDO::FETCH_COLUMN) as $av) {
        if (!in_array($av, $erlaubte_veran, true)) {
            $erlaubte_veran[] = $av;
        }
    }
}
$raw_termine    = $_POST['termine'] ?? [];

if (!is_array($raw_termine) || count($raw_termine) === 0) {
    json_err('Bitte mindestens einen Termin eintragen.');
}
if (count($raw_termine) > 20) {
    json_err('Maximal 20 Termine pro Antrag erlaubt.');
}

$termine = [];
foreach ($raw_termine as $i => $t) {
    $von         = clean($t['von'] ?? '');
    $bis         = clean($t['bis'] ?? '');
    $typ         = clean($t['typ'] ?? '');
    $bezeichnung = trim(clean($t['bezeichnung'] ?? ''));
    $idx         = (int)$i + 1;

    if (!$von || !$bis) {
        json_err("Termin {$idx}: Zeitraum unvollständig.");
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $von) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $bis)) {
        json_err("Termin {$idx}: Ungültiges Datumsformat.");
    }
    if ($bis < $von) {
        json_err("Termin {$idx}: Enddatum liegt vor Startdatum.");
    }
    if (!in_array($typ, $erlaubte_veran, true)) {
        json_err("Termin {$idx}: Bitte Veranstaltungsart wählen.");
    }
    if ($typ === 'sonstige') {
        if ($bezeichnung === '') {
            json_err("Termin {$idx}: Bitte eine Bezeichnung für die Veranstaltung angeben.");
        }
        if (mb_strlen($bezeichnung) > 150) {
            json_err("Termin {$idx}: Bezeichnung darf maximal 150 Zeichen lang sein.");
        }
    } else {
        // Optionaler Hinweis: erlaubt für alle Typen, aber nicht Pflicht
        if (mb_strlen($bezeichnung) > 150) {
            json_err("Termin {$idx}: Hinweis darf maximal 150 Zeichen lang sein.");
        }
        if ($bezeichnung === '') {
            $bezeichnung = null;
        }
    }
    $termine[] = compact('von', 'bis', 'typ', 'bezeichnung');
}

// Überschneidungen prüfen
for ($a = 0; $a < count($termine); $a++) {
    for ($b = $a + 1; $b < count($termine); $b++) {
        if ($termine[$a]['von'] <= $termine[$b]['bis'] &&
            $termine[$b]['von'] <= $termine[$a]['bis']) {
            json_err('Termine ' . ($a+1) . ' und ' . ($b+1) . ' überschneiden sich.');
        }
    }
}

// ── Deadline-Prüfung (nur für Mitglieder, nicht für Büro/Admin) ──
if (!isset($_SESSION['panel_user'])) {
    foreach ($termine as $t) {
        $dl_stmt = db()->prepare(
            "SELECT notiz FROM deadlines
             WHERE deadline_am <= NOW()
               AND zeitraum_bis >= ?
             LIMIT 1"
        );
        $dl_stmt->execute([$t['von']]);
        $dl = $dl_stmt->fetch();
        if ($dl) {
            $hinweis = $dl['notiz'] ? " ({$dl['notiz']})" : '';
            json_err(
                "Für diesen Zeitraum ist die Eingabefrist abgelaufen{$hinweis}. " .
                "Bitte wenden Sie sich bei dringenden Änderungen per E-Mail ans Tarifsekretariat."
            );
        }
    }
}

// ── TK prüfen ─────────────────────────────────────────────────
$tk_stmt = db()->prepare("SELECT id, bezeichnung FROM tks WHERE id = ? AND aktiv = 1 LIMIT 1");
$tk_stmt->execute([$tk_id]);
$tk = $tk_stmt->fetch();
if (!$tk) { json_err('Ungültige Tarifkommission.'); }

// ── Mitglied-Verknüpfung: existiert ein Mitglied mit dieser E-Mail,
//    das der gewählten TK angehört? ──
// Antrag wird mit mitglied_id verknüpft, damit das Mitglied
// seine Anträge im Portal sehen kann. Ein Mitglied hat nur noch EINE
// Zeile in "mitglieder" (die Heim-TK), gehört über mitglied_tks aber
// ggf. weiteren TKs an - deshalb hier NICHT mehr direkt auf
// mitglieder.tk_id filtern, sondern nach dem E-Mail-Treffer separat
// per mitglied_tk_ids() prüfen, ob die gewählte TK zulässig ist.
$mitglied_id = null;
$m_lookup = db()->prepare(
    "SELECT id FROM mitglieder
     WHERE email_hash = ? AND aktiv = 1 LIMIT 1"
);
$m_lookup->execute([email_hash($vc_email)]);
$m_row = $m_lookup->fetch();
if ($m_row && in_array($tk_id, mitglied_tk_ids((int)$m_row['id']), true)) {
    $mitglied_id = (int)$m_row['id'];
}

// ── Stationierungsort (optional) ──────────────────────────────
// Manche Airlines möchten den aktuellen Stationierungsort des
// Mitglieds im Antrag mitgeliefert bekommen. Kein Pflichtfeld -
// ist am Mitglied nichts hinterlegt, bleibt es einfach NULL und
// wird beim Export (Freistellungsliste) stillschweigend ignoriert.
$stationierung = null;
if ($mitglied_id) {
    $stat_stmt = db()->prepare("SELECT stationierung FROM mitglieder WHERE id = ? LIMIT 1");
    $stat_stmt->execute([$mitglied_id]);
    $stationierung = $stat_stmt->fetchColumn() ?: null;
}

// ── Referenten laden (ALLE aktiven, nicht nur LIMIT 1) ────────
// Fix: LIMIT 1 entfernt, damit bei mehreren aktiven Tarifreferenten
// für eine TK jeder seine Freigabe-Mail mit Token bekommt.
$ref_stmt = db()->prepare(
    "SELECT name, email FROM tarifreferenten WHERE tk_id = ? AND aktiv = 1"
);
$ref_stmt->execute([$tk_id]);
$referenten = $ref_stmt->fetchAll();

// ── Büro-Kontakt laden ──────────────────────────────────────────
// TK-Sitzungen werden nicht mehr vom Tarifreferenten, sondern direkt
// vom Büro freigegeben. Die Adresse wird unter "Büro Kontakt"
// gepflegt (dieselbe Adresse, die auch als CC bei Storno-Mails
// verwendet wird).
$buero_email = trim((string)get_einstellung(db(), 'storno_email_cc'));

// ── Verschlüsseln ─────────────────────────────────────────────
$nachname_enc = encrypt($nachname);
$email_enc    = encrypt($vc_email);

// ── Statements vorbereiten ────────────────────────────────────
$stmt_insert_antrag = db()->prepare(
    "INSERT INTO antraege
     (mitglied_id, vorname, nachname_enc, tk_id, vc_email_enc, airline, position,
      flugzeugmuster, stationierung, veranstaltung, zeitraum_von, zeitraum_bis,
      token, ereignis_id, freistellungscode, status, entschieden_am, entschieden_von)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);

$stmt_find_ereignis = db()->prepare(
    "SELECT id FROM ereignisse
     WHERE tk_id = ? AND veranstaltung = ?
       AND zeitraum_von = ? AND zeitraum_bis = ?
     LIMIT 1"
);

$stmt_insert_ereignis = db()->prepare(
    "INSERT INTO ereignisse (tk_id, veranstaltung, zeitraum_von, zeitraum_bis, bezeichnung)
     VALUES (?, ?, ?, ?, ?)"
);

$stmt_insert_tag = db()->prepare(
    "INSERT INTO antrag_tage (antrag_id, ereignis_id, tag, status)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE ereignis_id = VALUES(ereignis_id)"
);

// ── Hilfsfunktion: alle Tage eines Zeitraums ─────────────────
function tage_im_zeitraum(string $von, string $bis): array {
    $tage  = [];
    $start = new DateTimeImmutable($von);
    $end   = new DateTimeImmutable($bis);
    $curr  = $start;
    while ($curr <= $end) {
        $tage[] = $curr->format('Y-m-d');
        $curr   = $curr->modify('+1 day');
    }
    return $tage;
}

// ── Pro Termin Antrag + Tage in DB schreiben ──────────────────
$haupt_token     = generate_token();
$erste_antrag_id = null;

// Freistellungscode je Kategorie (Verhandlung/TK-Sitzung/Sonstiges) aus
// finance_preise nachschlagen - airline+position sind für den ganzen
// Antrag konstant, nur die Kategorie variiert je Termin. Vorher wurde
// finance_preise.freistellungscode nirgends auf tatsächliche Anträge
// übertragen, wodurch das Feld in der Praxis immer leer blieb.
$kat_map = ['Verhandlung' => 'verhandlung', 'TK Sitzung' => 'tk_sitzung', 'sonstige' => 'sonstiges'];
// Zusatz-Typen ebenfalls in kat_map aufnehmen (uw_key als Kategorie-Key)
if ($airline) {
    $av_map_stmt = db()->prepare(
        "SELECT veranstaltung, uw_key FROM airline_veranstaltungen
          WHERE airline = ? AND aktiv = 1"
    );
    $av_map_stmt->execute([$airline]);
    foreach ($av_map_stmt->fetchAll() as $av_row) {
        $kat_map[$av_row['veranstaltung']] = $av_row['uw_key'];
    }
}
$fscode_stmt = db()->prepare(
    "SELECT kategorie, freistellungscode FROM finance_preise
     WHERE airline = ? AND position = ?"
);
$fscode_stmt->execute([$airline, $position]);
$fscode_je_kategorie = [];
foreach ($fscode_stmt->fetchAll() as $fp) {
    $fscode_je_kategorie[$fp['kategorie']] = $fp['freistellungscode'];
}

// Bei manueller Antragsanlage durchs Büro kann der Freistellungscode explizit
// im Formular gewählt werden (z. B. wenn für die Airline mehrere Codes zur
// Auswahl stehen) - hat dann Vorrang vor dem automatischen Lookup je
// Kategorie. WICHTIG: Nur akzeptiert, wenn buero_direktanlage true ist -
// ein Mitglied selbst könnte dieses POST-Feld sonst frei mitschicken und
// sich einen beliebigen Freistellungscode zuweisen.
$fscode_explizit = null;
if ($buero_direktanlage) {
    $fscode_explizit = clean($_POST['freistellungscode'] ?? '') ?: null;
}

foreach ($termine as $idx => $t) {
    $token = ($idx === 0) ? $haupt_token : generate_token();
    $freistellungscode = $fscode_explizit ?? ($fscode_je_kategorie[$kat_map[$t['typ']] ?? ''] ?? null);

    // Ereignis suchen oder anlegen
    $stmt_find_ereignis->execute([$tk_id, $t['typ'], $t['von'], $t['bis']]);
    $ereignis_row = $stmt_find_ereignis->fetch();

    if ($ereignis_row) {
        // Bestehendes Ereignis (gleiche TK/Art/Zeitraum) wird wiederverwendet.
        // Die bereits hinterlegte bezeichnung bleibt maßgeblich – ein
        // abweichender Freitext eines weiteren Antragstellers überschreibt
        // sie bewusst NICHT (Variante A).
        $ereignis_id = (int)$ereignis_row['id'];
    } else {
        $stmt_insert_ereignis->execute([$tk_id, $t['typ'], $t['von'], $t['bis'], $t['bezeichnung']]);
        $ereignis_id = (int)db()->lastInsertId();
    }

    // Antrag anlegen (mit optionaler mitglied_id)
    $initial_status = $buero_direktanlage ? 'freigabe_buero' : 'ausstehend';
    $entschieden_am = $buero_direktanlage ? date('Y-m-d H:i:s') : null;
    $entschieden_von = $buero_direktanlage ? ($_SESSION['panel_user'] ?? null) : null;

    $stmt_insert_antrag->execute([
        $mitglied_id,
        $vorname, $nachname_enc, $tk_id, $email_enc,
        $airline, $position, $muster, $stationierung,
        $t['typ'], $t['von'], $t['bis'],
        $token, $ereignis_id, $freistellungscode, $initial_status, $entschieden_am, $entschieden_von,
    ]);
    $antrag_id = (int)db()->lastInsertId();
    if ($idx === 0) $erste_antrag_id = $antrag_id;

    // Jeden Einzeltag in antrag_tage speichern
    foreach (tage_im_zeitraum($t['von'], $t['bis']) as $tag) {
        $stmt_insert_tag->execute([$antrag_id, $ereignis_id, $tag, $initial_status]);
    }

    audit($buero_direktanlage ? 'antrag_eingereicht_buero_direkt' : 'antrag_eingereicht', $antrag_id,
        "TK:{$tk_id} Airline:{$airline} EreignisID:{$ereignis_id} {$t['von']} bis {$t['bis']}" .
        ($mitglied_id ? " MitgliedID:{$mitglied_id}" : '') .
        ($buero_direktanlage ? " (direkt freigabe_buero durch {$_SESSION['panel_user']})" : ''));

    if ($buero_direktanlage) {
        // Bereits intern genehmigt -> KEINE Freigabe-Anfrage-Mail mit Token
        // (der Link würde ohnehin sofort "Bereits entschieden" zeigen, da
        // der Antrag nicht mehr im Status 'ausstehend' ist). Stattdessen
        // bekommt das Mitglied direkt den Zwischenbescheid.
        mail_zwischenbescheid(
            $vc_email,
            $vorname,
            $t['typ'] === 'sonstige' && $t['bezeichnung'] ? $t['bezeichnung'] : $t['typ'],
            tage_im_zeitraum($t['von'], $t['bis'])
        );
    } elseif ($t['typ'] === 'TK Sitzung' && $buero_email && filter_var($buero_email, FILTER_VALIDATE_EMAIL)) {
        // TK Sitzung -> Freigabe-Mail ans Büro (nicht an Tarifreferenten)
        mail_referent($buero_email, 'Büro', [
            'vorname'        => $vorname,
            'nachname'       => $nachname,
            'token'          => $token,
            'tk_bezeichnung' => $tk['bezeichnung'],
            'airline'        => $airline,
            'position'       => $position,
            'flugzeugmuster' => $muster,
            'termine'        => [['von' => $t['von'], 'bis' => $t['bis'], 'typ' => $t['typ']]],
        ]);
        audit('freigabe_mail_gesendet', $antrag_id, "Empfänger: Büro <{$buero_email}> (Typ:buero)");
    } elseif (!empty($referenten)) {
        // Verhandlung / sonstige / Zusatz-Typen -> alle aktiven Tarifreferenten
        foreach ($referenten as $ref_person) {
            mail_referent($ref_person['email'], $ref_person['name'], [
                'vorname'        => $vorname,
                'nachname'       => $nachname,
                'token'          => $token,
                'tk_bezeichnung' => $tk['bezeichnung'],
                'airline'        => $airline,
                'position'       => $position,
                'flugzeugmuster' => $muster,
                'termine'        => [['von' => $t['von'], 'bis' => $t['bis'], 'typ' => $t['typ']]],
            ]);
            audit('freigabe_mail_gesendet', $antrag_id, "Empfänger: {$ref_person['name']} <{$ref_person['email']}> (Typ:referent)");
        }
    } elseif ($buero_email && filter_var($buero_email, FILTER_VALIDATE_EMAIL)) {
        // Kein aktiver Tarifreferent für diese TK hinterlegt -> Fallback aufs
        // Büro, damit die Freigabe-Anfrage nicht kommentarlos verloren geht.
        error_log("antrag.php: Kein aktiver Tarifreferent für TK_ID {$tk_id} (Antrag {$antrag_id}) - Freigabe-Mail ersatzweise ans Büro gesendet.");
        mail_referent($buero_email, 'Büro', [
            'vorname'        => $vorname,
            'nachname'       => $nachname,
            'token'          => $token,
            'tk_bezeichnung' => $tk['bezeichnung'],
            'airline'        => $airline,
            'position'       => $position,
            'flugzeugmuster' => $muster,
            'termine'        => [['von' => $t['von'], 'bis' => $t['bis'], 'typ' => $t['typ']]],
        ]);
        audit('freigabe_mail_gesendet', $antrag_id, "Empfänger: Büro <{$buero_email}> (Typ:buero)");
    } else {
        // Weder Tarifreferent noch Büro-Kontakt verfügbar -> zumindest
        // sichtbar loggen, statt die Freigabe-Mail stillschweigend zu verlieren.
        error_log("antrag.php: KEINE Freigabe-Mail versendet - weder Tarifreferent noch Büro-Kontakt für TK_ID {$tk_id} (Antrag {$antrag_id}) vorhanden.");
    }
}

// Eingangsbestätigung einmalig an Antragsteller – nicht bei Büro-Direktanlage,
// da der Text "wird jetzt durch das Büro geprüft" dort irreführend wäre
// (ist ja schon geprüft/genehmigt) und das Mitglied stattdessen bereits
// pro Termin den Zwischenbescheid "intern genehmigt" bekommen hat.
if (!$buero_direktanlage) {
    // Alle Tage und Veranstaltungsarten aus den Terminen sammeln
    $alle_tage_eingang = [];
    $veranstaltungen_eingang = [];
    foreach ($termine as $t) {
        $von_dt = new DateTime($t['von']);
        $bis_dt = new DateTime($t['bis']);
        while ($von_dt <= $bis_dt) {
            $alle_tage_eingang[] = $von_dt->format('Y-m-d');
            $von_dt->modify('+1 day');
        }
        $label = $t['typ'];
        if ($t['typ'] === 'sonstige' && !empty($t['bezeichnung'])) {
            $label .= ' (' . $t['bezeichnung'] . ')';
        }
        if (!in_array($label, $veranstaltungen_eingang, true)) {
            $veranstaltungen_eingang[] = $label;
        }
    }
    sort($alle_tage_eingang);
    $veranstaltung_eingang = implode(', ', $veranstaltungen_eingang);
    mail_eingang($vc_email, $vorname, $haupt_token, $veranstaltung_eingang, null, $alle_tage_eingang);
}

// ── Büro-Benachrichtigung bei Selbst-Einreichung durch ein Mitglied ──
// Einmal pro Einreichung (alle Termine zusammengefasst), unabhängig
// von der Veranstaltungsart. Gilt nur, wenn das Mitglied selbst über
// sein Portal einreicht – nicht, wenn das Büro den Antrag anlegt
// (mitglied_id_override), da das Büro es in dem Fall ohnehin selbst tut.
// Nutzt dieselbe Adresse wie "Büro Kontakt". Reine Info-Mail OHNE
// Genehmigen/Ablehnen-Token (dafür ist mail_referent() weiter oben
// zuständig) – sonst bekäme das Büro bei TK-Sitzungen zwei Mails,
// die beide wie eine Entscheidungsanfrage aussehen.
if ($mk && $buero_email && filter_var($buero_email, FILTER_VALIDATE_EMAIL)) {
    mail_buero_neuer_antrag($buero_email, [
        'vorname'        => $vorname,
        'nachname'       => $nachname,
        'tk_bezeichnung' => $tk['bezeichnung'],
        'airline'        => $airline,
        'position'       => $position,
        'flugzeugmuster' => $muster,
        'termine'        => $termine,
    ]);
}

json_ok([
    'antrag_id' => $erste_antrag_id,
    'token'     => $haupt_token,
    'termine'   => count($termine),
    'debug'     => $debug_info, // TEMPORÄR – nach Klärung wieder entfernen
]);
