<?php
// ============================================================
// api/entscheidung.php  –  Genehmigen/Ablehnen per Token-Link
// GET  → Bestätigungsseite anzeigen
// POST → Entscheidung speichern + Tage-Status synchronisieren
// ============================================================
require_once __DIR__ . '/../_backend/bootstrap.php';
send_security_headers();

$token  = trim($_GET['token']  ?? '');
$action = trim($_GET['action'] ?? '');

$erlaubt = ['genehmigt', 'abgelehnt'];

if (!preg_match('/^[a-f0-9]{64}$/', $token) || !in_array($action, $erlaubt, true)) {
    die(_seite('Ungültige Anfrage', '<p>Der Link ist ungültig oder fehlerhaft.</p>', 'fehler'));
}

$stmt = db()->prepare(
    "SELECT a.*, t.bezeichnung AS tk_name, e.bezeichnung AS ereignis_bezeichnung
     FROM antraege a
     JOIN tks t        ON t.id = a.tk_id
     LEFT JOIN ereignisse e ON e.id = a.ereignis_id
     WHERE a.token = ? LIMIT 1"
);
$stmt->execute([$token]);
$a = $stmt->fetch();

if (!$a) {
    die(_seite('Nicht gefunden', '<p>Dieser Link ist ungültig oder abgelaufen.</p>', 'fehler'));
}
if ($a['status'] !== 'ausstehend') {
    // Storno durch Büro: eigene Nachricht
    if ($a['status'] === 'storno_buero') {
        die(_seite('Storniert', '<p>Dieser Antrag wurde durch das Büro storniert und kann nicht mehr bearbeitet werden.</p>', 'fehler'));
    }
    die(_seite('Bereits entschieden',
        '<p>Dieser Antrag wurde bereits bearbeitet.<br>Aktueller Status: <strong>' .
        htmlspecialchars($a['status']) . '</strong></p>', 'info'));
}

$nachname = decrypt($a['nachname_enc']);
$ok       = ($action === 'genehmigt');
$farbe    = $ok ? '#1e7e34' : '#c0392b';
$label    = $ok ? 'intern genehmigen' : 'ablehnen';
$label_pp = $ok ? 'Intern genehmigt'  : 'Abgelehnt';
$icon     = $ok ? '✓' : '✕';

// ── Freistellungssymbol (z.B. V4/FS bei LH): wird bewusst NICHT mehr
//    automatisch aus finance_preise übernommen, sondern hier vom Büro/
//    Tarifreferenten bei der internen Genehmigung explizit bestätigt -
//    Grund: welches Symbol korrekt ist, war zuvor nicht immer eindeutig.
//    Nur relevant bei action=genehmigt. Es werden ALLE für die Airline
//    hinterlegten Symbole betrachtet (nicht nur die zur Kategorie des
//    Antrags passenden) - gibt es dort nur EIN mögliches Symbol, wird
//    kein Auswahlfeld nötig (automatisch übernommen); gibt es MEHRERE,
//    muss explizit ausgewählt werden.
$symbol_optionen = [];
if ($ok) {
    $sym_stmt = db()->prepare(
        "SELECT DISTINCT freistellungscode FROM finance_preise
         WHERE airline = ?
           AND freistellungscode IS NOT NULL AND freistellungscode <> ''
         ORDER BY freistellungscode"
    );
    $sym_stmt->execute([$a['airline']]);
    $symbol_optionen = array_column($sym_stmt->fetchAll(), 'freistellungscode');
}
$symbol_auswahl_noetig = $ok && count($symbol_optionen) > 1;

// Tatsächlicher DB-Status: Eine Genehmigung per Token ist immer nur
// eine INTERNE Genehmigung (Tarifreferent bzw. Büro) – die endgültige
// Freigabe erfolgt erst später durch den Arbeitgeber. Deshalb wird
// hier NIE direkt 'genehmigt' gesetzt, sondern 'freigabe_buero'.
// Eine Ablehnung ist dagegen bereits final.
$db_status = $ok ? 'freigabe_buero' : 'abgelehnt';

// ── Empfänger der Freigabe-Anfrage-Mail ermitteln ─────────────
// Wurde die Mail an einen NAMENTLICH bekannten Tarifreferenten
// geschickt, ist "wer entscheidet" bereits eindeutig. Ging sie
// dagegen an die allgemeine Büro-Adresse ("Typ:buero" - z.B. bei
// TK-Sitzungen oder als Fallback ohne hinterlegten Referenten),
// kann JEDE Person mit Zugriff auf dieses Postfach den Link öffnen -
// "Büro" allein identifiziert dann niemanden. In diesem Fall muss die
// freigebende Person ihren Namen selbst eintragen (Pflichtfeld).
$empf_stmt0 = db()->prepare(
    "SELECT details FROM audit_log
     WHERE antrag_id = ? AND aktion = 'freigabe_mail_gesendet'
     ORDER BY erstellt_am DESC LIMIT 1"
);
$empf_stmt0->execute([(int)$a['id']]);
$empf_row0 = $empf_stmt0->fetch();
$empf_details0    = $empf_row0 ? (string)$empf_row0['details'] : '';
$ist_buero_empfaenger = (bool)preg_match('/\(Typ:buero\)/u', $empf_details0);
$empfaenger_label0 = $empf_row0
    ? trim(preg_replace('/^Empfänger:\s*/u', '', preg_replace('/\s*\(Typ:\w+\)\s*$/u', '', $empf_details0)))
    : null;

