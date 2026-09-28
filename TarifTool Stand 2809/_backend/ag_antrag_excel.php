<?php
// ============================================================
// _backend/ag_antrag_excel.php
// Erzeugt die "Freistellungsliste"-Excel (Stil: Muster
// "Lufthansa_Freistellungsliste_…xlsx") für die Beantragung
// beim Arbeitgeber.
//
// Enthält für den gewählten Zeitraum IMMER beide Status
// (unabhängig vom Vorlagen-Typ 'standard'/'nachstichtag'):
//   - freigabe_buero – noch nicht beim AG gemeldet   → ROT
//   - beantragt_ag   – bereits gemeldet, Rückmeldung
//                       steht noch aus               → SCHWARZ
//
// Für 'kurzfristig' wird KEINE Excel erzeugt (Liste steht
// direkt im Mailtext über {{LISTE}}).
// ============================================================
require_once __DIR__ . '/xlsx_writer.php';

const AG_EXCEL_MONATSNAMEN = [
    1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
    5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
];

/**
 * @return array{path:string, filename:string}|null  null = keine Daten gefunden
 */
function ag_antrag_excel_bauen(PDO $pdo, string $airline, string $von, string $bis, string $typ, string $sprache = 'de', array $nur_antrag_ids = []): ?array {
    if ($typ === 'kurzfristig') return null;

    // Unabhängig vom gewählten Vorlagen-Typ werden IMMER beide Status in
    // die Freistellungsliste aufgenommen: "freigabe_buero" (noch nicht
    // beim AG gemeldet, ROT) und "beantragt_ag" (bereits gemeldet, dessen
    // Rückmeldung noch aussteht, SCHWARZ). Vorher galt das nur für den Typ
    // "nachstichtag" - dadurch fehlten bereits gemeldete Anträge in der
    // "Standard"-Liste komplett, obwohl sie für den gewählten Zeitraum
    // relevant sind (z.B. um dem Arbeitgeber die vollständige Übersicht
    // zu geben, oder wenn dieselbe Liste als Erinnerung erneut verschickt
    // wird).
    $stati = ['freigabe_buero', 'beantragt_ag'];
    $ph = implode(',', array_fill(0, count($stati), '?'));

    // WICHTIG: antrag_tage.status wird laut update_antrag() NUR beim Storno
    // auf 'storno_buero' gesetzt, sonst NIE synchron zum Antrags-Status
    // gehalten (bleibt z.B. auf 'ausstehend' stehen, auch wenn a.status
    // längst 'freigabe_buero' ist). Maßgeblich ist daher a.status; auf
    // Tage-Ebene wird nur "nicht storniert" geprüft – analog zu den
    // bestehenden Queries agv_stmt/as_stmt weiter oben in dieser Datei.
    //
    // Freistellungscode: a.freistellungscode ist oft NULL ("wird über
    // finance_preise gepflegt", siehe Kommentar bei der Antragserstellung).
    // Maßgeblich ist daher – wie in get_finance_stats() – die Zuordnung
    // über airline + kategorie(veranstaltung) + position in finance_preise,
    // a.freistellungscode wird nur als Override genutzt, falls gesetzt.
    $katMapSql = "CASE a.veranstaltung
                    WHEN 'Verhandlung' THEN 'verhandlung'
                    WHEN 'TK Sitzung'  THEN 'tk_sitzung'
                    ELSE                    'sonstiges'
                  END";
    $datumFilter = 'AND at2.tag BETWEEN ? AND ?';
    $datumParams = [$von, $bis];
    $idFilter    = '';
    $idParams    = [];
    if (!empty($nur_antrag_ids)) {
        $idPh        = implode(',', array_fill(0, count($nur_antrag_ids), '?'));
        $idFilter    = "AND a.id IN ({$idPh})";
        $idParams    = $nur_antrag_ids;
        // Datumsfilter bleibt aktiv: nur Tage im gefilterten Zeitraum
        // (monatsübergreifende Anträge werden getrennt).
    }

    $stmt = $pdo->prepare(
        "SELECT a.id, a.mitglied_id, a.vorname, a.nachname_enc, a.position, a.flugzeugmuster, a.stationierung,
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
         ORDER BY a.flugzeugmuster, a.vorname, at2.tag"
    );
    $stmt->execute([$airline, ...$stati, ...$datumParams, ...$idParams]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return null;

    foreach ($rows as &$r) {
        $r['nachname'] = decrypt($r['nachname_enc']);
        unset($r['nachname_enc']);
    }
    unset($r);

    // ── Gruppieren: Monat → Stationierungsort → Mitglied ─────
    // Mitglied-Schlüssel: die EINDEUTIGE mitglied_id (nicht der Name!) -
    // zwei verschiedene Personen mit zufällig gleichem Namen und gleicher
    // Position dürfen NIE zu einer Zeile zusammengeführt werden. Ist ein
    // Antrag ausnahmsweise keinem Mitglied zugeordnet (mitglied_id NULL,
    // z.B. bei sehr alten oder manuell angelegten Anträgen), wird
    // stattdessen die Antrags-ID selbst als Schlüssel verwendet, damit
    // auch das nie fälschlich mit einer anderen Person zusammenfällt.
    $months = []; // 'YYYY-MM' => ['stationen' => ['<station>' => ['<mitglied_key>' => [...]]]]
    $ohneStationLabel = 'Ohne Stationierungsort';

    foreach ($rows as $r) {
        $monatKey = substr($r['tag'], 0, 7);
        $station  = trim((string)($r['stationierung'] ?? '')) !== '' ? $r['stationierung'] : $ohneStationLabel;
        $mKey     = $r['mitglied_id'] ? "m{$r['mitglied_id']}" : "a{$r['id']}";

        $months[$monatKey]['stationen'][$station][$mKey]['vorname']       ??= $r['vorname'];
        $months[$monatKey]['stationen'][$station][$mKey]['nachname']      ??= $r['nachname'];
        $months[$monatKey]['stationen'][$station][$mKey]['position']      ??= $r['position'];
        $months[$monatKey]['stationen'][$station][$mKey]['flugzeugmuster']??= $r['flugzeugmuster'];
        $months[$monatKey]['stationen'][$station][$mKey]['veranstaltungen'][$r['veranstaltung']] = true;

        $tagNr = (int)substr($r['tag'], 8, 2);
        // Farbe richtet sich NUR nach dem Antrags-Status, unabhängig vom
        // gewählten Vorlagen-Typ: freigabe_buero (noch nicht gemeldet) =
        // rot, beantragt_ag (bereits gemeldet) = schwarz.
        $farbe = ($r['antrag_status'] === 'beantragt_ag') ? 'black_bold' : 'red_bold';
        $months[$monatKey]['stationen'][$station][$mKey]['tage'][$tagNr] = [
            'code'  => $r['freistellungscode'] ?: '?',
            'style' => $farbe,
        ];
    }
    ksort($months);

    $xlsx = new SimpleXlsx();

    foreach ($months as $monatKey => $monatDaten) {
        [$jahr, $monatNr] = array_map('intval', explode('-', $monatKey));
        $monatName    = AG_EXCEL_MONATSNAMEN[$monatNr] ?? (string)$monatNr;
        $tageImMonat  = (int)date('t', mktime(0, 0, 0, $monatNr, 1, $jahr));

        $sheet = $xlsx->addSheet(substr($monatName . ' ' . $jahr, 0, 31));
        $sheet->setColWidth(1, 45);
        for ($c = 2; $c <= $tageImMonat + 1; $c++) $sheet->setColWidth($c, 4.5);

        // Alle im Monat verwendeten Codes für die Legende sammeln
        $usedCodes = [];
        foreach ($monatDaten['stationen'] as $mitglieder) {
            foreach ($mitglieder as $m) {
                foreach ($m['tage'] as $t) $usedCodes[$t['code']] = true;
            }
        }
        $codesListe = implode(', ', array_keys($usedCodes));

        $row = 1;
        $en = ($sprache === 'en');
        $sheet->write($row++, 1, ($en ? "Leave of Absence List {$airline}" : "Freistellungsliste {$airline}"), 'title');
        $sheet->write($row++, 1, ($en ? "Airline: {$airline}" : "Fluggesellschaft: {$airline}"), 'default');
        $sheet->write($row++, 1, ($en ? "Month: {$monatName} {$jahr}" : "Freistellungsmonat: {$monatName} {$jahr}"), 'default');
        $sheet->write($row++, 1, ($en ? "Leave symbols: {$codesListe}" : "Freistellungssymbole: {$codesListe}"), 'default');
        $sheet->write($row++, 1, ($en ? "Date of request: " : "Datum der Antragstellung: ") . date('d.m.Y'), 'red');
        $row++; // Leerzeile

        // "Ohne Stationierungsort" ans Ende sortieren, sonst alphabetisch
        $stationen = $monatDaten['stationen'];
        uksort($stationen, function ($a, $b) use ($ohneStationLabel) {
            if ($a === $ohneStationLabel) return 1;
            if ($b === $ohneStationLabel) return -1;
            return strcmp($a, $b);
        });

        foreach ($stationen as $stationName => $mitglieder) {
            $sheet->write($row, 1, ($en ? 'Day of month:' : 'Tag des Monats:'), 'default');
            for ($t = 1; $t <= $tageImMonat; $t++) {
                $sheet->write($row, 1 + $t, $t, 'center_bordered');
            }
            $row++;

            $stationLabel = ($en && $stationName !== $ohneStationLabel)
                ? "Base: {$stationName}"
                : ($stationName !== $ohneStationLabel ? "Station: {$stationName}" : $stationName);
            $sheet->write($row++, 1, $stationLabel, 'default');

            uasort($mitglieder, fn($a, $b) => strcmp($a['nachname'] . '|' . $a['vorname'], $b['nachname'] . '|' . $b['vorname']));
            foreach ($mitglieder as $m) {
                $musterSuffix = $m['flugzeugmuster'] !== '' ? ", {$m['flugzeugmuster']}" : '';
                $sheet->write($row, 1, "{$m['nachname']}, {$m['vorname']} ({$m['position']}{$musterSuffix})", 'bold_bordered');
                for ($t = 1; $t <= $tageImMonat; $t++) {
                    if (isset($m['tage'][$t])) {
                        $info = $m['tage'][$t];
                        $borderedStyle = $info['style'] . '_bordered';
                        $sheet->write($row, 1 + $t, $info['code'], $borderedStyle);
                    } else {
                        $sheet->write($row, 1 + $t, '', 'bordered');
                    }
                }
                $row++;

                // Veranstaltungstypen ggf. übersetzen
                $terminKeys = array_keys($m['veranstaltungen']);
                if ($en) {
                    $terminKeys = array_map('ag_antrag_veranstaltung_en', $terminKeys);
                }
                $termine = implode('; ', $terminKeys);
                $sheet->write($row++, 1, ($en ? "Event(s): {$termine}" : "Termin(e): {$termine}"), 'default');
            }
        }
    }

    $langSuffix = $sprache === 'en' ? '_EN' : '';
    $filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $airline)
        . "_Freistellungsliste_{$von}_{$bis}{$langSuffix}.xlsx";
    $path = sys_get_temp_dir() . '/' . uniqid('ag_excel_', true) . '.xlsx';
    $xlsx->save($path);

    return ['path' => $path, 'filename' => $filename];
}

