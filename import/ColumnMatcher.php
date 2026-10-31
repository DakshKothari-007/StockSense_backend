<?php

/**
 * ColumnMatcher
 * Provides intelligent, fuzzy, case-insensitive, and punctuation-ignoring
 * column header detection, data value heuristics, stable SKU generation,
 * and per-shop saved mapping persistence for supermarket inventory imports.
 */
class ColumnMatcher {

    /**
     * Comprehensive synonyms dictionary for supermarket column names.
     */
    private static $fieldSynonyms = [
        'name' => [
            'product name', 'item name', 'item description', 'product description',
            'product', 'item', 'name', 'description', 'title', 'particulars',
            'article name', 'goods description', 'product title', 'item title',
            'item details', 'prod name', 'item desc', 'prod desc', 'descr'
        ],
        'sku' => [
            'sku', 'code', 'item code', 'product code', 'barcode', 'ean',
            'ean code', 'hsn', 'hsn code', 'upc', 'article no', 'article number',
            'item id', 'gtin', 'sku code', 'product id', 'item no', 'part no',
            'bar code', 'itemcode', 'prodcode'
        ],
        'category' => [
            'category', 'type', 'group', 'department', 'dept', 'product category',
            'classification', 'family', 'section', 'item group', 'cat',
            'sub category', 'sub cat', 'prod group'
        ],
        'units' => [
            'quantity', 'qty', 'units', 'stock', 'opening stock', 'qty on hand',
            'quantity on hand', 'stock in hand', 'closing stock', 'current stock',
            'balance stock', 'balance', 'pcs', 'count', 'physical stock',
            'units to add', 'add stock', 'available qty', 'on hand', 'qnty',
            'stock qty', 'total stock', 'units in stock'
        ],
        'cost_price' => [
            'cost price', 'cost', 'purchase price', 'buying price', 'purchase rate',
            'buy rate', 'unit cost', 'cp', 'landed cost', 'cost rate', 'purchase',
            'base cost', 'buy price', 'purchase cost', 'item cost'
        ],
        'price' => [
            'selling price', 'sale price', 'price', 'mrp', 'rate', 'selling rate',
            'sale rate', 'unit price', 'retail price', 'sp', 'sales price',
            'sales rate', 'selling', 'retail rate', 'unit rate', 'item price',
            'sale mrp', 'mrp rate'
        ],
        'minimum_stock' => [
            'minimum stock', 'min stock', 'reorder level', 'threshold', 'min level',
            'rop', 'safety stock', 'alert qty', 'low stock threshold', 'min qty',
            'reorder point', 'min threshold', 'alert level', 'warning level'
        ]
    ];

    /**
     * Normalizes a header string: lowercases and removes all spaces, dashes, and underscores.
     */
    public static function normalize(string $header): string {
        return strtolower(preg_replace('/[\s\-_]+/', '', trim($header)));
    }

    /**
     * Computes similarity between two normalized strings (0.0 to 1.0).
     */
    public static function computeSimilarity(string $s1, string $s2): float {
        $n1 = self::normalize($s1);
        $n2 = self::normalize($s2);

        if ($n1 === $n2) return 1.0;
        if ($n1 === '' || $n2 === '') return 0.0;

        // Substring containment check with length ratio
        if (strpos($n1, $n2) !== false || strpos($n2, $n1) !== false) {
            $minLen = min(strlen($n1), strlen($n2));
            $maxLen = max(strlen($n1), strlen($n2));
            if ($minLen >= 3) {
                return round($minLen / $maxLen, 3);
            }
        }

        similar_text($n1, $n2, $percent);
        return round($percent / 100.0, 3);
    }

    /**
     * Computes a stable hash for a list of headers to identify identical spreadsheet formats.
     */
    public static function computeHeadersHash(array $headers): string {
        $normalized = array_map([self::class, 'normalize'], $headers);
        return hash('sha256', implode('|', $normalized));
    }