// ── POST: Entscheidung ausführen ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_token  = trim($_POST['token']  ?? '');
    $post_action = trim($_POST['action'] ?? '');

    if ($post_token !== $token || $post_action !== $action) {
        die(_seite('Ungültige Anfrage', '<p>Sicherheitsprüfung fehlgeschlagen.</p>', 'fehler'));
    }

    // Ablehnungsgrund (nur bei Ablehnung, Pflichtfeld)
    $ablehnungsgrund = '';
    if (!$ok) {
        $ablehnungsgrund = trim($_POST['ablehnungsgrund'] ?? '');
        if ($ablehnungsgrund === '') {
            die(_seite('Grund fehlt', '<p>Bitte gehen Sie zurück und geben Sie einen Ablehnungsgrund ein. Ohne Begründung kann die Ablehnung nicht gespeichert werden.</p>', 'fehler'));
        }
        if (mb_strlen($ablehnungsgrund) > 1000) {
            die(_seite('Eingabe zu lang', '<p>Der Ablehnungsgrund darf maximal 1000 Zeichen enthalten.</p>', 'fehler'));
        }
    }

    // Freistellungssymbol (nur bei Genehmigung relevant)
    $gewaehltes_symbol = null;
    if ($ok) {
        $per_tag_symbole = (array)($_POST['symbol'] ?? []);
        $globales_symbol = trim($_POST['freistellungscode'] ?? '');

        if (count($symbol_optionen) === 0) {
            // Keine Symbole konfiguriert – kein Symbol nötig
            $gewaehltes_symbol = null;
        } elseif (count($symbol_optionen) === 1) {
            // Genau ein Symbol → automatisch übernehmen, keine Auswahl nötig
            $gewaehltes_symbol = $symbol_optionen[0];
        } else {
            // Mehrere Symbole → Auswahl nötig
            // Prüfen ob mind. ein gültiges Symbol vorhanden (global oder per Tag)
            $gueltige = array_filter(
                array_merge([$globales_symbol], array_values($per_tag_symbole)),
                fn($s) => in_array($s, $symbol_optionen, true)
            );
            if (empty($gueltige)) {
                die(_seite('Symbol fehlt',
                    '<p>Bitte wählen Sie für die genehmigten Tage eines der gültigen ' .
                    'Freistellungssymbole aus.</p>', 'fehler'));
            }
            // Globales Symbol = erstes gültiges (für Fallback bei alten Links)
            $gewaehltes_symbol = reset($gueltige);
        }
    }

    // Wer hat über den Link entschieden? Bei einem namentlich bekannten
    // Tarifreferenten reicht der protokollierte Mail-Empfänger. Ging die
    // Mail dagegen an die allgemeine Büro-Adresse, ist "Büro" allein keine
    // eindeutige Identifizierung (jede Person mit Postfach-Zugriff könnte
    // klicken) - hier MUSS die freigebende Person ihren Namen eintragen.
    $freigeber_name = trim((string)($_POST['freigeber_name'] ?? ''));
    if ($ist_buero_empfaenger) {
        if ($freigeber_name === '') {
            die(_seite('Name fehlt', '<p>Bitte gehen Sie zurück und tragen Sie Ihren Namen ein, bevor Sie den Antrag freigeben. Da diese Anfrage an die allgemeine Büro-Adresse ging, muss die freigebende Person eindeutig identifizierbar sein.</p>', 'fehler'));
        }
        if (mb_strlen($freigeber_name) > 120) {
            die(_seite('Ungültige Eingabe', '<p>Der eingegebene Name ist zu lang.</p>', 'fehler'));
        }
        // Diese Seite erfordert KEINEN Login - das Namensfeld kommt also von
        // einer nicht authentifizierten Quelle und landet unverändert im
        // Büro-Panel (entschieden_von / Audit-Log). Auf plausible
        // Namenszeichen beschränken, um gespeicherte Skript-Inhalte
        // (Stored-XSS) von vornherein auszuschließen statt sich auf
        // Escaping an jeder späteren Anzeigestelle zu verlassen.
        if (!preg_match('/^[\p{L}\p{M} .\'\-]+$/u', $freigeber_name)) {
            die(_seite('Ungültige Eingabe', '<p>Bitte geben Sie einen gültigen Namen ein (nur Buchstaben, Leerzeichen, Punkt, Bindestrich, Apostroph).</p>', 'fehler'));
        }
    }

    $empfaenger_label = $empfaenger_label0;
    $entschieden_von_text = $ist_buero_empfaenger
        ? ($freigeber_name !== ''
            ? "Freigabe per E-Mail-Link – Büro, freigegeben von: {$freigeber_name}"
            : "Freigabe per E-Mail-Link (Büro" . ($empfaenger_label ? " <{$empfaenger_label}>" : '') . ')')
        : ($empfaenger_label
            ? "Freigabe per E-Mail-Link (Empfänger: {$empfaenger_label})"
            : 'Freigabe per E-Mail-Link (Empfänger unbekannt)');

    // Aktive Tage des Antrags laden
    $alle_tage_stmt = db()->prepare(
        "SELECT id AS tag_id, tag FROM antrag_tage
          WHERE antrag_id=? AND status != 'storno_buero' ORDER BY tag"
    );
    $alle_tage_stmt->execute([(int)$a['id']]);
    $alle_tage = $alle_tage_stmt->fetchAll();

    if (!$ok) {
        // ── Ablehnung: gesamter Antrag, kein Split ────────────────
        if ($gewaehltes_symbol !== null) {
            db()->prepare(
                "UPDATE antraege SET status=?, freistellungscode=?, ablehnungsgrund=?,
                 entschieden_am=NOW(), entschieden_von=? WHERE token=?"
            )->execute([$db_status, $gewaehltes_symbol, $ablehnungsgrund ?: null,
                        $entschieden_von_text, $token]);
        } else {
            db()->prepare(
                "UPDATE antraege SET status=?, ablehnungsgrund=?,
                 entschieden_am=NOW(), entschieden_von=? WHERE token=?"
            )->execute([$db_status, $ablehnungsgrund ?: null, $entschieden_von_text, $token]);
        }
        db()->prepare(
            "UPDATE antrag_tage SET status=? WHERE antrag_id=? AND status != 'storno_buero'"
        )->execute([$db_status, (int)$a['id']]);
        audit('entscheidung_abgelehnt', (int)$a['id'],
            'Via Token-Link - Empfänger: ' . ($empfaenger_label ?: 'unbekannt'));
        $vc_email = decrypt($a['vc_email_enc']);
        $tage_abgelehnt_mail = array_column($alle_tage, 'tag');
        mail_ergebnis($vc_email, $a['vorname'], $db_status, $ablehnungsgrund ?: null,
            $a['veranstaltung'], $gewaehltes_symbol, $tage_abgelehnt_mail);

    } else {
        // ── Genehmigung: Split nach (status, symbol) pro Tag ─────
        $alle_aktive_tags = array_column($alle_tage, 'tag');
        $tag_zu_id        = array_column($alle_tage, 'tag_id', 'tag');

        // Gültige Datum-Strings aus POST
        $tage_genehmigt_post = array_values(array_filter(
            (array)($_POST['tage_genehmigt'] ?? []),
            fn($t) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $t)
        ));
        // Fallback: wenn keine Checkboxen → alle genehmigen
        if (empty($tage_genehmigt_post)) {
            $tage_genehmigt_post = $alle_aktive_tags;
        }

        // Symbol pro Tag: POST['symbol']['YYYY-MM-DD'] oder globales Symbol
        $symbol_post = (array)($_POST['symbol'] ?? []);
        $global_symbol = $gewaehltes_symbol ?? ($a['freistellungscode'] ?: null);

        // Pro Tag: (status, symbol) bestimmen
        $tag_info = [];
        foreach ($alle_aktive_tags as $t) {
            $ist_genehmigt = in_array($t, $tage_genehmigt_post, true);
            $symbol = $ist_genehmigt
                ? (isset($symbol_post[$t]) && in_array($symbol_post[$t], $symbol_optionen, true)
                    ? $symbol_post[$t]
                    : $global_symbol)
                : null; // abgelehnte Tage haben kein Symbol
            $tag_info[$t] = [
                'status' => $ist_genehmigt ? 'freigabe_buero' : 'abgelehnt',
                'symbol' => $symbol,
            ];
        }

        // Gruppieren: konsekutive Tage mit gleichem (status, symbol) → ein Antrag
        sort($alle_aktive_tags);
        $gruppen = []; // [['tage'=>[], 'status'=>, 'symbol'=>], ...]
        foreach ($alle_aktive_tags as $t) {
            $info = $tag_info[$t];
            if (!empty($gruppen)) {
                $letzte = &$gruppen[count($gruppen)-1];
                $prev   = end($letzte['tage']);
                $prevDt = new DateTime($prev); $prevDt->modify('+1 day');
                if ($prevDt->format('Y-m-d') === $t
                    && $letzte['status'] === $info['status']
                    && $letzte['symbol'] === $info['symbol']) {
                    $letzte['tage'][] = $t;
                    continue;
                }
            }
            $gruppen[] = ['tage' => [$t], 'status' => $info['status'], 'symbol' => $info['symbol']];
        }

        $aid      = (int)$a['id'];
        $vc_email = decrypt($a['vc_email_enc']);

        // Erste Gruppe: Ursprungsantrag verwenden
        $erste = array_shift($gruppen);
        $ph_upd = implode(',', array_fill(0, count($erste['tage']), '?'));
        db()->prepare(
            "UPDATE antraege SET status=?, freistellungscode=?,
             zeitraum_von=?, zeitraum_bis=?, entschieden_am=NOW(), entschieden_von=? WHERE id=?"
        )->execute([
            $erste['status'], $erste['symbol'],
            min($erste['tage']), max($erste['tage']),
            $entschieden_von_text, $aid,
        ]);
        db()->prepare("UPDATE antrag_tage SET status=? WHERE tag IN ({$ph_upd}) AND antrag_id=?")
            ->execute([$erste['status'], ...$erste['tage'], $aid]);

        // Alle weiteren Tage aus Ursprungsantrag entfernen
        $weitere_tage = array_merge(...array_map(fn($g) => $g['tage'], $gruppen));
        if ($weitere_tage) {
            $ph_del = implode(',', array_fill(0, count($weitere_tage), '?'));
            $ids_del = array_map(fn($t) => $tag_zu_id[$t], $weitere_tage);
            db()->prepare("DELETE FROM antrag_tage WHERE id IN ({$ph_del})")->execute($ids_del);
        }
        audit('entscheidung_' . $erste['status'], $aid,
            'Via Token-Link (Split) – ' . count($erste['tage']) . ' Tage ' . $erste['status']);

        // Weitere Gruppen als neue Anträge
        $tage_zwischenbescheid = $erste['status'] === 'freigabe_buero' ? $erste['tage'] : [];
        $tage_abgelehnt_mail   = $erste['status'] === 'abgelehnt'     ? $erste['tage'] : [];

        foreach ($gruppen as $gruppe) {
            db()->prepare(
                "INSERT INTO antraege
                 (mitglied_id,vorname,nachname_enc,tk_id,vc_email_enc,
                  airline,position,flugzeugmuster,stationierung,veranstaltung,
                  zeitraum_von,zeitraum_bis,token,ereignis_id,freistellungscode,
                  status,entschieden_am,entschieden_von,ablehnungsgrund,notiz)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,?)"
            )->execute([
                $a['mitglied_id'],$a['vorname'],$a['nachname_enc'],
                $a['tk_id'],$a['vc_email_enc'],
                $a['airline'],$a['position'],$a['flugzeugmuster'],$a['stationierung'],
                $a['veranstaltung'],
                min($gruppe['tage']), max($gruppe['tage']),
                generate_token(), $a['ereignis_id'], $gruppe['symbol'],
                $gruppe['status'],
                $entschieden_von_text,
                $gruppe['status'] === 'abgelehnt' ? ($ablehnungsgrund ?: null) : null,
                "[Split aus #{$aid}: {$gruppe['status']}" .
                ($gruppe['symbol'] ? " Symbol:{$gruppe['symbol']}" : '') . "]",
            ]);
            $neuer = (int)db()->lastInsertId();
            $ins = db()->prepare(
                "INSERT INTO antrag_tage (antrag_id,ereignis_id,tag,status) VALUES (?,?,?,?)"
            );
            foreach ($gruppe['tage'] as $t) {
                $ins->execute([$neuer, $a['ereignis_id'], $t, $gruppe['status']]);
            }
            audit('entscheidung_' . $gruppe['status'], $neuer,
                "Split aus #{$aid}" . ($gruppe['symbol'] ? " Symbol:{$gruppe['symbol']}" : ''));

            if ($gruppe['status'] === 'freigabe_buero') {
                $tage_zwischenbescheid = array_merge($tage_zwischenbescheid, $gruppe['tage']);
            } else {
                $tage_abgelehnt_mail = array_merge($tage_abgelehnt_mail, $gruppe['tage']);
            }
        }

        // Mails versenden
        if ($tage_zwischenbescheid) {
            mail_zwischenbescheid($vc_email, $a['vorname'], $a['veranstaltung'],
                $tage_zwischenbescheid);
        }
        if ($tage_abgelehnt_mail) {
            mail_ergebnis($vc_email, $a['vorname'], 'abgelehnt',
                $ablehnungsgrund ?: 'Einzelne Tage wurden nicht genehmigt.',
                $a['veranstaltung'], null, $tage_abgelehnt_mail);
        }
    }

    // Weiterleitung zur Bestätigungsseite (kein Code nach dem Split)
    $hinweis_intern = $ok
        ? '<p>Die Entscheidung wurde gespeichert. Der Antragsteller wurde per E-Mail informiert.</p>'
        : '<p>Der Antrag wurde abgelehnt. Der Antragsteller wurde per E-Mail benachrichtigt.</p>';

    $inhalt = <<<HTML
