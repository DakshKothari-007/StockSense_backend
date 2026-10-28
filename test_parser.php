<?php

class SpreadsheetReader {
    public static function read(string $filePath): array {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            return self::readCsv($filePath);
        } elseif ($ext === 'xlsx') {
            return self::readXlsx($filePath);
        } else {
            throw new InvalidArgumentException("Unsupported file format: .$ext. Only .csv and .xlsx are supported.");
        }
    }

    private static function readCsv(string $filePath): array {
        $rows = [];
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new RuntimeException("Could not open CSV file.");
        }

        // Check and strip UTF-8 BOM if present
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        while (($data = fgetcsv($handle, 10000, ",")) !== false) {
            // Trim all cells
            $rows[] = array_map(function($cell) {
                return trim((string)$cell);
            }, $data);
        }
        fclose($handle);
        return $rows;
    }

    private static function readXlsx(string $filePath): array {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new RuntimeException("Unable to open XLSX file as a valid zip archive.");
        }

        // 1. Read shared strings if exists
        $sharedStrings = [];
        $sharedIndex = $zip->locateName('xl/sharedStrings.xml', ZipArchive::FL_NODIR);
        if ($sharedIndex !== false) {
            $xmlContent = $zip->getFromIndex($sharedIndex);
            if ($xmlContent !== false) {
                $xml = simplexml_load_string($xmlContent);
                if ($xml && isset($xml->si)) {
                    foreach ($xml->si as $si) {
                        if (isset($si->t)) {
                            $sharedStrings[] = (string)$si->t;
                        } elseif (isset($si->r)) {
                            // Rich text runs
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

        // 2. Read first worksheet
        $sheetIndex = $zip->locateName('xl/worksheets/sheet1.xml', ZipArchive::FL_NODIR);
        if ($sheetIndex === false) {
            $zip->close();
            throw new RuntimeException("Worksheet sheet1.xml not found in XLSX file.");
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
            $maxColIndex = 0;
            
            foreach ($row->c as $cell) {
                $cellRef = (string)$cell['r']; // e.g. A1, B2
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

                // Fill gaps if any skipped columns
                while (count($rowData) < $colIndex) {
                    $rowData[] = '';
                }
                $rowData[$colIndex] = trim($cellValue);
            }
            $rows[] = array_values($rowData);
        }

        return $rows;
    }

    private static function columnLetterToIndex(string $col): int {
        $col = strtoupper($col);
        $len = strlen($col);
        $index = 0;
        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($col[$i]) - ord('A') + 1);
        }
        return $index - 1; // 0-indexed
    }
}

// Test with a sample CSV
$csvData = "SKU,Product,Quantity,Cost,Price,Category\nDAI-001,Amul Milk,60,27,30,Dairy\n";
file_put_contents(__DIR__ . '/test_sample.csv', $csvData);
$parsed = SpreadsheetReader::read(__DIR__ . '/test_sample.csv');
unlink(__DIR__ . '/test_sample.csv');
unlink(__FILE__);

echo "SpreadsheetReader Test Result:\n";
print_r($parsed);
