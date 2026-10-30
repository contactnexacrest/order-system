<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * QA-5 ORD-05/ORD-06: order creation passed product_quantity/product_unit_price
 * straight through as raw trimmed strings all the way to a DECIMAL column
 * bind — a non-numeric value ("abc") threw an uncaught PDOException/
 * ER_TRUNCATED_WRONG_VALUE_FOR_FIELD deep inside OrderProductRepository::add(),
 * by which point the orders/order_stages/order_payment_status rows were
 * already committed (no transaction wrapped the write path — see
 * OrderController::store()), leaving a half-created order behind. A negative
 * quantity was never rejected at all, silently producing a negative FOB
 * value. Checked here, before anything is written, matching the existing
 * HS-code-check-early pattern in OrderController::store() ("so a bad code
 * never leaves a half-created order behind").
 */
final class OrderLineValidator
{
    /** @return string|null an error message if invalid, null if the value passes. A blank or TBC quantity is always valid — quantity is optional at creation. */
    public static function checkQuantity(string $rawValue, bool $isTbc): ?string
    {
        $value = trim($rawValue);
        if ($value === '' || $isTbc) {
            return null;
        }
        if (!is_numeric($value)) {
            return "quantity must be a number (got \"{$rawValue}\")";
        }
        if ((float) $value <= 0) {
            return "quantity must be greater than zero (got \"{$rawValue}\")";
        }
        return null;
    }

    /** @return string|null an error message if invalid, null if the value passes. A blank unit price is always valid — price may be pending quotation. */
    public static function checkUnitPrice(string $rawValue): ?string
    {
        $value = trim($rawValue);
        if ($value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return "unit price must be a number (got \"{$rawValue}\")";
        }
        if ((float) $value < 0) {
            return "unit price must not be negative (got \"{$rawValue}\")";
        }
        // QA Round 6, R6-N14/N15: what the DECIMAL(14,2) column can hold —
        // no more than 2 decimals (0.0001 was stored as 0.00) and no more
        // than 999,999,999,999.99 (a larger price was an HTTP 500).
        if (self::decimals($value) > 2) {
            return "unit price can have at most 2 decimals (got \"{$rawValue}\")";
        }
        if ((float) $value > self::MAX_PRICE) {
            return 'unit price can be at most ' . number_format(self::MAX_PRICE, 2) . " (got \"{$rawValue}\")";
        }
        return null;
    }

    /** QA Round 6, R6-N14: DECIMAL(14,2) for price and line value, DECIMAL(14,3) for quantity. */
    public const MAX_PRICE = 999999999999.99;
    public const MAX_QUANTITY = 99999999999.999;

    /**
     * QA Round 6, R6-N14/N15: one line's quantity, unit price and line
     * value (quantity x price) — every place a product line is saved
     * (order create, add line, edit line) uses this.
     */
    public static function checkLine(string $rawQuantity, bool $isTbc, string $rawPrice): ?string
    {
        $error = self::checkQuantity($rawQuantity, $isTbc) ?? self::checkUnitPrice($rawPrice);
        if ($error !== null) {
            return $error;
        }
        $quantity = trim($rawQuantity);
        if ($quantity !== '' && !$isTbc) {
            if (self::decimals($quantity) > 3) {
                return "quantity can have at most 3 decimals (got \"{$rawQuantity}\")";
            }
            if ((float) $quantity > self::MAX_QUANTITY) {
                return 'quantity can be at most ' . number_format(self::MAX_QUANTITY, 3) . " (got \"{$rawQuantity}\")";
            }
            if (trim($rawPrice) !== '' && (float) $quantity * (float) trim($rawPrice) > self::MAX_PRICE) {
                return 'the line value (quantity x unit price) can be at most ' . number_format(self::MAX_PRICE, 2);
            }
        }
        return null;
    }

    private static function decimals(string $value): int
    {
        $value = strtolower($value);
        if (str_contains($value, 'e')) {
            return 99;
        }
        $dot = strpos($value, '.');
        return $dot === false ? 0 : strlen(rtrim(substr($value, $dot + 1), '0'));
    }
}