<div style="text-align:center;margin-bottom:28px">
  <div style="width:72px;height:72px;border-radius:50%;background:{$farbe};color:#fff;font-size:34px;line-height:72px;margin:0 auto 16px">{$icon}</div>
  <div style="font-size:22px;font-weight:700;color:{$farbe}">{$label_pp}</div>
</div>
{$hinweis_intern}
<hr style="margin:20px 0;border:none;border-top:1px solid #e9ecef">
<p><strong>Antragsteller:</strong> {$a['vorname']} {$nachname}</p>
<p><strong>Airline:</strong> {$a['airline']}</p>
HTML;
    die(_seite('Entscheidung erfasst', $inhalt, $ok ? 'erfolg' : 'info'));
}

// ── GET: Bestätigungsseite ────────────────────────────────────
// Tage aus antrag_tage laden; nicht-stornierte für Anzeige
$tage_stmt = db()->prepare(
    "SELECT tag, status FROM antrag_tage WHERE antrag_id=? ORDER BY tag"
);
$tage_stmt->execute([$a['id']]);
$tage = $tage_stmt->fetchAll();

// Zeitraum aus Tagen berechnen (aggregiert von–bis)
$aktive_tage     = array_filter($tage, fn($t) => $t['status'] !== 'storno_buero');
$stornierte_tage = array_filter($tage, fn($t) => $t['status'] === 'storno_buero');

