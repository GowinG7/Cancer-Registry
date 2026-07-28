<?php
/**
 * Minimal multi-sheet XLSX writer. Produces a real .xlsx file (Office Open XML)
 * using only ZipArchive, so no Composer packages are required.
 *
 * Usage:
 *   $xlsx = new XlsxWriter();
 *   $xlsx->addSheet('Patients', ['ID', 'Name'], [[1, 'Ram'], [2, 'Hari']]);
 *   $xlsx->download('patients.xlsx');
 */
class XlsxWriter
{
    /** @var array<int, array{name: string, rows: array<int, array<int, mixed>>}> */
    private $sheets = [];

    /**
     * Prepend a 1-based "S.N." column so exported sheets are numbered from 1.
     *
     * @param  int[] $numericColumns column indexes of the original table
     * @return array{0: string[], 1: array<int, array<int, mixed>>, 2: int[]}
     */
    public static function numbered(array $headers, array $rows, array $numericColumns)
    {
        array_unshift($headers, 'S.N.');

        $numbered = [];
        foreach (array_values($rows) as $index => $row) {
            $numbered[] = array_merge([$index + 1], array_values($row));
        }

        $shifted = [0];
        foreach ($numericColumns as $column) {
            $shifted[] = $column + 1;
        }

        return [$headers, $numbered, $shifted];
    }

    /**
     * @param string     $name           Sheet tab name (sanitised to Excel's rules).
     * @param string[]   $headers        Header row, rendered bold and frozen.
     * @param array      $rows           List of rows, each a list of scalar cell values.
     * @param int[]|null $numericColumns 0-based indexes to write as numbers. Everything
     *                                   else stays text, so phone numbers and IDs keep
     *                                   their leading zeros. Null = detect per value.
     */
    public function addSheet($name, array $headers, array $rows, array $numericColumns = null)
    {
        $all = [];
        if ($headers) {
            $all[] = $headers;
        }
        foreach ($rows as $row) {
            $all[] = array_values((array) $row);
        }
        $this->sheets[] = [
            'name' => $this->sheetName($name, count($this->sheets) + 1),
            'rows' => $all,
            'hasHeader' => (bool) $headers,
            'numeric' => $numericColumns === null ? null : array_flip($numericColumns),
        ];
    }

    public function save($path)
    {
        if (!$this->sheets) {
            $this->addSheet('Sheet1', [], []);
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create workbook at ' . $path);
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        foreach ($this->sheets as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->sheetXml($sheet));
        }
        $zip->close();
    }

    /** Stream the workbook to the browser as a download. */
    public function download($filename)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $this->save($tmp);
        if (!headers_sent()) {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
            header('Content-Length: ' . filesize($tmp));
            header('Cache-Control: max-age=0');
            header('Pragma: public');
        }
        readfile($tmp);
        unlink($tmp);
    }

    private function sheetName($name, $index)
    {
        $name = preg_replace('/[\\\\\/\?\*\[\]:]/', '-', (string) $name);
        $name = trim(mb_substr($name, 0, 31));
        return $name === '' ? 'Sheet' . $index : $name;
    }

    private function sheetXml(array $sheet)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($sheet['hasHeader']) {
            $xml .= '<sheetViews><sheetView workbookViewId="0">'
                . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
                . '</sheetView></sheetViews>';
        }
        $xml .= '<sheetData>';
        foreach ($sheet['rows'] as $r => $row) {
            $rowNo = $r + 1;
            $style = ($sheet['hasHeader'] && $r === 0) ? ' s="1"' : '';
            $xml .= '<row r="' . $rowNo . '">';
            foreach (array_values($row) as $c => $value) {
                $ref = $this->columnLetter($c) . $rowNo;
                if ($value === null || $value === '') {
                    $xml .= '<c r="' . $ref . '"' . $style . '/>';
                } elseif ($this->isNumeric($sheet, $c, $value)) {
                    $xml .= '<c r="' . $ref . '"' . $style . '><v>' . $value . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">'
                        . $this->escape($value) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        return $xml . '</sheetData></worksheet>';
    }

    private function isNumeric(array $sheet, $column, $value)
    {
        if (is_int($value) || is_float($value)) {
            return true;
        }
        if (!is_string($value) || !preg_match('/^-?(0|[1-9]\d{0,14})(\.\d+)?$/', $value)) {
            return false;
        }
        return $sheet['numeric'] === null || isset($sheet['numeric'][$column]);
    }

    private function escape($value)
    {
        $value = (string) $value;
        // Strip control characters Excel rejects.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function columnLetter($index)
    {
        $letter = '';
        for ($i = $index + 1; $i > 0; $i = intdiv($i - 1, 26)) {
            $letter = chr(65 + (($i - 1) % 26)) . $letter;
        }
        return $letter;
    }

    private function contentTypes()
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        foreach ($this->sheets as $i => $sheet) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml"'
                . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return $xml . '</Types>';
    }

    private function rootRels()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbook()
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        foreach ($this->sheets as $i => $sheet) {
            $xml .= '<sheet name="' . $this->escape($sheet['name']) . '" sheetId="' . ($i + 1)
                . '" r:id="rId' . ($i + 1) . '"/>';
        }
        return $xml . '</sheets></workbook>';
    }

    private function workbookRels()
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $count = count($this->sheets);
        foreach ($this->sheets as $i => $sheet) {
            $xml .= '<Relationship Id="rId' . ($i + 1) . '"'
                . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                . ' Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }
        return $xml . '<Relationship Id="rId' . ($count + 1) . '"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
            . ' Target="styles.xml"/></Relationships>';
    }

    private function styles()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}
