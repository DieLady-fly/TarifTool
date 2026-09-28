<?php
// ============================================================
// _backend/ag_storno_doc.php
// Erzeugt das LH-Formular "Antrag auf Stornierung der Freistellung"
// als DOCX - wie beim Antrags-DOC eine Zeile je Mitglied und Tag,
// ein Dokument je Stationierungsort.
//
// Basis ist der Original-Vordruck (_backend/vorlagen/LH_Storno_Vordruck.docx),
// in dem Kopf-/Fußzeile, Schrift und Layout unverändert bleiben. Die
// Vorlage enthält Platzhalter ({{ZEITRAUM}}, {{ORG}}, {{NAME}}, {{DATUM}},
// {{DATUM_ISO}}, {{GRUND}}, {{ANTRAGSTELLER}}, {{TAG_*}}), die hier
// ersetzt werden. Die Tageszeile wird je Storno-Tag vervielfältigt.
//
// Wird an die Storno-Mail an den Arbeitgeber angehängt, wenn für die
// Airline "DOC zusätzlich ausgeben" aktiv ist (airline_einstellungen.
// doc_anhang_aktiv - dieselbe Einstellung wie beim FS/V4-Formular).
// Nur Tage mit Freistellungscode FS oder V4 (die beiden Codes, die das
// Formular vorsieht).
// ============================================================

const AG_STORNO_DOC_VORLAGE = __DIR__ . '/vorlagen/LH_Storno_Vordruck.docx';
const AG_STORNO_DOC_CODES   = ['FS', 'V4'];

/** Ist das Storno-Formular für diese Airline aktiv? */
function ag_storno_doc_aktiv(PDO $pdo, string $airline): bool {
    if ($airline === '' || !is_file(AG_STORNO_DOC_VORLAGE)) return false;
    $stmt = $pdo->prepare("SELECT doc_anhang_aktiv FROM airline_einstellungen WHERE airline = ? LIMIT 1");
    $stmt->execute([$airline]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Lädt die für das Formular nötigen Antragsdaten (inkl. Freistellungscode,
 * Fallback über finance_preise wie in ag_antrag_doc.php).
 *
 * @param array<int, array<int,string>> $tageProAntrag  antrag_id => [Y-m-d, ...]
 * @return array<int, array> Einträge für ag_storno_doc_dateien_bauen()
 */
function ag_storno_doc_eintraege_laden(PDO $pdo, array $tageProAntrag): array {
    $tageProAntrag = array_filter($tageProAntrag);
    if (!$tageProAntrag) return [];
    $ids = array_map('intval', array_keys($tageProAntrag));
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT a.id, a.mitglied_id, a.vorname, a.nachname_enc, a.position, a.flugzeugmuster,
                a.stationierung, a.airline,
                COALESCE(NULLIF(a.freistellungscode, ''), (
                    SELECT fp.freistellungscode FROM finance_preise fp
                     WHERE fp.airline = a.airline
                       AND fp.freistellungscode = NULLIF(a.freistellungscode, '')
                       AND fp.position = a.position
                     ORDER BY fp.tagessatz DESC LIMIT 1
                )) AS freistellungscode
         FROM antraege a WHERE a.id IN ({$ph})"
    );
    $stmt->execute($ids);
    $eintraege = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $a['nachname'] = decrypt($a['nachname_enc']);
        unset($a['nachname_enc']);
        $a['tage'] = array_values(array_unique($tageProAntrag[(int)$a['id']] ?? []));
        $eintraege[] = $a;
    }
    return $eintraege;
}

/**
 * Baut das Storno-Formular - wie beim Antrags-DOC (ag_antrag_doc.php) EINE
 * Zeile je Mitglied und Freistellungstag. Ein Dokument je Stationierungsort
 * (Kopf "Zeitraum | Stationierungsort"), Name + Flotte + Funktion stehen
 * in der ersten Spalte jeder Zeile.
 *
 * @param array  $eintraege     aus ag_storno_doc_eintraege_laden()
 * @param string $antragsteller Name für "Antragsteller VC"
 * @param string $grund         Storno-Grund (Spalte "Grund")
 * @param string $absender      Absender im Briefkopf ("Von ... VC"), i.d.R. die
 *                              CC-Adresse aus den Einstellungen (storno_email_cc)
 * @return array<int, array{path:string, filename:string}>  eine Datei je Stationierungsort
 */