$veranstaltung_label = htmlspecialchars($a['veranstaltung']);
if ($a['veranstaltung'] === 'sonstige' && !empty($a['ereignis_bezeichnung'])) {
    $veranstaltung_label .= ' (' . htmlspecialchars($a['ereignis_bezeichnung']) . ')';
}

$termine_html = '';
if (!empty($aktive_tage)) {
    if ($ok) {
        // Genehmigung: Tages-Checkboxen – einzelne Tage können abgewählt = abgelehnt werden
        $rows = '';
        foreach ($aktive_tage as $t) {
            $tagFmt = date('d.m.Y', strtotime($t['tag']));
            $rows .= "<tr>
              <td style='padding:6px 10px'>
                <label style='display:flex;align-items:center;gap:10px;cursor:pointer'>
                  <input type='checkbox' name='tage_genehmigt[]' value='" . htmlspecialchars($t['tag']) . "' checked
                    style='width:16px;height:16px;cursor:pointer'>
                  <strong>{$tagFmt}</strong>
                </label>
              </td>
              <td style='padding:6px 10px;color:#555;font-size:13px'>{$veranstaltung_label}</td>
            </tr>";
        }
        // Stornierte Tage durchgestrichen
        foreach ($stornierte_tage as $t) {
            $tagFmt = date('d.m.Y', strtotime($t['tag']));
            $rows .= "<tr style='color:#aaa;text-decoration:line-through'>
              <td style='padding:6px 10px'>{$tagFmt}</td>
              <td style='padding:6px 10px;font-size:13px'>Storno Büro</td>
            </tr>";
        }
        $termine_html = "
        <p style='font-size:13px;color:#555;background:#f0f9ff;border:1px solid #bae6fd;border-radius:7px;padding:10px 14px;margin-bottom:10px'>
          ✓ = Haken gesetzt → Tag wird <strong>genehmigt</strong>.<br>
          ✗ = Haken entfernen → Tag wird <strong>abgelehnt</strong> (Split in eigenen Antrag).
        </p>
        <table style='width:100%;border-collapse:collapse;font-size:14px'>
          <thead><tr style='background:#f8f9fb'>
            <th style='padding:7px 10px;text-align:left;border-bottom:1px solid #e9ecef'>Tag</th>
            <th style='padding:7px 10px;text-align:left;border-bottom:1px solid #e9ecef'>Veranstaltung</th>
          </tr></thead>
          <tbody>{$rows}</tbody>
        </table>";
    } else {
        // Ablehnung: keine Checkboxen, nur Übersicht
        $ranges = _build_ranges(array_column($aktive_tage, 'tag'));
        $rows = '';
        foreach ($ranges as $r) {
            $von = date('d.m.Y', strtotime($r['von']));
            $bis = date('d.m.Y', strtotime($r['bis']));
            $zeitraum = ($r['von'] === $r['bis']) ? $von : "{$von} – {$bis}";
            $rows .= "<tr><td style='padding:7px 10px'>{$zeitraum}</td>
                          <td style='padding:7px 10px'>{$veranstaltung_label}</td></tr>";
        }
        foreach (($stornierte_tage ?: []) as $t) {
            $tagFmt = date('d.m.Y', strtotime($t['tag']));
            $rows .= "<tr style='color:#999;text-decoration:line-through'>
                        <td style='padding:7px 10px'>{$tagFmt}</td>
                        <td style='padding:7px 10px'>Storno Büro</td></tr>";
        }
        $termine_html = "
        <table style='width:100%;border-collapse:collapse;font-size:14px;margin-top:4px'>
          <thead><tr style='background:#f8f9fb'>
            <th style='padding:7px 10px;text-align:left;border-bottom:1px solid #e9ecef'>Zeitraum</th>
            <th style='padding:7px 10px;text-align:left;border-bottom:1px solid #e9ecef'>Veranstaltung</th>
          </tr></thead>
          <tbody>{$rows}</tbody>
        </table>";
    }
} else {
    $von = date('d.m.Y', strtotime($a['zeitraum_von'] ?? ''));
    $bis = date('d.m.Y', strtotime($a['zeitraum_bis'] ?? ''));
    $termine_html = "<p>{$von} – {$bis} · {$veranstaltung_label}</p>";
}

