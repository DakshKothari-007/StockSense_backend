<?php

/**
 * CLI Tool: review_intent_misses.php
 * 
 * Reviews low-confidence or fallback AI queries logged by Dukaan Mitra
 * to identify gaps and update concept synonym groups.
 * 
 * Usage:
 *   php cli/review_intent_misses.php [--limit=20] [--intent=<name>] [--min-count=1] [--clear]
 */

require_once __DIR__ . "/../config/db.php";

echo "=== DUKAAN MITRA INTENT LEARNING LOOP: REVIEW MISSES ===\n\n";

$options = getopt("", ["limit::", "intent::", "min-count::", "clear"]);
$limit = isset($options['limit']) ? (int)$options['limit'] : 25;
$intentFilter = $options['intent'] ?? null;
$minCount = isset($options['min-count']) ? (int)$options['min-count'] : 1;

if (isset($options['clear'])) {
    $count = $pdo->exec("DELETE FROM intent_misses");
    echo "Cleared {$count} recorded intent miss entries.\n";
    exit(0);
}

try {
    $totalCount = (int)$pdo->query("SELECT COUNT(*) FROM intent_misses")->fetchColumn();
    echo "Total logged low-confidence/missed queries: {$totalCount}\n";

    if ($totalCount === 0) {
        echo "No misses currently logged. Intent classification is operating with high confidence.\n";
        exit(0);
    }

    $sql = "
        SELECT 
            normalized_text,
            MAX(message_text) as sample_message,
            chosen_intent,
            AVG(confidence) as avg_confidence,
            layer_resolved,
            COUNT(*) as frequency,
            MAX(created_at) as last_seen
        FROM intent_misses
    ";

    $params = [];
    if (!empty($intentFilter)) {
        $sql .= " WHERE chosen_intent = :intent";
        $params[':intent'] = $intentFilter;
    }

    $sql .= "
        GROUP BY normalized_text, chosen_intent, layer_resolved
        HAVING COUNT(*) >= :min_count
        ORDER BY frequency DESC, avg_confidence ASC
        LIMIT :limit
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':min_count', $minCount, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo "No entries found matching filters.\n";
        exit(0);
    }

    echo "\nMost Common Queries Needing Synonym Tuning (Top " . count($rows) . "):\n";
    echo str_repeat("-", 105) . "\n";
    printf("%-5s | %-6s | %-16s | %-8s | %-10s | %-45s\n", "Freq", "Conf", "Chosen Intent", "Layer", "Last Seen", "Sample Query Text");
    echo str_repeat("-", 105) . "\n";

    foreach ($rows as $r) {
        $confStr = number_format((float)$r['avg_confidence'], 2);
        $sample = mb_strimwidth($r['sample_message'], 0, 45, '...');
        $lastSeen = substr($r['last_seen'], 0, 10);
        printf(
            "%-5d | %-6s | %-16s | %-8s | %-10s | %-45s\n",
            $r['frequency'],
            $confStr,
            $r['chosen_intent'],
            $r['layer_resolved'],
            $lastSeen,
            $sample
        );
    }
    echo str_repeat("-", 105) . "\n";
    echo "Tip: Add missing terms from sample queries into includes/IntentEngine.php concept groups.\n\n";

} catch (PDOException $e) {
    echo "Error reviewing intent misses: " . $e->getMessage() . "\n";
    exit(1);
}