function ag_storno_doc_dateien_bauen(array $eintraege, string $antragsteller, string $grund, string $absender = ''): array {
    if (!is_file(AG_STORNO_DOC_VORLAGE)) {
        error_log('ag_storno_doc: Vorlage fehlt: ' . AG_STORNO_DOC_VORLAGE);
        return [];
    }
    $zip = new ZipArchive();
    if ($zip->open(AG_STORNO_DOC_VORLAGE) !== true) return [];
    $vorlageXml = $zip->getFromName('word/document.xml');
    // Kopf-/Fußzeilen mit Platzhaltern (z.B. {{ABSENDER}} im Briefkopf)
    $kopfFussXml = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = $zip->getNameIndex($i);
        if (preg_match('#^word/(header|footer)\d*\.xml$#', $n)) {
            $inhalt = $zip->getFromIndex($i);
            if ($inhalt !== false && str_contains($inhalt, '{{')) $kopfFussXml[$n] = $inhalt;
        }
    }
    $zip->close();
    if ($vorlageXml === false) return [];

    // Tageszeile (Vorlage) = die <w:tr>, die {{DATUM}} enthält
    $datumPos = strpos($vorlageXml, '{{DATUM}}');
    if ($datumPos === false) {
        error_log('ag_storno_doc: Tageszeile in Vorlage nicht gefunden');
        return [];
    }
    $zeileStart   = strrpos(substr($vorlageXml, 0, $datumPos), '<w:tr ');
    $zeileEnde    = strpos($vorlageXml, '</w:tr>', $zeileStart) + strlen('</w:tr>');
    $zeileVorlage = substr($vorlageXml, $zeileStart, $zeileEnde - $zeileStart);

    // Eingaben sind teils per clean() HTML-kodiert gespeichert ("&amp;") -
    // erst dekodieren, dann XML-sicher maskieren.
    $dec = fn(string $s): string => html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $esc = fn(string $s): string => htmlspecialchars($dec($s), ENT_QUOTES | ENT_XML1, 'UTF-8');

    // ── Gruppieren: Stationierungsort → Zeilen (Mitglied × Tag) ──
    $ohneStation = 'Ohne Stationierungsort';
    $gruppen = [];
    foreach ($eintraege as $e) {
        $code = strtoupper(trim((string)($e['freistellungscode'] ?? '')));
        if (!in_array($code, AG_STORNO_DOC_CODES, true)) continue;

        $station = trim((string)($e['stationierung'] ?? '')) ?: $ohneStation;
        $name = implode(', ', array_filter([
            trim((string)$e['nachname']), trim((string)$e['vorname']),
            trim((string)($e['flugzeugmuster'] ?? '')), trim((string)($e['position'] ?? '')),
        ], fn($t) => $t !== ''));

        $gruppen[$station]['airline'] ??= (string)($e['airline'] ?? '');
        foreach ($e['tage'] as $tag) {
            // selbe Person + selber Tag nur einmal (analog Antrags-DOC)
            $dedup = mb_strtolower($e['nachname'] . '|' . $e['vorname']) . '|' . $tag;
            $gruppen[$station]['zeilen'][$dedup] ??= [
                'sort' => mb_strtolower($e['nachname'] . '|' . $e['vorname']) . '|' . $tag,
                'name' => $name, 'tag' => $tag, 'code' => $code, 'antrag_id' => (int)$e['id'],
            ];
        }
    }

    $ergebnisse = [];
    foreach ($gruppen as $station => $g) {
        if (empty($g['zeilen'])) continue;
        $zeilen = array_values($g['zeilen']);
        usort($zeilen, fn($a, $b) => strcmp($a['sort'], $b['sort']));
        $alleTage = array_column($zeilen, 'tag');
        $von = min($alleTage);
        $bis = max($alleTage);

        $zeilenXml = '';
        foreach ($zeilen as $z) {
            $zid = "{$z['antrag_id']}_{$z['tag']}";
            $r = strtr($zeileVorlage, [
                '{{NAME}}'           => $esc($z['name']),
                '{{DATUM}}'          => $esc(date('d.m.Y', strtotime($z['tag']))),
                '{{DATUM_ISO}}'      => $esc($z['tag']),
                '{{GRUND}}'          => $esc($grund !== '' ? $grund : 'Bitte um Storno'),
                '{{TAG_FS}}'         => $esc("storno_fs:{$zid}"),
                '{{TAG_V4}}'         => $esc("storno_v4:{$zid}"),
                '{{TAG_STORNIERT}}'  => $esc("storno_storniert:{$zid}"),
                '{{TAG_BLEIBT}}'     => $esc("storno_bleibt:{$zid}"),
            ]);
            // Symbol-Checkbox passend zum Code ankreuzen
            $r = ag_storno_doc_checkbox_setzen($r, $z['code'] === 'FS' ? "storno_fs:{$zid}" : "storno_v4:{$zid}");
            $zeilenXml .= $r;
        }

        $zeitraum = $von === $bis
            ? date('d.m.Y', strtotime($von))
            : date('d.m.Y', strtotime($von)) . ' – ' . date('d.m.Y', strtotime($bis));

        $xml = substr($vorlageXml, 0, $zeileStart) . $zeilenXml . substr($vorlageXml, $zeileEnde);
        $xml = strtr($xml, [
            '{{ZEITRAUM}}'      => $esc($zeitraum),
            '{{ORG}}'           => $esc($station),
            '{{ANTRAGSTELLER}}' => $esc($antragsteller),
        ]);
        $xml = ag_storno_doc_sdt_ids_neu($xml);

        $path = sys_get_temp_dir() . '/' . uniqid('ag_storno_doc_', true) . '.docx';
        if (!copy(AG_STORNO_DOC_VORLAGE, $path)) continue;
        $out = new ZipArchive();
        if ($out->open($path) !== true) { @unlink($path); continue; }
        $out->addFromString('word/document.xml', $xml);
        $absenderTxt = trim($absender) !== '' ? $absender : (defined('MAIL_FROM') ? MAIL_FROM : '');
        foreach ($kopfFussXml as $n => $inhalt) {
            $out->addFromString($n, strtr($inhalt, ['{{ABSENDER}}' => $esc($absenderTxt)]));
        }
        $out->close();

        $teil = date('d.m.y', strtotime($von)) . ($von !== $bis ? '-' . date('d.m.y', strtotime($bis)) : '');
        $roh  = date('Y-m-d') . "_Storno_Freistellung_{$g['airline']}_{$station}_{$teil}";
        $dateiname = preg_replace('/[^A-Za-z0-9_.\-]/', '_',
            strtr($dec($roh), ['ä'=>'ae','ö'=>'oe','ü'=>'ue','Ä'=>'Ae','Ö'=>'Oe','Ü'=>'Ue','ß'=>'ss'])) . '.docx';
        $ergebnisse[] = ['path' => $path, 'filename' => $dateiname];
    }
    return $ergebnisse;
}