$btn_style = "display:inline-block;padding:14px 32px;border-radius:8px;font-size:16px;font-weight:700;color:#fff;background:{$farbe};border:none;cursor:pointer;width:100%;margin-top:8px";

// Hinweis wenn Teile bereits storniert
$storno_hinweis = '';
if (!empty($stornierte_tage)) {
    $n = count($stornierte_tage);
    $storno_hinweis = <<<HTML
<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#856404">
  <strong>Hinweis:</strong> {$n} Tag(e) dieses Antrags wurden durch das Büro storniert
  und sind in der Übersicht durchgestrichen. Ihre Entscheidung gilt nur für die verbleibenden aktiven Tage.
</div>
HTML;
}

$symbol_feld_html = ''; // wird jetzt pro Tag in $termine_html eingebettet

// Termine mit Checkbox + Symbol pro Tag
$termine_html = '';
if (!empty($aktive_tage)) {
    if ($ok) {
        $rows = '';
        foreach ($aktive_tage as $t) {
            $tagFmt = date('d.m.Y', strtotime($t['tag']));
            $tagVal = htmlspecialchars($t['tag']);

            // Symbol-Auswahl pro Tag (nur wenn mehrere Symbole verfügbar)
            $symbol_html = '';
            if ($symbol_auswahl_noetig) {
                $opts = '';
                foreach ($symbol_optionen as $code) {
                    $cEsc = htmlspecialchars($code);
                    // Erstes Symbol vorauswählen
                    $checked = ($code === $symbol_optionen[0]) ? 'checked' : '';
                    $opts .= "<label style='display:inline-flex;align-items:center;gap:4px;margin-right:10px;font-size:12px;cursor:pointer'>
                        <input type='radio' name='symbol[{$tagVal}]' value='{$cEsc}' {$checked} style='accent-color:{$farbe}'>
                        <strong>{$cEsc}</strong>
                      </label>";
                }
                $symbol_html = "<div style='font-size:11px;color:#856404;margin-top:4px'>{$opts}</div>";
            } elseif (count($symbol_optionen) === 1) {
                $cEsc = htmlspecialchars($symbol_optionen[0]);
                $symbol_html = "<input type='hidden' name='symbol[{$tagVal}]' value='{$cEsc}'>";
            }

            $rows .= "<tr>
              <td style='padding:6px 10px;vertical-align:top'>
                <label style='display:flex;align-items:flex-start;gap:10px;cursor:pointer'>
                  <input type='checkbox' name='tage_genehmigt[]' value='{$tagVal}' checked
                    style='width:16px;height:16px;cursor:pointer;margin-top:2px'
                    onchange=\"document.getElementById('symbol-{$tagVal}').style.display=this.checked?'block':'none'\">
                  <div>
                    <strong>{$tagFmt}</strong>
                    <div id='symbol-{$tagVal}' style='display:block'>{$symbol_html}</div>
                  </div>
                </label>
              </td>
              <td style='padding:6px 10px;color:#555;font-size:13px;vertical-align:top'>{$veranstaltung_label}</td>
            </tr>";
        }
        foreach ($stornierte_tage as $t) {
            $tagFmt = date('d.m.Y', strtotime($t['tag']));
            $rows .= "<tr style='color:#aaa;text-decoration:line-through'>
              <td style='padding:6px 10px'>{$tagFmt}</td>
              <td style='padding:6px 10px;font-size:13px'>Storno Büro</td>
            </tr>";
        }

        $hint = $symbol_auswahl_noetig
            ? "Für jeden <strong>genehmigten</strong> Tag bitte Freistellungssymbol wählen. Nicht angehakte Tage werden abgelehnt."
            : "Nicht angehakte Tage werden abgelehnt.";
        $termine_html = "
        <p style='font-size:13px;color:#555;background:#f0f9ff;border:1px solid #bae6fd;border-radius:7px;padding:10px 14px;margin-bottom:10px'>
          {$hint}
        </p>
        <table style='width:100%;border-collapse:collapse;font-size:14px'>
          <thead><tr style='background:#f8f9fb'>
            <th style='padding:7px 10px;text-align:left;border-bottom:1px solid #e9ecef'>Tag</th>
            <th style='padding:7px 10px;text-align:left;border-bottom:1px solid #e9ecef'>Veranstaltung</th>
          </tr></thead>
          <tbody>{$rows}</tbody>
        </table>";
    } else {
        $ranges = _build_ranges(array_column($aktive_tage, 'tag'));
        $rows = '';
        foreach ($ranges as $r) {
            $von = date('d.m.Y', strtotime($r['von']));
            $bis = date('d.m.Y', strtotime($r['bis']));
            $zeitraum = ($r['von'] === $r['bis']) ? $von : "{$von} – {$bis}";
            $rows .= "<tr><td style='padding:7px 10px'>{$zeitraum}</td>
                          <td style='padding:7px 10px'>{$veranstaltung_label}</td></tr>";
        }
        foreach ($stornierte_tage as $t) {
            $tagFmt = date('d.m.Y', strtotime($t['tag']));
            $rows .= "<tr style='color:#999;text-decoration:line-through'>
                        <td style='padding:7px 10px'>{$tagFmt}</td>
                        <td style='padding:7px 10px'>Storno Büro</td></tr>";
        }
        $termine_html = "
        <table style='width:100%;border-collapse:collapse;font-size:14px;margin-top:4px'>
          <thead><tr style='background:#f8f9fb'>
            <th style='padding:7px 10px;text-align:left;border-bottom:1px solid #e9ecef'>Zeitraum</th>
            <th style='padding:7px 10px;text-align:left;border-bottom:1px solid #e9ecef'>Veranstaltung</th>
          </tr></thead>
          <tbody>{$rows}</tbody>
        </table>";
    }
} else {
    $von = date('d.m.Y', strtotime($a['zeitraum_von'] ?? ''));
    $bis = date('d.m.Y', strtotime($a['zeitraum_bis'] ?? ''));
    $termine_html = "<p>{$von} – {$bis} · {$veranstaltung_label}</p>";
}

