<?php
// ============================================================
// _backend/ag_doc_auswertung.php
// Wertet ein von der Airline ausgefülltes Freistellungs-Formular
// (DOCX, siehe ag_antrag_doc.php) aus und setzt automatisiert den
// Status der betroffenen Anträge:
//   "gewährt" angehakt        -> status = 'genehmigt'
//   "nicht gewährt" angehakt  -> status = 'abgelehnt_ag'
//
// Zweistufig wie die anderen Uploads/Sends in diesem System:
// 1) ag_doc_datei_analysieren()  – parst die Datei, baut eine
//    Vorschau (OHNE etwas in der DB zu ändern), legt sie unter
//    einem Token ab.
// 2) ag_doc_auswertung_uebernehmen() – wendet die zuvor geprüfte
//    Vorschau (per Token) tatsächlich auf die DB an.
// ============================================================
require_once __DIR__ . '/docx_reader.php';

function ag_doc_auswertung_verzeichnis(): string {
    $dir = __DIR__ . '/../_tmp/ag_doc_auswertung';
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
        file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    // Alte Auswertungen (>6h) aufräumen.
    foreach (glob($dir . '/*.json') ?: [] as $f) {
        if (filemtime($f) < time() - 6 * 3600) @unlink($f);
    }
    return $dir;
}

/**
 * Schritt 1: Datei parsen, Vorschau bauen (KEINE DB-Änderung).
 *
 * @return array{token:string, ergebnisse:array, anzahl_genehmigt:int,
 *               anzahl_abgelehnt:int, anzahl_uebersprungen:int}
 */
