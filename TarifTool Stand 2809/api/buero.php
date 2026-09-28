<?php
// ============================================================
// api/buero.php  –  Backend für das Büro-Panel
// POST-Parameter: action = list_antraege | get_antrag | update_antrag
//                           list_budgets  | save_budget | get_stats
//                           list_ereignisse | get_ereignis
//                           storno_tage   | get_storno_log | list_storno_log
//                           umwidmen_tage | list_umwidmung_log
//                           list_mitglieder | get_mitglied |
//                           create_mitglied | update_mitglied |
//                           toggle_mitglied | delete_mitglied |
//                           resend_einladung |
//                           get_finance_config | save_finance_config |
//                           get_finance_stats  | get_finance_chart   |
//                           list_finance_airlines |
//                           list_flugbetrieb_kontakte |
//                           create_flugbetrieb_kontakt |
//                           update_flugbetrieb_kontakt |
//                           delete_flugbetrieb_kontakt |
//                           get_einstellungen | save_einstellungen |
//                           get_email_vorschau |
//                           list_storno_email_log |
//                           list_ag_rueckmeldung | save_ag_rueckmeldung |
//                           list_freistellungscodes |
//                           delete_budget |
//                           export_rechnungsdb_csv |
// ============================================================
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/mitglieder_auth.php';
require_once __DIR__ . '/../_backend/mail_mitglieder.php';
require_once __DIR__ . '/../_backend/mailer.php';
send_security_headers();
session_start_secure();
header('Content-Type: application/json; charset=utf-8');

// Sicherheitsnetz: eine unerwartete Exception/ein Fatal Error darf hier
// niemals eine kaputte (Nicht-JSON) Antwort erzeugen – das Frontend
// (fetch(...).then(r=>r.json())) würde sonst eine JS-Exception werfen,
// die VOR dem "btn.disabled = false;" der aufrufenden Funktion greift,
// und der Button bliebe für den Benutzer sichtbar "hängen", obwohl der
// Request längst (mit Fehler) beendet ist.
set_exception_handler(function (Throwable $e) {
    error_log('Unbehandelte Exception in api/buero.php: ' . $e->getMessage());
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    // Absichtlich KEINE Exception-Details (SQL-Fragmente, Pfade, Tabellennamen)
    // an den Client ausgeben - die landen ausschließlich im Server-Log oben.
    echo json_encode(['ok' => false, 'error' => 'Interner Fehler. Bitte erneut versuchen.']);
    exit;
});

if (empty($_SESSION['panel_user'])) {
    json_err('Nicht angemeldet.', 401);
}

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');
csrf_check();

// Rollen-Hilfsfunktion
function is_admin(): bool {
    return panel_hat_rolle('admin');
}

function is_finance(): bool {
    return panel_hat_rolle('finance');
}


// ════════════════════════════════════════════════════════════
// HILFSFUNKTIONEN – Storno-E-Mail / Einstellungen
// (ausgelagert nach _backend/storno_helpers.php, damit sie auch
//  von api/antrag_storno.php – Mitglieder-Storno – genutzt werden
//  können)
// ════════════════════════════════════════════════════════════
require_once __DIR__ . '/../_backend/storno_helpers.php';
require_once __DIR__ . '/../_backend/ag_antrag_helpers.php';
require_once __DIR__ . '/../_backend/ag_antrag_excel.php';
require_once __DIR__ . '/../_backend/erinnerung_helpers.php';
require_once __DIR__ . '/../_backend/ag_antrag_doc.php';
require_once __DIR__ . '/../_backend/ag_storno_doc.php';
require_once __DIR__ . '/../_backend/ag_doc_auswertung.php';
require_once __DIR__ . '/../_backend/storno_mail_vorlagen.php';

/**
 * Teilt ein Array von Datum-Strings (Y-m-d) in Gruppen
 * konsekutiver Tage auf. Lücken erzeugen neue Gruppen.
 * Jede Gruppe wird zu einem eigenen Antrag.
 *
 * Beispiel: [12.10, 15.10, 16.10] → [[12.10], [15.10, 16.10]]
 */
function tage_zu_konsekutiven_gruppen(array $tage): array {
    if (empty($tage)) return [];
    sort($tage);
    $gruppen = [];
    $aktuelle = [$tage[0]];
    for ($i = 1; $i < count($tage); $i++) {
        $prev = new DateTime($tage[$i - 1]);
        $prev->modify('+1 day');
        if ($prev->format('Y-m-d') === $tage[$i]) {
            $aktuelle[] = $tage[$i];
        } else {
            $gruppen[] = $aktuelle;
            $aktuelle  = [$tage[$i]];
        }
    }
    $gruppen[] = $aktuelle;
    return $gruppen;
}

