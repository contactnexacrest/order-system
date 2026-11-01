<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Maker-checker: the person who created or requested an item does not also
 * approve it. The single choke point for every self-approval in the app —
 * payment terms, document review, e-mail send, order cancellation, purchase
 * award, amendment MD-approval and short-payment clearing. Who may approve
 * their own item is set per rule in Admin → Self-approval rules (owner
 * decision D-221; QA Round 8, R8-N09: no Super Admin or manage_permissions
 * blanket exemption any more — default Managing Director and Executive
 * Director on every rule).
 */
final class MakerCheckerGuard
{
    public static function selfApprovalAllowed(string $rule, int $userId): bool
    {
        return SelfApprovalRules::allowed($rule, $userId);
    }

    /** Owner decision 1 Nov 2026 (A-1, then D-221): payment terms of an order the person created. */
    public static function paymentTermsSelfApprovalAllowed(int $userId): bool
    {
        return self::selfApprovalAllowed('payment_terms', $userId);
    }
}
