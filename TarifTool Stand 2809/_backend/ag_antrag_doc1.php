<?php
// ============================================================
// _backend/ag_antrag_doc.php
// Erzeugt das LH-Formular "Antrag auf {CODE}-Freistellung"
// (Vorlage: 0Vordruck_FS.docx) als DOCX - ein Dokument je
// Kalendermonat, Freistellungscode (FS/V4) und Stationierungsort.
//
// Wird zusätzlich zur Freistellungsliste (Excel) an die Mail
// angehängt, wenn für die Airline "DOC zusätzlich ausgeben" aktiv
// ist (siehe airline_einstellungen).
// ============================================================
require_once __DIR__ . '/docx_writer.php';

const AG_DOC_MONATSNAMEN = [
    1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
    5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
];

// Nur diese beiden Codes bekommen ein eigenes DOC (LH-Vorgabe).
const AG_DOC_CODES = ['FS', 'V4'];

/**
 * @return array<int, array{path:string, filename:string}>  eine Datei je
 *   (Monat, Code, Station)-Kombination mit tatsächlich vorhandenen Daten.
 *   Leeres Array, wenn 'kurzfristig' oder keine passenden Daten.
 */
function ag_antrag_doc_dateien_bauen(PDO $pdo, string $airline, string $von, string $bis, string $typ, string $antragsteller, array $nur_antrag_ids = []): array {
    if ($typ === 'kurzfristig') return [];

    $stati = ['freigabe_buero'];
    $ph = implode(',', array_fill(0, count($stati), '?'));

    $katMapSql = "CASE a.veranstaltung
                    WHEN 'Verhandlung' THEN 'verhandlung'
                    WHEN 'TK Sitzung'  THEN 'tk_sitzung'
                    ELSE                    'sonstiges'
                  END";

    // Optionaler Filter auf bestimmte Antrags-IDs
    // Bei expliziter Auswahl: kein Datumsfilter → alle Tage der Anträge
    $idFilter    = '';
    $idParams    = [];
    $datumFilter = 'AND at2.tag BETWEEN ? AND ?';
    $datumParams = [$von, $bis];
    if (!empty($nur_antrag_ids)) {
        $idPh        = implode(',', array_fill(0, count($nur_antrag_ids), '?'));
        $idFilter    = "AND a.id IN ({$idPh})";
        $idParams    = $nur_antrag_ids;
        $datumFilter = ''; // alle Tage des Antrags, auch monatsübergreifend
        $datumParams = [];
    }

    $stmt = $pdo->prepare(
        "SELECT a.id, a.vorname, a.nachname_enc, a.position, a.flugzeugmuster, a.stationierung,
                a.veranstaltung, a.status AS antrag_status,
                COALESCE(NULLIF(a.freistellungscode, ''), fp.freistellungscode) AS freistellungscode,
                at2.tag, at2.status AS tag_status
         FROM antrag_tage at2
         JOIN antraege a ON a.id = at2.antrag_id
         LEFT JOIN finance_preise fp
                ON  fp.id = (
                    SELECT id FROM finance_preise
                     WHERE airline           = a.airline
                       AND freistellungscode = NULLIF(a.freistellungscode, '')
                       AND position          = a.position
                     ORDER BY tagessatz DESC LIMIT 1
                )
         WHERE a.airline = ?
           AND a.status IN ({$ph})
           AND at2.status != 'storno_buero'
           {$datumFilter}
           {$idFilter}
         ORDER BY a.vorname, at2.tag"
    );
    $stmt->execute([$airline, ...$stati, ...$datumParams, ...$idParams]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];

    foreach ($rows as &$r) {
        $r['nachname'] = decrypt($r['nachname_enc']);
        unset($r['nachname_enc']);
    }
    unset($r);

    // ── Gruppieren: Monat → Code (nur FS/V4) → Station → EINZELNES Ereignis ──
    // Jeder Antrag (= ein Ereignis/Zeitraum, den ein Mitglied eingereicht hat)
    // bekommt eine EIGENE Zeile im Formular - Anträge desselben Mitglieds
    // werden NICHT zusammengefasst, auch wenn sie im selben Monat liegen.
    $ohneStationLabel = 'Ohne Stationierungsort';
    $gruppen = []; // "YYYY-MM|CODE|STATION" => ['monatKey'=>, 'code'=>, 'station'=>, 'eintraege'=>[antragId=>[...]]]

    foreach ($rows as $r) {
        $code = $r['freistellungscode'] ?: '';
        if (!in_array($code, AG_DOC_CODES, true)) continue; // nur FS/V4 bekommen ein DOC

        $monatKey = substr($r['tag'], 0, 7);
        $station  = trim((string)($r['stationierung'] ?? '')) !== '' ? $r['stationierung'] : $ohneStationLabel;
        $gKey     = "{$monatKey}|{$code}|{$station}";
        $antragId = $r['id'];

        $gruppen[$gKey]['monatKey'] ??= $monatKey;
        $gruppen[$gKey]['code']     ??= $code;
        $gruppen[$gKey]['station']  ??= $station;

        $e = &$gruppen[$gKey]['eintraege'][$antragId];
        $e['vorname']       ??= $r['vorname'];
        $e['nachname']      ??= $r['nachname'];
        $e['position']      ??= $r['position'];
        $e['flugzeugmuster']??= $r['flugzeugmuster'];
        $e['veranstaltung'] ??= $r['veranstaltung'];
        $e['status']        = $r['antrag_status'];
        $e['tage'][]        = $r['tag'];
        unset($e);
    }
    if (!$gruppen) return [];

    $ergebnisse = [];
    foreach ($gruppen as $g) {
        [$jahr, $monatNr] = array_map('intval', explode('-', $g['monatKey']));
        $monatName = AG_DOC_MONATSNAMEN[$monatNr] ?? (string)$monatNr;

        $blocks = [];
        $blocks[] = SimpleDocx::pText("Antrag auf {$g['code']} Freistellung", ['bold' => true, 'underline' => true]);

        // Kopftabelle: Datum/Kalendermonat | Stationierungsort/Flotte
        // (entspricht dem echten LH-Formular: 2 Spalten, kein "Freistellungsart")
        $kopfRows = [];
        $kopfRows[] = SimpleDocx::tRow([
            SimpleDocx::tCell('Datum / Kalendermonat', ['width' => 5145, 'bold' => true, 'shade' => 'F2F2F2']),
            SimpleDocx::tCell('Stationierungsort / Flotte', ['width' => 4950, 'bold' => true, 'shade' => 'F2F2F2']),
        ]);
        $flotte = ''; // wird unten aus den Einträgen befüllt
        foreach ($g['eintraege'] as $e) {
            if (!empty($e['flugzeugmuster'])) { $flotte = $e['flugzeugmuster']; break; }
        }
        $kopfRows[] = SimpleDocx::tRow([
            SimpleDocx::tCell("{$monatName} {$jahr}", ['width' => 5145]),
            SimpleDocx::tCell($g['station'] . ($flotte ? " / {$flotte}" : ''), ['width' => 4950]),
        ]);
        $blocks[] = SimpleDocx::tTable([5145, 4950], $kopfRows);
        $blocks[] = SimpleDocx::pEmpty();

        // Haupttabelle – Spaltenbreiten wie im Original-Formular
        $colWidths = [3521, 1417, 1847, 992, 992, 1839];
        $haupt = [];

        // Zeile 1: Abschnittsbezeichnungen (von VC / von FRA CB auszufüllen)
        $haupt[] = SimpleDocx::tRow([
            SimpleDocx::tCell('von VC auszufüllen', ['width' => $colWidths[0] + $colWidths[1] + $colWidths[2], 'span' => 3, 'bold' => true, 'shade' => 'F2F2F2']),
            SimpleDocx::tCell("Von FRA CB auszufüllen", ['width' => $colWidths[3] + $colWidths[4] + $colWidths[5], 'span' => 3, 'bold' => true, 'shade' => 'F2F2F2']),
        ]);
        // Zeile 2: Spaltenköpfe
        $haupt[] = SimpleDocx::tRow([
            SimpleDocx::tCell('Name, Vorname (PK-Nummer / Funktion)', ['width' => $colWidths[0], 'bold' => true, 'shade' => 'F2F2F2']),
            SimpleDocx::tCell("Datum\n{$g['code']} Freistellung", ['width' => $colWidths[1], 'bold' => true, 'shade' => 'F2F2F2']),
            SimpleDocx::tCell('Freistellungsanlass / Bemerkungen', ['width' => $colWidths[2], 'bold' => true, 'shade' => 'F2F2F2']),
            SimpleDocx::tCell('Freistellung', ['width' => $colWidths[3] + $colWidths[4], 'span' => 2, 'bold' => true, 'shade' => 'F2F2F2']),
            SimpleDocx::tCell('Bemerkungen', ['width' => $colWidths[5], 'bold' => true, 'shade' => 'F2F2F2']),
        ]);
        // Zeile 3: gewährt / nicht gewährt
        $haupt[] = SimpleDocx::tRow([
            SimpleDocx::tCell('', ['width' => $colWidths[0]]),
            SimpleDocx::tCell('', ['width' => $colWidths[1]]),
            SimpleDocx::tCell('', ['width' => $colWidths[2]]),
            SimpleDocx::tCell('gewährt', ['width' => $colWidths[3], 'bold' => true, 'shade' => 'F2F2F2']),
            SimpleDocx::tCell('nicht gewährt', ['width' => $colWidths[4], 'bold' => true, 'shade' => 'F2F2F2']),
            SimpleDocx::tCell('', ['width' => $colWidths[5]]),
        ]);

        // Sortierung: nach Name, dann Datum
        uasort($g['eintraege'], function ($a, $b) {
            $keyA = $a['nachname'] . '|' . $a['vorname'] . '|' . min($a['tage']);
            $keyB = $b['nachname'] . '|' . $b['vorname'] . '|' . min($b['tage']);
            return strcmp($keyA, $keyB);
        });

        $alleAntragIds  = [];
        $bereitsGerendert = []; // "nachname|vorname|datum" → verhindert Duplikate
        $zeilenNr = 0;
        foreach ($g['eintraege'] as $antragId => $e) {
            $alleAntragIds[] = $antragId;
            $musterSuffix = $e['flugzeugmuster'] !== '' ? " {$e['flugzeugmuster']}" : '';
            $nameZeile = "{$e['nachname']}, {$e['vorname']}, {$e['position']}{$musterSuffix}";
            $anlassTxt = $e['veranstaltung'];

            sort($e['tage']);
            foreach ($e['tage'] as $tagDatum) {
                $dedupKey = "{$e['nachname']}|{$e['vorname']}|{$tagDatum}";
                if (isset($bereitsGerendert[$dedupKey])) continue; // selber Tag, selbe Person → überspringen
                $bereitsGerendert[$dedupKey] = true;

                $datumTxt = date('d.m.Y', strtotime($tagDatum));
                $zeilenId = "{$antragId}_{$tagDatum}";
                $haupt[] = SimpleDocx::tRow([
                    SimpleDocx::tCell($nameZeile, ['width' => $colWidths[0], 'bold' => true]),
                    SimpleDocx::tCell($datumTxt,  ['width' => $colWidths[1]]),
                    SimpleDocx::tCell($anlassTxt, ['width' => $colWidths[2]]),
                    SimpleDocx::tCheckboxCell(['width' => $colWidths[3]], "gewaehrt:{$zeilenId}"),
                    SimpleDocx::tCheckboxCell(['width' => $colWidths[4]], "nicht_gewaehrt:{$zeilenId}"),
                    SimpleDocx::tCell('', ['width' => $colWidths[5]]),
                ]);
                $zeilenNr++;
            }
        }

        // Fußzeile: Antragsteller VC | Genehmigender AG (wie im Original)
        $haupt[] = SimpleDocx::tRow([
            SimpleDocx::tCell('Antragsteller VC', ['width' => $colWidths[0] + $colWidths[1] + $colWidths[2], 'span' => 3, 'bold' => true, 'shade' => 'F2F2F2']),
            SimpleDocx::tCell("Genehmigender {$airline}", ['width' => $colWidths[3] + $colWidths[4] + $colWidths[5], 'span' => 3, 'bold' => true, 'shade' => 'F2F2F2']),
        ]);
        $haupt[] = SimpleDocx::tRow([
            SimpleDocx::tCell($antragsteller, ['width' => $colWidths[0] + $colWidths[1] + $colWidths[2], 'span' => 3]),
            SimpleDocx::tCell('Name, Vorname hier eintragen', ['width' => $colWidths[3] + $colWidths[4] + $colWidths[5], 'span' => 3, 'color' => '7F7F7F']),
        ]);

        $blocks[] = SimpleDocx::tTable($colWidths, $haupt);

        // Unsichtbarer Cross-Check-Marker mit allen Checkbox-IDs (antragId_datum)
        $erwarteteTags = [];
        foreach ($alleAntragIds as $antragId) {
            foreach ($g['eintraege'][$antragId]['tage'] as $tagDatum) {
                $zeilenId = "{$antragId}_{$tagDatum}";
                $erwarteteTags[] = "gewaehrt:{$zeilenId}";
                $erwarteteTags[] = "nicht_gewaehrt:{$zeilenId}";
            }
        }
        $blocks[] = SimpleDocx::pHiddenMarker('AG_DOC_TAGS:' . implode(',', $erwarteteTags));

        $dateiname = preg_replace('/[^A-Za-z0-9_\-]/', '_', "{$airline}_{$g['code']}_{$g['station']}_{$monatName}{$jahr}") . '.docx';
        $path = sys_get_temp_dir() . '/' . uniqid('ag_doc_', true) . '.docx';
        SimpleDocx::save($blocks, $path);

        $ergebnisse[] = ['path' => $path, 'filename' => $dateiname];
    }

    return $ergebnisse;
}