function ag_doc_datei_analysieren(PDO $pdo, string $hochgeladenePfad): array {
    $checkboxen   = docx_checkboxen_lesen($hochgeladenePfad);
    $proAntrag    = docx_checkboxen_zu_antraegen($checkboxen);
    $erwarteteTags = docx_erwartete_checkbox_tags($hochgeladenePfad);

    if (!$proAntrag && !$erwarteteTags) {
        throw new RuntimeException(
            'In der Datei wurden keine (markierten) Freistellungs-Formularfelder gefunden. ' .
            'Bitte sicherstellen, dass es sich um ein mit diesem System erzeugtes Formular handelt.'
        );
    }

    // Cross-Check: welche der beiden Checkboxen ("gewaehrt"/"nicht_gewaehrt")
    // waren laut Marker im Dokument enthalten, tauchen aber NICHT (mehr) in
    // den tatsächlich gefundenen Checkboxen auf - z.B. weil das Formular-
    // Feld entfernt/"zu statisch konvertiert" wurde. Das NICHT
    // stillschweigend wie "nicht angehakt" behandeln, sondern pro Antrag
    // vermerken, dass mindestens eine Checkbox nicht lesbar war.
    $gefundeneTags = [];
    foreach ($checkboxen as $cb) $gefundeneTags[$cb['tag']] = true;

    $nichtLesbareAntraege = [];
    foreach ($erwarteteTags as $tag) {
        if (isset($gefundeneTags[$tag])) continue;
        $t = docx_checkbox_tag_zerlegen($tag);
        if (!$t) continue;
        $nichtLesbareAntraege[$t['antrag_id']] = true;
    }
    foreach (array_keys($nichtLesbareAntraege) as $eid) {
        $proAntrag[$eid] ??= ['gewaehrt' => false, 'nicht_gewaehrt' => false];
        $proAntrag[$eid]['_nicht_lesbar'] = true;
    }

    // Reihenfolge: exakt wie im Formular. Der Marker (falls vorhanden)
    // enthält die Antrags-IDs bereits in der Reihenfolge, in der sie beim
    // Erzeugen des Dokuments angeordnet wurden - das ist zuverlässiger als
    // die Reihenfolge der tatsächlich gefundenen Checkboxen (die bei
    // teilweise entfernten Feldern Lücken haben kann).
    $reihenfolge = [];
    foreach ($erwarteteTags as $tag) {
        $t = docx_checkbox_tag_zerlegen($tag);
        if (!$t) continue;
        $reihenfolge[$t['antrag_id']] = true; // Set-artig, Reihenfolge = erstes Auftreten
    }
    if (!$reihenfolge) {
        // Kein Marker (z.B. sehr altes Dokument) - Fallback auf die
        // Reihenfolge der gefundenen Checkboxen selbst.
        foreach ($proAntrag as $antragId => $cb) $reihenfolge[$antragId] = true;
    }

    $ergebnisse = [];
    $anzahlGenehmigt = 0;
    $anzahlAbgelehnt = 0;
    $anzahlUebersprungen = 0;

    foreach (array_keys($reihenfolge) as $antragId) {
        $cb = $proAntrag[$antragId] ?? ['gewaehrt' => false, 'nicht_gewaehrt' => false, '_nicht_lesbar' => true];
        $stmt = $pdo->prepare(
            "SELECT a.id, a.vorname, a.nachname_enc, a.vc_email_enc, a.airline, a.veranstaltung,
                    a.freistellungscode, a.zeitraum_von, a.zeitraum_bis, a.status
             FROM antraege a WHERE a.id = ? LIMIT 1"
        );
        $stmt->execute([$antragId]);
        $a = $stmt->fetch(PDO::FETCH_ASSOC);

        $eintrag = [
            'antrag_id'       => $antragId,
            'gewaehrt'        => $cb['gewaehrt'],
            'nicht_gewaehrt'  => $cb['nicht_gewaehrt'],
            'neuer_status'    => null,
            'hinweis'         => null,
        ];

        if (!$a) {
            $eintrag['hinweis'] = 'Antrag-ID nicht gefunden (evtl. gelöscht) – wird übersprungen.';
            $anzahlUebersprungen++;
            $ergebnisse[] = $eintrag;
            continue;
        }

        $eintrag['name']            = decrypt($a['nachname_enc']) . ', ' . $a['vorname'];
        $eintrag['vorname']         = $a['vorname'];
        $eintrag['vc_email_enc']    = $a['vc_email_enc'];
        $eintrag['airline']         = $a['airline'];
        $eintrag['veranstaltung']   = $a['veranstaltung'];
        $eintrag['freistellungscode'] = $a['freistellungscode'];
        $eintrag['zeitraum_von']    = $a['zeitraum_von'];
        $eintrag['zeitraum_bis']    = $a['zeitraum_bis'];
        $eintrag['aktueller_status']= $a['status'];

        if ($a['status'] !== 'beantragt_ag') {
            $eintrag['hinweis'] = 'Antrag ist nicht mehr im Status „Beantragt bei AG" (aktuell: „' .
                status_label($a['status']) . '") – wird übersprungen, um andere Vorgänge nicht zu überschreiben.';
            $anzahlUebersprungen++;
        } elseif (!empty($cb['_nicht_lesbar'])) {
            $eintrag['hinweis'] = '⚠ Checkbox(en) für diesen Antrag konnten nicht gelesen werden ' .
                '(Formularfeld evtl. entfernt/"zu statisch konvertiert" oder Dokument anderweitig ' .
                'bearbeitet) – bitte Entscheidung manuell im Tab „AG-Rückmeldung" erfassen.';
            $anzahlUebersprungen++;
        } else {
            // Eine Formularzeile je Tag -> Tage des Antrags zusammenfassen.
            $gesamt    = (int)($cb['tage_gesamt']    ?? 1);
            $gew       = (int)($cb['tage_gewaehrt']  ?? ($cb['gewaehrt'] && !$cb['nicht_gewaehrt'] ? 1 : 0));
            $abg       = (int)($cb['tage_abgelehnt'] ?? (!$cb['gewaehrt'] && $cb['nicht_gewaehrt'] ? 1 : 0));
            $beide     = (int)($cb['tage_beide']     ?? ($cb['gewaehrt'] && $cb['nicht_gewaehrt'] ? 1 : 0));
            $offen     = $gesamt - $gew - $abg - $beide;

            if ($beide > 0) {
                $eintrag['hinweis'] = ($gesamt > 1 ? "Bei {$beide} von {$gesamt} Tagen" : 'Bei diesem Tag') .
                    ' beide Kästchen angehakt – uneindeutig, bitte manuell prüfen.';
                $anzahlUebersprungen++;
            } elseif ($gew > 0 && $abg > 0) {
                $eintrag['hinweis'] = "Teilweise gewährt ({$gew} Tag(e) gewährt, {$abg} nicht gewährt" .
                    ($offen > 0 ? ", {$offen} ohne Angabe" : '') . ') – bitte manuell erfassen (ggf. Antrag aufteilen).';
                $anzahlUebersprungen++;
            } elseif ($offen > 0 && ($gew > 0 || $abg > 0)) {
                $eintrag['hinweis'] = "Nicht alle Tage angehakt ({$offen} von {$gesamt} ohne Angabe) – bitte manuell prüfen.";
                $anzahlUebersprungen++;
            } elseif ($gew === $gesamt && $gesamt > 0) {
                $eintrag['neuer_status'] = 'genehmigt';
                $anzahlGenehmigt++;
            } elseif ($abg === $gesamt && $gesamt > 0) {
                $eintrag['neuer_status'] = 'abgelehnt_ag';
                $anzahlAbgelehnt++;
            } else {
                $eintrag['hinweis'] = 'Keines der Kästchen angehakt – keine Änderung.';
                $anzahlUebersprungen++;
            }
        }

        $ergebnisse[] = $eintrag;
    }

    $token = bin2hex(random_bytes(16));
    file_put_contents(
        ag_doc_auswertung_verzeichnis() . '/' . $token . '.json',
        json_encode($ergebnisse, JSON_UNESCAPED_UNICODE)
    );

    return [
        'token'                => $token,
        'ergebnisse'           => $ergebnisse,
        'anzahl_genehmigt'     => $anzahlGenehmigt,
        'anzahl_abgelehnt'     => $anzahlAbgelehnt,
        'anzahl_uebersprungen' => $anzahlUebersprungen,
    ];
}

