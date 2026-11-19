<?php

/**
 * SpreadsheetReader
 * Native reader for .csv and .xlsx files without external composer dependencies.
 * Uses ZipArchive and SimpleXML for Excel (.xlsx) and fgetcsv for CSV.
 */
class SpreadsheetReader {

    /**
     * Reads a spreadsheet file (.csv or .xlsx) and returns a 2D array of trimmed string values.
     *
     * @param string $filePath
     * @return array<int, array<int, string>>
     * @throws InvalidArgumentException|RuntimeException
     */
    public static function read(string $filePath): array {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("File not found: " . basename($filePath));
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            return self::readCsv($filePath);
        } elseif ($ext === 'xlsx') {
            return self::readXlsx($filePath);
        } else {
            throw new InvalidArgumentException("Unsupported file format: .{$ext}. Only .csv and .xlsx are supported.");
        }
    }

    /**
     * Reads first N rows and headers for quick preview.
     *
     * @param string $filePath
     * @param int $previewLimit
     * @return array{headers: array<string>, preview_rows: array<array<string>>, total_rows: int}
     */
    public static function getHeadersAndPreview(string $filePath, int $previewLimit = 5): array {
        $rows = self::read($filePath);
        if (empty($rows)) {
            return [
                'headers' => [],
                'preview_rows' => [],
                'total_rows' => 0
            ];
        }

        $extracted = self::extractHeaderAndRows($rows);
        $headers = $extracted['headers'];
        $dataRows = $extracted['rows'];
        $totalRows = count($dataRows);
        $preview = array_slice($dataRows, 0, $previewLimit);

        return [
            'headers' => $headers,
            'preview_rows' => $preview,
            'total_rows' => $totalRows
        ];
    }

    /**
     * Intelligently detects the header row from the first candidate rows (up to 10 rows).
     * Skips title rows, metadata rows, and notes above the table.
     *
     * @param array<int, array<int, string>> $allRows
     * @return array{headers: array<string>, rows: array<array<string>>, header_index: int}
     * @throws RuntimeException if header detection fails
     */
    public static function extractHeaderAndRows(array $allRows): array {
        if (empty($allRows)) {
            throw new RuntimeException("Spreadsheet contains no data rows.");
        }

        $maxScan = min(10, count($allRows));
        $bestRowIdx = -1;
        $bestScore = -100;

        // Common grocery / retail header keywords
        $headerKeywords = [
            'product', 'item', 'name', 'title', 'description', 'particulars',
            'category', 'type', 'dept', 'department', 'group',
            'selling', 'price', 'mrp', 'rate', 'retail', 'sp',
            'cost', 'purchase', 'buying', 'cp',
            'stock', 'qty', 'quantity', 'units', 'hand', 'count', 'balance', 'pcs',
            'sku', 'code', 'barcode', 'ean', 'hsn', 'upc', 'id',
            'min', 'minimum', 'threshold', 'level', 'reorder', 'alert'
        ];

        for ($i = 0; $i < $maxScan; $i++) {
            $row = $allRows[$i];
            $nonEmptyCells = [];
            foreach ($row as $cell) {
                $trimmed = trim((string)$cell);
                if ($trimmed !== '') {
                    $nonEmptyCells[] = $trimmed;
                }
            }

            $cellCount = count($nonEmptyCells);
            if ($cellCount < 2) {
                // Title row or single-cell comment; not the column header row
                continue;
            }

            $keywordMatches = 0;
            $textOnlyCount = 0;
            $numericCount = 0;

            foreach ($nonEmptyCells as $c) {
                $clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $c));
                $isNum = is_numeric(str_replace([',', ' '], '', $c));

                if ($isNum) {
                    $numericCount++;
                } else {
                    $textOnlyCount++;
                }

                foreach ($headerKeywords as $kw) {
                    if (strpos($clean, $kw) !== false) {
                        $keywordMatches++;
                        break;
                    }
                }
            }

            // High score for matching known header keywords and non-numeric labels
            // Penalty for numbers (data rows have prices / stock)
            $score = ($keywordMatches * 15) + ($textOnlyCount * 3) - ($numericCount * 6) + $cellCount;

            if ($score > $bestScore && ($keywordMatches >= 1 || ($textOnlyCount >= 2 && $numericCount == 0))) {
                $bestScore = $score;
                $bestRowIdx = $i;
            }
        }

        // Fallback: If no keywords matched, pick the first row with at least 2 non-empty cells where majority are text
        if ($bestRowIdx === -1) {
            for ($i = 0; $i < $maxScan; $i++) {
                $row = $allRows[$i];
                $nonEmpty = array_values(array_filter(array_map('trim', $row), fn($v) => $v !== ''));
                if (count($nonEmpty) >= 2) {
                    $textCount = count(array_filter($nonEmpty, fn($v) => !is_numeric(str_replace([',', ' '], '', $v))));
                    if ($textCount >= (count($nonEmpty) / 2)) {
                        $bestRowIdx = $i;
                        break;
                    }
                }
            }
        }

        if ($bestRowIdx === -1) {
            throw new RuntimeException("Header detection failed: Unable to detect column headers in spreadsheet. Please ensure your file contains column headers.");
        }

        $headerRow = $allRows[$bestRowIdx];
        $dataRows = array_slice($allRows, $bestRowIdx + 1);

        $headers = array_map(fn($h) => trim((string)$h), $headerRow);
        while (count($headers) > 0 && end($headers) === '') {
            array_pop($headers);
        }

        return [
            'headers' => $headers,
            'rows' => $dataRows,
            'header_index' => $bestRowIdx
        ];
    }

    /**
     * Parses CSV files with delimiter auto-detection and UTF-8 BOM removal.
     */
    private static function readCsv(string $filePath): array {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new RuntimeException("Could not open CSV file.");
        }

        // Check and strip UTF-8 BOM if present
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        // Detect delimiter by inspecting the first line
        $firstLine = fgets($handle);
        rewind($handle);
        if ($bom === "\xEF\xBB\xBF") {
            fread($handle, 3);
        }

        $delimiter = ",";
        if ($firstLine !== false) {
            $delimiters = [',', ';', "\t", '|'];
            $bestDelim = ',';
            $maxCount = 0;
            foreach ($delimiters as $d) {
                $count = substr_count($firstLine, $d);
                if ($count > $maxCount) {
                    $maxCount = $count;
                    $bestDelim = $d;
                }
            }
            $delimiter = $bestDelim;
        }

        $rows = [];
        while (($data = fgetcsv($handle, 10000, $delimiter)) !== false) {
            // Skip entirely empty lines
            $allEmpty = true;
            $cleanedRow = [];
            foreach ($data as $cell) {
                $val = trim((string)$cell);
                if ($val !== '') {
                    $allEmpty = false;
                }
                $cleanedRow[] = $val;
            }
            if (!$allEmpty) {
                $rows[] = $cleanedRow;
            }
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Parses XLSX files by extracting xl/sharedStrings.xml and sheet1.xml via ZipArchive.
     */
    private static function readXlsx(string $filePath): array {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException("PHP ZipArchive extension is required to parse .xlsx files.");
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new RuntimeException("Unable to open XLSX file as a valid zip archive.");
        }

        // 1. Read shared strings if present
        $sharedStrings = [];
        $sharedIndex = $zip->locateName('xl/sharedStrings.xml');
        if ($sharedIndex === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (stripos($zip->getNameIndex($i), 'sharedStrings.xml') !== false) {
                    $sharedIndex = $i;
                    break;
                }
            }
        }
        if ($sharedIndex !== false) {
            $xmlContent = $zip->getFromIndex($sharedIndex);
            if ($xmlContent !== false) {
                $xml = simplexml_load_string($xmlContent);
                if ($xml && isset($xml->si)) {
                    foreach ($xml->si as $si) {
                        if (isset($si->t)) {
                            $sharedStrings[] = (string)$si->t;
                        } elseif (isset($si->r)) {
                            $text = '';
                            foreach ($si->r as $r) {
                                $text .= (string)$r->t;
                            }
                            $sharedStrings[] = $text;
                        } else {
                            $sharedStrings[] = '';
                        }
                    }
                }
            }
        }

        // 2. Locate first worksheet
        $sheetIndex = $zip->locateName('xl/worksheets/sheet1.xml');
        if ($sheetIndex === false) {
            // Fallback: search for any worksheet in xl/worksheets/
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('/^xl\/worksheets\/sheet\d+\.xml$/i', $name)) {
                    $sheetIndex = $i;
                    break;
                }
            }
        }

        if ($sheetIndex === false) {
            $zip->close();
            throw new RuntimeException("No worksheet found inside XLSX file.");
        }

        $sheetXml = $zip->getFromIndex($sheetIndex);
        $zip->close();

        if ($sheetXml === false) {
            throw new RuntimeException("Unable to read worksheet content.");
        }

        $xml = simplexml_load_string($sheetXml);
        if (!$xml || !isset($xml->sheetData)) {
            return [];
        }

        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $rowData = [];
            $allEmpty = true;

            foreach ($row->c as $cell) {
                $cellRef = (string)$cell['r']; // e.g. A1, B2, AA5
                $colLetters = preg_replace('/[0-9]/', '', $cellRef);
                $colIndex = self::columnLetterToIndex($colLetters);

                $type = (string)$cell['t'];
                $val = isset($cell->v) ? (string)$cell->v : '';

                if ($type === 's' && is_numeric($val)) {
                    $stringIndex = (int)$val;
                    $cellValue = $sharedStrings[$stringIndex] ?? '';
                } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
                    $cellValue = (string)$cell->is->t;
                } else {
                    $cellValue = $val;
                }

                // Fill skipped empty columns
                while (count($rowData) < $colIndex) {
                    $rowData[] = '';
                }
                $trimmedVal = trim($cellValue);
                if ($trimmedVal !== '') {
                    $allEmpty = false;
                }
                $rowData[$colIndex] = $trimmedVal;
            }

            if (!$allEmpty && !empty($rowData)) {
                $rows[] = array_values($rowData);
            }
        }

        return $rows;
    }

    /**
     * Converts column letters like 'A', 'B', 'Z', 'AA' to 0-based index.
     */
    private static function columnLetterToIndex(string $col): int {
        $col = strtoupper(trim($col));
        $len = strlen($col);
        $index = 0;
        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($col[$i]) - ord('A') + 1);
        }
        return max(0, $index - 1);
    }
}