// ============================================================
// Token-basierte Zwischenablage für die Panel-Vorschau/-Download
// ============================================================
// Damit das Büro die erzeugte Freistellungsliste vor dem Versand
// noch einmal prüfen/herunterladen kann, wird sie NICHT nur in
// ein flüchtiges sys_get_temp_dir()-File geschrieben, sondern
// zusätzlich unter einem zufälligen Token abgelegt. Beim
// tatsächlichen Versand wird über denselben Token exakt diese
// Datei wiederverwendet (keine erneute Erzeugung, kein Risiko
// einer Abweichung zwischen Vorschau und E-Mail-Anhang).

function ag_antrag_anhang_verzeichnis(): string {
    $dir = __DIR__ . '/../_tmp/ag_anhang';
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
        // Verzeichnis liegt im Webroot – per .htaccess vor direktem
        // HTTP-Zugriff schützen (Auslieferung nur über den
        // login-geschützten Download-Endpunkt).
        file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    // Alte Anhänge (>6h) aufräumen, damit das Verzeichnis nicht wächst.
    foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
        if (is_dir($sub) && filemtime($sub) < time() - 6 * 3600) {
            foreach (glob($sub . '/*') ?: [] as $f) @unlink($f);
            @rmdir($sub);
        }
    }
    return $dir;
}

