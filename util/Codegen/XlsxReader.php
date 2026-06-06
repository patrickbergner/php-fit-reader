<?php

declare(strict_types=1);

namespace Emontis\FitReader\Util\Codegen;

/**
 * Minimal XLSX (OOXML) reader. Pulls each named sheet out as a 2-D array
 * of cell strings, with empty cells as ''. Numeric cells are returned as
 * their string representation so callers can decide how to interpret them.
 *
 * Doesn't support: formulas (returns the cached value), styles, dates as
 * such (returns the raw serial number), inline rich text (collapsed to
 * plain text).
 */
final class XlsxReader
{
    private \ZipArchive $zip;

    /** @var string[] shared string table */
    private array $sst = [];

    /** @var array<string, string>  sheet name → internal path (xl/worksheets/…) */
    private array $sheetPaths = [];

    public function __construct(string $path)
    {
        $this->zip = new \ZipArchive();
        if ($this->zip->open($path) !== true) {
            throw new \RuntimeException("Cannot open XLSX: {$path}");
        }
        $this->loadSharedStrings();
        $this->loadSheetIndex();
    }

    /** @return string[] */
    public function sheetNames(): array
    {
        return array_keys($this->sheetPaths);
    }

    /**
     * Returns the sheet as rows of column-indexed cell strings (col index is
     * 0-based, matching column A → 0).
     *
     * @return array<int, array<int, string>>
     */
    public function sheet(string $name): array
    {
        if (!isset($this->sheetPaths[$name])) {
            throw new \InvalidArgumentException("No such sheet: {$name}. Available: " . implode(', ', $this->sheetNames()));
        }

        $xml = $this->zip->getFromName($this->sheetPaths[$name]);
        if ($xml === false) {
            throw new \RuntimeException("Cannot read sheet {$name}");
        }

        $dom = new \DOMDocument();
        $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $rows = [];
        /** @var \DOMElement $rowEl */
        foreach ($xp->query('//s:sheetData/s:row') ?: [] as $rowEl) {
            $rowIdxAttr = $rowEl->getAttribute('r');
            $rowIdx = $rowIdxAttr === '' ? count($rows) : ((int) $rowIdxAttr) - 1;
            $row = [];
            /** @var \DOMElement $cellEl */
            foreach ($xp->query('s:c', $rowEl) ?: [] as $cellEl) {
                $ref = $cellEl->getAttribute('r');
                $col = self::columnIndex(self::columnFromRef($ref));
                $row[$col] = $this->cellValue($cellEl, $xp);
            }
            $rows[$rowIdx] = $row;
        }

        // Normalize to dense 0-based arrays.
        ksort($rows);
        $normalized = [];
        foreach ($rows as $row) {
            ksort($row);
            $maxCol = empty($row) ? -1 : max(array_keys($row));
            $dense = [];
            for ($c = 0; $c <= $maxCol; $c++) {
                $dense[$c] = $row[$c] ?? '';
            }
            $normalized[] = $dense;
        }
        return $normalized;
    }

    public function close(): void
    {
        $this->zip->close();
    }

    private function cellValue(\DOMElement $cell, \DOMXPath $xp): string
    {
        $type = $cell->getAttribute('t');

        if ($type === 'inlineStr') {
            return self::textOf($xp->query('.//s:t', $cell));
        }

        $vNodes = $xp->query('s:v', $cell);
        $vNode  = $vNodes === false ? null : $vNodes->item(0);
        if (!$vNode instanceof \DOMNode) {
            return '';
        }
        $v = $vNode->textContent;

        if ($type === 's') {
            $idx = (int) $v;
            return $this->sst[$idx] ?? '';
        }
        // 'b' = boolean, 'str' = formula string, '' = numeric/general
        return $v;
    }

    /**
     * Concatenate the text content of every DOMNode in an xpath result,
     * tolerating the `false` a query can return and skipping namespace nodes.
     *
     * @param \DOMNodeList<\DOMNode|\DOMNameSpaceNode>|false $nodes
     */
    private static function textOf(\DOMNodeList|false $nodes): string
    {
        if ($nodes === false) {
            return '';
        }
        $text = '';
        foreach ($nodes as $n) {
            if ($n instanceof \DOMNode) {
                $text .= $n->textContent;
            }
        }
        return $text;
    }

    private function loadSharedStrings(): void
    {
        $xml = $this->zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return;
        }
        $dom = new \DOMDocument();
        $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $siList = $xp->query('//s:si');
        if ($siList === false) {
            return;
        }
        foreach ($siList as $si) {
            if ($si instanceof \DOMElement) {
                $this->sst[] = self::textOf($xp->query('.//s:t', $si));
            }
        }
    }

    private function loadSheetIndex(): void
    {
        $workbook = $this->zip->getFromName('xl/workbook.xml');
        $rels     = $this->zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false || $rels === false) {
            throw new \RuntimeException('XLSX missing workbook.xml or its relationships');
        }

        $relsDom = new \DOMDocument();
        $relsDom->loadXML($rels, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        $relsXp = new \DOMXPath($relsDom);
        $relsXp->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $relIdToTarget = [];
        foreach ($relsXp->query('//r:Relationship') ?: [] as $rel) {
            /** @var \DOMElement $rel */
            $relIdToTarget[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
        }

        $wbDom = new \DOMDocument();
        $wbDom->loadXML($workbook, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        $wbXp = new \DOMXPath($wbDom);
        $wbXp->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $wbXp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        foreach ($wbXp->query('//s:sheets/s:sheet') ?: [] as $sheet) {
            /** @var \DOMElement $sheet */
            $name = $sheet->getAttribute('name');
            $rid  = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $target = $relIdToTarget[$rid] ?? null;
            if ($target === null) {
                continue;
            }
            // Targets are relative to xl/ — normalize to a full archive path.
            $path = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
            $this->sheetPaths[$name] = $path;
        }
    }

    private static function columnFromRef(string $ref): string
    {
        $col = '';
        $len = strlen($ref);
        for ($i = 0; $i < $len; $i++) {
            $ch = $ref[$i];
            if ($ch >= 'A' && $ch <= 'Z') {
                $col .= $ch;
            } elseif ($ch >= 'a' && $ch <= 'z') {
                $col .= strtoupper($ch);
            } else {
                break;
            }
        }
        return $col === '' ? 'A' : $col;
    }

    private static function columnIndex(string $col): int
    {
        $n = 0;
        $len = strlen($col);
        for ($i = 0; $i < $len; $i++) {
            $n = $n * 26 + (ord($col[$i]) - 64);
        }
        return $n - 1;
    }
}
