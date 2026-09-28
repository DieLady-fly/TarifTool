<?php
// ============================================================
// _backend/docx_writer.php
// Abhängigkeitsfreier DOCX-Generator für das LH-Formular
// "Antrag auf {CODE}-Freistellung" (Vorlage: 0Vordruck_FS.docx).
//
// Kein Composer/PhpSpreadsheet/PhpWord nötig - reines OOXML via
// ZipArchive, analog zu xlsx_writer.php.
//
// Checkboxen ("gewährt" / "nicht gewährt") werden als ECHTE Word-
// Formularsteuerelemente erzeugt (w14:checkbox content control,
// exakt dieselbe Struktur wie im Muster-Dokument), nicht nur als
// Unicode-Zeichen - lassen sich also in Word tatsächlich anklicken.
// ============================================================

final class SimpleDocx {
    private static int $sdtIdCounter = 100000;

    private static function xmlEsc(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function nextSdtId(): int {
        // Word erwartet plausible (auch negative) 32-Bit-Ids, Hauptsache
        // pro Dokument eindeutig.
        return self::$sdtIdCounter++;
    }

    /**
     * Ein Textlauf (Run) mit optionaler Formatierung.
     * $opts: bold, underline, color (Hex ohne #), size (Halbpunkte, Standard 20 = 10pt)
     */
    private static function run(string $text, array $opts = []): string {
        $rpr = '';
        if (!empty($opts['bold']))      $rpr .= '<w:b/>';
        if (!empty($opts['underline'])) $rpr .= '<w:u w:val="single"/>';
        if (!empty($opts['color']))     $rpr .= '<w:color w:val="' . $opts['color'] . '"/>';
        if (!empty($opts['hidden']))    $rpr .= '<w:vanish/>';
        $rpr .= '<w:sz w:val="' . ($opts['size'] ?? 20) . '"/><w:szCs w:val="' . ($opts['size'] ?? 20) . '"/>';
        $rprXml = $rpr ? "<w:rPr>{$rpr}</w:rPr>" : '';
        // Zeilenumbrüche innerhalb eines Runs als <w:br/> abbilden
        $lines = explode("\n", $text);
        $tXml = '';
        foreach ($lines as $i => $line) {
            if ($i > 0) $tXml .= '<w:br/>';
            $tXml .= '<w:t xml:space="preserve">' . self::xmlEsc($line) . '</w:t>';
        }
        return "<w:r>{$rprXml}{$tXml}</w:r>";
    }

    /**
     * Ein Absatz. $runs = Array von ['text'=>.., 'bold'=>.., ...] ODER ein
     * einzelner String (Kurzform).
     */
    private static function paragraph($runs, array $pOpts = []): string {
        if (is_string($runs)) $runs = [['text' => $runs]];
        $align = $pOpts['align'] ?? null;
        $ppr = '<w:spacing w:after="' . ($pOpts['spaceAfter'] ?? 120) . '" w:line="240" w:lineRule="auto"/>';
        if ($align) $ppr .= '<w:jc w:val="' . $align . '"/>';
        $runsXml = '';
        foreach ($runs as $r) {
            $runsXml .= self::run($r['text'], $r);
        }
        return "<w:p><w:pPr>{$ppr}</w:pPr>{$runsXml}</w:p>";
    }

    /**
     * Eine echte Word-Checkbox als Formular-Content-Control.
     * Wird als eigenständiges <w:p> zurückgegeben (innerhalb einer <w:sdt>),
     * zentriert.
     * $tag: optionaler unsichtbarer Bezeichner (z.B. "gewaehrt:1234"), der
     *   im Dokument gespeichert wird (<w:tag>) und beim Wiedereinlesen einer
     *   ausgefüllten Kopie erhalten bleibt - auch wenn der Nutzer nur die
     *   Checkbox anklickt. Damit lässt sich jede Checkbox beim Upload
     *   eindeutig einem Antrag zuordnen, ohne dass im sichtbaren Text etwas
     *   dafür stehen muss.
     */
    private static function checkboxParagraph(?string $tag = null): string {
        $id = self::nextSdtId();
        $tagXml = $tag !== null ? '<w:tag w:val="' . self::xmlEsc($tag) . '"/>' : '';
        return '<w:sdt>'
            . '<w:sdtPr>'
            . '<w:id w:val="' . $id . '"/>'
            . $tagXml
            . '<w14:checkbox>'
            . '<w14:checked w14:val="0"/>'
            . '<w14:checkedState w14:val="2612" w14:font="MS Gothic"/>'
            . '<w14:uncheckedState w14:val="2610" w14:font="MS Gothic"/>'
            . '</w14:checkbox>'
            . '</w:sdtPr>'
            . '<w:sdtEndPr/>'
            . '<w:sdtContent>'
            . '<w:p><w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/><w:jc w:val="center"/></w:pPr>'
            . '<w:r><w:rPr><w:rFonts w:ascii="MS Gothic" w:eastAsia="MS Gothic" w:hAnsi="MS Gothic" w:cs="Arial"/></w:rPr><w:t>☐</w:t></w:r>'
            . '</w:p>'
            . '</w:sdtContent>'
            . '</w:sdt>';
    }

    /**
     * Eine Tabellenzelle.
     * $content: String (einfacher Text) ODER Array von Absätzen (bereits
     *   fertiges <w:p>...</w:p> XML, z.B. via self::paragraph()) ODER
     *   ['checkbox' => true] für eine echte Word-Checkbox-Zelle.
     * $opts: width (twips), span (gridSpan), shade (Hex), valign,
     *   bold, color (für einfache String-Inhalte)
     */
    private static function cell($content, array $opts = []): string {
        $tcPr = '<w:tcW w:w="' . ($opts['width'] ?? 1000) . '" w:type="dxa"/>';
        if (!empty($opts['span']))  $tcPr .= '<w:gridSpan w:val="' . $opts['span'] . '"/>';
        if (!empty($opts['shade'])) $tcPr .= '<w:shd w:val="clear" w:color="auto" w:fill="' . $opts['shade'] . '"/>';
        if (!empty($opts['valign'])) $tcPr .= '<w:vAlign w:val="' . $opts['valign'] . '"/>';
        $tcPr = "<w:tcPr>{$tcPr}</w:tcPr>";

        if (is_array($content) && !empty($content['checkbox'])) {
            $body = self::checkboxParagraph($content['tag'] ?? null);
        } elseif (is_string($content)) {
            if ($content === '') {
                $body = self::paragraph('');
            } else {
                $body = self::paragraph([['text' => $content, 'bold' => $opts['bold'] ?? false, 'color' => $opts['color'] ?? null]]);
            }
        } else {
            // Array von bereits fertigen <w:p>-Strings
            $body = implode('', $content);
        }

        return "<w:tc>{$tcPr}{$body}</w:tc>";
    }

    /**
     * Eine ganze Tabellenzeile. $cells = Array von Rückgabewerten aus self::cell().
     */
    private static function row(array $cellsXml): string {
        return '<w:tr>' . implode('', $cellsXml) . '</w:tr>';
    }

    /**
     * Komplette Tabelle.
     * $colWidths: Array von Spaltenbreiten in Twips (1440 = 1 Zoll).
     * $rowsXml: Array von self::row()-Ergebnissen.
     * $borders: true = dünner schwarzer Rahmen überall (Standard), false = ohne.
     */
    private static function table(array $colWidths, array $rowsXml, bool $borders = true): string {
        $tblW = array_sum($colWidths);
        $gridXml = '';
        foreach ($colWidths as $w) $gridXml .= '<w:gridCol w:w="' . $w . '"/>';

        $borderXml = $borders
            ? '<w:tblBorders>'
              . '<w:top w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
              . '<w:left w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
              . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
              . '<w:right w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
              . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
              . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
              . '</w:tblBorders>'
            : '';

        return '<w:tbl>'
            . '<w:tblPr><w:tblW w:w="' . $tblW . '" w:type="dxa"/>' . $borderXml . '<w:tblLayout w:type="fixed"/></w:tblPr>'
            . '<w:tblGrid>' . $gridXml . '</w:tblGrid>'
            . implode('', $rowsXml)
            . '</w:tbl>';
    }

    // ── Öffentliche Bau-Helfer (werden vom Formular-Builder genutzt) ──
    public static function pText(string $text, array $opts = []): string { return self::paragraph([array_merge(['text' => $text], $opts)], $opts); }
    public static function pEmpty(): string { return self::paragraph(''); }
    /**
     * Vollständig unsichtbarer Absatz (Word-"ausgeblendeter Text", w:vanish) -
     * wird weder am Bildschirm noch beim Drucken angezeigt, bleibt aber beim
     * Öffnen/Speichern/Bearbeiten in Word erhalten (anders als der Inhalt
     * einer Checkbox-w:sdt, die z.B. per "Inhaltssteuerelement entfernen"
     * verloren gehen kann). Dient als robuster Cross-Check: eine Liste
     * aller im Dokument erzeugten Antrags-IDs, unabhängig von den
     * einzelnen Checkboxen selbst.
     */
    public static function pHiddenMarker(string $text): string {
        return self::paragraph([['text' => $text, 'hidden' => true]]);
    }
    public static function tCell($content, array $opts = []): string { return self::cell($content, $opts); }
    public static function tCheckboxCell(array $opts = [], ?string $tag = null): string { return self::cell(['checkbox' => true, 'tag' => $tag], $opts); }
    public static function tRow(array $cells): string { return self::row($cells); }
    public static function tTable(array $colWidths, array $rows, bool $borders = true): string { return self::table($colWidths, $rows, $borders); }

    /**
     * Baut das komplette Dokument aus einem Array bereits fertiger
     * Block-Elemente (Absätze/Tabellen als XML-Strings) und speichert es.
     */
    public static function save(array $blocks, string $path): void {
        if (file_exists($path)) @unlink($path);

        $body = implode('', $blocks);
        // Seitenränder + Papiergröße (A4, ~2cm Rand)
        $sectPr = '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
                . '<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="708" w:footer="708" w:gutter="0"/>'
                . '</w:sectPr>';

        $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document '
            . 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            . 'xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml" '
            . 'xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" '
            . 'mc:Ignorable="w14">'
            . '<w:body>' . $body . $sectPr . '</w:body>'
            . '</w:document>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE) !== true) {
            throw new RuntimeException("Konnte DOCX-Datei nicht anlegen: {$path}");
        }
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rootRels);
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->close();
    }
}