// Ging die Freigabe-Anfrage an die allgemeine Büro-Adresse (statt an
// einen namentlich bekannten Tarifreferenten), ist "Büro" allein keine
// eindeutige Identifizierung - hier muss die entscheidende Person ihren
// Namen selbst eintragen (serverseitig als Pflichtfeld erzwungen, siehe
// POST-Block oben).
$name_feld_html = '';
if ($ist_buero_empfaenger) {
    $name_feld_html = <<<HTML
<div style="background:#eef2ff;border:1px solid #c7d2fe;border-radius:8px;padding:14px 16px;margin-bottom:16px">
  <label style="display:block;font-size:13px;font-weight:700;color:#3730a3;margin-bottom:8px">
    Diese Anfrage ging an die allgemeine Büro-Adresse. Bitte tragen Sie Ihren Namen ein, damit die Entscheidung eindeutig zugeordnet werden kann (Pflichtfeld):
  </label>
  <input type="text" name="freigeber_name" required maxlength="120" placeholder="Vor- und Nachname"
    style="width:100%;box-sizing:border-box;padding:10px 12px;border:1.5px solid #c7d2fe;border-radius:7px;font-size:15px;font-family:inherit">
</div>
HTML;
}

$ablehnungsgrund_feld_html = '';
if (!$ok) {
    // Vollständige Ablehnung: Pflichtfeld
    $ablehnungsgrund_feld_html = <<<HTML
<div style="background:#fdf2f2;border:1.5px solid #f5c6cb;border-radius:8px;padding:14px 16px;margin-bottom:16px">
  <label style="display:block;font-size:13px;font-weight:700;color:#8b1c1c;margin-bottom:8px">
    Ablehnungsgrund <span style="font-weight:400;font-size:12px">(Pflichtfeld – wird dem Antragsteller per E-Mail mitgeteilt)</span>
  </label>
  <textarea name="ablehnungsgrund" required maxlength="1000" rows="4"
    placeholder="Bitte begründen Sie die Ablehnung …"
    style="width:100%;box-sizing:border-box;padding:10px 12px;border:1.5px solid #f5c6cb;border-radius:7px;font-size:14px;font-family:inherit;resize:vertical;line-height:1.5"></textarea>
  <div style="font-size:11px;color:#9b6060;margin-top:4px;text-align:right">max. 1000 Zeichen</div>
</div>
HTML;
} elseif (count($aktive_tage) > 1) {
    // Genehmigung mit mehreren Tagen: optionales Feld für abgelehnte Tage
    $ablehnungsgrund_feld_html = <<<HTML
<div style="background:#fdf2f2;border:1px solid #f5c6cb;border-radius:8px;padding:12px 16px;margin-bottom:16px">
  <label style="display:block;font-size:13px;font-weight:600;color:#8b1c1c;margin-bottom:6px">
    Begründung für abgelehnte Tage <span style="font-weight:400;font-size:12px">(optional – nur wenn einzelne Tage abgelehnt werden)</span>
  </label>
  <textarea name="ablehnungsgrund" maxlength="1000" rows="2"
    placeholder="Begründung für nicht genehmigte Tage …"
    style="width:100%;box-sizing:border-box;padding:8px 12px;border:1px solid #f5c6cb;border-radius:7px;font-size:13px;font-family:inherit;resize:vertical"></textarea>
</div>
HTML;
}

