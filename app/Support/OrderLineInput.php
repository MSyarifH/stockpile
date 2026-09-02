<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reads the repeated line-item inputs of an order form.
 *
 * Both order forms post parallel arrays — items[product_id][], items[quantity][]
 * and a price field — so both controllers had a near-identical 25-line parser
 * that differed only in the price field's name (see
 * docs/quality/refactor-log.md).
 *
 * Rows with no product chosen are dropped rather than rejected, because each
 * form always renders one blank row.
 */
final class OrderLineInput
{
    /**
     * @return list<array{product_id:int,quantity:int,price:float}>
     */
    public static function parse(Request $request, string $priceField): array
    {
        $raw = $request->input('items', []);
        if (!is_array($raw)) {
            return [];
        }

        $productIds = is_array($raw['product_id'] ?? null) ? $raw['product_id'] : [];
        $quantities = is_array($raw['quantity'] ?? null) ? $raw['quantity'] : [];
        $prices = is_array($raw[$priceField] ?? null) ? $raw[$priceField] : [];

        $lines = [];
        foreach ($productIds as $index => $productId) {
            if (!is_numeric($productId) || (int) $productId <= 0) {
                continue;
            }

            $quantity = $quantities[$index] ?? 0;
            $price = $prices[$index] ?? 0;

            $lines[] = [
                'product_id' => (int) $productId,
                'quantity' => is_numeric($quantity) ? (int) $quantity : 0,
                'price' => is_numeric($price) ? (float) $price : 0.0,
            ];
        }

        return $lines;
    }
}
