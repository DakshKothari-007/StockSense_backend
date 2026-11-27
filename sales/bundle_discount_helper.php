<?php

/**
 * Shared bundle offer discount calculation used by sales/preview_offers.php and sales/create.php.
 */

/**
 * Validates removed_offer_ids belong to $shopOwnerId and returns sanitized int IDs.
 * Throws InvalidArgumentException on invalid ID or cross-shop ID.
 */
function validate_removed_offer_ids(PDO $pdo, int $shopOwnerId, $rawRemoved): array {
    if ($rawRemoved === null) {
        return [];
    }
    if (!is_array($rawRemoved)) {
        throw new InvalidArgumentException("removed_offer_ids must be an array.");
    }
    $ids = [];
    foreach ($rawRemoved as $val) {
        if (!is_numeric($val) || (int)$val <= 0) {
            throw new InvalidArgumentException("Each removed_offer_id must be a positive integer.");
        }
        $ids[] = (int)$val;
    }
    $ids = array_values(array_unique($ids));
    if (empty($ids)) {
        return [];
    }

    $placeholders = implode(",", array_fill(0, count($ids), "?"));
    $stmt = $pdo->prepare("
        SELECT id
        FROM bundle_offers
        WHERE shop_owner_id = ? AND id IN ({$placeholders})
    ");
    $stmt->execute(array_merge([$shopOwnerId], $ids));
    $ownedIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if (count($ownedIds) !== count($ids)) {
        throw new InvalidArgumentException("One or more removed_offer_ids do not belong to your store.");
    }
    return $ids;
}

/**
 * Computes active bundle discounts for a cart of products.
 *
 * @param array<int, int> $qtyByProductId
 * @param array<int, float> $priceByProductId
 * @param array<int, int> $removedOfferIds
 * @return array{subtotal: float, bundle_discount: float, total_amount: float, applied_offers: array<int, array<string, mixed>>}
 */
function calculate_bundle_discounts_for_items(
    PDO $pdo,
    int $shopOwnerId,
    array $qtyByProductId,
    array $priceByProductId,
    array $removedOfferIds = []
): array {
    $subtotal = 0.0;
    foreach ($qtyByProductId as $pid => $qty) {
        $unitPrice = (float)($priceByProductId[$pid] ?? 0.0);
        $subtotal += round($qty * $unitPrice, 2);
    }
    $subtotal = round($subtotal, 2);

    $removedSet = array_flip($removedOfferIds);

    $stmt = $pdo->prepare("
        SELECT
            bo.id,
            bo.product_id_1,
            bo.product_id_2,
            bo.offer_price,
            bo.combined_price,
            p1.name AS product_1_name,
            p2.name AS product_2_name
        FROM bundle_offers bo
        INNER JOIN products p1 ON bo.product_id_1 = p1.id AND p1.shop_owner_id = bo.shop_owner_id
        INNER JOIN products p2 ON bo.product_id_2 = p2.id AND p2.shop_owner_id = bo.shop_owner_id
        WHERE bo.shop_owner_id = ? AND bo.status = 'active'
        ORDER BY bo.id ASC
    ");
    $stmt->execute([$shopOwnerId]);

    $appliedOffers = [];
    $totalDiscount = 0.0;

    while ($offer = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $offerId = (int)$offer["id"];
        if (isset($removedSet[$offerId])) {
            continue;
        }

        $p1 = (int)$offer["product_id_1"];
        $p2 = (int)$offer["product_id_2"];
        $qtyA = (int)($qtyByProductId[$p1] ?? 0);
        $qtyB = (int)($qtyByProductId[$p2] ?? 0);

        if ($qtyA <= 0 || $qtyB <= 0) {
            continue;
        }

        $applications = min($qtyA, $qtyB);
        $storedCombined = round((float)$offer["combined_price"], 2);
        $storedOfferPrice = round((float)$offer["offer_price"], 2);
        $currentCombined = round((float)($priceByProductId[$p1] ?? 0.0) + (float)($priceByProductId[$p2] ?? 0.0), 2);

        if ($storedCombined > 0.0 && abs($currentCombined - $storedCombined) >= 0.01) {
            // Recompute from current selling prices using the offer's stored percentage (e.g. 10%)
            $pct = max(0.0, min(1.0, ($storedCombined - $storedOfferPrice) / $storedCombined));
            $discountPerBundle = round($currentCombined * $pct, 2);
        } else {
            $discountPerBundle = max(0.0, round($storedCombined - $storedOfferPrice, 2));
        }

        if ($discountPerBundle <= 0.0) {
            continue;
        }

        $offerDiscount = round($discountPerBundle * $applications, 2);
        $totalDiscount += $offerDiscount;

        $appliedOffers[] = [
            "offer_id" => $offerId,
            "product_id_1" => $p1,
            "product_id_2" => $p2,
            "product_1_name" => $offer["product_1_name"],
            "product_2_name" => $offer["product_2_name"],
            "applications" => $applications,
            "discount_per_bundle" => $discountPerBundle,
            "discount_amount" => $offerDiscount
        ];
    }

    $totalDiscount = round(min($subtotal, max(0.0, $totalDiscount)), 2);
    $netTotal = round(max(0.0, $subtotal - $totalDiscount), 2);

    return [
        "subtotal" => $subtotal,
        "bundle_discount" => $totalDiscount,
        "total_amount" => $netTotal,
        "applied_offers" => $appliedOffers
    ];
}