/**
 * Schritt 2: Die unter $token gespeicherte, bereits geprüfte Vorschau
 * tatsächlich auf die Datenbank anwenden.
 *
 * $manuelleEntscheidungen: array<int antrag_id, string status> - manuelle
 *   Nachpflege durch das Büro für Zeilen, die automatisch NICHT eindeutig
 *   erkannt wurden (neuer_status war null). Wird NUR für genau solche
 *   Zeilen berücksichtigt - eine bereits automatisch erkannte Zeile lässt
 *   sich damit nicht überschreiben.
 *
 * @return array{angewendet:int, uebersprungen:int, manuell_angewendet:int}
 */
function ag_doc_auswertung_uebernehmen(PDO $pdo, string $token, string $panelUser, array $manuelleEntscheidungen = []): array {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        throw new RuntimeException('Ungültiger Token.');
    }
    $pfad = ag_doc_auswertung_verzeichnis() . '/' . $token . '.json';
    if (!is_file($pfad)) {
        throw new RuntimeException('Auswertung nicht gefunden oder abgelaufen – bitte Datei erneut hochladen.');
    }
    $ergebnisse = json_decode(file_get_contents($pfad), true) ?: [];

    $erlaubteStati = ['genehmigt', 'abgelehnt_ag'];
    $angewendet = 0;
    $manuellAngewendet = 0;
    $uebersprungen = 0;

    foreach ($ergebnisse as $e) {
        $manuell = false;
        $neuerStatus = $e['neuer_status'] ?? null;

        // Manuelle Nachpflege NUR für Zeilen zulassen, die automatisch
        // NICHT eindeutig erkannt wurden (neuer_status war null).
        if (!$neuerStatus && isset($manuelleEntscheidungen[$e['antrag_id']])) {
            $kandidat = $manuelleEntscheidungen[$e['antrag_id']];
            if (in_array($kandidat, $erlaubteStati, true)) {
                $neuerStatus = $kandidat;
                $manuell = true;
            }
        }

        if (!$neuerStatus) { $uebersprungen++; continue; }

        // Status defensiv erneut auf 'beantragt_ag' prüfen (könnte sich seit
        // der Analyse geändert haben) - WHERE-Klausel schützt davor, einen
        // zwischenzeitlich anders bearbeiteten Antrag zu überschreiben.
        $stmt = $pdo->prepare(
            "UPDATE antraege
             SET status = ?, entschieden_am = NOW(), entschieden_von = ?
             WHERE id = ? AND status = 'beantragt_ag'"
        );
        $herkunft = $manuell ? 'AG-Rückmeldung (DOC-Upload, manuell nachgepflegt)' : 'AG-Rückmeldung (DOC-Upload)';
        $stmt->execute([$neuerStatus, "{$herkunft} durch {$panelUser}", $e['antrag_id']]);

        if ($stmt->rowCount() > 0) {
            $angewendet++;
            if ($manuell) $manuellAngewendet++;
            audit('ag_doc_auswertung', $e['antrag_id'],
                "Status auf '{$neuerStatus}' gesetzt via {$herkunft} durch {$panelUser}");

            // Dasselbe Ergebnis-Mail-Verhalten wie beim manuellen
            // "AG-Rückmeldung"-Weg (save_ag_rueckmeldung), damit das
            // Mitglied unabhängig vom Erfassungsweg informiert wird.
            try {
                $tage_stmt = $pdo->prepare(
                    "SELECT tag FROM antrag_tage WHERE antrag_id = ? AND status != 'storno_buero' ORDER BY tag"
                );
                $tage_stmt->execute([$e['antrag_id']]);
                $tage_mail = array_column($tage_stmt->fetchAll(PDO::FETCH_ASSOC), 'tag');

                $vc_email = decrypt($e['vc_email_enc']);
                $mail_notiz = $neuerStatus === 'abgelehnt_ag'
                    ? 'Der Arbeitgeber hat die Freistellung abgelehnt.'
                    : null;
                mail_ergebnis($vc_email, $e['vorname'], $neuerStatus, $mail_notiz,
                    $e['veranstaltung'], $e['freistellungscode'], $tage_mail);
            } catch (Throwable $mailEx) {
                error_log('ag_doc_auswertung_uebernehmen: Ergebnis-Mail fehlgeschlagen für Antrag ' .
                    $e['antrag_id'] . ': ' . $mailEx->getMessage());
                // Status-Änderung bleibt bestehen, auch wenn die Mail scheitert.
            }
        } else {
            $uebersprungen++;
        }
    }

    @unlink($pfad);

    return ['angewendet' => $angewendet, 'uebersprungen' => $uebersprungen, 'manuell_angewendet' => $manuellAngewendet];
}
