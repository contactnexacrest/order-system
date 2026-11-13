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

    /**
     * HNT-011 (owner decision 13 Nov 2026, item 8 option A): a unit price typed as 0 is a price
     * of 0.00 (free of charge); only a blank price means "no price yet / To Be Confirmed".
     * PHP's `?: null` turned the string "0" into NULL — Node always kept 0.00.
     */
    public static function priceInput(mixed $raw): ?string
    {
        $value = trim((string) ($raw ?? ''));
        return $value === '' ? null : $value;
    }

    /** QA Round 6, R6-N14: DECIMAL(14,2) for price and line value, DECIMAL(14,3) for quantity. */
    public const MAX_PRICE = 999999999999.99;
    /**
     * HNT-011 (QA 13 Nov 2026), owner decision item 10a (13 Nov 2026): the hard block on one
     * line's quantity, in any unit — above 1,000,000 is a typing slip, not an order (the column,
     * DECIMAL(14,3), holds 99,999,999,999.999). The same number is the quantity fields'
     * data-max-value in the browser and on Node. Below it, a quantity above about 20 containers
     * of its unit needs "I confirm this quantity" (App\Services\QuantityLimits).
     */
    public const MAX_QUANTITY = 1000000.0;

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
                return 'quantity can be at most ' . number_format(self::MAX_QUANTITY, 0) . " (got \"{$rawQuantity}\")";
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