    /**
     * Retrieves saved column mapping for a shop owner and header format.
     */
    public static function getSavedMapping(PDO $pdo, int $ownerId, array $headers): ?array {
        try {
            $hash = self::computeHeadersHash($headers);
            $stmt = $pdo->prepare("SELECT mapping FROM import_mappings WHERE shop_owner_id = ? AND headers_hash = ? LIMIT 1");
            $stmt->execute([$ownerId, $hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['mapping'])) {
                $decoded = json_decode($row['mapping'], true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (Throwable $e) {
            error_log("Failed to get saved import mapping: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Persists the user's final column mapping for future imports of identical spreadsheets.
     */
    public static function saveMapping(PDO $pdo, int $ownerId, array $headers, array $mapping): bool {
        try {
            $hash = self::computeHeadersHash($headers);
            $raw = json_encode($headers);
            $mapJson = json_encode($mapping);
            $stmt = $pdo->prepare("
                INSERT INTO import_mappings (shop_owner_id, headers_hash, raw_headers, mapping)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE mapping = VALUES(mapping), raw_headers = VALUES(raw_headers), updated_at = CURRENT_TIMESTAMP
            ");
            return $stmt->execute([$ownerId, $hash, $raw, $mapJson]);
        } catch (Throwable $e) {
            error_log("Failed to save import mapping: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generates a stable, deterministic SKU for a product when no SKU is provided.
     */
    public static function generateStableSku(int $ownerId, string $productName): string {
        $cleanPrefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $productName));
        if (strlen($cleanPrefix) < 3) {
            $cleanPrefix = str_pad($cleanPrefix, 3, 'X');
        } else {
            $cleanPrefix = substr($cleanPrefix, 0, 4);
        }
        $hash = strtoupper(substr(md5($ownerId . ":" . strtolower(trim($productName))), 0, 5));
        return "SKU-{$cleanPrefix}-{$hash}";
    }

    /**
     * Analyzes up to 20 sample rows for a column to inspect data characteristics.
     */
    public static function inspectSampleValues(array $sampleRows, int $colIdx): array {
        $values = [];
        $sampleCount = min(20, count($sampleRows));
        for ($i = 0; $i < $sampleCount; $i++) {
            if (isset($sampleRows[$i][$colIdx])) {
                $v = trim((string)$sampleRows[$i][$colIdx]);
                if ($v !== '') {
                    $values[] = $v;
                }
            }
        }

        $count = count($values);
        if ($count === 0) {
            return [
                'count' => 0,
                'int_ratio' => 0.0,
                'decimal_ratio' => 0.0,
                'avg_val' => 0.0,
                'has_spaces' => false,
                'short_code_ratio' => 0.0
            ];
        }

        $intCount = 0;
        $decCount = 0;
        $numCount = 0;
        $sumVal = 0.0;
        $spacesCount = 0;
        $shortCodeCount = 0;

        foreach ($values as $v) {
            $cleanNum = preg_replace('/[^0-9.]/', '', $v);
            if (preg_match('/^\d+$/', $v)) {
                $intCount++;
            }
            if (is_numeric($cleanNum) && $cleanNum !== '') {
                $decCount++;
                $sumVal += (float)$cleanNum;
                $numCount++;
            }
            if (strpos($v, ' ') !== false) {
                $spacesCount++;
            }
            if (preg_match('/^[A-Za-z0-9\-_]{3,20}$/', $v) && strpos($v, ' ') === false) {
                $shortCodeCount++;
            }
        }

        return [
            'count' => $count,
            'int_ratio' => $intCount / $count,
            'decimal_ratio' => $decCount / $count,
            'avg_val' => $numCount > 0 ? ($sumVal / $numCount) : 0.0,
            'has_spaces' => ($spacesCount / $count) > 0.25,
            'short_code_ratio' => $shortCodeCount / $count
        ];
    }

    /**
     * Intelligent detection of column mappings:
     * 1. Checks saved mapping for this shop and header set.
     * 2. Header synonym and fuzzy matching.
     * 3. Sample data heuristics for tie-breaking and unassigned columns.
     */
    public static function detect(array $headers, array $sampleRows = [], ?PDO $pdo = null, ?int $ownerId = null): array {
        // Step 1: Check saved mapping
        if ($pdo !== null && $ownerId !== null) {
            $saved = self::getSavedMapping($pdo, $ownerId, $headers);
            if ($saved !== null) {
                $conf = [];
                foreach ($saved as $k => $v) {
                    $conf[$k] = ($v !== null && $v !== '') ? 1.0 : 0.0;
                }
                $matchedCount = count(array_filter($saved, function($v) { return $v !== null && $v !== ''; }));
                $reqMatched = !empty($saved['name']) && !empty($saved['units']);
                return [
                    'mappings' => $saved,
                    'confidence' => $conf,
                    'matched_count' => $matchedCount,
                    'total_fields' => 7,
                    'is_saved' => true,
                    'required_matched' => $reqMatched
                ];
            }
        }

        $detected = [
            'name' => null,
            'sku' => null,
            'units' => null,
            'cost_price' => null,
            'price' => null,
            'category' => null,
            'minimum_stock' => null
        ];
        $confidences = [
            'name' => 0.0,
            'sku' => 0.0,
            'units' => 0.0,
            'cost_price' => 0.0,
            'price' => 0.0,
            'category' => 0.0,
            'minimum_stock' => 0.0
        ];

        // Step 2: Scoring each column against field synonyms
        $scores = [];
        foreach (self::$fieldSynonyms as $field => $synonyms) {
            usort($synonyms, function($a, $b) {
                return strlen($b) - strlen($a);
            });

            foreach ($headers as $colIdx => $h) {
                $normH = self::normalize($h);
                if ($normH === '') continue;

                $best = 0.0;
                foreach ($synonyms as $syn) {
                    $normSyn = self::normalize($syn);
                    if ($normH === $normSyn) {
                        $best = 1.0;
                        break;
                    }
                    $sim = self::computeSimilarity($normH, $normSyn);
                    if ($sim > $best) {
                        $best = $sim;
                    }
                }

                // Minimum confidence threshold of 0.70
                if ($best >= 0.70) {
                    $scores[$field][$colIdx] = $best;
                }
            }
        }

        // Step 3: Resolve competitions in prioritized field order
        $fieldOrder = ['name', 'units', 'price', 'cost_price', 'sku', 'category', 'minimum_stock'];
        $usedCols = [];

        foreach ($fieldOrder as $field) {
            if (empty($scores[$field])) continue;

            arsort($scores[$field]);

            foreach ($scores[$field] as $colIdx => $score) {
                if (!in_array($colIdx, $usedCols, true)) {
                    // Cost price vs Price data check: price is typically >= cost price
                    if ($field === 'price' && isset($detected['cost_price']) && !empty($sampleRows)) {
                        $prevCol = array_search('cost_price', $detected, true);
                        if ($prevCol !== false) {
                            $stPrev = self::inspectSampleValues($sampleRows, $prevCol);
                            $stCurr = self::inspectSampleValues($sampleRows, $colIdx);
                            if ($stPrev['avg_val'] > $stCurr['avg_val'] && $stCurr['avg_val'] > 0) {
                                // Swap cost and price
                                $detected['cost_price'] = $headers[$colIdx];
                                $detected['price'] = $headers[$prevCol];
                                $confidences['cost_price'] = round($score, 2);
                                $usedCols[] = $colIdx;
                                break;
                            }
                        }
                    }

                    $detected[$field] = $headers[$colIdx];
                    $confidences[$field] = round($score, 2);
                    $usedCols[] = $colIdx;
                    break;
                }
            }
        }

        // Step 4: Data heuristics for unassigned fields if sample rows are available
        if (!empty($sampleRows)) {
            // If name is not assigned, look for text column with spaces
            if ($detected['name'] === null) {
                foreach ($headers as $colIdx => $h) {
                    if (in_array($colIdx, $usedCols, true)) continue;
                    $st = self::inspectSampleValues($sampleRows, $colIdx);
                    if ($st['has_spaces'] && $st['int_ratio'] < 0.2) {
                        $detected['name'] = $h;
                        $confidences['name'] = 0.75;
                        $usedCols[] = $colIdx;
                        break;
                    }
                }
            }

            // If units is not assigned, look for integer numeric column
            if ($detected['units'] === null) {
                foreach ($headers as $colIdx => $h) {
                    if (in_array($colIdx, $usedCols, true)) continue;
                    $st = self::inspectSampleValues($sampleRows, $colIdx);
                    if ($st['int_ratio'] >= 0.75 && $st['avg_val'] > 0 && $st['avg_val'] < 100000) {
                        $detected['units'] = $h;
                        $confidences['units'] = 0.75;
                        $usedCols[] = $colIdx;
                        break;
                    }
                }
            }

            // If sku is not assigned, look for barcode / short code column
            if ($detected['sku'] === null) {
                foreach ($headers as $colIdx => $h) {
                    if (in_array($colIdx, $usedCols, true)) continue;
                    $st = self::inspectSampleValues($sampleRows, $colIdx);
                    if ($st['short_code_ratio'] >= 0.8 && !$st['has_spaces']) {
                        $detected['sku'] = $h;
                        $confidences['sku'] = 0.75;
                        $usedCols[] = $colIdx;
                        break;
                    }
                }
            }
        }

        $matchedCount = count(array_filter($detected, function($v) { return $v !== null && $v !== ''; }));
        $requiredMatched = (!empty($detected['name']) && !empty($detected['units']));

        return [
            'mappings' => $detected,
            'confidence' => $confidences,
            'matched_count' => $matchedCount,
            'total_fields' => 7,
            'is_saved' => false,
            'required_matched' => $requiredMatched
        ];
    }

    /**
     * Resolves a column index from user mapping or auto-detection.
     */
    public static function resolveColumnIndex(string $field, $targetHeaderOrIndex, array $headerRow, bool $allowAutoDetect = true): ?int {
        // 1. If explicit user mapping is provided, locate it in the header row
        if ($targetHeaderOrIndex !== null && $targetHeaderOrIndex !== '') {
            if (is_numeric($targetHeaderOrIndex) && isset($headerRow[(int)$targetHeaderOrIndex])) {
                return (int)$targetHeaderOrIndex;
            }
            $targetNorm = self::normalize((string)$targetHeaderOrIndex);
            foreach ($headerRow as $idx => $h) {
                if (self::normalize($h) === $targetNorm) {
                    return $idx;
                }
            }
            return null;
        }

        if (!$allowAutoDetect) {
            return null;
        }

        // 2. Auto-detect from synonyms
        $synonyms = self::$fieldSynonyms[$field] ?? [];
        usort($synonyms, function($a, $b) {
            return strlen($b) - strlen($a);
        });

        foreach ($headerRow as $idx => $h) {
            $normH = self::normalize($h);
            foreach ($synonyms as $syn) {
                if ($normH === self::normalize($syn)) {
                    return $idx;
                }
            }
        }

        return null;
    }
}
