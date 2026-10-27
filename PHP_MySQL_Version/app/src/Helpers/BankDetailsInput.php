<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Owner request (2026-10-27): reads and checks the bank-details section of
 * the supplier form (staff) and of the Supplier Details Link (the supplier).
 * The account number is typed twice; a blank account number keeps the one
 * already on file.
 */
final class BankDetailsInput
{
    public const ACCOUNT_TYPES = ['Current', 'Savings', 'Cash Credit / Overdraft'];

    /**
     * @param array<string,mixed> $post
     * @param array<string,?string> $current SupplierRepository::bankDetails() of the supplier as it is now
     * @param bool $required the supplier's own link: holder, bank, account number and IFSC are required
     * @return array{bank: ?array<string,?string>, error: ?string} bank null = nothing to save (all blank, or unchanged)
     */
    public static function collect(array $post, array $current, bool $required): array
    {
        $field = static fn(string $key): ?string => trim((string) ($post[$key] ?? '')) ?: null;
        $bank = [
            'bank_account_name' => $field('bank_account_name'),
            'bank_name' => $field('bank_name'),
            'bank_branch' => $field('bank_branch'),
            'bank_ifsc' => $field('bank_ifsc') !== null ? strtoupper((string) $field('bank_ifsc')) : null,
            'bank_account_type' => $field('bank_account_type'),
            'bank_upi_id' => $field('bank_upi_id'),
        ];
        $number = preg_replace('/\s+/', '', (string) ($post['bank_account_number'] ?? ''));
        $confirm = preg_replace('/\s+/', '', (string) ($post['bank_account_number_confirm'] ?? ''));

        $allBlank = $number === '' && count(array_filter($bank)) === 0;
        if ($allBlank && !$required) {
            return ['bank' => null, 'error' => null];
        }
        if ($number !== '' || $confirm !== '') {
            if ($number !== $confirm) {
                return ['bank' => null, 'error' => 'The two bank account numbers do not match — type it again.'];
            }
            if (!preg_match('/^[0-9]{6,20}$/', $number)) {
                return ['bank' => null, 'error' => 'Enter the bank account number as digits only (6 to 20 digits).'];
            }
        }
        $hasAccount = $number !== '' || !empty($current['has_bank']);
        if ($required || $number !== '') {
            foreach (['bank_account_name' => 'account holder name', 'bank_name' => 'bank name', 'bank_ifsc' => 'IFSC code'] as $key => $label) {
                if ($bank[$key] === null) {
                    return ['bank' => null, 'error' => "Enter the {$label} for the bank account."];
                }
            }
        }
        if (!$hasAccount) {
            return ['bank' => null, 'error' => 'Enter the bank account number (twice), or leave the whole bank section empty.'];
        }
        if ($bank['bank_ifsc'] !== null && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $bank['bank_ifsc'])) {
            return ['bank' => null, 'error' => 'Enter a valid 11-character IFSC code (e.g. SBIN0001234).'];
        }
        if ($bank['bank_account_type'] !== null && !in_array($bank['bank_account_type'], self::ACCOUNT_TYPES, true)) {
            return ['bank' => null, 'error' => 'Choose the account type from the list.'];
        }

        $unchanged = $number === '' || $number === ($current['bank_account_number'] ?? null);
        foreach ($bank as $key => $value) {
            $unchanged = $unchanged && $value === ($current[$key] ?? null);
        }
        $bank['bank_account_number'] = $number !== '' ? $number : null;
        return ['bank' => $unchanged ? null : $bank, 'error' => null];
    }
}
