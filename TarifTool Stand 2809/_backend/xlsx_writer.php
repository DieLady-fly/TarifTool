<?php
// ============================================================
// _backend/xlsx_writer.php  –  Minimaler, abhängigkeitsfreier
// XLSX-Writer (kein Composer/PhpSpreadsheet nötig, funktioniert
// auf Strato-Standard-PHP mit ext-zip).
//
// Nutzung:
//   $xlsx = new SimpleXlsx();
//   $sheet = $xlsx->addSheet('Juni 2026');
//   $sheet->write(1, 1, 'Text', 'bold');
//   $xlsx->save('/tmp/datei.xlsx');
//
// Unterstützte Styles (siehe STYLES-Konstante unten):
//   'default', 'bold', 'title', 'red', 'red_bold', 'black_bold',
//   'center', 'center_bold', sowie umrandete Varianten für das
//   Tages-Raster: 'bordered', 'bold_bordered', 'red_bold_bordered',
//   'black_bold_bordered', 'center_bordered'
// ============================================================

final class SimpleXlsxSheet {
    public string $name;
    public array $cells = [];      // "R{row}C{col}" => ['v' => value, 'style' => key, 'type' => 's'|'n']
    public array $colWidths = [];  // col => width
    public array $rowHeights = []; // row => height

    public function __construct(string $name) {
        $this->name = $name;
    }

    public function write(int $row, int $col, $value, string $style = 'default'): void {
        $isNumeric = is_int($value) || is_float($value);
        $this->cells["R{$row}C{$col}"] = [
            'v'     => $value,
            'style' => $style,
            'type'  => $isNumeric ? 'n' : 's',
        ];
    }

    public function setColWidth(int $col, float $width): void {
        $this->colWidths[$col] = $width;
    }

    public function setRowHeight(int $row, float $height): void {
        $this->rowHeights[$row] = $height;
    }
}

final class SimpleXlsx {
    /** @var SimpleXlsxSheet[] */
    private array $sheets = [];
    private array $sharedStrings = [];
    private array $sharedStringIndex = [];

    // Reihenfolge hier bestimmt den cellXfs-Index (0-basiert) in styles.xml
    private const STYLES = [
        'default'     => 0,
        'bold'        => 1,
        'title'       => 2, // größer + fett
        'red'         => 3,
        'red_bold'    => 4,
        'black_bold'  => 5,
        'center'      => 6,
        'center_bold' => 7,
        // umrandet (dünner schwarzer Rahmen), für das Tages-Raster
        'bordered'           => 8,
        'bold_bordered'      => 9,
        'red_bold_bordered'  => 10,
        'black_bold_bordered'=> 11,
        'center_bordered'    => 12,
    ];

    public function addSheet(string $name): SimpleXlsxSheet {
        $sheet = new SimpleXlsxSheet($name);
        $this->sheets[] = $sheet;
        return $sheet;
    }

    private function sharedStringId(string $s): int {
        if (isset($this->sharedStringIndex[$s])) return $this->sharedStringIndex[$s];
        $id = count($this->sharedStrings);
        $this->sharedStrings[] = $s;
        $this->sharedStringIndex[$s] = $id;
        return $id;
    }