/**
 * Wie ag_antrag_doc_dateien_bauen(), legt aber jede erzeugte Datei sofort
 * unter einem eigenen Zufalls-Token ab (Panel-Vorschau/-Download, siehe
 * ag_antrag_anhang_token_ablegen() in ag_antrag_excel.php).
 * @return array<int, array{token:string, filename:string}>
 */
function ag_antrag_doc_anhaenge_bereitstellen(PDO $pdo, string $airline, string $von, string $bis, string $typ, string $antragsteller): array {
    $docs = ag_antrag_doc_dateien_bauen($pdo, $airline, $von, $bis, $typ, $antragsteller);
    $ergebnisse = [];
    foreach ($docs as $doc) {
        $ergebnisse[] = ag_antrag_anhang_token_ablegen($doc['path'], $doc['filename']);
    }
    return $ergebnisse;
}

/**
 * Fasst eine Liste von Datums-Strings (Y-m-d) zu lesbaren, zusammenhängenden
 * Bereichen zusammen, z.B. ["2026-06-12","2026-06-13","2026-06-20"]
 * -> "12.-13.06.2026; 20.06.2026"
 */
function ag_doc_tage_zu_bereichen(array $tage): string {
    $tage = array_values(array_unique($tage));
    sort($tage);
    if (!$tage) return '';

    $bereiche = [];
    $start = $tage[0];
    $prev  = $tage[0];

    foreach (array_slice($tage, 1) as $tag) {
        $erwartet = date('Y-m-d', strtotime($prev . ' +1 day'));
        if ($tag !== $erwartet) {
            $bereiche[] = [$start, $prev];
            $start = $tag;
        }
        $prev = $tag;
    }
    $bereiche[] = [$start, $prev];

    $teile = [];
    foreach ($bereiche as [$von, $bis]) {
        if ($von === $bis) {
            $teile[] = date('d.m.Y', strtotime($von));
        } else {
            $teile[] = date('d.m.Y', strtotime($von)) . '-' . date('d.m.Y', strtotime($bis));
        }
    }
    return implode('; ', $teile);
}