/**
 * Erzeugt die Excel (falls für den Typ vorgesehen) und legt sie unter
 * einem Zufalls-Token ab.
 * @return array{token:string, filename:string}|null
 */
function ag_antrag_anhang_bereitstellen(PDO $pdo, string $airline, string $von, string $bis, string $typ, string $sprache = 'de', array $nur_antrag_ids = []): ?array {
    $excel = ag_antrag_excel_bauen($pdo, $airline, $von, $bis, $typ, $sprache, $nur_antrag_ids);
    if (!$excel) return null;
    return ag_antrag_anhang_token_ablegen($excel['path'], $excel['filename']);
}

/**
 * Löst einen Token zu einem Dateipfad auf (validiert Format, prüft Existenz).
 * Funktioniert für beliebige unter dem Token abgelegte Dateien (Excel ODER
 * Word-DOC), da beide dieselbe Verzeichnisstruktur nutzen.
 * @return array{path:string, filename:string}|null
 */
function ag_antrag_anhang_aus_token(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    $dir = ag_antrag_anhang_verzeichnis() . '/' . $token;
    if (!is_dir($dir)) return null;
    $dateien = array_values(array_diff(glob($dir . '/*') ?: [], ['.htaccess']));
    $dateien = array_filter($dateien, fn($f) => basename($f) !== '.htaccess' && is_file($f));
    $dateien = array_values($dateien);
    if (!$dateien) return null;
    return ['path' => $dateien[0], 'filename' => basename($dateien[0])];
}

/**
 * Legt eine bereits erzeugte Datei (Excel oder DOC) unter einem neuen
 * Zufalls-Token ab. Generischer Baustein, den sowohl
 * ag_antrag_anhang_bereitstellen() (Excel) als auch die DOC-Erzeugung
 * (ag_antrag_doc.php) nutzen.
 * @return array{token:string, filename:string}
 */
function ag_antrag_anhang_token_ablegen(string $tmpPath, string $filename): array {
    $token = bin2hex(random_bytes(16));
    $dir   = ag_antrag_anhang_verzeichnis() . '/' . $token;
    mkdir($dir, 0770, true);
    $ziel = $dir . '/' . $filename;
    rename($tmpPath, $ziel);
    return ['token' => $token, 'filename' => $filename];
}