    private static function colLetter(int $col): string {
        $letter = '';
        while ($col > 0) {
            $mod = ($col - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $col = intdiv($col - $mod, 26);
        }
        return $letter;
    }

    private static function xmlEsc(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    public function save(string $path): void {
        if (file_exists($path)) @unlink($path);
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE) !== true) {
            throw new RuntimeException("Konnte XLSX-Datei nicht anlegen: {$path}");
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());

        // Sheets zuerst durchgehen (füllt sharedStrings), dann sharedStrings.xml schreiben
        $sheetXmls = [];
        foreach ($this->sheets as $i => $sheet) {
            $sheetXmls[$i] = $this->sheetXml($sheet);
        }
        $zip->addFromString('xl/sharedStrings.xml', $this->sharedStringsXml());

        foreach ($sheetXmls as $i => $xml) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $xml);
        }

        $zip->close();
    }

    private function contentTypesXml(): string {
        $overrides = '';
        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $overrides .= "<Override PartName=\"/xl/worksheets/sheet{$n}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . $overrides
            . '</Types>';
    }

    private function rootRelsXml(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml(): string {
        $sheetsXml = '';
        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $sheetsXml .= '<sheet name="' . self::xmlEsc($sheet->name) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheetsXml . '</sheets>'
            . '</workbook>';
    }

    private function workbookRelsXml(): string {
        $rels = '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $rels .= '<Relationship Id="rIdSS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
    }

    private function stylesXml(): string {
        // Fonts: 0 default, 1 bold, 2 title(bold 14), 3 red, 4 red bold, 5 black bold
        $fonts = '<fonts count="6">'
            . '<font><sz val="11"/><name val="Arial"/></font>'
            . '<font><sz val="11"/><b/><name val="Arial"/></font>'
            . '<font><sz val="14"/><b/><name val="Arial"/></font>'
            . '<font><sz val="11"/><color rgb="FFFF0000"/><name val="Arial"/></font>'
            . '<font><sz val="11"/><b/><color rgb="FFFF0000"/><name val="Arial"/></font>'
            . '<font><sz val="11"/><b/><color rgb="FF000000"/><name val="Arial"/></font>'
            . '</fonts>';
        $fills = '<fills count="2">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '</fills>';
        $borders = '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border>'
            . '<left style="thin"><color rgb="FF000000"/></left>'
            . '<right style="thin"><color rgb="FF000000"/></right>'
            . '<top style="thin"><color rgb="FF000000"/></top>'
            . '<bottom style="thin"><color rgb="FF000000"/></bottom>'
            . '<diagonal/>'
            . '</border>'
            . '</borders>';
        $cellStyleXfs = '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';

        // Reihenfolge MUSS zu self::STYLES passen
        $xfs = [
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>', // default
            '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>', // bold
            '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>', // title
            '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>', // red
            '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment horizontal="center" vertical="top"/></xf>', // red_bold
            '<xf numFmtId="0" fontId="5" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment horizontal="center" vertical="top"/></xf>', // black_bold
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"><alignment horizontal="center"/></xf>', // center
            '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment horizontal="center"/></xf>', // center_bold
            // ── umrandete Varianten (dünner schwarzer Rahmen, borderId=1) ──
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>', // bordered
            '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"/>', // bold_bordered
            '<xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"><alignment horizontal="center" vertical="top"/></xf>', // red_bold_bordered
            '<xf numFmtId="0" fontId="5" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"><alignment horizontal="center" vertical="top"/></xf>', // black_bold_bordered
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment horizontal="center"/></xf>', // center_bordered
        ];

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $fonts . $fills . $borders . $cellStyleXfs
            . '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs>'
            . '</styleSheet>';
    }

    private function sharedStringsXml(): string {
        $items = '';
        foreach ($this->sharedStrings as $s) {
            $items .= '<si><t xml:space="preserve">' . self::xmlEsc($s) . '</t></si>';
        }
        $count = count($this->sharedStrings);
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $count . '" uniqueCount="' . $count . '">'
            . $items . '</sst>';
    }

    private function sheetXml(SimpleXlsxSheet $sheet): string {
        // Spaltenbreiten
        $colsXml = '';
        if ($sheet->colWidths) {
            $colsXml = '<cols>';
            foreach ($sheet->colWidths as $col => $width) {
                $colsXml .= '<col min="' . $col . '" max="' . $col . '" width="' . $width . '" customWidth="1"/>';
            }
            $colsXml .= '</cols>';
        }

        // Zellen nach Zeile gruppieren
        $byRow = [];
        foreach ($sheet->cells as $key => $cell) {
            [, $rest] = explode('R', $key, 2);
            [$row, $col] = array_map('intval', explode('C', $rest));
            $byRow[$row][$col] = $cell;
        }
        ksort($byRow);

        $rowsXml = '';
        foreach ($byRow as $rowNum => $cols) {
            ksort($cols);
            $rowAttr = 'r="' . $rowNum . '"';
            if (isset($sheet->rowHeights[$rowNum])) {
                $rowAttr .= ' ht="' . $sheet->rowHeights[$rowNum] . '" customHeight="1"';
            }
            $cellsXml = '';
            foreach ($cols as $colNum => $cell) {
                $ref     = self::colLetter($colNum) . $rowNum;
                $styleId = self::STYLES[$cell['style']] ?? 0;
                if ($cell['type'] === 'n') {
                    $cellsXml .= '<c r="' . $ref . '" s="' . $styleId . '"><v>' . (string)$cell['v'] . '</v></c>';
                } else {
                    $sid = $this->sharedStringId((string)$cell['v']);
                    $cellsXml .= '<c r="' . $ref . '" t="s" s="' . $styleId . '"><v>' . $sid . '</v></c>';
                }
            }
            $rowsXml .= '<row ' . $rowAttr . '>' . $cellsXml . '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $colsXml
            . '<sheetData>' . $rowsXml . '</sheetData>'
            . '</worksheet>';
    }
}