$inhalt = <<<HTML
<div style="text-align:center;margin-bottom:24px">
  <div style="width:64px;height:64px;border-radius:50%;background:{$farbe};color:#fff;font-size:28px;line-height:64px;margin:0 auto 12px">{$icon}</div>
  <div style="font-size:20px;font-weight:700;color:{$farbe}">Antrag {$label}</div>
  <p style="color:#666;font-size:14px;margin-top:6px">Bitte prüfen Sie die Angaben und bestätigen Sie Ihre Entscheidung.</p>
</div>
<hr style="margin:0 0 20px;border:none;border-top:1px solid #e9ecef">
{$storno_hinweis}
<p><strong>Antragsteller:</strong> {$a['vorname']} {$nachname}</p>
<p><strong>Tarifkommission:</strong> {$a['tk_name']}</p>
<p><strong>Airline:</strong> {$a['airline']}</p>
<p><strong>Position:</strong> {$a['position']}</p>
<p><strong>Flugzeugmuster:</strong> {$a['flugzeugmuster']}</p>
<p><strong>Termine:</strong></p>
<form method="POST" action="entscheidung.php?token={$token}&action={$action}">
  <input type="hidden" name="token"  value="{$token}">
  <input type="hidden" name="action" value="{$action}">
  {$termine_html}
  <hr style="margin:20px 0;border:none;border-top:1px solid #e9ecef">
  {$name_feld_html}
  {$ablehnungsgrund_feld_html}
  {$symbol_feld_html}
  <button type="submit" style="{$btn_style}">{$icon} Jetzt {$label}</button>