/** Setzt die Checkbox mit dem angegebenen w:tag auf "angekreuzt". */
function ag_storno_doc_checkbox_setzen(string $xml, string $tag): string {
    $pos = strpos($xml, '<w:tag w:val="' . htmlspecialchars($tag, ENT_QUOTES | ENT_XML1, 'UTF-8') . '"/>');
    if ($pos === false) return $xml;
    $sdtEnde = strpos($xml, '</w:sdt>', $pos);
    if ($sdtEnde === false) return $xml;
    $block = substr($xml, $pos, $sdtEnde - $pos);
    $block = str_replace('<w14:checked w14:val="0"/>', '<w14:checked w14:val="1"/>', $block);
    $block = preg_replace('/<w:t>☐<\/w:t>/u', '<w:t>☒</w:t>', $block, 1);
    return substr($xml, 0, $pos) . $block . substr($xml, $sdtEnde);
}

/** Vergibt eindeutige Content-Control-IDs (nötig nach dem Vervielfältigen der Zeilen). */
function ag_storno_doc_sdt_ids_neu(string $xml): string {
    $n = 700000;
    return preg_replace_callback('/(<w:sdtPr>(?:(?!<\/w:sdtPr>).)*?<w:id w:val=")-?\d+(")/s',
        function ($m) use (&$n) { return $m[1] . ($n++) . $m[2]; }, $xml);
}