switch ($action) {

    // ── Dashboard-Stats ──────────────────────────────────────
    case 'get_stats':
        $stats = [
            'gesamt'         => (int)db()->query("SELECT COUNT(*) FROM antraege")->fetchColumn(),
            'ausstehend'     => (int)db()->query("SELECT COUNT(*) FROM antraege WHERE status='ausstehend'")->fetchColumn(),
            'genehmigt'      => (int)db()->query("SELECT COUNT(*) FROM antraege WHERE status='genehmigt'")->fetchColumn(),
            'abgelehnt'      => (int)db()->query("SELECT COUNT(*) FROM antraege WHERE status IN('abgelehnt','abgelehnt_ag')")->fetchColumn(),
            'storno_buero'   => (int)db()->query("SELECT COUNT(*) FROM antraege WHERE status='storno_buero'")->fetchColumn(),
            'tage_gesamt'    => (int)db()->query("SELECT COUNT(*) FROM antrag_tage")->fetchColumn(),
            'tage_storniert' => (int)db()->query("SELECT COUNT(*) FROM antrag_tage WHERE status='storno_buero'")->fetchColumn(),
            // Mitglieder-Stats
            'mitglieder_gesamt' => (int)db()->query("SELECT COUNT(*) FROM mitglieder WHERE aktiv=1")->fetchColumn(),
            'mitglieder_einladung_ausstehend' => (int)db()->query(
                "SELECT COUNT(*) FROM mitglieder WHERE aktiv=1 AND einladung_token IS NOT NULL"
            )->fetchColumn(),
        ];
        json_ok($stats);
        break;

    // ── Antragsliste (mit optionalem Filter) ─────────────────
    case 'list_antraege':
        $where  = [];
        $params = [];

        $filter_status_raw       = clean($_POST['status']           ?? '');
        $filter_tk              = (int)($_POST['tk_id']            ?? 0);
        $filter_airline         = clean($_POST['airline']          ?? '');
        $filter_ereignis        = (int)($_POST['ereignis_id']      ?? 0);
        $filter_freistellung_von = clean($_POST['freistellung_von'] ?? '');
        $filter_freistellung_bis = clean($_POST['freistellung_bis'] ?? '');
        $filter_antrag_von       = clean($_POST['antrag_von']       ?? '');
        $filter_antrag_bis       = clean($_POST['antrag_bis']       ?? '');
        $filter_fscode           = clean($_POST['freistellungscode'] ?? '');

        // Kommagetrennte Liste erlaubt (z.B. "freigabe_buero,beantragt_ag"
        // für die Ereignis-Auswahl bei Beantragung/Erinnerung), einzelner
        // Wert weiterhin wie bisher möglich.
        $erlaubte_status_werte = ['ausstehend','freigabe_buero','beantragt_ag','genehmigt','abgelehnt','abgelehnt_ag','storno_buero','storno_bestaetigt_ag'];
        $filter_status = array_values(array_intersect(
            array_filter(array_map('trim', explode(',', $filter_status_raw))),
            $erlaubte_status_werte
        ));

        if ($filter_status)  {
            $ph = implode(',', array_fill(0, count($filter_status), '?'));
            $where[] = "a.status IN ({$ph})";
            $params  = array_merge($params, $filter_status);
        }
        if ($filter_ereignis) { $where[] = 'a.ereignis_id = ?'; $params[] = $filter_ereignis; }
        if ($filter_tk)      { $where[] = 'a.tk_id = ?';      $params[] = $filter_tk; }
        if ($filter_airline) { $where[] = 'a.airline LIKE ?'; $params[] = '%'.$filter_airline.'%'; }
        if ($filter_fscode)  { $where[] = 'a.freistellungscode = ?'; $params[] = $filter_fscode; }

        // Antragsdatum-Filter direkt im WHERE (nicht aggregiert)
        if ($filter_antrag_von) { $where[] = 'DATE(a.erstellt_am) >= ?'; $params[] = $filter_antrag_von; }
        if ($filter_antrag_bis) { $where[] = 'DATE(a.erstellt_am) <= ?'; $params[] = $filter_antrag_bis; }

        // Freistellungszeitraum-Filter direkt im WHERE auf a.zeitraum_von / a.zeitraum_bis.
        // Überlappungslogik: Antrag überlappt den Filterbereich wenn
        //   a.zeitraum_von <= filter_bis  UND  a.zeitraum_bis >= filter_von
        if ($filter_freistellung_von && $filter_freistellung_bis) {
            $where[]  = 'a.zeitraum_von <= ?';
            $params[] = $filter_freistellung_bis;
            $where[]  = 'a.zeitraum_bis >= ?';
            $params[] = $filter_freistellung_von;
        } elseif ($filter_freistellung_von) {
            $where[]  = 'a.zeitraum_bis >= ?';
            $params[] = $filter_freistellung_von;
        } elseif ($filter_freistellung_bis) {
            $where[]  = 'a.zeitraum_von <= ?';
            $params[] = $filter_freistellung_bis;
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "
            SELECT
                a.id, a.vorname, a.nachname_enc, a.vc_email_enc,
                a.airline, a.position, a.veranstaltung, a.freistellungscode,
                a.status, a.erstellt_am, a.entschieden_am, a.notiz,
                a.ereignis_id,
                a.mitglied_id,
                t.kuerzel AS tk_kuerzel,
                e.bezeichnung                           AS ereignis_bezeichnung,
                COALESCE(MIN(at2.tag), a.zeitraum_von) AS zeitraum_von,
                COALESCE(MAX(at2.tag), a.zeitraum_bis) AS zeitraum_bis,
                COUNT(at2.id)                           AS anzahl_tage,
                (SELECT COUNT(*) FROM antrag_tage WHERE antrag_id = a.id AND status = 'storno_buero') AS tage_storniert,
                -- Wer hat büroseitig freigegeben - egal auf welchem Weg:
                -- übers Panel (status_geaendert), per E-Mail-Link
                -- (entscheidung_freigabe_buero) oder Büro-Direktanlage
                -- (bereits als freigabe_buero angelegt).
                (SELECT CASE
                    WHEN aktion = 'entscheidung_freigabe_buero' AND details LIKE '%Name eingetragen: %'
                        THEN SUBSTRING_INDEX(details, 'Name eingetragen: ', -1)
                    WHEN aktion = 'entscheidung_freigabe_buero'
                        THEN SUBSTRING_INDEX(details, 'Empfänger: ', -1)
                    WHEN aktion = 'antrag_eingereicht_buero_direkt' THEN TRIM(TRAILING ')' FROM SUBSTRING_INDEX(details, 'direkt freigabe_buero durch ', -1))
                    ELSE SUBSTRING_INDEX(details, 'durch ', -1)
                 END
                 FROM audit_log
                 WHERE antrag_id = a.id
                   AND (
                        (aktion = 'status_geaendert' AND details LIKE '%→ freigabe_buero durch %')
                     OR aktion = 'entscheidung_freigabe_buero'
                     OR (aktion = 'antrag_eingereicht_buero_direkt' AND details LIKE '%direkt freigabe_buero durch %')
                   )
                 ORDER BY erstellt_am DESC LIMIT 1) AS freigabe_erteilt_von
            FROM antraege a
            LEFT JOIN tks t       ON t.id  = a.tk_id
            LEFT JOIN ereignisse e ON e.id  = a.ereignis_id
            LEFT JOIN antrag_tage at2 ON at2.antrag_id = a.id AND at2.status != 'storno_bestaetigt_ag'
            {$where_sql}
            GROUP BY a.id
            ORDER BY a.erstellt_am DESC
            LIMIT 500
        ";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['nachname']       = decrypt($r['nachname_enc']);
            $r['vc_email']       = decrypt($r['vc_email_enc']);
            unset($r['nachname_enc'], $r['vc_email_enc']);
            $r['anzahl_tage']    = (int)$r['anzahl_tage'];
            $r['tage_storniert'] = (int)$r['tage_storniert'];
            $r['hat_mitglied']   = $r['mitglied_id'] !== null;
        }
        json_ok($rows);
        break;

    // ── Tagesgenaue Überschneidungsprüfung (für antrag_neu.php) ──
    // Liefert pro kollidierendem Tag den bestehenden Antrag/Ereignis,
    // damit das Frontend freie Tage normal einreichen und für
    // kollidierende Tage direkt eine Umwidmung anstoßen kann.
    // WICHTIG: Abgleich über die entschlüsselte E-Mail-Adresse (nicht
    // über antraege.mitglied_id) – die Verknüpfung ist nicht immer
    // gesetzt (z.B. ältere Anträge), dann würde ein Abgleich per ID
    // bestehende Konflikte schlicht übersehen.
    case 'pruefe_ueberschneidung':
        $pu_tk_id = (int)($_POST['tk_id'] ?? 0);
        $pu_email = clean_email($_POST['email'] ?? '');
        $pu_von   = clean($_POST['von'] ?? '');
        $pu_bis   = clean($_POST['bis'] ?? '');
        if (!$pu_tk_id || !$pu_email || !$pu_von || !$pu_bis) json_err('Parameter fehlen.');

        // Ein Tag gilt als "belegt" (Konflikt), solange er nicht endgültig
        // abgelehnt oder vom Arbeitgeber bestätigt storniert wurde:
        // abgelehnt/abgelehnt_ag geben den Tag sofort wieder frei,
        // storno_buero NICHT (Arbeitgeber hat die Stornierung noch nicht
        // bestätigt - erst storno_bestaetigt_ag gibt den Tag wirklich frei).
        $pu_stmt = db()->prepare(
            "SELECT at.tag, a.id AS antrag_id, a.ereignis_id, a.veranstaltung, a.status, a.vc_email_enc
             FROM antrag_tage at
             JOIN antraege a ON a.id = at.antrag_id
             WHERE a.tk_id = ?
               AND at.tag BETWEEN ? AND ?
               AND at.status NOT IN ('abgelehnt', 'abgelehnt_ag', 'storno_bestaetigt_ag')
             ORDER BY at.tag"
        );
        $pu_stmt->execute([$pu_tk_id, $pu_von, $pu_bis]);

        $pu_konflikte = [];
        foreach ($pu_stmt->fetchAll() as $pu_row) {
            if (strtolower(decrypt($pu_row['vc_email_enc'])) === strtolower($pu_email)) {
                unset($pu_row['vc_email_enc']);
                $pu_konflikte[] = $pu_row;
            }
        }
        json_ok(['konflikte' => $pu_konflikte]);
        break;

    // ── Einzelantrag laden (inkl. Tages-Liste) ───────────────
    case 'get_antrag':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_err('ID fehlt.');

        $stmt = db()->prepare(
            "SELECT a.*, t.kuerzel AS tk_kuerzel, t.bezeichnung AS tk_name,
                    e.bezeichnung AS ereignis_bezeichnung
             FROM antraege a
             JOIN tks t ON t.id = a.tk_id
             LEFT JOIN ereignisse e ON e.id = a.ereignis_id
             WHERE a.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $a = $stmt->fetch();
        if (!$a) json_err('Nicht gefunden.', 404);

        $a['nachname'] = decrypt($a['nachname_enc']);
        $a['vc_email'] = decrypt($a['vc_email_enc']);
        unset($a['nachname_enc'], $a['vc_email_enc'], $a['token']);

        // Wer hat büroseitig die (interne) Freigabe erteilt? "entschieden_von"
        // oben zeigt immer nur die LETZTE Statusänderung - sobald der Antrag
        // danach noch beim AG beantragt/final entschieden wird, wäre "wer hat
        // freigegeben" darüber nicht mehr nachvollziehbar. Das Audit-Log
        // bleibt dagegen dauerhaft erhalten. Drei mögliche Quellen, je
        // nachdem auf welchem Weg freigegeben wurde:
        //   - status_geaendert         → übers Büro-Panel
        //   - entscheidung_freigabe_buero → per E-Mail-Link (Tarifreferent/Büro)
        //   - antrag_eingereicht_buero_direkt → Büro-Direktanlage (schon als freigabe_buero angelegt)
        // Bei mehrfacher Freigabe (z.B. nach Zurücksetzen) die jüngste davon.
        $fg_stmt = db()->prepare(
            "SELECT aktion, details, erstellt_am FROM audit_log
             WHERE antrag_id = ?
               AND (
                    (aktion = 'status_geaendert' AND details LIKE '%→ freigabe_buero durch %')
                 OR aktion = 'entscheidung_freigabe_buero'
                 OR (aktion = 'antrag_eingereicht_buero_direkt' AND details LIKE '%direkt freigabe_buero durch %')
               )
             ORDER BY erstellt_am DESC LIMIT 1"
        );
        $fg_stmt->execute([$id]);
        $fg_row = $fg_stmt->fetch();
        $a['freigabe_erteilt_von'] = null;
        $a['freigabe_erteilt_am']  = null;
        if ($fg_row) {
            $fg_details = (string)$fg_row['details'];
            if ($fg_row['aktion'] === 'entscheidung_freigabe_buero') {
                if (preg_match('/Name eingetragen:\s*(.+)$/u', $fg_details, $fg_mname)) {
                    $a['freigabe_erteilt_von'] = trim($fg_mname[1]);
                } else {
                    $a['freigabe_erteilt_von'] = trim(preg_replace('/^.*Empfänger:\s*/su', '', $fg_details));
                }
            } elseif ($fg_row['aktion'] === 'antrag_eingereicht_buero_direkt') {
                if (preg_match('/direkt freigabe_buero durch\s+(.+?)\)/u', $fg_details, $fg_m2)) {
                    $a['freigabe_erteilt_von'] = trim($fg_m2[1]);
                }
            } elseif (preg_match('/durch\s+(.+)$/u', $fg_details, $fg_m)) {
                $a['freigabe_erteilt_von'] = trim($fg_m[1]);
            }
            $a['freigabe_erteilt_am'] = $fg_row['erstellt_am'];
        }

        $tage_stmt = db()->prepare(
            "SELECT id, tag, status, storniert_am, storniert_von, storno_grund
             FROM antrag_tage WHERE antrag_id = ? ORDER BY tag"
        );
        $tage_stmt->execute([$id]);
        $a['tage'] = $tage_stmt->fetchAll();

        // Bearbeitungsverlauf – alle statusrelevanten Audit-Einträge
        $log_stmt = db()->prepare(
            "SELECT aktion, details, erstellt_am FROM audit_log
              WHERE antrag_id = ?
                AND aktion IN (
                    'antrag_eingereicht',
                    'antrag_eingereicht_buero_direkt',
                    'status_geaendert',
                    'entscheidung_freigabe_buero',
                    'entscheidung_abgelehnt',
                    'entscheidung_genehmigt',
                    'antrag_umgewidmet',
                    'antrag_geloescht',
                    'antrag_geloescht_mitglied',
                    'beantragt_beim_arbeitgeber',
                    'ag_erinnerung_gesendet',
                    'ag_rueckmeldung'
                )
              ORDER BY erstellt_am ASC"
        );
        $log_stmt->execute([$id]);
        $a['bearbeitungs_log'] = $log_stmt->fetchAll();

        json_ok($a);
        break;

    // ── Antrag um weitere Tage erweitern ─────────────────────
    // Wird von antrag_neu.php genutzt: wenn bei einem Termin nur EIN Teil
    // kollidiert (Umwidmung) und der Rest frei ist, wird der freie Teil
    // hier direkt in den soeben umgewidmeten Antrag mit aufgenommen,
    // statt einen zweiten, separaten Antrag/Ereignis anzulegen.
    case 'antrag_erweitern':
        $eu_antrag_id = (int)($_POST['antrag_id'] ?? 0);
        $eu_tage = array_values(array_filter(array_map('clean', (array)($_POST['tage'] ?? []))));
        if (!$eu_antrag_id || empty($eu_tage)) json_err('Parameter fehlen.');
        foreach ($eu_tage as $eu_d) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eu_d)) json_err("Ungültiges Datum: {$eu_d}");
        }

        $eu_stmt = db()->prepare("SELECT * FROM antraege WHERE id = ? LIMIT 1");
        $eu_stmt->execute([$eu_antrag_id]);
        $eu_a = $eu_stmt->fetch();
        if (!$eu_a) json_err('Antrag nicht gefunden.', 404);

        $eu_ins = db()->prepare(
            "INSERT INTO antrag_tage (antrag_id, ereignis_id, tag, status)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE ereignis_id = VALUES(ereignis_id)"
        );
        foreach ($eu_tage as $eu_d) {
            $eu_ins->execute([$eu_antrag_id, $eu_a['ereignis_id'], $eu_d, $eu_a['status']]);
        }

        // Zeitraum auf antraege + ereignisse ausweiten (min/max aller Tage)
        $eu_mm = db()->prepare("SELECT MIN(tag) AS von, MAX(tag) AS bis FROM antrag_tage WHERE antrag_id = ?");
        $eu_mm->execute([$eu_antrag_id]);
        $eu_range = $eu_mm->fetch();

        db()->prepare("UPDATE antraege SET zeitraum_von = ?, zeitraum_bis = ? WHERE id = ?")
            ->execute([$eu_range['von'], $eu_range['bis'], $eu_antrag_id]);
        db()->prepare("UPDATE ereignisse SET zeitraum_von = ?, zeitraum_bis = ? WHERE id = ?")
            ->execute([$eu_range['von'], $eu_range['bis'], $eu_a['ereignis_id']]);

        audit('antrag_erweitert', $eu_antrag_id,
            'Tage ergänzt: ' . implode(',', $eu_tage) . ' durch ' . $_SESSION['panel_user']);

        json_ok(['antrag_id' => $eu_antrag_id, 'von' => $eu_range['von'], 'bis' => $eu_range['bis']]);
        break;

    // ══════════════════════════════════════════════════════════
    // BEANTRAGUNG BEIM ARBEITGEBER
    // ══════════════════════════════════════════════════════════

    // Alle drei Mailvorlagen laden (gespeichert oder Werkseinstellung)
    case 'get_ag_mail_vorlagen':
        json_ok([
            'typen'    => ag_antrag_vorlage_typen(),
            'vorlagen' => ag_antrag_vorlagen_laden(db()),
        ]);
        break;

    // Genau eine Mailvorlage speichern (die anderen beiden bleiben unangetastet)
    case 'save_ag_mail_vorlage':
        $v_typ     = clean($_POST['typ']     ?? '');
        $v_subject = trim($_POST['subject']  ?? '');
        $v_body    = trim($_POST['body']     ?? '');
        if (!array_key_exists($v_typ, ag_antrag_vorlage_typen())) json_err('Ungültiger Vorlagen-Typ.');
        if (!$v_subject || !$v_body) json_err('Betreff und Text dürfen nicht leer sein.');

        // Geschützter Platzhalter: {{LISTE}} ist die Stelle, an der die
        // Mitgliederliste automatisch eingefügt wird - darf beim
        // Bearbeiten des Standardtexts nicht entfernt werden.
        $v_fehler = mail_vorlage_pflicht_platzhalter_pruefen($v_body, '{{LISTE}}');
        if ($v_fehler) json_err($v_fehler);

        $v_alle = ag_antrag_vorlagen_laden(db());
        $v_alle[$v_typ] = ['subject' => $v_subject, 'body' => $v_body];

        set_einstellung(
            db(),
            'ag_antrag_mail_vorlagen',
            json_encode($v_alle, JSON_UNESCAPED_UNICODE),
            $_SESSION['panel_user']
        );
        json_ok();
        break;

    // ── Storno-/Umwidmungs-Mailvorlagen (Arbeitgeber-Mails) ────
    case 'get_storno_mail_vorlagen':
        json_ok([
            'vorlagen' => storno_vorlagen_laden(db()),
            'typen'    => storno_vorlage_typen(),
        ]);
        break;

    case 'save_storno_mail_vorlage':
        $sv_typ     = clean($_POST['typ']     ?? '');
        $sv_subject = trim($_POST['subject']  ?? '');
        $sv_body    = trim($_POST['body']     ?? '');
        if (!array_key_exists($sv_typ, storno_vorlage_typen())) json_err('Ungültiger Vorlagen-Typ.');
        if (!$sv_subject || !$sv_body) json_err('Betreff und Text dürfen nicht leer sein.');

        // Geschützter Platzhalter: {{MITGLIEDER}} ist die Stelle, an der
        // die Namensliste automatisch eingefügt wird.
        $sv_fehler = mail_vorlage_pflicht_platzhalter_pruefen($sv_body, '{{MITGLIEDER}}');
        if ($sv_fehler) json_err($sv_fehler);

        $sv_alle = storno_vorlagen_laden(db());
        $sv_alle[$sv_typ] = ['subject' => $sv_subject, 'body' => $sv_body];

        set_einstellung(
            db(),
            'storno_umwidmung_mail_vorlagen',
            json_encode($sv_alle, JSON_UNESCAPED_UNICODE),
            $_SESSION['panel_user']
        );
        json_ok();
        break;

    // Vorschau der Sammel-Beantragungsmail für eine Airline + Zeitraum.
    // Berücksichtigt NUR Anträge mit status = 'freigabe_buero'.
    // Optional: antrag_ids[] - wenn übergeben (z.B. aus der Checkbox-Auswahl
    // auf Basis eines einzelnen Ereignisses), werden AUSSCHLIESSLICH diese
    // Anträge berücksichtigt, nicht alle im Zeitraum/Airline gefundenen.
    case 'get_ag_antragsmail_vorschau':
        $agv_airline = clean($_POST['airline']      ?? '');
        $agv_von     = clean($_POST['zeitraum_von'] ?? '');
        $agv_bis     = clean($_POST['zeitraum_bis'] ?? '');
        $agv_typ     = clean($_POST['typ']          ?? 'standard');
        $agv_sprache = in_array($_POST['sprache'] ?? '', ['de','en']) ? $_POST['sprache'] : 'de';
        $agv_nur_ids = array_filter(array_map('intval', (array)($_POST['antrag_ids'] ?? [])));
        $agv_erinnerung  = (int)($_POST['erinnerung']    ?? 0) !== 0;
        $agv_excel_senden = (int)($_POST['excel_senden'] ?? 1) !== 0;
        $agv_doc_senden   = (int)($_POST['doc_senden']   ?? 1) !== 0;
        $agv_tag_status = $agv_erinnerung
            ? "at2.status IN ('freigabe_buero','beantragt_ag')"
            : "at2.status = 'freigabe_buero'";
        if (!$agv_airline || !$agv_von || !$agv_bis) json_err('Airline und Zeitraum sind Pflichtfelder.');
        if (!array_key_exists($agv_typ, ag_antrag_vorlage_typen())) json_err('Ungültiger Vorlagen-Typ.');

        // Tages-basierte Abfrage: jeder Antragstag im gewählten Zeitraum
        // bekommt eine eigene Zeile. So werden monatsübergreifende Anträge
        // korrekt gesplittet und bereits gemeldete Tage ausgeblendet.
        if ($agv_nur_ids) {
            // Explizite Auswahl: nur die gewählten Anträge, aber ebenfalls
            // NUR deren Tage im gefilterten Zeitraum. Monatsübergreifende
            // Anträge (z.B. 30.09.-01.10.) werden so getrennt: bei Filter
            // "September" wird nur der 30.09. beantragt, der 01.10. bleibt
            // "Intern genehmigt".
            $agv_ph = implode(',', array_fill(0, count($agv_nur_ids), '?'));
            $agv_stmt = db()->prepare(
                "SELECT a.id, a.vorname, a.nachname_enc, a.veranstaltung, a.tk_id,
                        at2.tag AS zeitraum_von, at2.tag AS zeitraum_bis
                 FROM antrag_tage at2
                 JOIN antraege a ON a.id = at2.antrag_id
                 WHERE a.id IN ({$agv_ph})
                   AND a.airline = ?
                   AND a.status IN ('freigabe_buero', 'beantragt_ag')
                   AND {$agv_tag_status}
                   AND at2.tag BETWEEN ? AND ?
                 ORDER BY a.vorname, at2.tag"
            );
            $agv_stmt->execute([...$agv_nur_ids, $agv_airline, $agv_von, $agv_bis]);
        } else {
            $agv_stmt = db()->prepare(
                "SELECT a.id, a.vorname, a.nachname_enc, a.veranstaltung, a.tk_id,
                        at2.tag AS zeitraum_von, at2.tag AS zeitraum_bis
                 FROM antrag_tage at2
                 JOIN antraege a ON a.id = at2.antrag_id
                 WHERE a.airline = ?
                   AND a.status IN ('freigabe_buero', 'beantragt_ag')
                   AND {$agv_tag_status}
                   AND at2.tag BETWEEN ? AND ?
                 ORDER BY a.vorname, at2.tag"
            );
            $agv_stmt->execute([$agv_airline, $agv_von, $agv_bis]);
        }

        $agv_rows = $agv_stmt->fetchAll();
        foreach ($agv_rows as &$agv_r) {
            $agv_r['nachname'] = decrypt($agv_r['nachname_enc']);
            unset($agv_r['nachname_enc']);
        }
        unset($agv_r);

        // Nach Nachname sortieren (erst nach Entschlüsselung möglich)
        usort($agv_rows, fn($a, $b) =>
            strcmp($a['nachname'] . $a['vorname'] . $a['zeitraum_von'],
                   $b['nachname'] . $b['vorname'] . $b['zeitraum_von'])
        );

        if (!$agv_rows) json_err('Keine Anträge mit Status „Intern genehmigt" für diese Airline/diesen Zeitraum gefunden.');

        // Alle aktiven Kontakte dieser Airline (jetzt direkt an der Airline
        // hinterlegt, nicht mehr über eine TK vermittelt) – die Mail geht
        // an ALLE gleichzeitig (To:), nicht nur an einen.
        $agv_kontakt_stmt = db()->prepare(
            "SELECT email, bezeichnung
             FROM flugbetrieb_kontakte
             WHERE airline = ? AND aktiv = 1
             ORDER BY id"
        );
        $agv_kontakt_stmt->execute([$agv_airline]);
        $agv_kontakte = $agv_kontakt_stmt->fetchAll();

        $agv_cc_email  = get_einstellung(db(), 'storno_email_cc');
        $agv_from_name = $_SESSION['panel_user'];
        $agv_from_email = '';
        $agv_pu = db()->prepare('SELECT email FROM panel_users WHERE username = ? LIMIT 1');
        $agv_pu->execute([$agv_from_name]);
        $agv_pu_row = $agv_pu->fetch();
        if ($agv_pu_row && $agv_pu_row['email']) $agv_from_email = $agv_pu_row['email'];

        $agv_vorlage = ag_antrag_vorlage_laden(db(), $agv_typ, $agv_sprache);
        $agv_vars = [
            'AIRLINE'    => $agv_airline,
            'VON'        => implode('.', array_reverse(explode('-', $agv_von))),
            'BIS'        => implode('.', array_reverse(explode('-', $agv_bis))),
            'LISTE'      => ag_antrag_liste_bauen($agv_rows, $agv_sprache),
            'BEARBEITER' => $agv_from_name,
            'ANZAHL'     => (string)count($agv_rows),
        ];

        // Freistellungsliste erzeugen – nur wenn Excel gewünscht
        $agv_anhang = null;
        if ($agv_excel_senden && $agv_typ !== 'kurzfristig') {
            try {
                $agv_anhang = ag_antrag_anhang_bereitstellen(db(), $agv_airline, $agv_von, $agv_bis, $agv_typ, $agv_sprache, $agv_nur_ids);
            } catch (Throwable $e) {
                error_log('ag_antrag_anhang_bereitstellen (Vorschau) Fehler: ' . $e->getMessage());
            }
        }

        $agv_doc_aktiv_stmt = db()->prepare(
            "SELECT doc_anhang_aktiv FROM airline_einstellungen WHERE airline = ? LIMIT 1"
        );
        $agv_doc_aktiv_stmt->execute([$agv_airline]);
        $agv_doc_aktiv = (bool)$agv_doc_aktiv_stmt->fetchColumn() && ($agv_typ !== 'kurzfristig') && $agv_doc_senden;

        // Word-Formular(e) erzeugen – nur wenn DOC gewünscht und aktiv
        $agv_docs = [];
        if ($agv_doc_aktiv) {
            try {
                $agv_docs = ag_antrag_doc_anhaenge_bereitstellen(db(), $agv_airline, $agv_von, $agv_bis, $agv_typ, $agv_from_name, $agv_nur_ids);
            } catch (Throwable $e) {
                error_log('ag_antrag_doc_anhaenge_bereitstellen (Vorschau) Fehler: ' . $e->getMessage());
            }
        }

        json_ok([
            'typ'            => $agv_typ,
            'typ_label'      => ag_antrag_vorlage_typen()[$agv_typ],
            'from_name'      => $agv_from_name,
            'from_email'     => $agv_from_email,
            'to_bezeichnung' => implode(', ', array_column($agv_kontakte, 'bezeichnung')),
            'to_email'       => implode(', ', array_column($agv_kontakte, 'email')),
            'to_kontakte'    => $agv_kontakte,
            'cc_email'       => $agv_cc_email,
            'subject'        => ag_antrag_render($agv_vorlage['subject'], $agv_vars),
            'body'           => ag_antrag_render($agv_vorlage['body'], $agv_vars),
            'antrag_ids'     => array_column($agv_rows, 'id'),
            'anzahl'         => count($agv_rows),
            'hat_anhang'     => $agv_excel_senden && ($agv_typ !== 'kurzfristig'),
            'anhang_token'   => $agv_anhang['token']    ?? null,
            'anhang_name'    => $agv_anhang['filename'] ?? null,
            'doc_aktiv'      => $agv_doc_aktiv,
            'doc_anhaenge'   => $agv_docs,
        ]);
        break;

    // Versendet die Sammel-Beantragungsmail und setzt die enthaltenen
    // Anträge von 'freigabe_buero' auf 'beantragt_ag'.
    case 'sende_ag_antragsmail':
        $as_airline    = clean($_POST['airline'] ?? '');
        $as_antrag_ids = array_map('intval', (array)($_POST['antrag_ids'] ?? []));
        $as_von_filter = clean($_POST['zeitraum_von'] ?? '');
        $as_bis_filter = clean($_POST['zeitraum_bis'] ?? '');
        $as_typ        = clean($_POST['typ'] ?? 'standard');
        $as_sprache    = in_array($_POST['sprache'] ?? '', ['de','en']) ? $_POST['sprache'] : 'de';
        $as_erinnerung = (int)($_POST['erinnerung'] ?? 0) !== 0;
        $as_tag_status = $as_erinnerung
            ? "at2.status IN ('freigabe_buero','beantragt_ag')"
            : "at2.status = 'freigabe_buero'";
        $as_anhang_token = clean($_POST['anhang_token'] ?? '');
        $as_doc_tokens   = array_map('clean', (array)($_POST['doc_tokens'] ?? []));
        $as_excel_senden = (int)($_POST['excel_senden'] ?? 1) !== 0; // Standard: an
        $as_doc_senden   = (int)($_POST['doc_senden']   ?? 1) !== 0; // Standard: an
        if (!$as_airline || empty($as_antrag_ids)) json_err('Parameter fehlen.');
        if (!array_key_exists($as_typ, ag_antrag_vorlage_typen())) json_err('Ungültiger Vorlagen-Typ.');

        // Nur die Tage der gewählten Anträge im gefilterten Zeitraum.
        // Monatsübergreifende Anträge werden dadurch getrennt: Tage
        // außerhalb des Filters bleiben "Intern genehmigt" und werden
        // unten per Split im Ursprungsantrag belassen.
        $as_datum_ok = static fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        $as_datum_sql    = '';
        $as_datum_params = [];
        if ($as_datum_ok($as_von_filter) && $as_datum_ok($as_bis_filter)) {
            $as_datum_sql    = 'AND at2.tag BETWEEN ? AND ?';
            $as_datum_params = [$as_von_filter, $as_bis_filter];
        }
        $in = implode(',', array_fill(0, count($as_antrag_ids), '?'));
        $as_stmt = db()->prepare(
            "SELECT a.id, a.vorname, a.nachname_enc, a.veranstaltung, at2.tag AS zeitraum_von,
                    at2.tag AS zeitraum_bis, at2.id AS tag_id
             FROM antrag_tage at2
             JOIN antraege a ON a.id = at2.antrag_id
             WHERE a.id IN ({$in})
               AND a.airline = ?
               AND a.status IN ('freigabe_buero', 'beantragt_ag')
               AND {$as_tag_status}
               {$as_datum_sql}
             ORDER BY a.vorname, at2.tag"
        );
        $as_stmt->execute([...$as_antrag_ids, $as_airline, ...$as_datum_params]);
        $as_rows = $as_stmt->fetchAll();
        if (!$as_rows) json_err('Keine passenden Tage mehr gefunden (Status hat sich evtl. geändert).');

        foreach ($as_rows as &$as_r) {
            $as_r['nachname'] = decrypt($as_r['nachname_enc']);
            unset($as_r['nachname_enc']);
        }
        unset($as_r);
        usort($as_rows, fn($a, $b) =>
            strcmp($a['nachname'] . $a['vorname'] . $a['zeitraum_von'],
                   $b['nachname'] . $b['vorname'] . $b['zeitraum_von'])
        );

        $as_ids_gueltig = array_unique(array_column($as_rows, 'id'));
        $as_tag_ids     = array_column($as_rows, 'tag_id');

        $as_von = $as_von_filter ?: min(array_column($as_rows, 'zeitraum_von'));
        $as_bis = $as_bis_filter ?: max(array_column($as_rows, 'zeitraum_bis'));

        $as_kontakt_stmt = db()->prepare(
            "SELECT email, bezeichnung
             FROM flugbetrieb_kontakte
             WHERE airline = ? AND aktiv = 1
             ORDER BY id"
        );
        $as_kontakt_stmt->execute([$as_airline]);
        $as_kontakte = $as_kontakt_stmt->fetchAll();
        if (!$as_kontakte) {
            json_err('Kein aktiver Flugbetrieb-Kontakt für diese Airline hinterlegt.');
        }
        $as_to = implode(', ', array_map(
            fn($k) => trim($k['bezeichnung']) !== '' ? "{$k['bezeichnung']} <{$k['email']}>" : $k['email'],
            $as_kontakte
        ));

        $as_cc_email  = get_einstellung(db(), 'storno_email_cc');
        $as_from_name = $_SESSION['panel_user'];

        $as_vorlage = ag_antrag_vorlage_laden(db(), $as_typ, $as_sprache);
        $as_vars = [
            'AIRLINE'    => $as_airline,
            'VON'        => implode('.', array_reverse(explode('-', $as_von))),
            'BIS'        => implode('.', array_reverse(explode('-', $as_bis))),
            'LISTE'      => ag_antrag_liste_bauen($as_rows, $as_sprache),
            'BEARBEITER' => $as_from_name,
            'ANZAHL'     => (string)count($as_rows),
        ];
        $as_subject = ag_antrag_render($as_vorlage['subject'], $as_vars);
        $as_body    = ag_antrag_render($as_vorlage['body'],    $as_vars);

        // Vorschau war editierbar (siehe beantragung_ag.php) - vom Nutzer
        // bearbeiteten Text 1:1 übernehmen, falls mitgeschickt.
        $as_subject_override = trim($_POST['subject'] ?? '');
        $as_body_override    = trim($_POST['body']    ?? '');
        if ($as_subject_override) $as_subject = $as_subject_override;
        if ($as_body_override)    $as_body    = $as_body_override;

        $as_html    = _mail_wrap('Beantragung beim Arbeitgeber', '<p>' . nl2br(htmlspecialchars($as_body)) . '</p>');

        // Freistellungsliste-Excel: die bereits in der Vorschau erzeugte und
        // vom Büro geprüfte Datei wiederverwenden (anhang_token), NICHT neu
        // erzeugen. Nur falls kein/kein gültiger Token übergeben wurde
        // (z.B. Altsystem-Aufruf), wird als Fallback frisch generiert.
        // Fehler hierbei werden abgefangen, damit sie nicht zu einem
        // hängenden Request im Panel führen (siehe vorheriger Bug).
        $as_attachments = [];
        $as_excel_tmp   = null;
        try {
            if ($as_excel_senden) {
                $as_excel = $as_anhang_token ? ag_antrag_anhang_aus_token($as_anhang_token) : null;
                if (!$as_excel && $as_typ !== 'kurzfristig') {
                    $as_excel_tmp = ag_antrag_excel_bauen(db(), $as_airline, $as_von, $as_bis, $as_typ, $as_sprache, $as_antrag_ids);
                    $as_excel = $as_excel_tmp;
                }
                if ($as_excel) {
                    $as_attachments[] = [
                        'path'     => $as_excel['path'],
                        'filename' => $as_excel['filename'],
                        'mime'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log('sende_ag_antragsmail Excel-Fehler: ' . $e->getMessage());
            json_err('Freistellungsliste konnte nicht erzeugt werden: ' . $e->getMessage());
        }

        // Word-Formular(e) (Antrag auf FS/V4-Freistellung, je Stationierungsort)
        // zusätzlich anhängen, falls für diese Airline aktiviert. Die bereits
        // in der Vorschau erzeugten und vom Büro geprüften Dateien werden
        // über ihre Tokens wiederverwendet (analog zur Excel), NICHT neu
        // erzeugt. Nur falls keine Tokens übergeben wurden, wird als
        // Fallback frisch generiert.
        $as_doc_tmps = [];
        try {
            $as_docs_resolved = [];
            if ($as_doc_senden) {
                foreach ($as_doc_tokens as $dtoken) {
                    if (!$dtoken) continue;
                    $resolved = ag_antrag_anhang_aus_token($dtoken);
                    if ($resolved) $as_docs_resolved[] = $resolved;
                }

                if (!$as_docs_resolved) {
                    $as_doc_aktiv_stmt = db()->prepare(
                        "SELECT doc_anhang_aktiv FROM airline_einstellungen WHERE airline = ? LIMIT 1"
                    );
                    $as_doc_aktiv_stmt->execute([$as_airline]);
                    if ((bool)$as_doc_aktiv_stmt->fetchColumn()) {
                        $as_docs_resolved = ag_antrag_doc_dateien_bauen(db(), $as_airline, $as_von, $as_bis, $as_typ, $as_from_name, $as_antrag_ids);
                        foreach ($as_docs_resolved as $doc) $as_doc_tmps[] = $doc['path'];
                    }
                }

                foreach ($as_docs_resolved as $doc) {
                    $as_attachments[] = [
                        'path'     => $doc['path'],
                        'filename' => $doc['filename'],
                        'mime'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log('sende_ag_antragsmail DOC-Fehler: ' . $e->getMessage());
            // Bewusst NICHT abbrechen - die Excel-Freistellungsliste soll trotzdem
            // versendet werden können, auch wenn das zusätzliche Word-Formular
            // aus irgendeinem Grund fehlschlägt.
        }

        $as_gesendet = @_mail_send_attach($as_to, $as_subject, $as_html, $as_cc_email ?: '', $as_attachments);

        if ($as_excel_tmp) @unlink($as_excel_tmp['path']);
        foreach ($as_doc_tmps as $tmpPath) @unlink($tmpPath);

        if (!$as_gesendet) {
            json_err('E-Mail konnte nicht versendet werden. Status wurde NICHT geändert.');
        }

        // Status umstellen: freigabe_buero -> beantragt_ag. Anträge, die
        // bereits beantragt_ag sind (Erinnerungs-Versand), werden bewusst
        // NICHT nochmal angefasst - der Status ist ja schon korrekt, hier
        // ging es nur um ein erneutes Senden derselben Mail.
        // antrag_tage.status wird automatisch per DB-Trigger
        // ── Split: Beantrage Tage als eigenen Antrag abtrennen ───
        // Wie bei Umwidmung: Tage die beim AG beantragt wurden werden
        // aus dem Ursprungsantrag herausgetrennt und als neuer Antrag
        // mit Status 'beantragt_ag' gespeichert.
        $as_neue_antrag_ids = [];

        // Welche tag_ids gehören zu welchem Antrag?
        $as_tags_pro_antrag = [];
        foreach ($as_rows as $r) {
            $as_tags_pro_antrag[$r['id']][] = ['tag_id' => $r['tag_id'], 'tag' => $r['zeitraum_von']];
        }

        foreach ($as_ids_gueltig as $as_aid) {
            // Alle Tage dieses Antrags laden
            $as_alle_tage_stmt = db()->prepare(
                "SELECT id AS tag_id, tag, status FROM antrag_tage
                  WHERE antrag_id = ? ORDER BY tag"
            );
            $as_alle_tage_stmt->execute([$as_aid]);
            $as_alle_tage = $as_alle_tage_stmt->fetchAll();

            // Welche Tage werden beantragt (sind in as_tag_ids)?
            $as_tag_ids_set = array_flip($as_tag_ids);
            $as_tage_beantragt = [];
            $as_tage_bleiben   = [];
            foreach ($as_alle_tage as $t) {
                if (isset($as_tag_ids_set[$t['tag_id']]) && $t['status'] === 'freigabe_buero') {
                    $as_tage_beantragt[] = $t;
                } else {
                    $as_tage_bleiben[] = $t;
                }
            }

            if (empty($as_tage_beantragt)) continue;

            // Ursprungsantrag laden
            $as_orig_stmt = db()->prepare("SELECT * FROM antraege WHERE id = ? LIMIT 1");
            $as_orig_stmt->execute([$as_aid]);
            $as_orig = $as_orig_stmt->fetch();
            if (!$as_orig) continue;

            if (empty($as_tage_bleiben)) {
                // Alle Tage beantragt → in konsekutive Gruppen splitten
                $as_beantragt_tags = array_column($as_tage_beantragt, 'tag');
                $as_tag_zu_id = array_column($as_tage_beantragt, 'tag_id', 'tag');
                $as_gruppen = tage_zu_konsekutiven_gruppen($as_beantragt_tags);
                $as_erste = array_shift($as_gruppen);

                // Ursprungsantrag für erste Gruppe
                $ph_upd = implode(',', array_fill(0, count($as_erste), '?'));
                db()->prepare("UPDATE antraege SET status='beantragt_ag', zeitraum_von=?, zeitraum_bis=? WHERE id=?")
                    ->execute([min($as_erste), max($as_erste), $as_aid]);
                db()->prepare("UPDATE antrag_tage SET status='beantragt_ag' WHERE tag IN ({$ph_upd}) AND antrag_id=?")
                    ->execute([...$as_erste, $as_aid]);
                $as_neue_antrag_ids[] = $as_aid;

                foreach ($as_gruppen as $as_gruppe) {
                    $ph_del2 = implode(',', array_fill(0, count($as_gruppe), '?'));
                    db()->prepare("DELETE FROM antrag_tage WHERE antrag_id=? AND tag IN ({$ph_del2})")
                        ->execute([$as_aid, ...$as_gruppe]);
                    db()->prepare(
                        "INSERT INTO antraege (mitglied_id,vorname,nachname_enc,tk_id,vc_email_enc,airline,position,flugzeugmuster,stationierung,veranstaltung,zeitraum_von,zeitraum_bis,token,ereignis_id,freistellungscode,status,entschieden_am,entschieden_von,notiz) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'beantragt_ag',NOW(),?,?)"
                    )->execute([$as_orig['mitglied_id'],$as_orig['vorname'],$as_orig['nachname_enc'],$as_orig['tk_id'],$as_orig['vc_email_enc'],$as_orig['airline'],$as_orig['position'],$as_orig['flugzeugmuster'],$as_orig['stationierung'],$as_orig['veranstaltung'],min($as_gruppe),max($as_gruppe),generate_token(),$as_orig['ereignis_id'],$as_orig['freistellungscode'],$as_from_name,"[Split aus #{$as_aid}]"]);
                    $as_n = (int)db()->lastInsertId();
                    $as_neue_antrag_ids[] = $as_n;
                    $as_ins2 = db()->prepare("INSERT INTO antrag_tage (antrag_id,ereignis_id,tag,status) VALUES (?,?,?,'beantragt_ag')");
                    foreach ($as_gruppe as $t) $as_ins2->execute([$as_n,$as_orig['ereignis_id'],$t]);
                    audit('beantragt_beim_arbeitgeber',$as_n,"Split aus #{$as_aid}");
                }
            } else {
                // Teiltage beantragt → konsekutive Gruppen als separate Anträge
                $as_beantragt_tags = array_column($as_tage_beantragt, 'tag');
                $as_gruppen = tage_zu_konsekutiven_gruppen($as_beantragt_tags);

                $as_del_ph = implode(',', array_fill(0, count($as_tage_beantragt), '?'));
                db()->prepare("DELETE FROM antrag_tage WHERE id IN ({$as_del_ph})")
                    ->execute(array_column($as_tage_beantragt, 'tag_id'));

                $as_bleiben_tags = array_column($as_tage_bleiben, 'tag');
                db()->prepare("UPDATE antraege SET zeitraum_von=?,zeitraum_bis=?,notiz=CONCAT(COALESCE(notiz,''),'
[Teilmeldung AG]') WHERE id=?")
                    ->execute([min($as_bleiben_tags),max($as_bleiben_tags),$as_aid]);

                foreach ($as_gruppen as $as_gruppe) {
                    db()->prepare(
                        "INSERT INTO antraege (mitglied_id,vorname,nachname_enc,tk_id,vc_email_enc,airline,position,flugzeugmuster,stationierung,veranstaltung,zeitraum_von,zeitraum_bis,token,ereignis_id,freistellungscode,status,entschieden_am,entschieden_von,notiz) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'beantragt_ag',NOW(),?,?)"
                    )->execute([$as_orig['mitglied_id'],$as_orig['vorname'],$as_orig['nachname_enc'],$as_orig['tk_id'],$as_orig['vc_email_enc'],$as_orig['airline'],$as_orig['position'],$as_orig['flugzeugmuster'],$as_orig['stationierung'],$as_orig['veranstaltung'],min($as_gruppe),max($as_gruppe),generate_token(),$as_orig['ereignis_id'],$as_orig['freistellungscode'],$as_from_name,"[Split aus #{$as_aid}: beim AG beantragt]"]);
                    $as_neuer_aid = (int)db()->lastInsertId();
                    $as_neue_antrag_ids[] = $as_neuer_aid;
                    $as_ins_tag = db()->prepare("INSERT INTO antrag_tage (antrag_id,ereignis_id,tag,status) VALUES (?,?,?,'beantragt_ag')");
                    foreach ($as_gruppe as $t) $as_ins_tag->execute([$as_neuer_aid,$as_orig['ereignis_id'],$t]);
                    audit('beantragt_beim_arbeitgeber',$as_neuer_aid,"Split aus #{$as_aid}: Airline:{$as_airline} an {$as_to}");
                }
                audit('beantragt_beim_arbeitgeber',$as_aid,"Teilmeldung: ".count($as_tage_beantragt)." Tage abgetrennt");
            }
        }

        json_ok([
            'gesendet'   => true,
            'anzahl'     => count($as_tag_ids),
            'antrag_ids' => $as_neue_antrag_ids,
            'to_email'   => $as_to,
        ]);
        break;

    // ── Antrag-Status manuell ändern ──────────────────────────
    case 'get_freistellungscodes_fuer_airline':
        $fc_airline = clean($_POST['airline'] ?? '');
        if (!$fc_airline) json_err('Airline fehlt.');
        $fc_stmt = db()->prepare(
            "SELECT DISTINCT freistellungscode FROM finance_preise
              WHERE airline = ? AND freistellungscode IS NOT NULL AND freistellungscode <> ''
              ORDER BY freistellungscode"
        );
        $fc_stmt->execute([$fc_airline]);
        json_ok(['codes' => array_column($fc_stmt->fetchAll(), 'freistellungscode')]);
        break;

    case 'get_antrag_tage':
        $gat_id = (int)($_POST['antrag_id'] ?? 0);
        if (!$gat_id) json_err('Antrag-ID fehlt.');
        $gat_stmt = db()->prepare(
            "SELECT id, tag, status FROM antrag_tage WHERE antrag_id = ? ORDER BY tag"
        );
        $gat_stmt->execute([$gat_id]);
        json_ok(['tage' => $gat_stmt->fetchAll()]);
        break;

    case 'update_antrag':
        $id         = (int)($_POST['id']     ?? 0);
        $new_status = clean($_POST['status'] ?? '');
        $notiz      = clean($_POST['notiz']  ?? '');
        $new_symbol = clean($_POST['freistellungscode'] ?? '');
        $tag_ids    = array_filter(array_map('intval', (array)($_POST['tag_ids'] ?? [])));
        $ok_stati   = ['ausstehend', 'freigabe_buero', 'beantragt_ag', 'genehmigt', 'abgelehnt', 'abgelehnt_ag', 'storno_buero', 'storno_bestaetigt_ag'];

        if (!$id || !in_array($new_status, $ok_stati, true)) json_err('Ungültige Parameter.');

        $stmt = db()->prepare("SELECT * FROM antraege WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $a = $stmt->fetch();
        if (!$a) json_err('Antrag nicht gefunden.', 404);

        // Alle aktiven Tage des Antrags laden
        $alle_tage_stmt = db()->prepare(
            "SELECT id AS tag_id, tag, status FROM antrag_tage
              WHERE antrag_id = ? AND status NOT IN ('storno_buero','storno_bestaetigt_ag')
              ORDER BY tag"
        );
        $alle_tage_stmt->execute([$id]);
        $alle_tage = $alle_tage_stmt->fetchAll();

        // Wenn keine tag_ids übergeben oder alle Tage gewählt → kein Split
        $alle_tag_ids = array_column($alle_tage, 'tag_id');
        $tag_ids_set  = !empty($tag_ids) ? array_flip($tag_ids) : array_flip($alle_tag_ids);
        $tage_betroffen = array_filter($alle_tage, fn($t) => isset($tag_ids_set[$t['tag_id']]));
        $tage_bleiben   = array_filter($alle_tage, fn($t) => !isset($tag_ids_set[$t['tag_id']]));

        $bearbeiter = $_SESSION['panel_user'];
        $symbol     = $new_symbol ?: $a['freistellungscode'] ?: null;

        if (empty($tage_bleiben)) {
            // Alle Tage betroffen → Antrag direkt aktualisieren
            $update_fields = "status=?, notiz=?, entschieden_am=NOW(), entschieden_von=?";
            $update_params = [$new_status, $notiz ?: null, $bearbeiter];
            if ($new_symbol) {
                $update_fields .= ", freistellungscode=?";
                $update_params[] = $new_symbol;
            }
            $update_params[] = $id;
            db()->prepare("UPDATE antraege SET {$update_fields} WHERE id=?")
                ->execute($update_params);
            // Tage synchronisieren
            $ph = implode(',', array_fill(0, count($alle_tag_ids), '?'));
            db()->prepare("UPDATE antrag_tage SET status=? WHERE id IN ({$ph})")
                ->execute([$new_status, ...$alle_tag_ids]);
            audit('status_geaendert', $id, "{$a['status']} → {$new_status} durch {$bearbeiter}");
        } else {
            // Teiltage betroffen → Split nach konsekutiven Gruppen
            $betroffen_tags = array_column(array_values($tage_betroffen), 'tag');
            $gruppen = tage_zu_konsekutiven_gruppen($betroffen_tags);

            // Betroffene Tage aus Ursprungsantrag entfernen
            $ph_del = implode(',', array_fill(0, count($tage_betroffen), '?'));
            db()->prepare("DELETE FROM antrag_tage WHERE id IN ({$ph_del})")
                ->execute(array_column(array_values($tage_betroffen), 'tag_id'));

            // Ursprungsantrag auf verbleibende Tage anpassen
            $bleiben_tags = array_column(array_values($tage_bleiben), 'tag');
            db()->prepare(
                "UPDATE antraege SET zeitraum_von=?, zeitraum_bis=?,
                 notiz=CONCAT(COALESCE(notiz,''),'\n[Split: ',?,' Tage → ',?,']')
                 WHERE id=?"
            )->execute([min($bleiben_tags), max($bleiben_tags),
                count($tage_betroffen), $new_status, $id]);

            // Pro konsekutiver Gruppe einen neuen Antrag anlegen
            foreach ($gruppen as $gruppe) {
                db()->prepare(
                    "INSERT INTO antraege
                     (mitglied_id,vorname,nachname_enc,tk_id,vc_email_enc,
                      airline,position,flugzeugmuster,stationierung,veranstaltung,
                      zeitraum_von,zeitraum_bis,token,ereignis_id,freistellungscode,
                      status,entschieden_am,entschieden_von,notiz)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?)"
                )->execute([
                    $a['mitglied_id'],$a['vorname'],$a['nachname_enc'],
                    $a['tk_id'],$a['vc_email_enc'],
                    $a['airline'],$a['position'],$a['flugzeugmuster'],$a['stationierung'],
                    $a['veranstaltung'], min($gruppe), max($gruppe),
                    generate_token(), $a['ereignis_id'], $symbol,
                    $new_status, $bearbeiter,
                    ($notiz ?: '') . "\n[Split aus #{$id}]",
                ]);
                $neuer = (int)db()->lastInsertId();
                $ins = db()->prepare(
                    "INSERT INTO antrag_tage (antrag_id,ereignis_id,tag,status) VALUES (?,?,?,?)"
                );
                foreach ($gruppe as $t) {
                    $ins->execute([$neuer, $a['ereignis_id'], $t, $new_status]);
                }
                audit('status_geaendert', $neuer, "Split aus #{$id}: {$new_status}");
            }
        }

        // Mail ans Mitglied
        $mail_stati = ['genehmigt', 'abgelehnt', 'abgelehnt_ag', 'storno_buero', 'freigabe_buero'];
        if ($new_status !== $a['status'] && in_array($new_status, $mail_stati, true)) {
            $vc_email   = decrypt($a['vc_email_enc']);
            $mail_notiz = match($new_status) {
                'abgelehnt_ag' => 'Der Arbeitgeber hat die Freistellung abgelehnt.' . ($notiz ? ' ' . $notiz : ''),
                'storno_buero' => 'Das Büro hat Ihre Freistellung storniert.' . ($notiz ? ' ' . $notiz : ''),
                default        => ($notiz ?: null),
            };
            $tage_fuer_mail = array_column(array_values($tage_betroffen), 'tag')
                ?: array_column($alle_tage, 'tag');
            if ($new_status === 'freigabe_buero') {
                mail_zwischenbescheid($vc_email, $a['vorname'], $a['veranstaltung'], $tage_fuer_mail);
            } else {
                mail_ergebnis($vc_email, $a['vorname'], $new_status, $mail_notiz,
                    $a['veranstaltung'], $symbol, $tage_fuer_mail);
            }
        }
        json_ok(['id' => $id, 'status' => $new_status]);
        break;

    // ══════════════════════════════════════════════════════════
    // EREIGNIS-VERWALTUNG
    // ══════════════════════════════════════════════════════════

    case 'list_ereignisse':
        $filter_tk   = (int)($_POST['tk_id']        ?? 0);
        $filter_von  = clean($_POST['zeitraum_von'] ?? '');
        $filter_bis  = clean($_POST['zeitraum_bis'] ?? '');
        $filter_veran= clean($_POST['veranstaltung']?? '');
        $filter_airline_ev = clean($_POST['airline'] ?? '');
        // Für "AG-Rückmeldung": nur Ereignisse zeigen, die mindestens EINEN
        // TAG mit Status "Beantragt bei AG" oder "Storno Büro" haben (also
        // tatsächlich auf eine Rückmeldung/Bestätigung des Arbeitgebers
        // warten). WICHTIG: auf Tage-Ebene (at2.status) prüfen, nicht auf
        // den Antrags-Gesamtstatus (a.status) - sonst fallen Anträge mit
        // nur TEILWEISE storniertem Zeitraum durch (Antrag bleibt z.B.
        // "genehmigt", einzelne Tage stehen aber auf "storno_buero" und
        // warten trotzdem auf AG-Bestätigung).
        // storno.php nutzt dieselbe Action weiterhin OHNE diesen Parameter
        // und sieht daher unverändert alle Ereignisse.
        $nur_ag_relevante = ($_POST['nur_ag_relevante'] ?? '0') === '1';
        // Allgemeiner Status-Filter (kommagetrennte Liste, z.B. für die
        // Ereignisauswahl bei der Umwidmung: nur Ereignisse zeigen, die
        // mindestens einen Tag mit einem der gewählten Status haben).
        $filter_status_raw = clean($_POST['status'] ?? '');
        $erlaubte_status_werte = ['ausstehend','freigabe_buero','beantragt_ag','genehmigt','abgelehnt','abgelehnt_ag','storno_buero'];
        $filter_status = array_values(array_intersect(
            array_filter(array_map('trim', explode(',', $filter_status_raw))),
            $erlaubte_status_werte
        ));

        $where  = [];
        $params = [];
        if ($filter_tk)    { $where[] = 'e.tk_id = ?';            $params[] = $filter_tk; }
        if ($filter_von)   { $where[] = 'e.zeitraum_bis >= ?';    $params[] = $filter_von; }
        if ($filter_bis)   { $where[] = 'e.zeitraum_von <= ?';    $params[] = $filter_bis; }
        if ($filter_veran) { $where[] = 'e.veranstaltung = ?';    $params[] = $filter_veran; }
        if ($filter_airline_ev) { $where[] = 'tk.airline = ?';    $params[] = $filter_airline_ev; }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $having_parts = [];
        if ($nur_ag_relevante) {
            $having_parts[] = "SUM(at2.status IN ('beantragt_ag', 'storno_buero')) > 0";
        }
        if ($filter_status) {
            $ph = implode(',', array_fill(0, count($filter_status), '?'));
            $having_parts[] = "SUM(at2.status IN ({$ph})) > 0";
            $params = array_merge($params, $filter_status);
        }
        $having_sql = $having_parts ? ('HAVING ' . implode(' AND ', $having_parts)) : '';

        $sql = "
            SELECT
                e.id                                AS ereignis_id,
                e.tk_id,
                tk.kuerzel                          AS tk_kuerzel,
                tk.bezeichnung                      AS tk_name,
                e.veranstaltung,
                e.zeitraum_von,
                e.zeitraum_bis,
                e.bezeichnung                       AS ereignis_bezeichnung,
                e.erstellt_am,
                tk.airline,
                COUNT(DISTINCT a.id)                AS anzahl_mitglieder,
                COUNT(at2.id)                       AS anzahl_tage_gesamt,
                SUM(at2.status='ausstehend')        AS tage_ausstehend,
                SUM(at2.status='genehmigt')         AS tage_genehmigt,
                SUM(at2.status='storno_buero')      AS tage_storniert
            FROM ereignisse e
            JOIN tks tk ON tk.id = e.tk_id
            LEFT JOIN antraege a    ON a.ereignis_id = e.id
            LEFT JOIN antrag_tage at2 ON at2.antrag_id = a.id
            {$where_sql}
            GROUP BY e.id, e.tk_id, tk.kuerzel, tk.bezeichnung,
                     e.veranstaltung, e.zeitraum_von, e.zeitraum_bis,
                     e.bezeichnung, e.erstellt_am, tk.airline
            {$having_sql}
            ORDER BY e.zeitraum_von DESC
            LIMIT 200
        ";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['anzahl_mitglieder']  = (int)$r['anzahl_mitglieder'];
            $r['anzahl_tage_gesamt'] = (int)$r['anzahl_tage_gesamt'];
            $r['tage_ausstehend']    = (int)$r['tage_ausstehend'];
            $r['tage_genehmigt']     = (int)$r['tage_genehmigt'];
            $r['tage_storniert']     = (int)$r['tage_storniert'];
        }
        json_ok($rows);
        break;

    case 'get_ereignis':
        $ereignis_id = (int)($_POST['ereignis_id'] ?? $_GET['ereignis_id'] ?? 0);
        if (!$ereignis_id) json_err('ereignis_id fehlt.');

        $stmt = db()->prepare(
            "SELECT e.*, tk.kuerzel AS tk_kuerzel, tk.bezeichnung AS tk_name
             FROM ereignisse e JOIN tks tk ON tk.id = e.tk_id
             WHERE e.id = ? LIMIT 1"
        );
        $stmt->execute([$ereignis_id]);
        $ereignis = $stmt->fetch();
        if (!$ereignis) json_err('Ereignis nicht gefunden.', 404);

        $mitglieder_stmt = db()->prepare("
            SELECT
                a.id        AS antrag_id,
                a.vorname,
                a.nachname_enc,
                a.vc_email_enc,
                a.airline,
                a.position,
                a.status    AS antrag_status,
                a.token,
                a.mitglied_id,
                a.freistellungscode,
                MIN(at2.tag)                        AS zeitraum_von,
                MAX(at2.tag)                        AS zeitraum_bis,
                COUNT(at2.id)                       AS anzahl_tage,
                SUM(at2.status='storno_buero')      AS tage_storniert
            FROM antraege a
            LEFT JOIN antrag_tage at2 ON at2.antrag_id = a.id
            WHERE a.ereignis_id = ?
            GROUP BY a.id, a.vorname, a.nachname_enc, a.vc_email_enc,
                     a.airline, a.position, a.status, a.token, a.mitglied_id,
                     a.freistellungscode
            ORDER BY a.vorname
        ");
        $mitglieder_stmt->execute([$ereignis_id]);
        $mitglieder = $mitglieder_stmt->fetchAll();
        foreach ($mitglieder as &$m) {
            $m['nachname']       = decrypt($m['nachname_enc']);
            $m['vc_email']       = decrypt($m['vc_email_enc']);
            $m['anzahl_tage']    = (int)$m['anzahl_tage'];
            $m['tage_storniert'] = (int)$m['tage_storniert'];
            $m['hat_mitglied']   = $m['mitglied_id'] !== null;
            unset($m['nachname_enc'], $m['vc_email_enc'], $m['token']);
        }

        $tage_stmt = db()->prepare("
            SELECT
                at2.tag,
                COUNT(DISTINCT at2.antrag_id)   AS mitglieder_gesamt,
                SUM(at2.status='storno_buero')  AS mitglieder_storniert,
                SUM(at2.status='genehmigt')     AS mitglieder_genehmigt,
                SUM(at2.status='ausstehend')    AS mitglieder_ausstehend
            FROM antrag_tage at2
            JOIN antraege a ON a.id = at2.antrag_id
            WHERE a.ereignis_id = ?
            GROUP BY at2.tag
            ORDER BY at2.tag
        ");
        $tage_stmt->execute([$ereignis_id]);
        $tage = $tage_stmt->fetchAll();

        json_ok([
            'ereignis'   => $ereignis,
            'mitglieder' => $mitglieder,
            'tage'       => $tage,
        ]);
        break;

    // ══════════════════════════════════════════════════════════
    // STORNO-AKTIONEN
    // ══════════════════════════════════════════════════════════

    case 'storno_tage':
        $ereignis_id = (int)($_POST['ereignis_id'] ?? 0);
        $grund       = clean($_POST['grund']       ?? '');
        $raw_tage    = $_POST['tage']              ?? [];
        $raw_aids    = $_POST['antrag_ids']        ?? [];

        if (!$ereignis_id)              json_err('ereignis_id fehlt.');
        if (!$grund)                    json_err('Stornierungsgrund ist Pflicht.');
        if (!is_array($raw_tage) || count($raw_tage) === 0) {
            json_err('Bitte mindestens einen Tag auswählen.');
        }

        $tage = [];
        foreach ($raw_tage as $d) {
            $d = clean($d);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                json_err("Ungültiges Datum: {$d}");
            }
            $tage[] = $d;
        }
        $tage = array_unique($tage);

        $stmt = db()->prepare("SELECT * FROM ereignisse WHERE id = ? LIMIT 1");
        $stmt->execute([$ereignis_id]);
        $ereignis = $stmt->fetch();
        if (!$ereignis) json_err('Ereignis nicht gefunden.', 404);

        if (!empty($raw_aids)) {
            $aids_safe = array_map('intval', $raw_aids);
            $pholders  = implode(',', array_fill(0, count($aids_safe), '?'));
            $aid_stmt  = db()->prepare(
                "SELECT id AS antrag_id FROM antraege
                 WHERE ereignis_id = ? AND id IN ({$pholders})"
            );
            $aid_stmt->execute(array_merge([$ereignis_id], $aids_safe));
        } else {
            $aid_stmt = db()->prepare(
                "SELECT id AS antrag_id FROM antraege WHERE ereignis_id = ?"
            );
            $aid_stmt->execute([$ereignis_id]);
        }
        $antrag_ids = $aid_stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($antrag_ids)) {
            json_err('Keine betroffenen Anträge gefunden.');
        }

        // Für das LH-Storno-Formular: welche der zu stornierenden Tage
        // waren bereits beim Arbeitgeber (beantragt/genehmigt)? Muss VOR
        // dem Storno ermittelt werden, danach ist der Status überschrieben.
        $storno_doc_tage = []; // antrag_id => [Y-m-d, ...]
        {
            $sd_ph_a = implode(',', array_fill(0, count($antrag_ids), '?'));
            $sd_ph_t = implode(',', array_fill(0, count($tage), '?'));
            $sd_stmt = db()->prepare(
                "SELECT antrag_id, tag FROM antrag_tage
                  WHERE antrag_id IN ({$sd_ph_a}) AND tag IN ({$sd_ph_t})
                    AND status IN ('beantragt_ag', 'genehmigt')"
            );
            $sd_stmt->execute([...array_map('intval', $antrag_ids), ...array_values($tage)]);
            foreach ($sd_stmt->fetchAll(PDO::FETCH_ASSOC) as $sd_r) {
                $storno_doc_tage[(int)$sd_r['antrag_id']][] = $sd_r['tag'];
            }
        }

        db()->beginTransaction();
        try {
            $update_tag = db()->prepare(
                "UPDATE antrag_tage
                 SET status='storno_buero',
                     storniert_am=NOW(),
                     storniert_von=?,
                     storno_grund=?
                 WHERE antrag_id = ? AND tag = ?
                   AND status != 'storno_buero'"
            );

            $aktion_id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
                mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
                mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));

            $elog = db()->prepare(
                "SELECT e.veranstaltung, e.zeitraum_von, e.zeitraum_bis,
                        t.id AS tk_id, t.bezeichnung AS tk_name, t.airline
                 FROM ereignisse e JOIN tks t ON t.id = e.tk_id
                 WHERE e.id = ? LIMIT 1"
            );
            $elog->execute([$ereignis_id]);
            $emeta = $elog->fetch() ?: [];

            $storno_log_stmt = db()->prepare(
                "INSERT INTO storno_log
                 (ereignis_id, storniert_von, grund, betroffene_tage, betroffene_antraege,
                  storno_aktion_id, tk_id, tk_name, veranstaltung, ereignis_von, ereignis_bis)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            $gesamt_storniert = 0;

            foreach ($tage as $tag) {
                $betroffene_in_tag = [];
                foreach ($antrag_ids as $aid) {
                    $update_tag->execute([
                        $_SESSION['panel_user'], $grund, $aid, $tag
                    ]);
                    if ($update_tag->rowCount() > 0) {
                        $betroffene_in_tag[] = $aid;
                        $gesamt_storniert++;
                    }
                }

                $storno_log_stmt->execute([
                    $ereignis_id,
                    $_SESSION['panel_user'],
                    $grund,
                    $tag,
                    json_encode($betroffene_in_tag),
                    $aktion_id,
                    $emeta['tk_id']         ?? null,
                    $emeta['tk_name']       ?? null,
                    $emeta['veranstaltung'] ?? null,
                    $emeta['zeitraum_von']  ?? null,
                    $emeta['zeitraum_bis']  ?? null,
                ]);
            }

            foreach ($antrag_ids as $aid) {
                $check = db()->prepare(
                    "SELECT
                        COUNT(*) AS gesamt,
                        SUM(status='storno_buero') AS storniert
                     FROM antrag_tage WHERE antrag_id = ?"
                );
                $check->execute([$aid]);
                $counts = $check->fetch();

                if ((int)$counts['gesamt'] > 0 &&
                    (int)$counts['gesamt'] === (int)$counts['storniert']) {
                    $a_stmt = db()->prepare("SELECT * FROM antraege WHERE id = ? LIMIT 1");
                    $a_stmt->execute([$aid]);
                    $a_row = $a_stmt->fetch();

                    db()->prepare(
                        "UPDATE antraege
                         SET status='storno_buero', notiz=?,
                             entschieden_am=NOW(), entschieden_von=?
                         WHERE id=? AND status != 'storno_buero'"
                    )->execute([$grund, $_SESSION['panel_user'], $aid]);

                    if ($a_row) {
                        $vc_email = decrypt($a_row['vc_email_enc']);
                        // Alle stornierten Tage für Mail laden
                        $st_tage = db()->prepare(
                            "SELECT tag FROM antrag_tage
                             WHERE antrag_id = ? AND status = 'storno_buero'
                             ORDER BY tag"
                        );
                        $st_tage->execute([$aid]);
                        $st_tage_list = array_column($st_tage->fetchAll(), 'tag');
                        mail_ergebnis($vc_email, $a_row['vorname'], 'storno_buero', $grund,
                            $emeta['veranstaltung'] ?? null,
                            $a_row['freistellungscode'] ?? null,
                            $st_tage_list);
                    }
                    audit('antrag_storniert', $aid,
                        "Vollständig storniert via EreignisID:{$ereignis_id} durch {$_SESSION['panel_user']}");
                } else {
                    // Teilstorno: die stornierten Tage in einen eigenen,
                    // in sich homogenen Antrag abspalten (analog zu
                    // umwidmen_tage) - der Ursprungsantrag bleibt für die
                    // verbleibenden (weiterhin aktiven) Tage bestehen und
                    // behält seinen bisherigen Status unverändert (z.B.
                    // weiterhin "genehmigt"). Damit gibt es innerhalb
                    // EINES antrag_id nie mehr gemischte Tage-Status -
                    // die AG-Rückmeldung kann also weiterhin einfach auf
                    // Antrags-Ebene arbeiten.
                    $a_teil_stmt = db()->prepare("SELECT * FROM antraege WHERE id = ? LIMIT 1");
                    $a_teil_stmt->execute([$aid]);
                    $orig = $a_teil_stmt->fetch();

                    $storniert_stmt = db()->prepare(
                        "SELECT tag FROM antrag_tage
                         WHERE antrag_id = ? AND status = 'storno_buero'
                         ORDER BY tag"
                    );
                    $storniert_stmt->execute([$aid]);
                    $stornierte_tage = $storniert_stmt->fetchAll(PDO::FETCH_COLUMN);

                    $verbleibend_stmt = db()->prepare(
                        "SELECT MIN(tag) AS von, MAX(tag) AS bis
                         FROM antrag_tage
                         WHERE antrag_id = ? AND status != 'storno_buero'"
                    );
                    $verbleibend_stmt->execute([$aid]);
                    $verbleibend = $verbleibend_stmt->fetch();

                    // Neuen, abgespaltenen Antrag für die stornierten Tage
                    // anlegen - gleiches Ereignis (nur die Anwesenheit
                    // wurde storniert, nicht das Ereignis selbst geändert).
                    db()->prepare(
                        "INSERT INTO antraege
                         (mitglied_id, vorname, nachname_enc, tk_id, vc_email_enc,
                          airline, position, flugzeugmuster,
                          veranstaltung, zeitraum_von, zeitraum_bis,
                          token, ereignis_id, freistellungscode,
                          status, notiz, entschieden_am, entschieden_von)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                                 'storno_buero', ?, NOW(), ?)"
                    )->execute([
                        $orig['mitglied_id'], $orig['vorname'], $orig['nachname_enc'],
                        $orig['tk_id'], $orig['vc_email_enc'],
                        $orig['airline'], $orig['position'], $orig['flugzeugmuster'],
                        $orig['veranstaltung'], min($stornierte_tage), max($stornierte_tage),
                        generate_token(), $ereignis_id, $orig['freistellungscode'],
                        $grund, $_SESSION['panel_user'],
                    ]);
                    $neuer_storno_aid = (int)db()->lastInsertId();

                    // Stornierte antrag_tage-Zeilen zum neuen Antrag
                    // umhängen (nicht neu anlegen - Status und
                    // Storno-Metadaten bleiben dabei erhalten).
                    $ph_move = implode(',', array_fill(0, count($stornierte_tage), '?'));
                    db()->prepare(
                        "UPDATE antrag_tage SET antrag_id = ?
                         WHERE antrag_id = ? AND tag IN ({$ph_move})"
                    )->execute(array_merge([$neuer_storno_aid, $aid], $stornierte_tage));

                    // Ursprungsantrag: Zeitraum auf die verbleibenden Tage
                    // anpassen, Status bleibt unverändert.
                    db()->prepare(
                        "UPDATE antraege
                         SET zeitraum_von = ?, zeitraum_bis = ?,
                             notiz = CONCAT(COALESCE(notiz,''), '\n[Teilstorno Büro → neuer Antrag #', ?, ': ', ?, ']')
                         WHERE id=?"
                    )->execute([
                        $verbleibend['von'],
                        $verbleibend['bis'],
                        $neuer_storno_aid,
                        $grund,
                        $aid,
                    ]);

                    // Mail an Antragsteller über die stornierten Tage
                    if ($orig) {
                        $vc_email = decrypt($orig['vc_email_enc']);
                        mail_ergebnis($vc_email, $orig['vorname'], 'storno_buero', $grund,
                            $emeta['veranstaltung'] ?? null,
                            $orig['freistellungscode'] ?? null,
                            $stornierte_tage);
                    }

                    audit('tage_storniert', $aid,
                        "Teilstorno via EreignisID:{$ereignis_id}: Split AltID:{$aid} → NeuID:{$neuer_storno_aid} durch {$_SESSION['panel_user']}");
                }
            }

            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            error_log('storno_tage Fehler: ' . $e->getMessage());
            json_err('Datenbankfehler beim Storno.', 500);
        }

        // ── E-Mail an Flugbetrieb versenden & loggen ────────────
        $email_gesendet = false;
        $email_fehler   = '';
        $email_senden   = ($_POST['email_senden'] ?? '0') === '1';

        if ($email_senden) {
            $storno_airline = (string)($emeta['airline'] ?? '');

            // Alle aktiven Kontakte dieser Airline (nicht mehr per TK, siehe
            // Umstellung von flugbetrieb_kontakte auf airline-basiert)
            $ks = db()->prepare(
                'SELECT bezeichnung, email
                 FROM flugbetrieb_kontakte
                 WHERE airline = ? AND aktiv = 1
                 ORDER BY id'
            );
            $ks->execute([$storno_airline]);
            $kontakte_storno = $ks->fetchAll(PDO::FETCH_ASSOC);

            // CC aus Einstellungen
            $cc_email = get_einstellung(db(), 'storno_email_cc');

            // Absender = eingeloggter Benutzer
            $from_name  = $_SESSION['panel_user'];
            $from_email = '';
            $pu = db()->prepare('SELECT email FROM panel_users WHERE username = ? LIMIT 1');
            $pu->execute([$from_name]);
            $pu_row = $pu->fetch(PDO::FETCH_ASSOC);
            if ($pu_row && $pu_row['email']) $from_email = $pu_row['email'];
            if (!$from_email) $from_email = MAIL_FROM;

            // Mitglieder (Name + Freistellungscode) aus den betroffenen Anträgen
            $in2 = implode(',', array_map('intval', $antrag_ids));
            $mgl = db()->query(
                "SELECT vorname, nachname_enc AS nachname, freistellungscode
                 FROM antraege WHERE id IN ($in2)"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($mgl as &$mm) $mm['nachname'] = decrypt($mm['nachname']);
            unset($mm);

            $codes_mail = array_values(array_unique(array_filter(
                array_column($mgl, 'freistellungscode')
            )));

            $tage_sorted = $tage;
            sort($tage_sorted);

            // Vorschau war editierbar (siehe storno.php) - vom Nutzer
            // bearbeiteten Text 1:1 übernehmen, falls mitgeschickt.
            // Nur falls keiner mitgeschickt wurde (z.B. älterer Client),
            // aus der Vorlage neu rendern.
            $subject_override = trim($_POST['subject'] ?? '');
            $body_override    = trim($_POST['body']    ?? '');

            if ($subject_override && $body_override) {
                $subject = $subject_override;
                $body    = $body_override;
            } else {
                $tage_fmt_mail = array_map(fn($t) => implode('.', array_reverse(explode('-', $t))), $tage_sorted);
                $storno_vars = [
                    'TAGE'          => implode(', ', $tage_fmt_mail),
                    'SYMBOL'        => implode(', ', $codes_mail),
                    'MITGLIEDER'    => storno_mitglieder_liste_bauen($mgl),
                    'BEARBEITER'    => $from_name,
                    'TK'            => $emeta['tk_name']       ?? '',
                    'VERANSTALTUNG' => $emeta['veranstaltung'] ?? '',
                ];
                $storno_vorlage = storno_vorlage_laden(db(), 'storno');
                $subject = storno_render($storno_vorlage['subject'], $storno_vars);
                $body    = storno_render($storno_vorlage['body'], $storno_vars);
            }

            $log_status = 'fehler';
            $log_fehler = '';

            $storno_to_email = implode(', ', array_map(
                fn($k) => trim($k['bezeichnung']) !== '' ? "{$k['bezeichnung']} <{$k['email']}>" : $k['email'],
                $kontakte_storno
            ));
            $storno_to_bezeichnung = implode(', ', array_column($kontakte_storno, 'bezeichnung'));

            // LH-Storno-Formular (DOC, eine Zeile je Mitglied und Tag) anhängen, falls für die
            // Airline "DOC zusätzlich ausgeben" aktiv ist.
            $storno_anhaenge = [];
            if ($kontakte_storno && $storno_doc_tage) {
                try {
                    if (ag_storno_doc_aktiv(db(), $storno_airline)) {
                        $storno_anhaenge = ag_storno_doc_dateien_bauen(
                            ag_storno_doc_eintraege_laden(db(), $storno_doc_tage),
                            $from_name, $grund, (string)$cc_email
                        );
                        foreach ($storno_anhaenge as &$sa) {
                            $sa['mime'] = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                        }
                        unset($sa);
                    }
                } catch (Throwable $ex) {
                    // Formular ist Zusatz - Storno-Mail trotzdem versenden
                    error_log('storno_tage: Storno-DOC fehlgeschlagen: ' . $ex->getMessage());
                    $storno_anhaenge = [];
                }
            }

            if ($kontakte_storno) {
                try {
                    $ok = mail_storno_flugbetrieb(
                        $storno_to_email,
                        $cc_email,
                        $from_name,
                        $from_email,
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
                $log_fehler = 'Kein aktiver Kontakt für Airline "' . $storno_airline . '" hinterlegt.';
            }
            foreach ($storno_anhaenge as $sa) @unlink($sa['path']);

            // Immer loggen – auch bei Fehler
            db()->prepare(
                'INSERT INTO storno_email_log
                   (storno_aktion_id, gesendet_am, gesendet_von,
                    from_email, to_email, to_bezeichnung, cc_email,
                    subject, body, tk_id, tk_name, status, fehler_meldung)
                 VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $aktion_id,
                $from_name,
                $from_email,
                $storno_to_email,
                $storno_to_bezeichnung,
                $cc_email,
                $subject,
                $body,
                $emeta['tk_id'] ?? null,
                $emeta['tk_name'] ?? '',
                $log_status,
                $log_fehler ?: null,
            ]);

            if (!$email_gesendet) $email_fehler = $log_fehler;
        }

        json_ok([
            'storniert_tage'      => $gesamt_storniert,
            'betroffene_antraege' => count($antrag_ids),
            'email_gesendet'      => $email_gesendet,
            'email_fehler'        => $email_fehler,
        ]);
        break;

    // ── Storno-Log laden ──────────────────────────────────────
    case 'get_storno_log':
        $ereignis_id = (int)($_POST['ereignis_id'] ?? $_GET['ereignis_id'] ?? 0);
        if (!$ereignis_id) json_err('ereignis_id fehlt.');

        $stmt = db()->prepare(
            "SELECT id, ereignis_id, storniert_von, storniert_am,
                    grund, betroffene_tage, betroffene_antraege
             FROM storno_log
             WHERE ereignis_id = ?
             ORDER BY storniert_am DESC, betroffene_tage ASC"
        );
        $stmt->execute([$ereignis_id]);
        $log = $stmt->fetchAll();
        foreach ($log as &$l) {
            $l['betroffene_antraege'] = json_decode($l['betroffene_antraege'] ?? '[]', true);
        }
        json_ok($log);
        break;

    // ── Budgets laden ─────────────────────────────────────────
    // Hierarchie Airline -> TK -> Freistellungscode. Eine Zeile auf einer
    // höheren Ebene (z.B. "Ganze Airline" oder "TK: X") ist NICHT
    // zwangsläufig ein eigener gespeicherter Datensatz: existieren für
    // sie feinere Kind-Budgets (z.B. je TK bzw. je Code), wird nur die
    // BUDGET-ZAHL stattdessen aus der SUMME dieser Kinder gebildet.
    // Beantragt/Genehmigt/Quartale werden dagegen IMMER direkt über
    // alle TKs/Codes hinweg abgefragt (nicht aus den Kindern summiert!) -
    // sonst gingen z.B. Tage eines Codes ohne eigenes Budget (z.B. V4,
    // wenn nur FS budgetiert ist) in der Summe verloren.
    case 'list_budgets':
        $jahr = (int)($_POST['jahr'] ?? date('Y'));
        $airlines = db()->query(
            "SELECT DISTINCT airline FROM antraege UNION SELECT DISTINCT airline FROM budgets ORDER BY airline"
        )->fetchAll(PDO::FETCH_COLUMN);

        $result = [];
        foreach ($airlines as $airline) {
            // Codes, die für diese Airline vorab konfiguriert sind (finance_preise)
            $codes_stmt = db()->prepare(
                "SELECT DISTINCT freistellungscode FROM finance_preise
                 WHERE airline = ? AND freistellungscode IS NOT NULL AND freistellungscode != ''"
            );
            $codes_stmt->execute([$airline]);
            $codes = $codes_stmt->fetchAll(PDO::FETCH_COLUMN);

            // TKs, für die es bei dieser Airline bereits eine gezielte
            // Aufteilung gibt (TK-spezifisches Budget ODER tatsächliche
            // Anträge unter dieser TK).
            $tk_stmt = db()->prepare(
                "SELECT id, kuerzel FROM tks
                 WHERE id IN (
                     SELECT tk_id FROM budgets  WHERE airline = ? AND tk_id IS NOT NULL
                     UNION
                     SELECT tk_id FROM antraege WHERE airline = ? AND tk_id IS NOT NULL
                 )
                 ORDER BY kuerzel"
            );
            $tk_stmt->execute([$airline, $airline]);
            $tks_fuer_airline = $tk_stmt->fetchAll();

            $tk_zeilen_out = [];               // fertige Zeilen je TK + deren Codes
            $airline_hat_budget_kinder   = false;
            $airline_budget_kinder_summe = 0.0;

            // ── je TK ──
            foreach ($tks_fuer_airline as $tk) {
                $tk_id      = (int)$tk['id'];
                $tk_kuerzel = $tk['kuerzel'];

                // Code-Zeilen dieser TK - angezeigt, sobald ein eigenes
                // Budget existiert ODER tatsächlich beantragte/genehmigte
                // Tage für diesen Code vorliegen (auch ohne eigenes Budget,
                // rein zur Transparenz). Nur Budget>0 zählt für die
                // Budget-Summe der TK-Zeile weiter unten.
                $code_zeilen_dieser_tk = [];
                $tk_hat_code_budgets  = false;
                $tk_code_budget_summe = 0.0;
                foreach ($codes as $code) {
                    [$budget_id, $budget, $beantragt, $verbrauch, $quartale] =
                        budget_kennzahlen($tk_id, $airline, $code, $jahr);
                    if ($budget > 0) {
                        $tk_hat_code_budgets   = true;
                        $tk_code_budget_summe += $budget;
                    }
                    if ($budget > 0 || $beantragt > 0 || $verbrauch > 0) {
                        $code_zeilen_dieser_tk[] = budget_zeile(
                            $budget_id, $tk_id, $tk_kuerzel, $airline, $code,
                            $budget, $beantragt, $verbrauch, $quartale
                        );
                    }
                }

                // Vollständiger Beantragt/Genehmigt/Quartale-Wert
                // dieser TK, über ALLE Codes hinweg (auch unbudgetierte!) -
                // direkt abgefragt, nicht aus den Code-Zeilen oben summiert.
                [$tk_direkt_budget_id, $tk_direkt_budget, $tk_beantragt, $tk_verbrauch, $tk_quartale] =
                    budget_kennzahlen($tk_id, $airline, '', $jahr);

                // Nur die Budget-Zahl rollt aus den Code-Budgets auf.
                if ($tk_hat_code_budgets) {
                    $tk_budget    = $tk_code_budget_summe;
                    $tk_budget_id = null; // Summenzeile, kein eigener Datensatz
                } else {
                    $tk_budget    = $tk_direkt_budget;
                    $tk_budget_id = $tk_direkt_budget_id;
                }

                if ($tk_budget > 0) {
                    $tk_zeilen_out[] = budget_zeile(
                        $tk_budget_id, $tk_id, $tk_kuerzel, $airline, '',
                        $tk_budget, $tk_beantragt, $tk_verbrauch, $tk_quartale
                    );
                    foreach ($code_zeilen_dieser_tk as $cz) $tk_zeilen_out[] = $cz;

                    $airline_hat_budget_kinder    = true;
                    $airline_budget_kinder_summe += $tk_budget;
                }
            }

            // ── Code-Splits direkt unter der Airline (ohne TK) ──
            // Gleiche Regel: anzeigen bei eigenem Budget ODER vorhandenen
            // beantragten/genehmigten Tagen; nur Budget>0 fließt in die
            // Budget-Summe der Airline-Zeile ein.
            $code_zeilen_airline = [];
            foreach ($codes as $code) {
                [$budget_id, $budget, $beantragt, $verbrauch, $quartale] =
                    budget_kennzahlen(null, $airline, $code, $jahr);
                if ($budget > 0) {
                    $airline_hat_budget_kinder    = true;
                    $airline_budget_kinder_summe += $budget;
                }
                if ($budget > 0 || $beantragt > 0 || $verbrauch > 0) {
                    $code_zeilen_airline[] = budget_zeile(
                        $budget_id, null, null, $airline, $code,
                        $budget, $beantragt, $verbrauch, $quartale
                    );
                }
            }

            // Vollständiger Beantragt/Genehmigt/Quartale-Wert der
            // ganzen Airline, über ALLE TKs und ALLE Codes hinweg - direkt
            // abgefragt, nicht aus den TK-/Code-Zeilen oben summiert.
            [$airline_direkt_budget_id, $airline_direkt_budget, $airline_beantragt, $airline_verbrauch, $airline_quartale] =
                budget_kennzahlen(null, $airline, '', $jahr);

            // Nur die Budget-Zahl rollt aus den Kind-Budgets auf.
            if ($airline_hat_budget_kinder) {
                $airline_budget    = $airline_budget_kinder_summe;
                $airline_budget_id = null;
            } else {
                $airline_budget    = $airline_direkt_budget;
                $airline_budget_id = $airline_direkt_budget_id;
            }

            if ($airline_budget <= 0) continue; // nichts Budgetiertes für diese Airline

            $result[] = budget_zeile(
                $airline_budget_id, null, null, $airline, '',
                $airline_budget, $airline_beantragt, $airline_verbrauch, $airline_quartale
            );
            foreach ($code_zeilen_airline as $cz) $result[] = $cz;
            foreach ($tk_zeilen_out as $tz) $result[] = $tz;
        }
        json_ok(['budgets' => $result, 'jahr' => $jahr]);
        break;

    // ── Budget speichern ──────────────────────────────────────
    // tk_id ist optional: leer/0 = Budget gilt für die ganze Airline
    // (über alle TKs hinweg). Nur bei Bedarf eine konkrete TK angeben,
    // um für diese TK ein eigenes Budget zu hinterlegen.
    // freistellungscode ist ebenfalls optional: '' = alle Codes zusammen.
    case 'save_budget':
        $tk_id_raw = trim((string)($_POST['tk_id'] ?? ''));
        $tk_id   = ($tk_id_raw !== '' && (int)$tk_id_raw > 0) ? (int)$tk_id_raw : null;
        $airline = clean($_POST['airline_name']      ?? '');
        $code    = clean($_POST['freistellungscode'] ?? '');
        $budget  = (float)str_replace(',', '.', $_POST['budget_tage'] ?? '0');
        $jahr    = (int)($_POST['jahr']              ?? date('Y'));
        if (!$airline || $budget < 0 || !$jahr) json_err('Ungültige Eingabe.');
        db()->prepare(
            "INSERT INTO budgets (tk_id, airline, freistellungscode, jahr, budget_tage)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE budget_tage = VALUES(budget_tage)"
        )->execute([$tk_id, $airline, $code, $jahr, $budget]);
        audit('budget_geaendert', null,
            "TK:" . ($tk_id ?: '– (ganze Airline)') . " Airline:{$airline} Code:" . ($code ?: '–') . " Jahr:{$jahr} Tage:{$budget}");
        json_ok(['saved' => true]);
        break;

    // ── Budget löschen ─────────────────────────────────────────
    // Löscht einen einzelnen Budget-Datensatz (z.B. weil er nicht mehr
    // gültig ist). Betrifft nur den Eintrag in `budgets`, nicht die
    // bereits gestellten/genehmigten Anträge - die bleiben unverändert.
    case 'delete_budget':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_err('ID fehlt.');
        $stmt = db()->prepare("SELECT * FROM budgets WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $b = $stmt->fetch();
        if (!$b) json_err('Budget nicht gefunden.', 404);
        db()->prepare("DELETE FROM budgets WHERE id = ?")->execute([$id]);
        audit('budget_geloescht', null,
            "ID:{$id} TK:" . ($b['tk_id'] ?? '– (ganze Airline)') .
            " Airline:{$b['airline']} Code:" . ($b['freistellungscode'] ?: '–') .
            " Jahr:{$b['jahr']} Tage:{$b['budget_tage']} durch {$_SESSION['panel_user']}");
        json_ok(['deleted' => true]);
        break;

    // ── Vorkommende Freistellungscodes einer Airline (Budget-Formular) ──
    // Quelle: finance_preise (dort per Konfigurations-Tab VORAB je Airline
    // hinterlegt) - NICHT antraege, da ein Code sonst erst nach dem ersten
    // genehmigten Antrag im Dropdown auftauchen würde.
    case 'list_freistellungscodes':
        $airline = clean($_POST['airline'] ?? '');
        if (!$airline) json_err('Airline fehlt.');
        $stmt = db()->prepare(
            "SELECT DISTINCT freistellungscode FROM finance_preise
             WHERE airline = ? AND freistellungscode IS NOT NULL AND freistellungscode != ''
             ORDER BY freistellungscode"
        );
        $stmt->execute([$airline]);
        json_ok(['codes' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
        break;

    // ── Antrag löschen ────────────────────────────────────────
    case 'delete_antrag':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_err('ID fehlt.');
        $stmt = db()->prepare("SELECT id FROM antraege WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) json_err('Antrag nicht gefunden.', 404);
        audit('antrag_geloescht', $id, "Durch: {$_SESSION['panel_user']}");
        db()->prepare("DELETE FROM antrag_tage WHERE antrag_id = ?")->execute([$id]);
        db()->prepare("DELETE FROM antraege WHERE id = ?")->execute([$id]);
        json_ok();
        break;

    // ── Storno-Log (globale Liste) ────────────────────────────
    case 'list_storno_log':
        $where  = ["(sl.aktion_typ IS NULL OR sl.aktion_typ = 'storno')"];
        $params = [];
        $filter_tk      = (int)($_POST['tk_id']        ?? 0);
        $filter_von     = clean($_POST['von']           ?? '');
        $filter_bis     = clean($_POST['bis']           ?? '');
        $filter_user    = clean($_POST['storniert_von'] ?? '');
        $filter_airline = clean($_POST['airline']       ?? '');
        $filter_fs_von  = clean($_POST['freistellung_von'] ?? '');
        $filter_fs_bis  = clean($_POST['freistellung_bis'] ?? '');

        if ($filter_tk)      { $where[] = 'sl.tk_id = ?';            $params[] = $filter_tk; }
        if ($filter_von)     { $where[] = 'sl.storniert_am >= ?';    $params[] = $filter_von . ' 00:00:00'; }
        if ($filter_bis)     { $where[] = 'sl.storniert_am <= ?';    $params[] = $filter_bis . ' 23:59:59'; }
        if ($filter_user)    { $where[] = 'sl.storniert_von LIKE ?'; $params[] = '%' . $filter_user . '%'; }
        if ($filter_airline) { $where[] = 'tk.airline = ?';          $params[] = $filter_airline; }

        // Freistellungszeitraum-Filter (Überlappungslogik, wie bei
        // list_antraege): filtert auf den tatsächlichen Ereigniszeitraum
        // der stornierten Tage, nicht auf das Stornodatum selbst. Bezieht
        // sich auf dieselben Spalten wie im späteren SELECT
        // (sl.ereignis_von/bis mit Fallback auf e.zeitraum_von/bis).
        if ($filter_fs_von && $filter_fs_bis) {
            $where[]  = 'COALESCE(sl.ereignis_bis, e.zeitraum_bis) >= ?';
            $params[] = $filter_fs_von;
            $where[]  = 'COALESCE(sl.ereignis_von, e.zeitraum_von) <= ?';
            $params[] = $filter_fs_bis;
        } elseif ($filter_fs_von) {
            $where[]  = 'COALESCE(sl.ereignis_bis, e.zeitraum_bis) >= ?';
            $params[] = $filter_fs_von;
        } elseif ($filter_fs_bis) {
            $where[]  = 'COALESCE(sl.ereignis_von, e.zeitraum_von) <= ?';
            $params[] = $filter_fs_bis;
        }

        $sql = "
            SELECT
                sl.id, sl.storno_aktion_id, sl.ereignis_id,
                sl.storniert_von, sl.storniert_am, sl.grund,
                sl.betroffene_tage, sl.betroffene_antraege,
                sl.tk_id, tk.airline,
                COALESCE(sl.tk_name,      tk.bezeichnung)  AS tk_name,
                COALESCE(sl.veranstaltung, e.veranstaltung) AS veranstaltung,
                COALESCE(sl.ereignis_von,  e.zeitraum_von)  AS ereignis_von,
                COALESCE(sl.ereignis_bis,  e.zeitraum_bis)  AS ereignis_bis
            FROM storno_log sl
            LEFT JOIN ereignisse e  ON e.id  = sl.ereignis_id
            LEFT JOIN tks        tk ON tk.id = COALESCE(sl.tk_id, e.tk_id)
            WHERE " . implode(' AND ', $where) . "
            ORDER BY sl.storniert_am DESC, sl.storno_aktion_id, sl.betroffene_tage
            LIMIT 2000
        ";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $aids    = json_decode($row['betroffene_antraege'] ?? '[]', true);
            $details = [];
            if (!empty($aids)) {
                $ph    = implode(',', array_fill(0, count($aids), '?'));
                $astmt = db()->prepare(
                    "SELECT a.id AS antrag_id, a.vorname, a.nachname_enc,
                            a.airline, a.position, t.kuerzel AS tk_kuerzel
                     FROM antraege a
                     LEFT JOIN tks t ON t.id = a.tk_id
                     WHERE a.id IN ({$ph})"
                );
                $astmt->execute($aids);
                foreach ($astmt->fetchAll() as $a) {
                    $a['nachname'] = decrypt($a['nachname_enc']);
                    unset($a['nachname_enc']);
                    $details[] = $a;
                }
            }
            $row['betroffene_antraege_details'] = $details;
            unset($row['betroffene_antraege']);
        }
        json_ok($rows);
        break;

    // ── Von TK-Mitgliedern selbst gelöschte Anträge (Mitgliederportal,
    //    api/antrag_loeschen.php) - für Büro sonst nicht sichtbar, da
    //    der Antrag beim Löschen komplett aus antraege/antrag_tage
    //    entfernt wird. Quelle ist ausschließlich audit_log, da dort
    //    (anders als bei Storno) keine eigene Protokoll-Tabelle existiert.
    //    Details-Text wird geparst, da audit_log kein eigenes Spalten-
    //    schema für Airline/Veranstaltung/Zeitraum/TK hat.
    case 'list_mitglied_loeschungen':
        $ml_von     = clean($_POST['von']     ?? '');
        $ml_bis     = clean($_POST['bis']     ?? '');
        $ml_tk      = (int)($_POST['tk_id']   ?? 0);
        $ml_airline = clean($_POST['airline'] ?? '');
        $ml_fs_von  = clean($_POST['freistellung_von'] ?? '');
        $ml_fs_bis  = clean($_POST['freistellung_bis'] ?? '');

        $ml_where  = ["aktion IN ('antrag_geloescht_mitglied', 'antrag_geloescht')"];
        // "antrag_geloescht" (ohne "_mitglied") wird sowohl vom Büro
        // (api/buero.php) als auch - vor der Umbenennung - vom Mitglieder-
        // portal genutzt. Alte, vom Mitglied stammende Einträge lassen
        // sich daran erkennen, dass ihr details-Text mit "MitgliedID:"
        // beginnt (Büro-Einträge beginnen stattdessen mit "Durch: ").
        $ml_where[] = "(aktion = 'antrag_geloescht_mitglied' OR details LIKE 'MitgliedID:%')";
        $ml_params = [];
        if ($ml_von) { $ml_where[] = 'erstellt_am >= ?'; $ml_params[] = $ml_von . ' 00:00:00'; }
        if ($ml_bis) { $ml_where[] = 'erstellt_am <= ?'; $ml_params[] = $ml_bis . ' 23:59:59'; }

        $ml_stmt = db()->prepare(
            "SELECT id, antrag_id, details, erstellt_am
             FROM audit_log
             WHERE " . implode(' AND ', $ml_where) . "
             ORDER BY erstellt_am DESC
             LIMIT 2000"
        );
        $ml_stmt->execute($ml_params);
        $ml_rows = $ml_stmt->fetchAll(PDO::FETCH_ASSOC);

        $ml_out = [];
        foreach ($ml_rows as $ml_r) {
            // Details-Format (siehe api/antrag_loeschen.php):
            // "MitgliedID:X TK:Y [Status-vor-Löschung:Z ]Airline:A Veranstaltung:B Zeitraum:C"
            // Der Status-Teil ist optional (erst nachträglich ergänzt) -
            // ältere Einträge haben ihn nicht.
            if (!preg_match(
                '/^MitgliedID:(?<mid>\d+)\s+TK:(?<tk>\d+)\s+(?:Status-vor-Löschung:(?<status>\S+)\s+)?Airline:(?<airline>.*)\s+Veranstaltung:(?<veranstaltung>.*)\s+Zeitraum:(?<zeitraum>.*)$/u',
                (string)$ml_r['details'],
                $ml_m
            )) {
                continue; // nicht auswertbarer/fremder Eintrag - überspringen
            }
            $ml_mitglied_id = (int)$ml_m['mid'];
            $ml_tk_id       = (int)$ml_m['tk'];

            if ($ml_tk && $ml_tk_id !== $ml_tk) continue;
            if ($ml_airline && trim($ml_m['airline']) !== $ml_airline) continue;

            // Zeitraum-Text kommt als ISO-Datum bzw. "ISO – ISO" aus
            // antrag_loeschen.php (siehe dortiger $zeitraum_txt) - hier in
            // Freistellung-von/-bis zerlegen, damit sich danach filtern
            // lässt (Überlappungslogik, wie bei den anderen Protokoll-
            // Listen). "(kein Zeitraum)" (Antrag ohne Tage) ergibt beide
            // Werte null und wird vom Freistellungsfilter dann ausgeschlossen.
            $ml_zeitraum_roh = trim($ml_m['zeitraum']);
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\s*–\s*(\d{4}-\d{2}-\d{2})$/u', $ml_zeitraum_roh, $ml_zm)) {
                $ml_fv = $ml_zm[1];
                $ml_fb = $ml_zm[2];
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ml_zeitraum_roh)) {
                $ml_fv = $ml_fb = $ml_zeitraum_roh;
            } else {
                $ml_fv = $ml_fb = null;
            }

            if ($ml_fs_von && $ml_fs_bis) {
                if (!$ml_fb || $ml_fb < $ml_fs_von || !$ml_fv || $ml_fv > $ml_fs_bis) continue;
            } elseif ($ml_fs_von) {
                if (!$ml_fb || $ml_fb < $ml_fs_von) continue;
            } elseif ($ml_fs_bis) {
                if (!$ml_fv || $ml_fv > $ml_fs_bis) continue;
            }

            $ml_out[] = [
                'id'                  => (int)$ml_r['id'],
                'antrag_id'           => (int)$ml_r['antrag_id'],
                'geloescht_am'        => $ml_r['erstellt_am'],
                'mitglied_id'         => $ml_mitglied_id,
                'tk_id'               => $ml_tk_id,
                'status_vor_loeschung'=> $ml_m['status'] ?: null,
                'airline'             => trim($ml_m['airline']),
                'veranstaltung'       => trim($ml_m['veranstaltung']),
                'freistellung_von'    => $ml_fv,
                'freistellung_bis'    => $ml_fb,
            ];
        }

        // Mitglieder- und TK-Namen nachladen (in EINER Abfrage je Tabelle,
        // nicht pro Zeile) - Mitglied kann inzwischen gelöscht/deaktiviert
        // sein, daher defensiv mit Fallback im Frontend.
        $ml_mids = array_values(array_unique(array_column($ml_out, 'mitglied_id')));
        $ml_mitglieder = [];
        if ($ml_mids) {
            $ml_ph = implode(',', array_fill(0, count($ml_mids), '?'));
            $ml_mstmt = db()->prepare("SELECT id, vorname, nachname_enc FROM mitglieder WHERE id IN ({$ml_ph})");
            $ml_mstmt->execute($ml_mids);
            foreach ($ml_mstmt->fetchAll(PDO::FETCH_ASSOC) as $ml_mrow) {
                $ml_mitglieder[$ml_mrow['id']] = trim($ml_mrow['vorname'] . ' ' . decrypt($ml_mrow['nachname_enc']));
            }
        }
        $ml_tkids = array_values(array_unique(array_column($ml_out, 'tk_id')));
        $ml_tks = [];
        if ($ml_tkids) {
            $ml_ph2 = implode(',', array_fill(0, count($ml_tkids), '?'));
            $ml_tstmt = db()->prepare("SELECT id, kuerzel, bezeichnung FROM tks WHERE id IN ({$ml_ph2})");
            $ml_tstmt->execute($ml_tkids);
            foreach ($ml_tstmt->fetchAll(PDO::FETCH_ASSOC) as $ml_trow) {
                $ml_tks[$ml_trow['id']] = $ml_trow['kuerzel'] . ' – ' . $ml_trow['bezeichnung'];
            }
        }
        foreach ($ml_out as &$ml_row) {
            $ml_row['mitglied_name'] = $ml_mitglieder[$ml_row['mitglied_id']] ?? ('Mitglied #' . $ml_row['mitglied_id'] . ' (nicht mehr vorhanden)');
            $ml_row['tk_name']       = $ml_tks[$ml_row['tk_id']] ?? ('TK #' . $ml_row['tk_id']);
        }
        unset($ml_row);

        json_ok($ml_out);
        break;

    // ══════════════════════════════════════════════════════════
    // MITGLIEDER-VERWALTUNG (auch für 'buero'-Rolle nutzbar,
    // Löschen / Deaktivieren nur für 'admin')
    // ══════════════════════════════════════════════════════════

    // ── Mitglieder-Liste einer TK ─────────────────────────────
    case 'list_mitglieder':
        $tk_id = (int)($_POST['tk_id'] ?? 0);
        if (!$tk_id) json_err('TK-ID fehlt.');

        // Zeigt Mitglieder, deren HEIM-TK diese TK ist, UND Mitglieder,
        // die dieser TK nur zusätzlich zugeordnet sind (Mehrfach-
        // mitgliedschaft, siehe mitglied_tks). Vorher wurde nur nach
        // Heim-TK gefiltert - über add_mitglied_zu_tk()/update_mitglied_tks()
        // zugeordnete Mitglieder tauchten dadurch in der Liste der
        // Zusatz-TK gar nicht auf, obwohl die Zuordnung technisch bereits
        // bestand.
        $stmt = db()->prepare(
            "SELECT
                m.id, m.tk_id, m.vorname, m.nachname_enc, m.vc_email_enc, m.aktiv,
                m.airline, m.position, m.flugzeugmuster, m.stationierung,
                (m.einladung_token IS NOT NULL) AS einladung_ausstehend,
                m.erstellt_am, m.erstellt_von, m.letzter_login,
                (m.tk_id <> ?) AS ist_zusatz_tk,
                th.kuerzel AS heim_tk_kuerzel
             FROM mitglieder m
             LEFT JOIN tks th ON th.id = m.tk_id
             WHERE m.tk_id = ?
                OR EXISTS (SELECT 1 FROM mitglied_tks mt WHERE mt.mitglied_id = m.id AND mt.tk_id = ?)
             ORDER BY vorname ASC"
        );
        $stmt->execute([$tk_id, $tk_id, $tk_id]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['aktiv']                = (bool)$r['aktiv'];
            $r['einladung_ausstehend'] = (bool)$r['einladung_ausstehend'];
            $r['ist_zusatz_tk']        = (bool)$r['ist_zusatz_tk'];
            $r['nachname']             = decrypt($r['nachname_enc']);
            $r['email']                = decrypt($r['vc_email_enc']);
            unset($r['nachname_enc'], $r['vc_email_enc']);
        }
        json_ok($rows);
        break;

    // ── Mitglied-Detail (inkl. E-Mail, nur für Büro/Admin) ───
    case 'get_mitglied':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_err('ID fehlt.');

        $stmt = db()->prepare(
            "SELECT m.id, m.tk_id, m.vorname, m.nachname_enc, m.vc_email_enc,
                    m.aktiv, m.einladung_ablauf, m.erstellt_am, m.erstellt_von,
                    m.letzter_login, m.airline, m.position, m.flugzeugmuster, m.stationierung,
                    t.bezeichnung AS tk_bezeichnung,
                    (m.einladung_token IS NOT NULL) AS einladung_ausstehend
             FROM mitglieder m JOIN tks t ON t.id = m.tk_id
             WHERE m.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $m = $stmt->fetch();
        if (!$m) json_err('Nicht gefunden.', 404);

        $m['nachname']             = decrypt($m['nachname_enc']);
        $m['email']                = decrypt($m['vc_email_enc']);
        $m['aktiv']                = (bool)$m['aktiv'];
        $m['einladung_ausstehend'] = (bool)$m['einladung_ausstehend'];
        unset($m['nachname_enc'], $m['vc_email_enc']);
        // Weitere TKs (Mehrfachmitgliedschaft) - ohne die Heim-TK selbst,
        // die wird separat als tk_id/tk_bezeichnung geführt und ist über
        // dieses UI-Feld nicht änderbar (dafür müsste das Mitglied neu
        // angelegt werden).
        $weitere_stmt = db()->prepare(
            "SELECT tk_id FROM mitglied_tks WHERE mitglied_id = ? AND tk_id != ?"
        );
        $weitere_stmt->execute([$id, (int)$m['tk_id']]);
        $m['weitere_tk_ids'] = array_map('intval', array_column($weitere_stmt->fetchAll(), 'tk_id'));
        json_ok($m);
        break;

    // ── Prüfen, ob ein Mitglied mit dieser E-Mail bereits existiert ──
    // Wird vom Anlegen-Formular genutzt: statt nur "E-Mail bereits
    // vergeben" zu melden, wird das bestehende Mitglied (inkl. Heim-TK)
    // zurückgegeben, damit das Büro es stattdessen per
    // add_mitglied_zu_tk() der aktuell gewählten TK zuordnen kann - ohne
    // einen doppelten Mitglieder-Datensatz für dieselbe Person anzulegen.
    case 'find_mitglied_by_email':
        $email = clean_email($_POST['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Ungültige E-Mail-Adresse.');

        $stmt = db()->prepare(
            "SELECT m.id, m.vorname, m.nachname_enc, m.tk_id, t.kuerzel AS tk_kuerzel, t.bezeichnung AS tk_bezeichnung
             FROM mitglieder m JOIN tks t ON t.id = m.tk_id
             WHERE m.email_hash = ? LIMIT 1"
        );
        $stmt->execute([email_hash($email)]);
        $m = $stmt->fetch();
        if (!$m) { json_ok(null); break; }

        $m['nachname'] = decrypt($m['nachname_enc']);
        unset($m['nachname_enc']);
        $weitere_stmt = db()->prepare("SELECT tk_id FROM mitglied_tks WHERE mitglied_id = ? AND tk_id != ?");
        $weitere_stmt->execute([$m['id'], (int)$m['tk_id']]);
        $m['weitere_tk_ids'] = array_map('intval', array_column($weitere_stmt->fetchAll(), 'tk_id'));
        json_ok($m);
        break;

    // ── Vorhandenes Mitglied einer weiteren TK zuordnen ─────────
    // Schlanke Alternative zu update_mitglied_tks() für genau den Fall
    // "bestehendes Mitglied per E-Mail gefunden, jetzt zur aktuell
    // angezeigten TK hinzufügen" - braucht dafür nicht erst die komplette
    // bisherige Zuordnungsliste zu kennen (vermeidet einen Race-Condition-
    // Zwischenschritt im Frontend).
    case 'add_mitglied_zu_tk':
        $id    = (int)($_POST['id']    ?? 0);
        $tk_id = (int)($_POST['tk_id'] ?? 0);
        if (!$id || !$tk_id) json_err('Mitglied oder TK fehlt.');

        $m_stmt = db()->prepare("SELECT id, vorname, tk_id FROM mitglieder WHERE id = ? LIMIT 1");
        $m_stmt->execute([$id]);
        $m = $m_stmt->fetch();
        if (!$m) json_err('Mitglied nicht gefunden.', 404);

        $tk_stmt = db()->prepare("SELECT id, kuerzel, bezeichnung FROM tks WHERE id = ? AND aktiv = 1 LIMIT 1");
        $tk_stmt->execute([$tk_id]);
        $tk = $tk_stmt->fetch();
        if (!$tk) json_err('Ungültige oder inaktive TK.');

        if ((int)$m['tk_id'] === $tk_id) {
            json_err('Dieses Mitglied gehört dieser TK bereits als Heim-TK an.');
        }

        db()->prepare(
            "INSERT INTO mitglied_tks (mitglied_id, tk_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE tk_id = tk_id"
        )->execute([$id, $tk_id]);

        audit('mitglied_tks_geaendert', $id,
            "TK {$tk['kuerzel']} hinzugefügt durch {$_SESSION['panel_user']}");
        json_ok(['tk_kuerzel' => $tk['kuerzel'], 'tk_bezeichnung' => $tk['bezeichnung']]);
        break;

    case 'create_mitglied':
        $tk_id        = (int)($_POST['tk_id']          ?? 0);
        $vorname      = clean($_POST['vorname']         ?? '');
        $nachname     = clean($_POST['nachname']        ?? '');
        $email        = clean_email($_POST['email']     ?? '');
        $airline      = clean($_POST['airline']         ?? '');
        $position     = clean($_POST['position']        ?? '');
        $muster       = clean($_POST['flugzeugmuster']  ?? '');
        $stationierung= clean($_POST['stationierung']   ?? '');

        $erlaubte_pos = ['CPT', 'SFO', 'FO'];

        if (!$tk_id)    json_err('TK-ID fehlt.');
        if (!$vorname)  json_err('Vorname ist ein Pflichtfeld.');
        if (!$nachname) json_err('Nachname ist ein Pflichtfeld.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Ungültige E-Mail-Adresse.');
        if (!$airline)  json_err('Airline ist ein Pflichtfeld.');
        if (!in_array($position, $erlaubte_pos, true)) json_err('Ungültige Position.');
        if (!$muster)   json_err('Flugzeugmuster ist ein Pflichtfeld.');

        // TK prüfen
        $tk_stmt = db()->prepare("SELECT id, bezeichnung FROM tks WHERE id = ? AND aktiv = 1 LIMIT 1");
        $tk_stmt->execute([$tk_id]);
        $tk = $tk_stmt->fetch();
        if (!$tk) json_err('Ungültige oder inaktive TK.');

        // Doppelprüfung
        $dup = db()->prepare("SELECT id FROM mitglieder WHERE email_hash = ? LIMIT 1");
        $dup->execute([email_hash($email)]);
        if ($dup->fetch()) json_err('Diese E-Mail-Adresse ist bereits als Mitglied registriert.');

        try {
            $new_id = mitglied_anlegen(
                $tk_id, $vorname, $nachname, $email,
                $_SESSION['panel_user']
            );

            // Beschäftigungsdaten speichern
            db()->prepare(
                "UPDATE mitglieder SET airline = ?, position = ?, flugzeugmuster = ?, stationierung = ? WHERE id = ?"
            )->execute([$airline, $position, $muster, $stationierung ?: null, $new_id]);

            $tok_stmt = db()->prepare(
                "SELECT einladung_token FROM mitglieder WHERE id = ? LIMIT 1"
            );
            $tok_stmt->execute([$new_id]);
            $tok = $tok_stmt->fetchColumn();

            mail_einladung_mitglied($email, $vorname, $nachname, $tk['bezeichnung'], $tok);

            audit('mitglied_angelegt', $new_id,
                "TK:{$tk_id} {$vorname} {$nachname} <{$email}> Airline:{$airline} Pos:{$position} durch {$_SESSION['panel_user']}");

            json_ok(['id' => $new_id]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') json_err('Diese E-Mail-Adresse ist bereits registriert.');
            throw $e;
        }
        break;

    // ── Einladung erneut versenden ────────────────────────────
    case 'resend_einladung':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_err('ID fehlt.');

        $stmt = db()->prepare(
            "SELECT m.*, t.bezeichnung AS tk_bezeichnung
             FROM mitglieder m JOIN tks t ON t.id = m.tk_id
             WHERE m.id = ? AND m.aktiv = 1 LIMIT 1"
        );
        $stmt->execute([$id]);
        $m = $stmt->fetch();
        if (!$m) json_err('Mitglied nicht gefunden.');
        if ($m['einladung_token'] === null) {
            json_err('Dieses Mitglied hat die Einladung bereits angenommen.');
        }

        $new_token  = bin2hex(random_bytes(32));
        $new_ablauf = date('Y-m-d H:i:s', time() + MK_EINLADUNG_TTL);
        db()->prepare(
            "UPDATE mitglieder SET einladung_token = ?, einladung_ablauf = ? WHERE id = ?"
        )->execute([$new_token, $new_ablauf, $id]);

        $email    = decrypt($m['vc_email_enc']);
        $nachname = decrypt($m['nachname_enc']);
        mail_einladung_mitglied($email, $m['vorname'], $nachname, $m['tk_bezeichnung'], $new_token);

        audit('einladung_erneut', $id, "Durch {$_SESSION['panel_user']}");
        json_ok();
        break;

    // ── Mitglied bearbeiten ───────────────────────────────────
    case 'update_mitglied':
        $id       = (int)($_POST['id']              ?? 0);
        $vorname  = clean($_POST['vorname']          ?? '');
        $nachname = clean($_POST['nachname']         ?? '');
        $email    = clean_email($_POST['email']      ?? '');
        $airline  = clean($_POST['airline']          ?? '');
        $position = clean($_POST['position']         ?? '');
        $muster   = clean($_POST['flugzeugmuster']   ?? '');
        $stationierung = clean($_POST['stationierung'] ?? '');

        $erlaubte_pos = ['CPT', 'SFO', 'FO'];

        if (!$id || !$vorname || !$nachname) json_err('Pflichtfelder fehlen.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Ungültige E-Mail.');
        if (!$airline)  json_err('Airline ist ein Pflichtfeld.');
        if (!in_array($position, $erlaubte_pos, true)) json_err('Ungültige Position.');
        if (!$muster)   json_err('Flugzeugmuster ist ein Pflichtfeld.');

        $new_hash   = email_hash($email);
        $nachname_e = encrypt($nachname);
        $email_e    = encrypt($email);

        $dup = db()->prepare(
            "SELECT id FROM mitglieder WHERE email_hash = ? AND id != ? LIMIT 1"
        );
        $dup->execute([$new_hash, $id]);
        if ($dup->fetch()) json_err('Diese E-Mail ist bereits einem anderen Mitglied zugeordnet.');

        db()->prepare(
            "UPDATE mitglieder
             SET vorname = ?, nachname_enc = ?, vc_email_enc = ?, email_hash = ?,
                 airline = ?, position = ?, flugzeugmuster = ?, stationierung = ?
             WHERE id = ?"
        )->execute([$vorname, $nachname_e, $email_e, $new_hash,
                    $airline, $position, $muster, $stationierung ?: null, $id]);

        audit('mitglied_bearbeitet', $id, "Durch {$_SESSION['panel_user']}" . ($stationierung ? " Stationierung:{$stationierung}" : ''));
        json_ok();
        break;

    // ── Weitere TKs (Mehrfachmitgliedschaft) setzen ─────────────
    // Ersetzt komplett die Menge der ZUSÄTZLICHEN TK-Zuordnungen dieses
    // Mitglieds (die Heim-TK aus mitglieder.tk_id bleibt davon unberührt
    // und kann hier nicht entfernt werden - dafür müsste das Mitglied
    // neu angelegt werden).
    case 'update_mitglied_tks':
        $id      = (int)($_POST['id'] ?? 0);
        $raw_ids = $_POST['tk_ids'] ?? [];
        if (!$id) json_err('ID fehlt.');
        if (!is_array($raw_ids)) $raw_ids = [];

        $home_stmt = db()->prepare("SELECT tk_id FROM mitglieder WHERE id = ? LIMIT 1");
        $home_stmt->execute([$id]);
        $home_tk = $home_stmt->fetchColumn();
        if ($home_tk === false) json_err('Mitglied nicht gefunden.', 404);
        $home_tk = (int)$home_tk;

        $tk_ids = array_values(array_unique(array_filter(array_map('intval', $raw_ids))));
        $tk_ids = array_filter($tk_ids, fn($t) => $t !== $home_tk); // Heim-TK nie doppelt/entfernbar über diesen Weg

        if ($tk_ids) {
            $ph = implode(',', array_fill(0, count($tk_ids), '?'));
            $valid_stmt = db()->prepare("SELECT id FROM tks WHERE id IN ({$ph}) AND aktiv = 1");
            $valid_stmt->execute($tk_ids);
            $valid_ids = array_map('intval', array_column($valid_stmt->fetchAll(), 'id'));
            $ungueltig = array_diff($tk_ids, $valid_ids);
            if ($ungueltig) json_err('Ungültige oder inaktive TK-Auswahl.');
        }

        db()->beginTransaction();
        db()->prepare("DELETE FROM mitglied_tks WHERE mitglied_id = ? AND tk_id != ?")
            ->execute([$id, $home_tk]);
        if ($tk_ids) {
            $ins = db()->prepare("INSERT INTO mitglied_tks (mitglied_id, tk_id) VALUES (?, ?)");
            foreach ($tk_ids as $tid) $ins->execute([$id, $tid]);
        }
        db()->commit();

        audit('mitglied_tks_geaendert', $id,
            "Weitere TKs: " . ($tk_ids ? implode(',', $tk_ids) : '(keine)') . " durch {$_SESSION['panel_user']}");
        json_ok(['weitere_tk_ids' => array_values($tk_ids)]);
        break;

    // ── Stationierungsort inline in der Mitglieder-Tabelle ändern ──
    // Eigene, schlanke Action statt update_mitglied wiederzuverwenden,
    // damit die Tabellen-Spalte nicht Vorname/Nachname/Airline/Position/
    // Flugzeugmuster mitschicken und deren Pflichtfeld-Validierung
    // erfüllen muss, nur um den Stationierungsort zu ändern.
    case 'update_mitglied_stationierung':
        $id            = (int)($_POST['id'] ?? 0);
        $stationierung = clean($_POST['stationierung'] ?? '');
        if (!$id) json_err('ID fehlt.');

        db()->prepare(
            "UPDATE mitglieder SET stationierung = ? WHERE id = ?"
        )->execute([$stationierung ?: null, $id]);

        audit('mitglied_stationierung_geaendert', $id,
            "Neuer Stationierungsort: " . ($stationierung ?: '(leer)') . " durch {$_SESSION['panel_user']}");
        json_ok(['stationierung' => $stationierung ?: null]);
        break;

    // ── Flugzeugmuster inline in der Mitglieder-Tabelle ändern ──
    // Anders als Stationierung ist Flugzeugmuster ein Pflichtfeld
    // (siehe update_mitglied) - leer ist hier NICHT erlaubt.
    case 'update_mitglied_flugzeugmuster':
        $id     = (int)($_POST['id'] ?? 0);
        $muster = clean($_POST['flugzeugmuster'] ?? '');
        if (!$id) json_err('ID fehlt.');
        if (!$muster) json_err('Flugzeugmuster darf nicht leer sein.');

        db()->prepare(
            "UPDATE mitglieder SET flugzeugmuster = ? WHERE id = ?"
        )->execute([$muster, $id]);

        audit('mitglied_flugzeugmuster_geaendert', $id,
            "Neues Flugzeugmuster: {$muster} durch {$_SESSION['panel_user']}");
        json_ok(['flugzeugmuster' => $muster]);
        break;

    // ── Mitglied aktivieren / deaktivieren ────────────────────
    case 'toggle_mitglied':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_err('ID fehlt.');

        db()->prepare("UPDATE mitglieder SET aktiv = NOT aktiv WHERE id = ?")->execute([$id]);

        $neu = db()->prepare("SELECT aktiv FROM mitglieder WHERE id = ? LIMIT 1");
        $neu->execute([$id]);
        if (!(int)$neu->fetchColumn()) {
            // Bei Deaktivierung alle Sessions des Mitglieds löschen
            db()->prepare(
                "DELETE FROM mitglieder_sessions WHERE mitglied_id = ?"
            )->execute([$id]);
        }

        audit('mitglied_toggle', $id, "Durch {$_SESSION['panel_user']}");
        json_ok();
        break;

    // ── Mitglied löschen ───────────────────────────────────────
    case 'delete_mitglied':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_err('ID fehlt.');

        // Anträge entknüpfen (nicht löschen)
        db()->prepare(
            "UPDATE antraege SET mitglied_id = NULL WHERE mitglied_id = ?"
        )->execute([$id]);
        db()->prepare(
            "DELETE FROM mitglieder_sessions WHERE mitglied_id = ?"
        )->execute([$id]);
        db()->prepare(
            "DELETE FROM mitglieder WHERE id = ?"
        )->execute([$id]);

        audit('mitglied_geloescht', $id, "Durch {$_SESSION['panel_user']}");
        json_ok();
        break;

    // ── Mitglieder-Statistik einer TK ─────────────────────────
    // Für das Dashboard: wie viele Mitglieder je TK, Einladungsstatus
    case 'mitglieder_stats':
        $tk_id = (int)($_POST['tk_id'] ?? 0);
        if (!$tk_id) json_err('TK-ID fehlt.');

        $stmt = db()->prepare(
            "SELECT
                COUNT(*)                                    AS gesamt,
                SUM(aktiv = 1)                              AS aktiv,
                SUM(aktiv = 0)                              AS inaktiv,
                SUM(aktiv = 1 AND einladung_token IS NOT NULL) AS einladung_ausstehend,
                SUM(aktiv = 1 AND einladung_token IS NULL)  AS aktiviert
             FROM mitglieder WHERE tk_id = ?"
        );
        $stmt->execute([$tk_id]);
        json_ok($stmt->fetch());
        break;

    // ── TKs: Liste ────────────────────────────────────────────
    case 'list_tks':
        $stmt = db()->query(
            "SELECT t.id, t.kuerzel, t.bezeichnung, t.airline, t.aktiv,
                    COUNT(r.id) AS ref_count
             FROM tks t
             LEFT JOIN tarifreferenten r ON r.tk_id = t.id AND r.aktiv = 1
             GROUP BY t.id
             ORDER BY t.kuerzel ASC"
        );
        json_ok($stmt->fetchAll());
        break;

    // ── TKs: Anlegen ──────────────────────────────────────────
    case 'create_tk':
        $kuerzel     = clean($_POST['kuerzel']     ?? '');
        $bezeichnung = clean($_POST['bezeichnung'] ?? '');
        $airline     = clean($_POST['airline']     ?? '');
        if (!$kuerzel || !$bezeichnung) json_err('Kürzel und Bezeichnung sind Pflichtfelder.');
        $dup = db()->prepare("SELECT id FROM tks WHERE kuerzel = ? LIMIT 1");
        $dup->execute([$kuerzel]);
        if ($dup->fetch()) json_err('Dieses Kürzel existiert bereits.');
        db()->prepare(
            "INSERT INTO tks (kuerzel, bezeichnung, airline, aktiv) VALUES (?, ?, ?, 1)"
        )->execute([$kuerzel, $bezeichnung, $airline ?: null]);
        audit('tk_angelegt', null, "Kürzel:{$kuerzel} durch {$_SESSION['panel_user']}");
        json_ok();
        break;

    // ── TKs: Aktivieren / Deaktivieren ────────────────────────
    case 'toggle_tk':
        $tk_id = (int)($_POST['tk_id'] ?? 0);
        if (!$tk_id) json_err('TK-ID fehlt.');
        db()->prepare("UPDATE tks SET aktiv = NOT aktiv WHERE id = ?")->execute([$tk_id]);
        audit('tk_toggle', $tk_id, "Durch {$_SESSION['panel_user']}");
        json_ok();
        break;

    // ── TKs: Löschen ──────────────────────────────────────────
    case 'delete_tk':
        $tk_id = (int)($_POST['tk_id'] ?? 0);
        if (!$tk_id) json_err('TK-ID fehlt.');
        // Schutz: nur löschen wenn keine Anträge oder Mitglieder verknüpft.
        // Prüft sowohl die Heim-TK (mitglieder.tk_id) als auch Zusatz-TK-
        // Zuordnungen (mitglied_tks, Mehrfachmitgliedschaft) - sonst könnte
        // eine TK, die nur als Zweit-TK zugeordnet ist, unbemerkt gelöscht
        // werden und würde die dortige(n) Mitgliedschaft(en) per
        // ON DELETE CASCADE stillschweigend mitlöschen.
        $chk = db()->prepare(
            "SELECT
               (SELECT COUNT(*) FROM antraege WHERE tk_id = ?) +
               (SELECT COUNT(*) FROM mitglieder WHERE tk_id = ?) +
               (SELECT COUNT(*) FROM mitglied_tks WHERE tk_id = ?) AS total"
        );
        $chk->execute([$tk_id, $tk_id, $tk_id]);
        if ((int)$chk->fetchColumn() > 0) {
            json_err('TK kann nicht gelöscht werden, da noch Anträge oder Mitglieder zugeordnet sind.');
        }
        db()->prepare("DELETE FROM tarifreferenten WHERE tk_id = ?")->execute([$tk_id]);
        db()->prepare("DELETE FROM tks WHERE id = ?")->execute([$tk_id]);
        audit('tk_geloescht', $tk_id, "Durch {$_SESSION['panel_user']}");
        json_ok();
        break;

    // ── Referenten: Liste ──────────────────────────────────────
    case 'list_referenten':
        $stmt = db()->query(
            "SELECT r.id, r.name, r.email, t.kuerzel
             FROM tarifreferenten r
             JOIN tks t ON t.id = r.tk_id
             WHERE r.aktiv = 1
             ORDER BY t.kuerzel, r.name ASC"
        );
        json_ok($stmt->fetchAll());
        break;

    // ── Referenten: Anlegen ────────────────────────────────────
    case 'create_referent':
        $tk_id = (int)($_POST['tk_id'] ?? 0);
        $name  = clean($_POST['name']  ?? '');
        $email = clean_email($_POST['email'] ?? '');
        if (!$tk_id || !$name) json_err('TK und Name sind Pflichtfelder.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Ungültige E-Mail-Adresse.');
        db()->prepare(
            "INSERT INTO tarifreferenten (tk_id, name, email, aktiv) VALUES (?, ?, ?, 1)"
        )->execute([$tk_id, $name, $email]);
        audit('referent_angelegt', null, "TK:{$tk_id} {$name} durch {$_SESSION['panel_user']}");
        json_ok();
        break;

    // ── Referenten: Löschen ────────────────────────────────────
    case 'delete_referent':
        $ref_id = (int)($_POST['ref_id'] ?? 0);
        if (!$ref_id) json_err('ID fehlt.');
        db()->prepare("DELETE FROM tarifreferenten WHERE id = ?")->execute([$ref_id]);
        audit('referent_geloescht', $ref_id, "Durch {$_SESSION['panel_user']}");
        json_ok();
        break;

    // ── Deadlines: Liste ──────────────────────────────────────
    case 'list_deadlines':
        $stmt = db()->query(
            "SELECT id, zeitraum_von, zeitraum_bis, deadline_am, notiz, gesetzt_von, erstellt_am
             FROM deadlines
             ORDER BY zeitraum_von DESC"
        );
        json_ok($stmt->fetchAll());
        break;

    // ── Deadlines: Anlegen ────────────────────────────────────
    case 'save_deadline':
        $bis      = clean($_POST['zeitraum_bis'] ?? '');
        $deadline = clean($_POST['deadline_am']  ?? '');
        $notiz    = clean($_POST['notiz']         ?? '');

        if (!$bis || !$deadline) json_err('Pflichtfelder fehlen.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bis))      json_err('Ungültiges Datum (Bis).');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) json_err('Ungültiges Datum (Deadline).');

        // Zeit immer auf Tagesbeginn setzen
        $deadline_dt = $deadline . ' 00:00:00';
        $von = '0001-01-01';

        db()->prepare(
            "INSERT INTO deadlines (zeitraum_von, zeitraum_bis, deadline_am, notiz, gesetzt_von)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$von, $bis, $deadline_dt, $notiz ?: null, $_SESSION['panel_user']]);

        audit('deadline_gesetzt', null,
            "Gesperrt bis:{$bis} Deadline:{$deadline_dt} durch {$_SESSION['panel_user']}");
        json_ok();
        break;

    // ── Deadlines: Löschen ────────────────────────────────────
    case 'delete_deadline':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_err('ID fehlt.');
        db()->prepare("DELETE FROM deadlines WHERE id = ?")->execute([$id]);
        audit('deadline_geloescht', null, "ID:{$id} durch {$_SESSION['panel_user']}");
        json_ok();
        break;

    // ══════════════════════════════════════════════════════════
    // UMWIDMUNGS-AKTIONEN
    // ══════════════════════════════════════════════════════════

    case 'umwidmen_tage':
        $ereignis_id    = (int)($_POST['ereignis_id']    ?? 0);
        $grund          = clean($_POST['grund']          ?? '');
        $neuer_typ      = clean($_POST['neuer_typ']      ?? '');
        $neuer_typ_text = clean($_POST['neuer_typ_text'] ?? '');
        $freistellungscode_neu = clean($_POST['freistellungscode'] ?? '');
        $raw_tage       = $_POST['tage']                 ?? [];
        $raw_aids       = $_POST['antrag_ids']           ?? [];

        // Veranstaltungstyp-Mapping: interne Keys → DB-Werte (wie in api/antrag.php)
        // Basis-Typen immer erlaubt; airline-spezifische Zusatz-Typen aus DB laden.
        $erlaubte_typen = ['verhandlung', 'tk_sitzung', 'sonstiges'];
        $typ_zu_veranstaltung = [
            'verhandlung' => 'Verhandlung',
            'tk_sitzung'  => 'TK Sitzung',
            'sonstiges'   => 'sonstige',
        ];

        // Airline des Ereignisses ermitteln, um Zusatz-Typen zu laden
        $uw_airline_stmt = db()->prepare(
            "SELECT DISTINCT a.airline FROM antraege a
              WHERE a.ereignis_id = ? AND a.airline IS NOT NULL LIMIT 1"
        );
        $uw_airline_stmt->execute([$ereignis_id]);
        $uw_airline = $uw_airline_stmt->fetchColumn() ?: null;
        if ($uw_airline) {
            $uw_ext_stmt = db()->prepare(
                "SELECT veranstaltung, uw_key FROM airline_veranstaltungen
                  WHERE airline = ? AND aktiv = 1"
            );
            $uw_ext_stmt->execute([$uw_airline]);
            foreach ($uw_ext_stmt->fetchAll() as $uw_ext) {
                $erlaubte_typen[]                          = $uw_ext['uw_key'];
                $typ_zu_veranstaltung[$uw_ext['uw_key']]  = $uw_ext['veranstaltung'];
            }
        }

        if (!$ereignis_id)  json_err('ereignis_id fehlt.');
        // neuer_typ ist jetzt optional: leer = Ereignisart bleibt je Antrag
        // unverändert (nur der Freistellungscode wird umgewidmet). Wird ein
        // Typ angegeben, muss er einer der drei bekannten sein.
        if ($neuer_typ !== '' && !in_array($neuer_typ, $erlaubte_typen, true)) {
            json_err('Ungültiger Zieltyp.');
        }
        if ($neuer_typ === 'sonstiges' && !$neuer_typ_text) json_err('Bezeichnung für Sonstiges fehlt.');
        if (!is_array($raw_tage) || count($raw_tage) === 0) json_err('Bitte mindestens einen Tag auswählen.');

        // Datumsvalidierung
        $tage_uw = [];
        foreach ($raw_tage as $d) {
            $d = clean($d);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) json_err("Ungültiges Datum: {$d}");
            $tage_uw[] = $d;
        }
        $tage_uw = array_unique($tage_uw);
        sort($tage_uw);

        // Ursprungs-Ereignis laden (inkl. TK-Meta für Log)
        $stmt = db()->prepare(
            "SELECT e.*, t.id AS tk_id_val, t.bezeichnung AS tk_name
             FROM ereignisse e JOIN tks t ON t.id = e.tk_id
             WHERE e.id = ? LIMIT 1"
        );
        $stmt->execute([$ereignis_id]);
        $ereignis = $stmt->fetch();
        if (!$ereignis) json_err('Ereignis nicht gefunden.', 404);

        // Betroffene Anträge laden (vollständige Zeilen für Kopie)
        if (!empty($raw_aids)) {
            $aids_safe = array_map('intval', $raw_aids);
            $pholders  = implode(',', array_fill(0, count($aids_safe), '?'));
            $a_stmt    = db()->prepare(
                "SELECT * FROM antraege WHERE ereignis_id = ? AND id IN ({$pholders})"
            );
            $a_stmt->execute(array_merge([$ereignis_id], $aids_safe));
        } else {
            $a_stmt = db()->prepare("SELECT * FROM antraege WHERE ereignis_id = ?");
            $a_stmt->execute([$ereignis_id]);
        }
        $antraege_rows = $a_stmt->fetchAll();
        if (empty($antraege_rows)) json_err('Keine betroffenen Anträge gefunden.');

        // Freistellungscode-Pflicht: nur wenn die betroffene Airline
        // tatsächlich mehrere Codes zur Auswahl hat (Defense-in-Depth -
        // dieselbe Regel prüft bereits das Frontend vor dem Absenden).
        $uw_airline = $antraege_rows[0]['airline'] ?? '';
        if ($uw_airline) {
            $codes_stmt = db()->prepare(
                "SELECT DISTINCT freistellungscode FROM finance_preise
                 WHERE airline = ? AND freistellungscode IS NOT NULL AND freistellungscode != ''"
            );
            $codes_stmt->execute([$uw_airline]);
            $verfuegbare_codes = $codes_stmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($verfuegbare_codes) > 1 && $freistellungscode_neu === '') {
                json_err('Bitte einen Freistellungscode auswählen (für diese Airline stehen mehrere zur Auswahl).');
            }
        }

        // Typ-Label für Anzeige/Log (darf frei sein, landet nur in Notiz/Log).
        // Nur relevant, wenn tatsächlich ein neuer Typ gewählt wurde - sonst
        // wird das Label weiter unten pro Antrag aus dessen bisherigem Typ
        // gebildet ("Freistellungscode geändert, Typ unverändert: Verhandlung").
        $typ_label = $typ_explizit_gewaehlt
            ? (($neuer_typ === 'sonstiges') ? $neuer_typ_text
                : (['verhandlung' => 'Verhandlung', 'tk_sitzung' => 'TK-Sitzung'][$neuer_typ] ?? $neuer_typ))
            : 'unverändert (nur Freistellungscode)';
        // DB-Wert für ereignisse.veranstaltung / antraege.veranstaltung – IMMER einer
        // der drei festen Enum-Werte (wie in api/antrag.php). Der Freitext bei
        // "sonstiges" landet separat in ereignisse.bezeichnung, NICHT in veranstaltung
        // (sonst wird der antraege.veranstaltung-ENUM stillschweigend auf '' gekürzt).
        // Reverse-Mapping (DB-Wert -> interner Key) für den Fall "Typ unverändert".
        $veranstaltung_zu_typ = [
            'Verhandlung' => 'verhandlung',
            'TK Sitzung'  => 'tk_sitzung',
            'sonstige'    => 'sonstiges',
        ];
        $typ_explizit_gewaehlt = $neuer_typ !== '';

        // Aktion-UUID für Log
        $aktion_id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
            mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));

        db()->beginTransaction();
        try {
            $neue_antrag_ids   = [];
            $gesamt_umgewidmet = 0;
            $tage_uw_set       = array_flip($tage_uw);  // O(1)-Lookup

            // ── Pro Antrag splitten ───────────────────────────────
            foreach ($antraege_rows as $orig) {
                $aid = (int)$orig['id'];

                // Zieltyp für DIESEN Antrag: explizit gewählter Typ, oder -
                // wenn keiner gewählt wurde - der bisherige Typ des Antrags
                // selbst (reines Umwidmen des Freistellungscodes, Ereignisart
                // bleibt unangetastet).
                if ($typ_explizit_gewaehlt) {
                    $neues_veran_db = $typ_zu_veranstaltung[$neuer_typ];
                    $neue_bez_db    = ($neuer_typ === 'sonstiges') ? $neuer_typ_text : null;
                    $typ_label_akt  = $typ_label;
                } else {
                    $eigener_typ    = $veranstaltung_zu_typ[$orig['veranstaltung']] ?? 'sonstiges';
                    $neues_veran_db = $orig['veranstaltung'];
                    $neue_bez_db    = $ereignis['bezeichnung'] ?? null;
                    $typ_label_akt  = ['verhandlung' => 'Verhandlung', 'tk_sitzung' => 'TK-Sitzung']
                        [$eigener_typ] ?? ($ereignis['bezeichnung'] ?: 'Sonstiges');
                }

                // Alle Tage dieses Antrags laden
                $tag_stmt = db()->prepare(
                    "SELECT tag, status FROM antrag_tage WHERE antrag_id = ? ORDER BY tag"
                );
                $tag_stmt->execute([$aid]);
                $alle_tage = $tag_stmt->fetchAll(PDO::FETCH_KEY_PAIR); // tag => status

                // Aufteilen: bleiben vs. umwidmen
                $tage_bleiben = [];
                $tage_neu     = [];
                foreach ($alle_tage as $tag => $status) {
                    if (isset($tage_uw_set[$tag]) && $status !== 'storno_buero') {
                        $tage_neu[] = $tag;
                    } else {
                        $tage_bleiben[] = $tag;
                    }
                }

                if (empty($tage_neu)) continue;

                // ── Ursprungsantrag anpassen ──────────────────────
                if (empty($tage_bleiben)) {
                    // Alle Tage umgewidmet → Ursprungsantrag löschen
                    db()->prepare("DELETE FROM antrag_tage WHERE antrag_id = ?")->execute([$aid]);
                    db()->prepare("DELETE FROM antraege    WHERE id = ?")->execute([$aid]);
                } else {
                    // Umgewidmete Tage aus antrag_tage entfernen,
                    // Zeitraum des Ursprungsantrags auf verbleibende Tage anpassen
                    $ph_del = implode(',', array_fill(0, count($tage_neu), '?'));
                    db()->prepare(
                        "DELETE FROM antrag_tage WHERE antrag_id = ? AND tag IN ({$ph_del})"
                    )->execute(array_merge([$aid], $tage_neu));

                    db()->prepare(
                        "UPDATE antraege
                         SET zeitraum_von = ?, zeitraum_bis = ?,
                             notiz = CONCAT(COALESCE(notiz,''),
                                     '\n[Teilumwidmung Büro → ', ?, ': ', ?, ']')
                         WHERE id = ?"
                    )->execute([min($tage_bleiben), max($tage_bleiben), $typ_label_akt, $grund, $aid]);
                }

                // ── Ziel-Ereignis suchen oder neu anlegen ─────────
                $neues_von = min($tage_neu);
                $neues_bis = max($tage_neu);

                $ev_stmt = db()->prepare(
                    "SELECT id FROM ereignisse
                     WHERE tk_id = ? AND veranstaltung = ?
                       AND zeitraum_von = ? AND zeitraum_bis = ?
                     LIMIT 1"
                );
                $ev_stmt->execute([$ereignis['tk_id'], $neues_veran_db, $neues_von, $neues_bis]);
                $ev_row = $ev_stmt->fetch();

                if ($ev_row) {
                    // Bestehendes Ereignis wiederverwenden – dessen bezeichnung
                    // bleibt maßgeblich, ein abweichender Freitext wird nicht
                    // nachträglich überschrieben (Variante A, wie in api/antrag.php).
                    $neues_ereignis_id = (int)$ev_row['id'];
                } else {
                    db()->prepare(
                        "INSERT INTO ereignisse (tk_id, veranstaltung, zeitraum_von, zeitraum_bis, bezeichnung)
                         VALUES (?, ?, ?, ?, ?)"
                    )->execute([$ereignis['tk_id'], $neues_veran_db, $neues_von, $neues_bis, $neue_bez_db]);
                    $neues_ereignis_id = (int)db()->lastInsertId();
                }

                // Neuen Antrag anlegen (Kopie mit neuer ereignis_id, direkt
                // intern genehmigt = 'freigabe_buero', NICHT 'genehmigt' –
                // die finale Freigabe erfolgt weiterhin erst durch den
                // Arbeitgeber, konsistent mit api/antrag.php.)
                // Der im Formular gewählte Freistellungscode (z.B. V4 -> FS)
                // hat Vorrang - genau das ist der Zweck der Umwidmung. Fehlt
                // er (Airline ohne/mit nur einem Code), bleibt der bisherige
                // Code des Antrags erhalten statt ihn zu löschen.
                $neuer_fs_code = $freistellungscode_neu !== '' ? $freistellungscode_neu : ($orig['freistellungscode'] ?? null);
                db()->prepare(
                    "INSERT INTO antraege
                     (mitglied_id, vorname, nachname_enc, tk_id, vc_email_enc,
                      airline, position, flugzeugmuster,
                      veranstaltung, zeitraum_von, zeitraum_bis,
                      token, ereignis_id, freistellungscode,
                      status, entschieden_am, entschieden_von)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                             'freigabe_buero', NOW(), ?)"
                )->execute([
                    $orig['mitglied_id'],
                    $orig['vorname'],
                    $orig['nachname_enc'],
                    $orig['tk_id'],
                    $orig['vc_email_enc'],
                    $orig['airline'],
                    $orig['position'],
                    $orig['flugzeugmuster'],
                    $neues_veran_db,
                    $neues_von,
                    $neues_bis,
                    generate_token(),
                    $neues_ereignis_id,
                    $neuer_fs_code,
                    $_SESSION['panel_user'],
                ]);
                $neuer_aid = (int)db()->lastInsertId();
                $neue_antrag_ids[] = $neuer_aid;

                // Notiz separat setzen (damit kein INSERT-Fehler durch langes Notiz-Feld)
                if ($grund) {
                    db()->prepare(
                        "UPDATE antraege SET notiz = ? WHERE id = ?"
                    )->execute([
                        "[Umgewidmet von EreignisID:{$ereignis_id} → {$typ_label_akt}: {$grund}]",
                        $neuer_aid,
                    ]);
                }

                // ── antrag_tage für neuen Antrag anlegen ──────────
                $ins_tag = db()->prepare(
                    "INSERT INTO antrag_tage (antrag_id, ereignis_id, tag, status)
                     VALUES (?, ?, ?, 'freigabe_buero')"
                );
                foreach ($tage_neu as $tag) {
                    $ins_tag->execute([$neuer_aid, $neues_ereignis_id, $tag]);
                    $gesamt_umgewidmet++;
                }

                audit('antrag_umgewidmet', $aid,
                    "Split: AltID:{$aid} → NeuID:{$neuer_aid} " .
                    "EreignisID:{$neues_ereignis_id} ({$typ_label_akt}) " .
                    "durch {$_SESSION['panel_user']}");
            }

            // ── Log-Eintrag pro umgewidmetem Tag ──────────────────
            $log_stmt = db()->prepare(
                "INSERT INTO storno_log
                 (ereignis_id, storniert_von, grund, betroffene_tage, betroffene_antraege,
                  storno_aktion_id, tk_id, tk_name, veranstaltung, ereignis_von, ereignis_bis,
                  aktion_typ, neuer_typ, neuer_typ_label)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'umwidmung', ?, ?)"
            );
            foreach ($tage_uw as $tag) {
                $log_stmt->execute([
                    $ereignis_id,
                    $_SESSION['panel_user'],
                    $grund,
                    $tag,
                    json_encode($neue_antrag_ids),
                    $aktion_id,
                    $ereignis['tk_id_val'],
                    $ereignis['tk_name'],
                    $ereignis['veranstaltung'],
                    $ereignis['zeitraum_von'],
                    $ereignis['zeitraum_bis'],
                    $neuer_typ,
                    $typ_label,
                ]);
            }

            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            error_log('umwidmen_tage Fehler: ' . $e->getMessage());
            json_err('Datenbankfehler bei der Umwidmung.', 500);
        }

        // ── E-Mail an Flugbetrieb versenden & loggen (analog zu Storno,
        //    bisher gab es bei Umwidmung KEINEN Mailversand) ──────────
        $uw_email_gesendet = false;
        $uw_email_fehler   = '';
        $uw_email_senden   = ($_POST['email_senden'] ?? '0') === '1';

        if ($uw_email_senden) {
            $uw_airline = (string)($antraege_rows[0]['airline'] ?? '');

            $uwks = db()->prepare(
                'SELECT bezeichnung, email FROM flugbetrieb_kontakte
                 WHERE airline = ? AND aktiv = 1 ORDER BY id'
            );
            $uwks->execute([$uw_airline]);
            $uw_kontakte = $uwks->fetchAll(PDO::FETCH_ASSOC);

            $uw_cc_email = get_einstellung(db(), 'storno_email_cc');

            $uw_from_name  = $_SESSION['panel_user'];
            $uw_from_email = '';
            $uwpu = db()->prepare('SELECT email FROM panel_users WHERE username = ? LIMIT 1');
            $uwpu->execute([$uw_from_name]);
            $uwpu_row = $uwpu->fetch(PDO::FETCH_ASSOC);
            if ($uwpu_row && $uwpu_row['email']) $uw_from_email = $uwpu_row['email'];
            if (!$uw_from_email) $uw_from_email = MAIL_FROM;

            // Mitglieder für die Namensliste (aus den URSPRÜNGLICHEN Anträgen,
            // da diese nach dem Split evtl. gelöscht wurden)
            $uw_mgl = array_map(fn($o) => [
                'vorname'  => $o['vorname'],
                'nachname' => decrypt($o['nachname_enc']),
            ], $antraege_rows);

            $uw_subject_override = trim($_POST['subject'] ?? '');
            $uw_body_override    = trim($_POST['body']    ?? '');

            if ($uw_subject_override && $uw_body_override) {
                $uw_subject = $uw_subject_override;
                $uw_body    = $uw_body_override;
            } else {
                $uw_vars = [
                    'TAGE'          => implode(', ', array_map(
                        fn($t) => implode('.', array_reverse(explode('-', $t))), $tage_uw
                    )),
                    'SYMBOL'        => '',
                    'MITGLIEDER'    => storno_mitglieder_liste_bauen($uw_mgl),
                    'BEARBEITER'    => $uw_from_name,
                    'TK'            => $ereignis['tk_name'],
                    'VERANSTALTUNG' => $ereignis['veranstaltung'],
                    'ZIELTYP'       => $typ_label,
                ];
                $uw_vorlage = storno_vorlage_laden(db(), 'umwidmung');
                $uw_subject = storno_render($uw_vorlage['subject'], $uw_vars);
                $uw_body    = storno_render($uw_vorlage['body'], $uw_vars);
            }

            $uw_log_status = 'fehler';
            $uw_log_fehler = '';
            $uw_to_email   = implode(', ', array_map(
                fn($k) => trim($k['bezeichnung']) !== '' ? "{$k['bezeichnung']} <{$k['email']}>" : $k['email'],
                $uw_kontakte
            ));

            if ($uw_kontakte) {
                try {
                    $uw_ok = _mail_send($uw_to_email, $uw_subject, nl2br(htmlspecialchars($uw_body)), $uw_cc_email ?: '');
                    if ($uw_ok) {
                        $uw_email_gesendet = true;
                        $uw_log_status      = 'gesendet';
                    } else {
                        $uw_log_fehler = 'mail() gab false zurück.';
                    }
                } catch (Throwable $ex) {
                    $uw_log_fehler = $ex->getMessage();
                }
            } else {
                $uw_log_fehler = 'Kein aktiver Kontakt für Airline "' . $uw_airline . '" hinterlegt.';
            }

            db()->prepare(
                'INSERT INTO umwidmung_email_log
                   (storno_aktion_id, gesendet_am, gesendet_von,
                    from_email, to_email, to_bezeichnung, cc_email,
                    subject, body, tk_id, tk_name, neuer_typ, neuer_typ_label,
                    status, fehler_meldung)
                 VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $aktion_id,
                $uw_from_name,
                $uw_from_email,
                $uw_to_email,
                implode(', ', array_column($uw_kontakte, 'bezeichnung')),
                $uw_cc_email,
                $uw_subject,
                $uw_body,
                $ereignis['tk_id_val'],
                $ereignis['tk_name'],
                $neuer_typ,
                $typ_label,
                $uw_log_status,
                $uw_log_fehler ?: null,
            ]);

            if (!$uw_email_gesendet) $uw_email_fehler = $uw_log_fehler;
        }

        json_ok([
            'betroffene_tage'     => $gesamt_umgewidmet,
            'betroffene_antraege' => count($antraege_rows),
            'neue_antrag_ids'     => $neue_antrag_ids,
            'neuer_typ'           => $neuer_typ,
            'neuer_typ_label'     => $typ_label,
            'email_gesendet'      => $uw_email_gesendet,
            'email_fehler'        => $uw_email_fehler,
        ]);
        break;


    // ── Umwidmungs-Log (globale Liste) ───────────────────────────
    case 'list_umwidmung_log':
        $where  = ["sl.aktion_typ = 'umwidmung'"];
        $params = [];
        $filter_tk      = (int)($_POST['tk_id']          ?? 0);
        $filter_von     = clean($_POST['von']             ?? '');
        $filter_bis     = clean($_POST['bis']             ?? '');
        $filter_typ     = clean($_POST['neuer_typ']       ?? '');
        $filter_user    = clean($_POST['umgewidmet_von']  ?? '');
        $filter_airline = clean($_POST['airline']         ?? '');
        $filter_fs_von  = clean($_POST['freistellung_von'] ?? '');
        $filter_fs_bis  = clean($_POST['freistellung_bis'] ?? '');

        if ($filter_tk)      { $where[] = 'sl.tk_id = ?';              $params[] = $filter_tk; }
        if ($filter_von)     { $where[] = 'sl.storniert_am >= ?';      $params[] = $filter_von . ' 00:00:00'; }
        if ($filter_bis)     { $where[] = 'sl.storniert_am <= ?';      $params[] = $filter_bis . ' 23:59:59'; }
        if ($filter_typ)     { $where[] = 'sl.neuer_typ = ?';          $params[] = $filter_typ; }
        if ($filter_user)    { $where[] = 'sl.storniert_von LIKE ?';   $params[] = '%' . $filter_user . '%'; }
        if ($filter_airline) { $where[] = 'tk.airline = ?';            $params[] = $filter_airline; }

        // Freistellungszeitraum-Filter (Überlappungslogik, wie bei
        // list_antraege/list_storno_log): filtert auf den tatsächlichen
        // Ereigniszeitraum der umgewidmeten Tage, nicht auf das
        // Umwidmungsdatum selbst.
        if ($filter_fs_von && $filter_fs_bis) {
            $where[]  = 'COALESCE(sl.ereignis_bis, e.zeitraum_bis) >= ?';
            $params[] = $filter_fs_von;
            $where[]  = 'COALESCE(sl.ereignis_von, e.zeitraum_von) <= ?';
            $params[] = $filter_fs_bis;
        } elseif ($filter_fs_von) {
            $where[]  = 'COALESCE(sl.ereignis_bis, e.zeitraum_bis) >= ?';
            $params[] = $filter_fs_von;
        } elseif ($filter_fs_bis) {
            $where[]  = 'COALESCE(sl.ereignis_von, e.zeitraum_von) <= ?';
            $params[] = $filter_fs_bis;
        }

        $sql = "
            SELECT
                sl.id, sl.storno_aktion_id, sl.ereignis_id,
                sl.storniert_von, sl.storniert_am, sl.grund,
                sl.betroffene_tage, sl.betroffene_antraege,
                sl.tk_id, sl.neuer_typ, sl.neuer_typ_label, tk.airline,
                COALESCE(sl.tk_name,       tk.bezeichnung)   AS tk_name,
                COALESCE(sl.veranstaltung, e.veranstaltung)  AS veranstaltung,
                COALESCE(sl.ereignis_von,  e.zeitraum_von)   AS ereignis_von,
                COALESCE(sl.ereignis_bis,  e.zeitraum_bis)   AS ereignis_bis
            FROM storno_log sl
            LEFT JOIN ereignisse e  ON e.id  = sl.ereignis_id
            LEFT JOIN tks        tk ON tk.id = COALESCE(sl.tk_id, e.tk_id)
            WHERE " . implode(' AND ', $where) . "
            ORDER BY sl.storniert_am DESC, sl.storno_aktion_id, sl.betroffene_tage
            LIMIT 2000
        ";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $aids    = json_decode($row['betroffene_antraege'] ?? '[]', true);
            $details = [];
            if (!empty($aids)) {
                $ph    = implode(',', array_fill(0, count($aids), '?'));
                $astmt = db()->prepare(
                    "SELECT a.id AS antrag_id, a.vorname, a.nachname_enc,
                            a.airline, a.position, t.kuerzel AS tk_kuerzel
                     FROM antraege a
                     LEFT JOIN tks t ON t.id = a.tk_id
                     WHERE a.id IN ({$ph})"
                );
                $astmt->execute($aids);
                foreach ($astmt->fetchAll() as $a) {
                    $a['nachname'] = decrypt($a['nachname_enc']);
                    unset($a['nachname_enc']);
                    $details[] = $a;
                }
            }
            $row['betroffene_antraege_details'] = $details;
            unset($row['betroffene_antraege']);
        }
        json_ok($rows);
        break;

    // ── Vorschau der Umwidmungs-Arbeitgeber-Mail (analog zu
    //    get_email_vorschau bei Storno - bisher gab es das bei
    //    Umwidmung gar nicht) ──────────────────────────────────
    case 'get_umwidmung_email_vorschau':
        $uwv_ereignis_id  = (int)($_POST['ereignis_id'] ?? 0);
        $uwv_antrag_ids   = array_map('intval', (array)($_POST['antrag_ids'] ?? []));
        $uwv_tage_raw     = array_values(array_filter(array_map('clean', (array)($_POST['tage'] ?? []))));
        $uwv_neuer_typ    = clean($_POST['neuer_typ']      ?? '');
        $uwv_neuer_typ_txt= clean($_POST['neuer_typ_text'] ?? '');
        if (!$uwv_ereignis_id || empty($uwv_antrag_ids) || empty($uwv_tage_raw)) json_err('Parameter fehlen.');

        $uwv_erg = db()->prepare(
            'SELECT e.veranstaltung, e.tk_id, t.kuerzel AS tk_kuerzel, t.bezeichnung AS tk_bezeichnung, t.airline
             FROM ereignisse e JOIN tks t ON t.id = e.tk_id WHERE e.id = ?'
        );
        $uwv_erg->execute([$uwv_ereignis_id]);
        $uwv_ereignis = $uwv_erg->fetch();
        if (!$uwv_ereignis) json_err('Ereignis nicht gefunden.', 404);

        $uwv_in = implode(',', $uwv_antrag_ids);
        $uwv_mgl = db()->query(
            "SELECT vorname, nachname_enc AS nachname FROM antraege WHERE id IN ($uwv_in)"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($uwv_mgl as &$uwv_m) $uwv_m['nachname'] = decrypt($uwv_m['nachname']);
        unset($uwv_m);

        $uwv_ks = db()->prepare(
            'SELECT bezeichnung, email FROM flugbetrieb_kontakte
             WHERE airline = ? AND aktiv = 1 ORDER BY id'
        );
        $uwv_ks->execute([$uwv_ereignis['airline']]);
        $uwv_kontakte = $uwv_ks->fetchAll(PDO::FETCH_ASSOC);

        $uwv_cc_email   = get_einstellung(db(), 'storno_email_cc');
        $uwv_from_name  = $_SESSION['panel_user'];
        $uwv_from_email = '';
        $uwv_pu = db()->prepare('SELECT email FROM panel_users WHERE username = ? LIMIT 1');
        $uwv_pu->execute([$uwv_from_name]);
        $uwv_pu_row = $uwv_pu->fetch(PDO::FETCH_ASSOC);
        if ($uwv_pu_row && $uwv_pu_row['email']) $uwv_from_email = $uwv_pu_row['email'];

        $uwv_typ_label = ($uwv_neuer_typ === 'sonstiges') ? $uwv_neuer_typ_txt
            : (['verhandlung' => 'Verhandlung', 'tk_sitzung' => 'TK-Sitzung'][$uwv_neuer_typ] ?? $uwv_neuer_typ);

        $uwv_tage_fmt = array_map(fn($t) => implode('.', array_reverse(explode('-', $t))), $uwv_tage_raw);
        sort($uwv_tage_fmt);
        $uwv_vars = [
            'TAGE'          => implode(', ', $uwv_tage_fmt),
            'SYMBOL'        => '',
            'MITGLIEDER'    => storno_mitglieder_liste_bauen($uwv_mgl),
            'BEARBEITER'    => $uwv_from_name,
            'TK'            => $uwv_ereignis['tk_bezeichnung'],
            'VERANSTALTUNG' => $uwv_ereignis['veranstaltung'],
            'ZIELTYP'       => $uwv_typ_label,
        ];
        $uwv_vorlage = storno_vorlage_laden(db(), 'umwidmung');

        json_ok([
            'from_name'      => $uwv_from_name,
            'from_email'     => $uwv_from_email,
            'to_bezeichnung' => implode(', ', array_column($uwv_kontakte, 'bezeichnung')),
            'to_email'       => implode(', ', array_column($uwv_kontakte, 'email')),
            'to_kontakte'    => $uwv_kontakte,
            'cc_email'       => $uwv_cc_email,
            'subject'        => storno_render($uwv_vorlage['subject'], $uwv_vars),
            'body'           => storno_render($uwv_vorlage['body'], $uwv_vars),
        ]);
        break;

    // ── Umwidmungs-E-Mail-Protokoll (analog zu list_storno_email_log) ──
    case 'list_umwidmung_email_log':
        $uwe_where  = ['1=1'];
        $uwe_params = [];
        $uwe_von        = clean($_POST['von']          ?? '');
        $uwe_bis        = clean($_POST['bis']          ?? '');
        $uwe_bearbeiter = clean($_POST['gesendet_von'] ?? '');
        $uwe_airline    = clean($_POST['airline']      ?? '');
        if ($uwe_von)        { $uwe_where[] = 'DATE(uel.gesendet_am) >= ?'; $uwe_params[] = $uwe_von; }
        if ($uwe_bis)        { $uwe_where[] = 'DATE(uel.gesendet_am) <= ?'; $uwe_params[] = $uwe_bis; }
        if ($uwe_bearbeiter) { $uwe_where[] = 'uel.gesendet_von = ?';       $uwe_params[] = $uwe_bearbeiter; }
        if ($uwe_airline)    { $uwe_where[] = 'tk.airline = ?';             $uwe_params[] = $uwe_airline; }

        $uwe_stmt = db()->prepare(
            'SELECT uel.*, tk.airline
             FROM umwidmung_email_log uel
             LEFT JOIN tks tk ON tk.id = uel.tk_id
             WHERE ' . implode(' AND ', $uwe_where) . '
             ORDER BY uel.gesendet_am DESC
             LIMIT 2000'
        );
        $uwe_stmt->execute($uwe_params);
        json_ok($uwe_stmt->fetchAll());
        break;

    // ── Finance-Preiskonfiguration einer Airline laden ────────
    case 'get_finance_config':
        $airline = clean($_POST['airline'] ?? '');
        if (!$airline) json_err('Airline fehlt.');

        $preise_stmt = db()->prepare(
            "SELECT kategorie, position, tagessatz, freistellungscode
             FROM finance_preise
             WHERE airline = ?"
        );
        $preise_stmt->execute([$airline]);
        $preise_rows = $preise_stmt->fetchAll();

        $preise = [
            'verhandlung' => ['CPT' => 0, 'SFO' => 0, 'FO' => 0],
            'tk_sitzung'  => ['CPT' => 0, 'SFO' => 0, 'FO' => 0],
            'sonstiges'   => ['CPT' => 0, 'SFO' => 0, 'FO' => 0],
        ];
        $codes = [
            'verhandlung' => '',
            'tk_sitzung'  => '',
            'sonstiges'   => '',
        ];
        foreach ($preise_rows as $r) {
            $preise[$r['kategorie']][$r['position']] = (float)$r['tagessatz'];
            if (!empty($r['freistellungscode'])) {
                $codes[$r['kategorie']] = $r['freistellungscode'];
            }
        }

        json_ok([
            'airline' => $airline,
            'preise'  => $preise,
            'codes'   => $codes,
        ]);
        break;

    // ── Finance-Preiskonfiguration speichern ──────────────────
    case 'save_finance_config':
        if (!is_finance()) json_err('Keine Berechtigung.', 403);

        $airline = clean($_POST['airline'] ?? '');
        if (!$airline) json_err('Airline fehlt.');

        $kategorien = ['verhandlung', 'tk_sitzung', 'sonstiges'];
        $positionen = ['CPT', 'SFO', 'FO'];

        db()->beginTransaction();
        try {
            foreach ($kategorien as $kat) {
                $fscode = clean($_POST['codes'][$kat] ?? '');

                foreach ($positionen as $pos) {
                    $satz = (float)str_replace(',', '.', $_POST['preise'][$kat][$pos] ?? '0');
                    if ($satz < 0) $satz = 0;
                    db()->prepare(
                        "INSERT INTO finance_preise (airline, kategorie, position, tagessatz, freistellungscode)
                         VALUES (?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE tagessatz = VALUES(tagessatz),
                                                 freistellungscode = VALUES(freistellungscode)"
                    )->execute([$airline, $kat, $pos, $satz, $fscode ?: null]);
                }
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            error_log('save_finance_config Fehler: ' . $e->getMessage());
            json_err('Datenbankfehler beim Speichern.', 500);
        }

        audit('finance_config_gespeichert', null,
            "Airline:{$airline} durch {$_SESSION['panel_user']}");
        json_ok(['saved' => true]);
        break;

    // ── Kumulierte Freistellungskosten & Tage abrufen ─────────
    case 'get_finance_stats':
        $filter_von     = clean($_POST['von']     ?? '');
        $filter_bis     = clean($_POST['bis']     ?? '');
        $filter_airline = clean($_POST['airline'] ?? '');
        $filter_code    = clean($_POST['code']    ?? '');
        // Echter Freistellungscode (V4, FS usw.) - unabhängig vom obigen
        // "code"-Parameter, der historisch die Kategorie meint.
        $filter_fscode  = clean($_POST['freistellungscode'] ?? '');

        $kat_map_sql = "
            CASE a.veranstaltung
                WHEN 'Verhandlung' THEN 'verhandlung'
                WHEN 'TK Sitzung'  THEN 'tk_sitzung'
                ELSE                    'sonstiges'
            END
        ";

        // Bucket "Aktiv" (Finance): genehmigt + beantragt_ag + storno_buero
        // zählen, abgelehnt/abgelehnt_ag/storno_bestaetigt_ag nicht. Auf
        // Tage-Ebene (at2.status) gefiltert, nicht auf Antrags-Ebene, damit
        // Teilstornos korrekt nur den stornierten Tag ausschließen.
        $where  = ["at2.status IN ('genehmigt','beantragt_ag','storno_buero')"];
        $params = [];

        if ($filter_von) {
            $where[]  = 'at2.tag >= ?';
            $params[] = $filter_von;
        }
        if ($filter_bis) {
            $where[]  = 'at2.tag <= ?';
            $params[] = $filter_bis;
        }
        if ($filter_airline) {
            $where[]  = 'a.airline = ?';
            $params[] = $filter_airline;
        }
        if ($filter_code && in_array($filter_code, ['verhandlung','tk_sitzung','sonstiges'], true)) {
            $where[]  = "NULLIF(a.freistellungscode,'') = ?";
            $params[] = $filter_code;
        }
        if ($filter_fscode) {
            $where[]  = 'a.freistellungscode = ?';
            $params[] = $filter_fscode;
        }

        $where_sql = 'WHERE ' . implode(' AND ', $where);

        $sql = "
            SELECT
                a.airline,
                COALESCE(NULLIF(a.freistellungscode,''), '–') AS kategorie,
                a.freistellungscode                           AS freistellungscode,
                a.position,
                COUNT(at2.id)                                 AS anzahl_tage,
                COALESCE(MAX(fp.tagessatz), 0)                AS tagessatz,
                SUM(COALESCE(fp.tagessatz, 0))                AS kosten
            FROM antrag_tage at2
            JOIN antraege a ON a.id = at2.antrag_id
            LEFT JOIN finance_preise fp
                ON  fp.id = (SELECT id FROM finance_preise
                              WHERE airline           = a.airline
                                AND freistellungscode = NULLIF(a.freistellungscode, '')
                                AND position          = a.position ORDER BY tagessatz DESC LIMIT 1)
            {$where_sql}
            GROUP BY a.airline, a.freistellungscode, a.position
            ORDER BY a.airline, a.freistellungscode, a.position
        ";

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $zeilen = $stmt->fetchAll();

        $aggregiert    = [];
        $gesamt_kosten = 0.0;
        $gesamt_tage   = 0;

        foreach ($zeilen as $z) {
            $key = $z['airline'] . '|' . $z['kategorie'];
            if (!isset($aggregiert[$key])) {
                $aggregiert[$key] = [
                    'airline'           => $z['airline'],
                    'kategorie'         => $z['kategorie'],
                    'freistellungscode' => $z['freistellungscode'],
                    'tage'      => 0,
                    'kosten'    => 0.0,
                    'pos'       => [],
                ];
            }
            $aggregiert[$key]['tage']              += (int)$z['anzahl_tage'];
            $aggregiert[$key]['kosten']            += (float)$z['kosten'];
            $aggregiert[$key]['pos'][$z['position']] = [
                'tage'      => (int)$z['anzahl_tage'],
                'tagessatz' => (float)$z['tagessatz'],
                'kosten'    => (float)$z['kosten'],
            ];
            $gesamt_kosten += (float)$z['kosten'];
            $gesamt_tage   += (int)$z['anzahl_tage'];
        }

        // Anzahl Anträge separat zählen
        $a_where  = ["a.status IN ('genehmigt','beantragt_ag','storno_buero')"];
        $a_params = [];
        if ($filter_airline) { $a_where[] = 'a.airline = ?';       $a_params[] = $filter_airline; }
        if ($filter_von)     { $a_where[] = 'a.zeitraum_von >= ?'; $a_params[] = $filter_von; }
        if ($filter_bis)     { $a_where[] = 'a.zeitraum_bis <= ?'; $a_params[] = $filter_bis; }
        if ($filter_code)    { $a_where[] = "NULLIF(a.freistellungscode,'') = ?"; $a_params[] = $filter_code; }
        if ($filter_fscode)  { $a_where[] = 'a.freistellungscode = ?'; $a_params[] = $filter_fscode; }

        $a_stmt = db()->prepare(
            "SELECT COUNT(*) FROM antraege a WHERE " . implode(' AND ', $a_where)
        );
        $a_stmt->execute($a_params);
        $gesamt_antraege = (int)$a_stmt->fetchColumn();

        $avg = $gesamt_tage > 0 ? round($gesamt_kosten / $gesamt_tage, 2) : 0.0;

        json_ok([
            'zeilen'          => array_values($aggregiert),
            'gesamt_kosten'   => round($gesamt_kosten, 2),
            'gesamt_tage'     => $gesamt_tage,
            'avg_kosten_tag'  => $avg,
            'gesamt_antraege' => $gesamt_antraege,
        ]);
        break;

    // ── Chartdaten: monatlich / quartalsweise / jährlich ──────
    case 'get_finance_chart':
        $granularitaet = clean($_POST['granularitaet'] ?? 'monthly');
        if (!in_array($granularitaet, ['monthly','quarterly','yearly'], true)) {
            $granularitaet = 'monthly';
        }
        $filter_airline = clean($_POST['airline'] ?? '');
        $filter_von     = clean($_POST['von']     ?? date('Y') . '-01-01');
        $filter_bis     = clean($_POST['bis']     ?? date('Y') . '-12-31');

        $periode_sql = match($granularitaet) {
            'monthly'   => "DATE_FORMAT(at2.tag, '%Y-%m')",
            'quarterly' => "CONCAT(YEAR(at2.tag), '-Q', QUARTER(at2.tag))",
            'yearly'    => "YEAR(at2.tag)",
        };

        $kat_map_sql = "
            CASE a.veranstaltung
                WHEN 'Verhandlung' THEN 'verhandlung'
                WHEN 'TK Sitzung'  THEN 'tk_sitzung'
                ELSE                    'sonstiges'
            END
        ";

        // Bucket "Aktiv" (Finance): genehmigt + beantragt_ag + storno_buero
        // zählen, auf Tage-Ebene gefiltert (siehe get_finance_stats).
        $where  = [
            "at2.status IN ('genehmigt','beantragt_ag','storno_buero')",
            "at2.tag BETWEEN ? AND ?",
        ];
        $params = [$filter_von, $filter_bis];

        if ($filter_airline) {
            $where[]  = 'a.airline = ?';
            $params[] = $filter_airline;
        }

        // Kosten werden pro Tag einzeln berechnet (SUM statt COUNT*Satz),
        // um das GROUP-BY-Problem bei verschiedenen Tagessätzen zu vermeiden.
        $sql = "
            SELECT
                ({$periode_sql})                               AS periode,
                COALESCE(NULLIF(a.freistellungscode,''), '–') AS kategorie,
                COUNT(at2.id)                                  AS anzahl_tage,
                SUM(COALESCE(fp.tagessatz, 0))                 AS kosten
            FROM antrag_tage at2
            JOIN antraege a ON a.id = at2.antrag_id
            LEFT JOIN finance_preise fp
                ON  fp.id = (SELECT id FROM finance_preise
                              WHERE airline           = a.airline
                                AND freistellungscode = NULLIF(a.freistellungscode, '')
                                AND position          = a.position ORDER BY tagessatz DESC LIMIT 1)
            WHERE " . implode(' AND ', $where) . "
            GROUP BY ({$periode_sql}), a.freistellungscode
            ORDER BY periode, a.freistellungscode
        ";

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $raw = $stmt->fetchAll();

        $chart = [];
        $alle_codes = [];
        foreach ($raw as $r) {
            $p   = $r['periode'];
            $kat = $r['kategorie'] ?: '–';
            $alle_codes[$kat] = true;
            if (!isset($chart[$p])) {
                $chart[$p] = ['periode' => $p];
            }
            if (!isset($chart[$p][$kat])) {
                $chart[$p][$kat] = ['tage' => 0, 'kosten' => 0.0];
            }
            $chart[$p][$kat]['tage']   += (int)$r['anzahl_tage'];
            $chart[$p][$kat]['kosten'] += (float)$r['kosten'];
        }

        // Alle Perioden mit allen vorkommenden Codes auffüllen
        $codes = array_keys($alle_codes);
        foreach ($chart as &$c) {
            foreach ($codes as $code) {
                if (!isset($c[$code])) {
                    $c[$code] = ['tage' => 0, 'kosten' => 0.0];
                }
                $c[$code]['kosten'] = round($c[$code]['kosten'], 2);
            }
        }
        unset($c);

        json_ok([
            'granularitaet' => $granularitaet,
            'codes'         => $codes, // damit Frontend die Legende dynamisch aufbauen kann
            'perioden'      => array_values($chart),
        ]);
        break;

    // ── Distinct Airlines für Finance-Dropdowns ───────────────
    case 'list_finance_airlines':
        $airlines = db()->query(
            "SELECT DISTINCT airline FROM (
                SELECT airline FROM tks WHERE aktiv = 1 AND airline IS NOT NULL AND airline != ''
                UNION
                SELECT airline FROM antraege WHERE airline IS NOT NULL AND airline != ''
             ) x
             ORDER BY airline"
        )->fetchAll(PDO::FETCH_COLUMN);
        json_ok(['airlines' => $airlines]);
        break;

    // ── Alle vorkommenden Freistellungscodes (airline-übergreifend) ──
    // Für das Filter-Dropdown in "Kosten & Tage" - im Unterschied zu
    // list_freistellungscodes (Zeile ~1453) NICHT auf eine Airline
    // beschränkt, da hier airlineübergreifend gefiltert werden soll.
    case 'list_freistellungscodes_alle':
        $codes = db()->query(
            "SELECT DISTINCT freistellungscode FROM antraege
             WHERE freistellungscode IS NOT NULL AND freistellungscode != ''
             ORDER BY freistellungscode"
        )->fetchAll(PDO::FETCH_COLUMN);
        json_ok(['codes' => $codes]);
        break;

    // ── Excel-Export für das Quartalsmonitoring, gegliedert nach
    //    Freistellungscode ─────────────────────────────────────────
    // Filter: Zeitraum (von/bis) und/oder Airline, wie in "Kosten & Tage"
    // bereits ausgewählt. Eine Zeile je Freistellungscode + Quartal +
    // Airline, damit sich der Verbrauch je Code über die Quartale
    // hinweg nachvollziehen lässt.
    case 'export_finance_quartal_excel':
        require_once __DIR__ . '/../_backend/xlsx_writer.php';

        $filter_von     = clean($_POST['von']     ?? '');
        $filter_bis     = clean($_POST['bis']     ?? '');
        $filter_airline = clean($_POST['airline'] ?? '');
        if (!$filter_von || !$filter_bis) json_err('Zeitraum (von/bis) fehlt.');

        $where  = [
            "at2.status IN ('genehmigt','beantragt_ag','storno_buero')",
            'at2.tag BETWEEN ? AND ?',
            "a.freistellungscode IS NOT NULL AND a.freistellungscode != ''",
        ];
        $params = [$filter_von, $filter_bis];
        if ($filter_airline) {
            $where[]  = 'a.airline = ?';
            $params[] = $filter_airline;
        }

        $kat_map_sql = "
            CASE a.veranstaltung
                WHEN 'Verhandlung' THEN 'verhandlung'
                WHEN 'TK Sitzung'  THEN 'tk_sitzung'
                ELSE                    'sonstiges'
            END
        ";

        $stmt = db()->prepare(
            "SELECT
                a.freistellungscode                            AS code,
                a.airline                                      AS airline,
                CONCAT(YEAR(at2.tag), '-Q', QUARTER(at2.tag))  AS quartal,
                COUNT(at2.id)                                  AS tage,
                SUM(COALESCE(fp.tagessatz, 0))                 AS kosten
             FROM antrag_tage at2
             JOIN antraege a ON a.id = at2.antrag_id
             LEFT JOIN finance_preise fp
                 ON  fp.id = (SELECT id FROM finance_preise
                               WHERE airline           = a.airline
                                 AND freistellungscode = NULLIF(a.freistellungscode, '')
                                 AND position          = a.position ORDER BY tagessatz DESC LIMIT 1)
             WHERE " . implode(' AND ', $where) . "
             GROUP BY a.freistellungscode, a.airline, quartal
             ORDER BY a.freistellungscode, a.airline, quartal"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        if (!$rows) json_err('Keine Daten für diesen Zeitraum/diese Airline gefunden.');

        $xlsx  = new SimpleXlsx();
        $sheet = $xlsx->addSheet('Quartalsmonitoring');

        $header = ['Freistellungscode', 'Airline', 'Quartal', 'Anzahl Tage', 'Kosten (EUR)'];
        foreach ($header as $c => $h) $sheet->write(1, $c + 1, $h, 'bold');
        $sheet->setColWidth(1, 22); $sheet->setColWidth(2, 16);
        $sheet->setColWidth(3, 12); $sheet->setColWidth(4, 14); $sheet->setColWidth(5, 14);

        $row = 2;
        $summe_je_code = [];
        foreach ($rows as $r) {
            $sheet->write($row, 1, $r['code']);
            $sheet->write($row, 2, $r['airline']);
            $sheet->write($row, 3, $r['quartal']);
            $sheet->write($row, 4, (int)$r['tage']);
            $sheet->write($row, 5, round((float)$r['kosten'], 2));
            $row++;
            $summe_je_code[$r['code']]['tage']   = ($summe_je_code[$r['code']]['tage']   ?? 0) + (int)$r['tage'];
            $summe_je_code[$r['code']]['kosten'] = ($summe_je_code[$r['code']]['kosten'] ?? 0) + (float)$r['kosten'];
        }

        // Leerzeile + Gesamtsumme je Code als Übersicht am Ende
        $row++;
        $sheet->write($row, 1, 'Gesamt je Freistellungscode', 'bold');
        $row++;
        foreach ($summe_je_code as $code => $sum) {
            $sheet->write($row, 1, $code);
            $sheet->write($row, 4, (int)$sum['tage']);
            $sheet->write($row, 5, round((float)$sum['kosten'], 2));
            $row++;
        }

        $tmp_path = sys_get_temp_dir() . '/' . uniqid('finance_export_', true) . '.xlsx';
        $xlsx->save($tmp_path);

        audit('finance_quartal_export', null,
            "Zeitraum:{$filter_von}–{$filter_bis} Airline:" . ($filter_airline ?: 'alle') .
            " durch {$_SESSION['panel_user']}");

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="quartalsmonitoring_' . $filter_von . '_' . $filter_bis . '.xlsx"');
        readfile($tmp_path);
        @unlink($tmp_path);
        exit;
    // Veranstaltungsart: bei 'sonstige' der Freitext aus ereignisse.bezeichnung,
    // bei 'TK Sitzung' -> 'Sitzung', bei 'Verhandlung' unverändert.
    case 'export_rechnungsdb_csv':
        $filter_von = clean($_POST['von'] ?? '');
        $filter_bis = clean($_POST['bis'] ?? '');
        if (!$filter_von || !$filter_bis) json_err('Zeitraum (von/bis) fehlt.');

        $art_sql = "
            CASE
                WHEN a.veranstaltung = 'sonstige'   THEN e.bezeichnung
                WHEN a.veranstaltung = 'TK Sitzung' THEN 'Sitzung'
                ELSE a.veranstaltung
            END
        ";
        // Gleicher Fallback wie bei Excel-/Word-Erzeugung (ag_antrag_excel.php,
        // ag_antrag_doc.php): a.freistellungscode ist nicht immer direkt am
        // Antrag gesetzt - dann über finance_preise (Airline+Kategorie+
        // Position) nachschlagen, statt eine leere Spalte auszugeben.
        $katMapSql = "CASE a.veranstaltung
                        WHEN 'Verhandlung' THEN 'verhandlung'
                        WHEN 'TK Sitzung'  THEN 'tk_sitzung'
                        ELSE                    'sonstiges'
                      END";

        $stmt = db()->prepare(
            "SELECT
                at2.tag                AS datum,
                e.bezeichnung           AS veranstaltungsname,
                ({$art_sql})            AS veranstaltungsart,
                COALESCE(NULLIF(a.freistellungscode, ''), fp.freistellungscode) AS freistellungstyp,
                a.vorname,
                a.nachname_enc,
                a.position,
                a.airline               AS fluggesellschaft
             FROM antrag_tage at2
             JOIN antraege a        ON a.id = at2.antrag_id
             LEFT JOIN ereignisse e ON e.id = a.ereignis_id
             LEFT JOIN finance_preise fp
                    ON  fp.id = (SELECT id FROM finance_preise
                                  WHERE airline   = a.airline
                                    AND kategorie = ({$katMapSql})
                                    AND position  = a.position LIMIT 1)
             WHERE at2.status = 'genehmigt'
               AND at2.tag BETWEEN ? AND ?
             ORDER BY at2.tag, a.vorname"
        );
        $stmt->execute([$filter_von, $filter_bis]);
        $rows = $stmt->fetchAll();

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 BOM, damit Excel Umlaute korrekt zeigt
        fputcsv($fh, ['Datum','Veranstaltungsname','Veranstaltungsart','Freistellungstyp',
                      'Vorname','Nachname','Position','Fluggesellschaft'], ';', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($fh, [
                date('d.m.Y', strtotime($r['datum'])),
                $r['veranstaltungsname'] ?? '',
                $r['veranstaltungsart']  ?? '',
                $r['freistellungstyp']   ?? '',
                $r['vorname'],
                decrypt($r['nachname_enc']),
                $r['position'] ?? '',
                $r['fluggesellschaft'] ?? '',
            ], ';', '"', '\\');
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        audit('rechnungsdb_export', null,
            "Zeitraum:{$filter_von}–{$filter_bis} Zeilen:" . count($rows) . " durch {$_SESSION['panel_user']}");

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="freistellungsbericht_' . $filter_von . '_' . $filter_bis . '_rechnungsdb.csv"');
        echo $csv;
        exit;

    // ── Pro Airline: soll bei der Beantragung zusätzlich das
    //    Word-Formular (Antrag auf FS/V4-Freistellung) erzeugt und
    //    angehängt werden? ──────────────────────────────────────
    // ══════════════════════════════════════════════════════════
    // ERWEITERTE FREISTELLUNGSGRÜNDE PRO AIRLINE
    // ══════════════════════════════════════════════════════════

    // Gibt alle konfigurierten Zusatz-Typen zurück, gruppiert nach Airline.
    // Standard-Typen (Verhandlung, TK Sitzung, sonstige) sind NICHT enthalten.
    case 'list_airline_veranstaltungen':
        $rows = db()->query(
            "SELECT id, airline, veranstaltung, label, uw_key, sort_order, aktiv
               FROM airline_veranstaltungen ORDER BY airline, sort_order, label"
        )->fetchAll();
        json_ok(['eintraege' => $rows]);
        break;

    // Gibt die erweiterten Typen für eine bestimmte Airline zurück
    // (nur aktive). Wird vom Frontend beim Airline-Wechsel abgerufen.
    case 'get_veranstaltungen_fuer_airline':
        $gvfa_airline = clean($_POST['airline'] ?? '');
        if (!$gvfa_airline) json_err('Airline fehlt.');
        $gvfa_stmt = db()->prepare(
            "SELECT veranstaltung, label, uw_key, sort_order
               FROM airline_veranstaltungen
              WHERE airline = ? AND aktiv = 1
              ORDER BY sort_order, label"
        );
        $gvfa_stmt->execute([$gvfa_airline]);
        json_ok(['zusatz_typen' => $gvfa_stmt->fetchAll()]);
        break;

    // Einen Eintrag anlegen oder aktualisieren (per id), löschen per delete=1
    case 'save_airline_veranstaltung':
        $sav_id       = (int)($_POST['id']       ?? 0);
        $sav_airline  = clean($_POST['airline']  ?? '');
        $sav_label    = clean($_POST['label']    ?? '');
        $sav_sort     = max(0, (int)($_POST['sort_order'] ?? 10));
        $sav_aktiv    = (int)($_POST['aktiv']   ?? 1) ? 1 : 0;
        $sav_delete   = (int)($_POST['delete']  ?? 0);

        if ($sav_delete && $sav_id) {
            db()->prepare("DELETE FROM airline_veranstaltungen WHERE id = ?")->execute([$sav_id]);
            audit('airline_veranstaltung_geloescht', null,
                "ID:{$sav_id} durch {$_SESSION['panel_user']}");
            json_ok();
        }

        if (!$sav_airline) json_err('Airline fehlt.');
        if (!$sav_label)   json_err('Bezeichnung fehlt.');

        // DB-Wert und UW-Key aus Label ableiten (lowercase, Leerzeichen → _)
        $sav_veranstaltung = $sav_label;
        $sav_uw_key        = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $sav_label));

        if ($sav_id) {
            db()->prepare(
                "UPDATE airline_veranstaltungen
                    SET airline=?, veranstaltung=?, label=?, uw_key=?, sort_order=?, aktiv=?
                  WHERE id=?"
            )->execute([$sav_airline, $sav_veranstaltung, $sav_label, $sav_uw_key, $sav_sort, $sav_aktiv, $sav_id]);
        } else {
            db()->prepare(
                "INSERT INTO airline_veranstaltungen
                    (airline, veranstaltung, label, uw_key, sort_order, aktiv)
                 VALUES (?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    label=VALUES(label), uw_key=VALUES(uw_key),
                    sort_order=VALUES(sort_order), aktiv=VALUES(aktiv)"
            )->execute([$sav_airline, $sav_veranstaltung, $sav_label, $sav_uw_key, $sav_sort, $sav_aktiv]);
        }
        audit('airline_veranstaltung_gespeichert', null,
            "Airline:{$sav_airline} Label:{$sav_label} durch {$_SESSION['panel_user']}");
        json_ok();
        break;

    case 'list_airline_doc_einstellungen':
        $rows = db()->query(
            "SELECT airline, doc_anhang_aktiv FROM airline_einstellungen"
        )->fetchAll(PDO::FETCH_KEY_PAIR); // airline => doc_anhang_aktiv
        json_ok(['einstellungen' => array_map('boolval', $rows)]);
        break;

    case 'save_airline_doc_einstellung':
        $sade_airline = clean($_POST['airline'] ?? '');
        $sade_aktiv   = (int)($_POST['aktiv'] ?? 0) ? 1 : 0;
        if (!$sade_airline) json_err('Airline fehlt.');

        db()->prepare(
            "INSERT INTO airline_einstellungen (airline, doc_anhang_aktiv, geaendert_am, geaendert_von)
             VALUES (?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE doc_anhang_aktiv = VALUES(doc_anhang_aktiv),
                                     geaendert_am = VALUES(geaendert_am),
                                     geaendert_von = VALUES(geaendert_von)"
        )->execute([$sade_airline, $sade_aktiv, $_SESSION['panel_user']]);

        audit('airline_doc_einstellung_geaendert', null,
            "Airline:{$sade_airline} doc_anhang_aktiv:{$sade_aktiv} durch {$_SESSION['panel_user']}");
        json_ok();
        break;




    // ══════════════════════════════════════════════════════════
    // FLUGBETRIEB-KONTAKTE
    // ══════════════════════════════════════════════════════════

    case 'list_flugbetrieb_kontakte':
        $rows = db()->query(
            'SELECT id, airline, bezeichnung, email, aktiv
             FROM flugbetrieb_kontakte
             ORDER BY airline, bezeichnung'
        )->fetchAll();
        json_ok($rows);
        break;

    case 'create_flugbetrieb_kontakt':
        $airline     = clean($_POST['airline']      ?? '');
        $bezeichnung = clean($_POST['bezeichnung']  ?? '');
        $email       = clean($_POST['email']        ?? '');
        $aktiv       = (int)($_POST['aktiv']        ?? 1);
        if (!$airline || !$bezeichnung || !$email) json_err('Pflichtfelder fehlen.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Ungültige E-Mail-Adresse.');
        db()->prepare(
            'INSERT INTO flugbetrieb_kontakte (airline, bezeichnung, email, aktiv) VALUES (?,?,?,?)'
        )->execute([$airline, $bezeichnung, $email, $aktiv]);
        json_ok(['id' => db()->lastInsertId()]);
        break;

    case 'update_flugbetrieb_kontakt':
        $id          = (int)($_POST['id']           ?? 0);
        $airline     = clean($_POST['airline']      ?? '');
        $bezeichnung = clean($_POST['bezeichnung']  ?? '');
        $email       = clean($_POST['email']        ?? '');
        $aktiv       = (int)($_POST['aktiv']        ?? 1);
        if (!$id || !$airline || !$bezeichnung || !$email) json_err('Pflichtfelder fehlen.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Ungültige E-Mail-Adresse.');
        db()->prepare(
            'UPDATE flugbetrieb_kontakte SET airline=?, bezeichnung=?, email=?, aktiv=? WHERE id=?'
        )->execute([$airline, $bezeichnung, $email, $aktiv, $id]);
        json_ok();
        break;

    case 'delete_flugbetrieb_kontakt':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_err('ID fehlt.');
        db()->prepare('DELETE FROM flugbetrieb_kontakte WHERE id=?')->execute([$id]);
        json_ok();
        break;

    // ══════════════════════════════════════════════════════════
    // SYSTEM-EINSTELLUNGEN
    // ══════════════════════════════════════════════════════════

    case 'get_einstellungen':
        json_ok([
            'storno_email_cc'      => get_einstellung(db(), 'storno_email_cc'),
            'erinnerung_frist_tage'=> (int)(get_einstellung(db(), 'erinnerung_frist_tage') ?: 7),
        ]);
        break;

    case 'save_einstellungen':
        $cc = clean($_POST['storno_email_cc'] ?? '');
        if ($cc !== '' && !filter_var($cc, FILTER_VALIDATE_EMAIL))
            json_err('Ungültige CC-E-Mail-Adresse.');
        set_einstellung(db(), 'storno_email_cc', $cc, $_SESSION['panel_user']);

        $fristTage = (int)($_POST['erinnerung_frist_tage'] ?? 7);
        if ($fristTage < 1 || $fristTage > 90)
            json_err('Frist-Erinnerung: bitte einen Wert zwischen 1 und 90 Tagen angeben.');
        set_einstellung(db(), 'erinnerung_frist_tage', (string)$fristTage, $_SESSION['panel_user']);

        json_ok();
        break;

    // Manueller Testlauf der Frist-Erinnerung (normalerweise per Cron
    // täglich automatisch, siehe cron/erinnerung_frist.php). Verschickt
    // tatsächlich eine Mail an den Büro-Kontakt – Zugriff wie die Seite
    // "Büro Kontakt" selbst (jeder eingeloggte Büro/Admin-Nutzer).
    case 'sende_frist_erinnerung_test':
        $result = erinnerung_frist_mail_senden(db(), force: true);
        json_ok($result);
        break;

    // ══════════════════════════════════════════════════════════
    // STORNO-E-MAIL-VORSCHAU
    // ══════════════════════════════════════════════════════════

    case 'get_email_vorschau':
        $ereignis_id = (int)($_POST['ereignis_id'] ?? 0);
        $antrag_ids  = array_map('intval', (array)($_POST['antrag_ids'] ?? []));
        $tage_raw    = array_values(array_filter(array_map('clean', (array)($_POST['tage'] ?? []))));
        if (!$ereignis_id || empty($antrag_ids) || empty($tage_raw)) json_err('Parameter fehlen.');

        // Ereignis + TK
        $erg = db()->prepare(
            'SELECT e.veranstaltung, e.tk_id, t.kuerzel AS tk_kuerzel, t.bezeichnung AS tk_bezeichnung, t.airline
             FROM ereignisse e JOIN tks t ON t.id = e.tk_id WHERE e.id = ?'
        );
        $erg->execute([$ereignis_id]);
        $ereignis = $erg->fetch();
        if (!$ereignis) json_err('Ereignis nicht gefunden.', 404);

        // Mitglieder + Freistellungscode
        $in = implode(',', $antrag_ids);
        $mgl = db()->query(
            "SELECT vorname, nachname_enc AS nachname, freistellungscode FROM antraege WHERE id IN ($in)"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($mgl as &$m) $m['nachname'] = decrypt($m['nachname']);
        unset($m);

        $codes = array_values(array_unique(array_filter(array_column($mgl, 'freistellungscode'))));

        // Alle aktiven Kontakte dieser Airline (airline-basiert statt tk_id)
        $ks = db()->prepare(
            'SELECT bezeichnung, email FROM flugbetrieb_kontakte
             WHERE airline = ? AND aktiv = 1 ORDER BY id'
        );
        $ks->execute([$ereignis['airline']]);
        $kontakte_ev = $ks->fetchAll(PDO::FETCH_ASSOC);

        // CC + Absender
        $cc_email   = get_einstellung(db(), 'storno_email_cc');
        $from_name  = $_SESSION['panel_user'];
        $from_email = '';
        $pu = db()->prepare('SELECT email FROM panel_users WHERE username = ? LIMIT 1');
        $pu->execute([$from_name]);
        $pu_row = $pu->fetch(PDO::FETCH_ASSOC);
        if ($pu_row && $pu_row['email']) $from_email = $pu_row['email'];

        // Betreff/Text jetzt aus der (vom Büro anpassbaren) Vorlage
        // gerendert statt fest programmiert - siehe storno_mail_vorlagen.php.
        $tage_fmt = array_map(fn($t) => implode('.', array_reverse(explode('-', $t))), $tage_raw);
        sort($tage_fmt);
        $storno_vars = [
            'TAGE'          => implode(', ', $tage_fmt),
            'SYMBOL'        => implode(', ', $codes),
            'MITGLIEDER'    => storno_mitglieder_liste_bauen($mgl),
            'BEARBEITER'    => $from_name,
            'TK'            => $ereignis['tk_bezeichnung'],
            'VERANSTALTUNG' => $ereignis['veranstaltung'],
        ];
        $storno_vorlage = storno_vorlage_laden(db(), 'storno');

        json_ok([
            'from_name'          => $from_name,
            'from_email'         => $from_email,
            'to_bezeichnung'     => implode(', ', array_column($kontakte_ev, 'bezeichnung')),
            'to_email'           => implode(', ', array_column($kontakte_ev, 'email')),
            'to_kontakte'        => $kontakte_ev,
            'cc_email'           => $cc_email,
            'freistellungscodes' => $codes,
            // LH-Storno-Formular wird angehängt (nur Tage mit FS/V4, die
            // bereits beim AG beantragt/genehmigt waren)
            'storno_doc_aktiv'   => ag_storno_doc_aktiv(db(), (string)$ereignis['airline'])
                                    && (bool)array_intersect(array_map('strtoupper', $codes), AG_STORNO_DOC_CODES),
            'subject'            => storno_render($storno_vorlage['subject'], $storno_vars),
            'body'               => storno_render($storno_vorlage['body'], $storno_vars),
        ]);
        break;

    // ══════════════════════════════════════════════════════════
    // STORNO-E-MAIL-LOG
    // ══════════════════════════════════════════════════════════

    case 'list_storno_email_log':
        $where  = ['1=1'];
        $params = [];
        $von        = clean($_POST['von']          ?? '');
        $bis        = clean($_POST['bis']          ?? '');
        $bearbeiter = clean($_POST['gesendet_von'] ?? '');
        $airline    = clean($_POST['airline']      ?? '');
        if ($von)        { $where[] = 'DATE(sel.gesendet_am) >= ?'; $params[] = $von; }
        if ($bis)        { $where[] = 'DATE(sel.gesendet_am) <= ?'; $params[] = $bis; }
        if ($bearbeiter) { $where[] = 'sel.gesendet_von = ?';       $params[] = $bearbeiter; }
        if ($airline)    { $where[] = 'tk.airline = ?';             $params[] = $airline; }
        $st = db()->prepare(
            'SELECT sel.id, sel.storno_aktion_id, sel.gesendet_am, sel.gesendet_von,
                    sel.from_email, sel.to_email, sel.to_bezeichnung, sel.cc_email,
                    sel.subject, sel.tk_id, sel.tk_name, sel.status, sel.fehler_meldung,
                    tk.airline
             FROM storno_email_log sel
             LEFT JOIN tks tk ON tk.id = sel.tk_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY sel.gesendet_am DESC LIMIT 500'
        );
        $st->execute($params);
        json_ok($st->fetchAll());
        break;


    // ══════════════════════════════════════════════════════════
    // AG-RÜCKMELDUNG
    // ══════════════════════════════════════════════════════════

    // Liste der Anträge eines Ereignisses für AG-Rückmeldung
    // Gibt alle Anträge mit Status freigabe_buero oder beantragt_ag zurück
    case 'list_ag_rueckmeldung':
        $ereignis_id = (int)($_POST['ereignis_id'] ?? 0);
        if (!$ereignis_id) json_err('Ereignis-ID fehlt.');

        // Tages-basiert: jeder Tag einzeln, gruppiert nach Antrag.
        $rueckmeldung_status = clean($_POST['status'] ?? 'beantragt_ag');
        $erlaubte_status = ['ausstehend','freigabe_buero','beantragt_ag','genehmigt','abgelehnt','abgelehnt_ag','storno_buero','storno_bestaetigt_ag'];
        if ($rueckmeldung_status && !in_array($rueckmeldung_status, $erlaubte_status, true)) {
            json_err('Ungültiger Status-Filter.');
        }
        $tag_status_sql = $rueckmeldung_status
            ? "AND at2.status = '{$rueckmeldung_status}'"
            : "AND at2.status NOT IN ('storno_buero','storno_bestaetigt_ag')";

        $stmt = db()->prepare(
            "SELECT a.id AS antrag_id, a.vorname, a.nachname_enc, a.airline,
                    a.position, a.status AS antrag_status, a.notiz,
                    a.freistellungscode,
                    at2.id AS tag_id, at2.tag, at2.status AS tag_status
             FROM antraege a
             JOIN antrag_tage at2 ON at2.antrag_id = a.id
             WHERE a.ereignis_id = ?
               {$tag_status_sql}
             ORDER BY a.vorname, at2.tag"
        );
        $stmt->execute([$ereignis_id]);
        $rows = $stmt->fetchAll();

        $antraege = [];
        foreach ($rows as $r) {
            $aid = $r['antrag_id'];
            if (!isset($antraege[$aid])) {
                $antraege[$aid] = [
                    'id'               => $aid,
                    'vorname'          => $r['vorname'],
                    'nachname'         => decrypt($r['nachname_enc']),
                    'position'         => $r['position'],
                    'antrag_status'    => $r['antrag_status'],
                    'notiz'            => $r['notiz'],
                    'freistellungscode'=> $r['freistellungscode'],
                    'tage'             => [],
                ];
            }
            $antraege[$aid]['tage'][] = [
                'tag_id'     => $r['tag_id'],
                'tag'        => $r['tag'],
                'tag_status' => $r['tag_status'],
            ];
        }
        foreach ($antraege as &$a) {
            $tags = array_column($a['tage'], 'tag');
            $a['zeitraum_von'] = min($tags);
            $a['zeitraum_bis'] = max($tags);
            $a['anzahl_tage']  = count($a['tage']);
        }
        unset($a);
        json_ok(array_values($antraege));
        break;

    // Setzt Status auf Tages-Ebene: pro tag_id einzeln, Antrag-Status wird abgeleitet.
    case 'save_ag_rueckmeldung':
        $tag_ids    = array_map('intval', (array)($_POST['tag_ids']    ?? []));
        $antrag_ids = array_map('intval', (array)($_POST['antrag_ids'] ?? []));
        $new_status = clean($_POST['status'] ?? '');
        $notiz      = clean($_POST['notiz']  ?? '');

        // Kompatibilität: wenn nur antrag_ids ohne tag_ids übergeben werden,
        // alle Tage dieser Anträge updaten (Rückwärtskompatibilität).
        if (empty($tag_ids) && !empty($antrag_ids)) {
            $in_ph = implode(',', array_fill(0, count($antrag_ids), '?'));
            $t_stmt = db()->prepare("SELECT id FROM antrag_tage WHERE antrag_id IN ({$in_ph}) AND status NOT IN ('storno_buero','storno_bestaetigt_ag')");
            $t_stmt->execute($antrag_ids);
            $tag_ids = array_column($t_stmt->fetchAll(), 'id');
        }

        if (empty($tag_ids)) json_err('Keine Tage gewählt.');
        if (!in_array($new_status, ['genehmigt', 'abgelehnt_ag', 'storno_bestaetigt_ag'], true))
            json_err('Ungültiger Status. Erlaubt: genehmigt, abgelehnt_ag, storno_bestaetigt_ag.');

        $bearbeiter = $_SESSION['panel_user'];
        $tag_ids_set = array_flip($tag_ids);

        // Betroffene Antrags-IDs ermitteln
        $tag_ph = implode(',', array_fill(0, count($tag_ids), '?'));
        $aid_stmt = db()->prepare(
            "SELECT DISTINCT antrag_id FROM antrag_tage WHERE id IN ({$tag_ph})"
        );
        $aid_stmt->execute($tag_ids);
        $betroffene_antrag_ids = array_column($aid_stmt->fetchAll(), 'antrag_id');

        $aktualisiert = 0;
        foreach ($betroffene_antrag_ids as $aid) {
            $a_stmt = db()->prepare("SELECT * FROM antraege WHERE id = ? LIMIT 1");
            $a_stmt->execute([$aid]);
            $a = $a_stmt->fetch();
            if (!$a) continue;

            // Alle Tage dieses Antrags laden
            $alle_tage_stmt = db()->prepare(
                "SELECT id AS tag_id, tag, status FROM antrag_tage
                  WHERE antrag_id = ? ORDER BY tag"
            );
            $alle_tage_stmt->execute([$aid]);
            $alle_tage = $alle_tage_stmt->fetchAll();

            // Tage aufteilen: betroffen vs. bleiben
            $tage_betroffen = [];
            $tage_bleiben   = [];
            foreach ($alle_tage as $t) {
                if (isset($tag_ids_set[$t['tag_id']])) {
                    $tage_betroffen[] = $t;
                } else {
                    $tage_bleiben[] = $t;
                }
            }

            if (empty($tage_betroffen)) continue;

            if ($new_status === 'storno_bestaetigt_ag') {
                // Kein Split bei Storno – einfach Status setzen
                $ph = implode(',', array_fill(0, count($tage_betroffen), '?'));
                db()->prepare("UPDATE antrag_tage SET status='storno_bestaetigt_ag' WHERE id IN ({$ph})")
                    ->execute(array_column($tage_betroffen, 'tag_id'));
                db()->prepare("UPDATE antraege SET status='storno_bestaetigt_ag', entschieden_von=? WHERE id=?")
                    ->execute([$bearbeiter, $aid]);
                audit('ag_rueckmeldung', $aid, "storno_bestaetigt_ag durch {$bearbeiter}");
                $aktualisiert++;
                continue;
            }

            // ── Split-Logik ───────────────────────────────────────
            // Betroffene Tage in konsekutive Gruppen aufteilen.
            // Jede Gruppe bekommt einen eigenen Antrag.
            $betroffen_tags = array_column($tage_betroffen, 'tag');
            $tag_zu_id = array_column($tage_betroffen, 'tag_id', 'tag');
            $konsekutiv_gruppen = tage_zu_konsekutiven_gruppen($betroffen_tags);

            if (empty($tage_bleiben)) {
                // Alle Tage betroffen – Ursprungsantrag für erste Gruppe verwenden,
                // für weitere Gruppen neue Anträge anlegen
                $erste_gruppe = array_shift($konsekutiv_gruppen);

                // Ursprungsantrag auf erste Gruppe setzen
                $ph_upd = implode(',', array_fill(0, count($erste_gruppe), '?'));
                db()->prepare("UPDATE antrag_tage SET status=? WHERE tag IN ({$ph_upd}) AND antrag_id=?")
                    ->execute([$new_status, ...$erste_gruppe, $aid]);
                db()->prepare(
                    "UPDATE antraege SET status=?, zeitraum_von=?, zeitraum_bis=?,
                     notiz=?, entschieden_am=NOW(), entschieden_von=? WHERE id=?"
                )->execute([
                    $new_status, min($erste_gruppe), max($erste_gruppe),
                    $notiz ?: null, $bearbeiter, $aid,
                ]);
                audit('ag_rueckmeldung', $aid,
                    "{$a['status']} → {$new_status} (" . count($erste_gruppe) . " Tage) durch {$bearbeiter}");
                $neuer_aid_fuer_mail = $aid;
                $alle_betroffenen_tage_fuer_mail = $erste_gruppe;

                // Restliche Gruppen als neue Anträge
                foreach ($konsekutiv_gruppen as $gruppe) {
                    $tag_ids_gruppe = array_map(fn($t) => $tag_zu_id[$t], $gruppe);
                    // antrag_tage aus Ursprungsantrag entfernen
                    $ph_del = implode(',', array_fill(0, count($tag_ids_gruppe), '?'));
                    db()->prepare("DELETE FROM antrag_tage WHERE id IN ({$ph_del})")
                        ->execute($tag_ids_gruppe);
                    // Neuen Antrag anlegen
                    db()->prepare(
                        "INSERT INTO antraege
                         (mitglied_id, vorname, nachname_enc, tk_id, vc_email_enc,
                          airline, position, flugzeugmuster, stationierung,
                          veranstaltung, zeitraum_von, zeitraum_bis,
                          token, ereignis_id, freistellungscode,
                          status, entschieden_am, entschieden_von, notiz)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?)"
                    )->execute([
                        $a['mitglied_id'], $a['vorname'], $a['nachname_enc'],
                        $a['tk_id'], $a['vc_email_enc'],
                        $a['airline'], $a['position'], $a['flugzeugmuster'], $a['stationierung'],
                        $a['veranstaltung'], min($gruppe), max($gruppe),
                        generate_token(), $a['ereignis_id'], $a['freistellungscode'],
                        $new_status, $bearbeiter,
                        ($notiz ?: '') . "\n[Split aus #{$aid}: {$new_status}]",
                    ]);
                    $neuer_sub_aid = (int)db()->lastInsertId();
                    // antrag_tage für neuen Antrag
                    $ins = db()->prepare(
                        "INSERT INTO antrag_tage (antrag_id, ereignis_id, tag, status) VALUES (?,?,?,?)"
                    );
                    foreach ($gruppe as $t) {
                        $ins->execute([$neuer_sub_aid, $a['ereignis_id'], $t, $new_status]);
                    }
                    audit('ag_rueckmeldung', $neuer_sub_aid,
                        "Split aus #{$aid}: {$new_status} durch {$bearbeiter}");
                    $alle_betroffenen_tage_fuer_mail = array_merge($alle_betroffenen_tage_fuer_mail, $gruppe);
                }
            } else {
                // Teiltage betroffen → alle betroffenen Tage aus Ursprungsantrag entfernen
                $ph_del = implode(',', array_fill(0, count($tage_betroffen), '?'));
                db()->prepare("DELETE FROM antrag_tage WHERE id IN ({$ph_del})")
                    ->execute(array_column($tage_betroffen, 'tag_id'));

                // Ursprungsantrag-Zeitraum auf verbleibende Tage anpassen
                $bleiben_tags = array_column($tage_bleiben, 'tag');
                db()->prepare(
                    "UPDATE antraege SET zeitraum_von=?, zeitraum_bis=?,
                     notiz=CONCAT(COALESCE(notiz,''),'\n[AG-Split: ',?,' Tage ',?,']')
                     WHERE id=?"
                )->execute([
                    min($bleiben_tags), max($bleiben_tags),
                    count($tage_betroffen), $new_status, $aid,
                ]);

                // Pro konsekutiver Gruppe einen neuen Antrag
                $alle_betroffenen_tage_fuer_mail = [];
                foreach ($konsekutiv_gruppen as $gi => $gruppe) {
                    db()->prepare(
                        "INSERT INTO antraege
                         (mitglied_id, vorname, nachname_enc, tk_id, vc_email_enc,
                          airline, position, flugzeugmuster, stationierung,
                          veranstaltung, zeitraum_von, zeitraum_bis,
                          token, ereignis_id, freistellungscode,
                          status, entschieden_am, entschieden_von, notiz)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?)"
                    )->execute([
                        $a['mitglied_id'], $a['vorname'], $a['nachname_enc'],
                        $a['tk_id'], $a['vc_email_enc'],
                        $a['airline'], $a['position'], $a['flugzeugmuster'], $a['stationierung'],
                        $a['veranstaltung'], min($gruppe), max($gruppe),
                        generate_token(), $a['ereignis_id'], $a['freistellungscode'],
                        $new_status, $bearbeiter,
                        ($notiz ?: '') . "\n[Split aus #{$aid}: {$new_status}]",
                    ]);
                    $neuer_aid = (int)db()->lastInsertId();
                    if ($gi === 0) $neuer_aid_fuer_mail = $neuer_aid;

                    $ins = db()->prepare(
                        "INSERT INTO antrag_tage (antrag_id, ereignis_id, tag, status) VALUES (?,?,?,?)"
                    );
                    foreach ($gruppe as $t) {
                        $ins->execute([$neuer_aid, $a['ereignis_id'], $t, $new_status]);
                    }
                    audit('ag_rueckmeldung', $neuer_aid,
                        "Split aus #{$aid}: {$new_status} durch {$bearbeiter}");
                    $alle_betroffenen_tage_fuer_mail = array_merge($alle_betroffenen_tage_fuer_mail, $gruppe);
                }
            }

            // Mail ans Mitglied – alle betroffenen Tage zusammen
            $vc_email = decrypt($a['vc_email_enc']);
            $mail_notiz = match($new_status) {
                'abgelehnt_ag' => 'Der Arbeitgeber hat die Freistellung für diese Tage abgelehnt.' . ($notiz ? ' ' . $notiz : ''),
                default        => ($notiz ?: null),
            };
            mail_ergebnis($vc_email, $a['vorname'], $new_status, $mail_notiz,
                $a['veranstaltung'], $a['freistellungscode'], $alle_betroffenen_tage_fuer_mail);

            $aktualisiert++;
        }

        json_ok(['aktualisiert' => $aktualisiert]);
        break;

    // ══════════════════════════════════════════════════════════
    // AG-RÜCKMELDUNG PER DOC-UPLOAD (automatisierte Auswertung)
    // ══════════════════════════════════════════════════════════

    // Schritt 1: hochgeladenes, ausgefülltes Formular (DOCX) analysieren.
    // Ändert NOCH NICHTS in der DB - liefert nur eine Vorschau + Token.
    // ── Excel/DOC-Anhang vor dem Versand durch eine bearbeitete
    //    Version ersetzen (gleicher Token, gleiche Datei-Endung -
    //    der bestehende Versand-Mechanismus greift danach automatisch
    //    auf die neue Datei zu, ohne dass sich sonst etwas ändert) ──
    case 'ersetze_ag_anhang':
        $era_token = clean($_POST['token'] ?? '');
        if (!$era_token) json_err('Token fehlt.');
        if (empty($_FILES['datei']) || $_FILES['datei']['error'] !== UPLOAD_ERR_OK) {
            json_err('Keine Datei hochgeladen oder Upload-Fehler.');
        }
        if ($_FILES['datei']['size'] > 20 * 1024 * 1024) json_err('Datei ist zu groß (max. 20 MB).');

        $era_bestehend = ag_antrag_anhang_aus_token($era_token);
        if (!$era_bestehend) json_err('Anhang nicht gefunden oder abgelaufen - bitte Vorschau erneut öffnen.');

        $era_alte_ext = strtolower(pathinfo($era_bestehend['filename'], PATHINFO_EXTENSION));
        $era_neue_ext = strtolower(pathinfo($_FILES['datei']['name'], PATHINFO_EXTENSION));
        if ($era_alte_ext !== $era_neue_ext) {
            json_err("Falscher Dateityp: erwartet .{$era_alte_ext}, hochgeladen wurde .{$era_neue_ext}.");
        }

        // Alte Datei löschen, hochgeladene an genau denselben Platz legen -
        // Dateiname (und damit der Anzeigename beim Versand) bleibt gleich.
        @unlink($era_bestehend['path']);
        if (!move_uploaded_file($_FILES['datei']['tmp_name'], $era_bestehend['path'])) {
            json_err('Datei konnte nicht gespeichert werden.');
        }

        audit('ag_anhang_ersetzt', null, "Token:{$era_token} Datei:{$era_bestehend['filename']} durch {$_SESSION['panel_user']}");
        json_ok(['filename' => $era_bestehend['filename']]);
        break;

    case 'ag_doc_analysieren':
        if (empty($_FILES['docx']) || $_FILES['docx']['error'] !== UPLOAD_ERR_OK) {
            json_err('Keine Datei hochgeladen oder Upload-Fehler.');
        }
        $adf_name = $_FILES['docx']['name'] ?? '';
        if (strtolower(pathinfo($adf_name, PATHINFO_EXTENSION)) !== 'docx') {
            json_err('Bitte eine .docx-Datei hochladen.');
        }
        // Groben Größenschutz (z.B. gegen versehentlichen Upload einer
        // riesigen falschen Datei) - ein Formular ist normalerweise <100KB.
        if ($_FILES['docx']['size'] > 20 * 1024 * 1024) {
            json_err('Datei ist zu groß (max. 20 MB).');
        }

        try {
            $adf_ergebnis = ag_doc_datei_analysieren(db(), $_FILES['docx']['tmp_name']);
            json_ok($adf_ergebnis);
        } catch (Throwable $e) {
            error_log('ag_doc_analysieren Fehler: ' . $e->getMessage());
            json_err('Datei konnte nicht ausgewertet werden: ' . $e->getMessage());
        }
        break;

    // Schritt 2: die unter dem Token gespeicherte, bereits im Panel
    // geprüfte Auswertung tatsächlich auf die Anträge anwenden.
    case 'ag_doc_uebernehmen':
        $adu_token = clean($_POST['token'] ?? '');
        if (!$adu_token) json_err('Token fehlt.');

        // Manuelle Nachpflege für Zeilen, die automatisch nicht eindeutig
        // erkannt wurden: JSON-Objekt {antrag_id: 'genehmigt'|'abgelehnt_ag'}.
        $adu_manuell = [];
        $adu_manuell_raw = (string)($_POST['manuelle_entscheidungen'] ?? '');
        if ($adu_manuell_raw !== '') {
            $decoded = json_decode($adu_manuell_raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $aid => $status) {
                    $aid = (int)$aid;
                    $status = clean((string)$status);
                    if ($aid && in_array($status, ['genehmigt', 'abgelehnt_ag'], true)) {
                        $adu_manuell[$aid] = $status;
                    }
                }
            }
        }

        try {
            $adu_ergebnis = ag_doc_auswertung_uebernehmen(db(), $adu_token, $_SESSION['panel_user'], $adu_manuell);
            json_ok($adu_ergebnis);
        } catch (Throwable $e) {
            error_log('ag_doc_uebernehmen Fehler: ' . $e->getMessage());
            json_err('Auswertung konnte nicht übernommen werden: ' . $e->getMessage());
        }
        break;


    default:
        json_err('Unbekannte Aktion.', 400);
}