</form>
<p style="text-align:center;margin-top:12px;font-size:12px;color:#aaa">
  Dieser Link ist nur für Sie bestimmt und einmalig verwendbar.
</p>
HTML;

echo _seite("Entscheidung: {$label}", $inhalt, 'info');

// ── Hilfsfunktion: Einzeldaten zu Ranges zusammenfassen ───────
function _build_ranges(array $dates): array {
    if (empty($dates)) return [];
    sort($dates);
    $ranges  = [];
    $von     = $dates[0];
    $prev    = $dates[0];
    for ($i = 1; $i < count($dates); $i++) {
        $expected = date('Y-m-d', strtotime($prev . ' +1 day'));
        if ($dates[$i] === $expected) {
            $prev = $dates[$i];
        } else {
            $ranges[] = ['von' => $von, 'bis' => $prev];
            $von      = $dates[$i];
            $prev     = $dates[$i];
        }
    }
    $ranges[] = ['von' => $von, 'bis' => $prev];
    return $ranges;
}

// ── Minimal-HTML ──────────────────────────────────────────────
function _seite(string $titel, string $inhalt, string $typ = 'info'): string {
    $farben = ['erfolg' => '#1e7e34', 'fehler' => '#c0392b', 'info' => '#0f2744'];
    $f = $farben[$typ] ?? '#333';
    return <<<HTML
<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Freistellungssystem – {$titel}</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
body{margin:0;padding:32px 16px;background:#f0f2f5;font-family:'IBM Plex Sans',Arial,sans-serif;
     display:flex;align-items:center;justify-content:center;min-height:100vh;box-sizing:border-box}
.card{background:#fff;max-width:540px;width:100%;border-radius:12px;
      box-shadow:0 4px 24px rgba(0,0,0,.1);overflow:hidden}
.card-head{background:#0f2744;color:#fff;padding:24px 32px;border-bottom:3px solid #c8a84b}
.card-head h1{margin:0;font-size:18px}
.card-body{padding:32px}
.card-body p{color:#333;line-height:1.65;margin:0 0 10px;font-size:15px}
.card-body strong{color:#1c2b3a}
.back{display:block;text-align:center;margin-top:20px;font-size:13px}
.back a{color:#6b7c93;text-decoration:none}
.back a:hover{color:#0f2744}
</style></head><body>
<div>
<div class="card">
  <div class="card-head"><h1>{$titel}</h1></div>
  <div class="card-body">{$inhalt}</div>
</div>
<div class="back"><a href="../">← Zum Antragsformular</a></div>
</div>
</body></html>
HTML;
}
