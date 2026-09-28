<?php
// ============================================================
// _backend/docx_reader.php
// Liest eine hochgeladene DOCX-Datei ein und extrahiert alle
// getaggten Word-Checkbox-Formularfelder (siehe docx_writer.php:
// SimpleDocx::tCheckboxCell(..., $tag)).
//
// Wird genutzt, um von der Airline ausgefüllte Freistellungs-
// Formulare automatisiert auszuwerten (AG-Rückmeldung per DOC).
// ============================================================

/**
 * @return array<int, array{tag:string, checked:bool}>
 * @throws RuntimeException bei ungültiger/kein DOCX
 */
function docx_checkboxen_lesen(string $pfad): array {
    $zip = new ZipArchive();
    if ($zip->open($pfad) !== true) {
        throw new RuntimeException('Datei ist keine gültige DOCX/ZIP-Datei.');
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) {
        throw new RuntimeException('word/document.xml nicht gefunden - keine gültige Word-Datei.');
    }

    $ergebnisse = [];

    // Jede getaggte Checkbox liegt als:
    //   <w:sdt><w:sdtPr>...<w:tag w:val="TAG"/>...<w14:checkbox>
    //     <w14:checked w14:val="0|1"/>...
    // vor. Wir suchen jeden <w:sdt>-Block, der sowohl <w:tag> als auch
    // <w14:checkbox> enthält, unabhängig von der genauen Reihenfolge der
    // Kind-Elemente innerhalb von <w:sdtPr> (robust gegen Word, das beim
    // Speichern gelegentlich Attribute/Reihenfolgen leicht verändert).
    if (!preg_match_all('/<w:sdt>(.*?)<\/w:sdt>/s', $xml, $sdtMatches)) {
        return [];
    }

    foreach ($sdtMatches[1] as $block) {
        if (strpos($block, '<w14:checkbox>') === false) continue;
        if (!preg_match('/<w:tag w:val="([^"]*)"/', $block, $tagMatch)) continue;
        if (!preg_match('/<w14:checked w14:val="(\d)"/', $block, $checkedMatch)) continue;

        $ergebnisse[] = [
            'tag'     => html_entity_decode($tagMatch[1], ENT_QUOTES | ENT_XML1, 'UTF-8'),
            'checked' => $checkedMatch[1] === '1',
        ];
    }

    return $ergebnisse;
}

/**
 * Liest den unsichtbaren Cross-Check-Marker (siehe SimpleDocx::pHiddenMarker()
 * in docx_writer.php / ag_antrag_doc.php) aus: die vollständige Liste der
 * Checkbox-Bezeichner ("gewaehrt:ID" / "nicht_gewaehrt:ID"), die bei der
 * Erzeugung in diesem Dokument enthalten waren - unabhängig davon, ob sie
 * noch als Formular-Content-Control vorhanden sind. So lässt sich auch
 * erkennen, wenn nur EINE von zwei Checkboxen einer Zeile nicht mehr lesbar
 * ist. Kann mehrere Marker enthalten (mehrere Tabellen/Monate) - alle
 * werden zusammengeführt.
 *
 * @return array<int, string> Liste erwarteter Tags, z.B. ["gewaehrt:501", "nicht_gewaehrt:501"]
 */
function docx_erwartete_checkbox_tags(string $pfad): array {
    $zip = new ZipArchive();
    if ($zip->open($pfad) !== true) return [];
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) return [];

    if (!preg_match_all('/AG_DOC_TAGS:([\w:,\-]*)/', $xml, $matches)) {
        return [];
    }
    $tags = [];
    foreach ($matches[1] as $liste) {
        foreach (explode(',', $liste) as $tag) {
            if ($tag !== '') $tags[] = $tag;
        }
    }
    return array_values(array_unique($tags));
}

/**
 * Zerlegt einen Checkbox-Tag.
 * Formate: "gewaehrt:{antragId}_{Y-m-d}" (aktuell, eine Zeile je Tag)
 *          "gewaehrt:{antragId}"          (alte Dokumente, eine Zeile je Antrag)
 * @return array{art:string, antrag_id:int, tag:string}|null  tag = '' bei altem Format
 */
function docx_checkbox_tag_zerlegen(string $tag): ?array {
    if (!preg_match('/^(gewaehrt|nicht_gewaehrt):(\d+)(?:_(\d{4}-\d{2}-\d{2}))?$/', $tag, $m)) return null;
    return ['art' => $m[1], 'antrag_id' => (int)$m[2], 'tag' => $m[3] ?? ''];
}

/**
 * Wandelt die Roh-Checkbox-Liste in eine Struktur je Antrag um.
 * Da das Formular eine Zeile JE TAG hat, werden die Tage eines Antrags
 * zusammengefasst:
 *   antrag_id => [
 *     'gewaehrt'       => bool  (mind. ein Tag "gewährt" angehakt),
 *     'nicht_gewaehrt' => bool  (mind. ein Tag "nicht gewährt" angehakt),
 *     'tage_gesamt', 'tage_gewaehrt', 'tage_abgelehnt', 'tage_beide', 'tage_offen' => int,
 *   ]
 *
 * @return array<int, array>
 */
function docx_checkboxen_zu_antraegen(array $checkboxen): array {
    $proTag = []; // antragId => [tag => ['gewaehrt'=>bool,'nicht_gewaehrt'=>bool]]
    foreach ($checkboxen as $cb) {
        $t = docx_checkbox_tag_zerlegen($cb['tag']);
        if (!$t) continue;
        $proTag[$t['antrag_id']][$t['tag']][$t['art']] = $cb['checked'];
    }

    $ergebnis = [];
    foreach ($proTag as $antragId => $tage) {
        $z = ['gewaehrt' => false, 'nicht_gewaehrt' => false,
              'tage_gesamt' => 0, 'tage_gewaehrt' => 0, 'tage_abgelehnt' => 0, 'tage_beide' => 0, 'tage_offen' => 0];
        foreach ($tage as $cbTag) {
            $g = !empty($cbTag['gewaehrt']);
            $n = !empty($cbTag['nicht_gewaehrt']);
            $z['tage_gesamt']++;
            if ($g && $n)  $z['tage_beide']++;
            elseif ($g)    $z['tage_gewaehrt']++;
            elseif ($n)    $z['tage_abgelehnt']++;
            else           $z['tage_offen']++;
            $z['gewaehrt']       = $z['gewaehrt'] || $g;
            $z['nicht_gewaehrt'] = $z['nicht_gewaehrt'] || $n;
        }
        $ergebnis[$antragId] = $z;
    }
    return $ergebnis;
}
