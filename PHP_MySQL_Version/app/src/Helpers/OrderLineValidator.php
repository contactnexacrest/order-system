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
        return null;
    }
}
