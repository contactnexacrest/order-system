<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use App\Services\EncryptionService;

/**
 * Owner request (2026-10-27): the bank details we pay a supplier or a
 * logistics partner into, and the no-login Vendor Details Link through which
 * they fill in their own details. Shared by the Suppliers and the Logistics
 * Partners directories ($kind 'supplier' or 'logistics_partner').
 *
 * The account number is stored encrypted (EncryptionService). Every save
 * records who entered it (staff, or the vendor through the link) and
 * clears the "verified" mark unless staff verified it in the same save, so
 * changed bank details are always checked again before a payment.
 */
final class VendorDetailsRepository
{
    public const KINDS = [
        'supplier' => ['table' => 'suppliers', 'name' => 'supplier_legal_name', 'contact' => 'contact_person', 'label' => 'supplier', 'edit' => '/suppliers/%d/edit'],
        'logistics_partner' => ['table' => 'logistics_partners', 'name' => 'partner_name', 'contact' => 'contact_person_name', 'label' => 'logistics partner', 'edit' => '/logistics-partners/%d/edit'],
    ];

    public const BANK_FIELDS = ['bank_account_name', 'bank_name', 'bank_branch', 'bank_ifsc', 'bank_account_type', 'bank_upi_id'];

    /** The everyday details the vendor can correct on the link (besides the bank). */
    public const DETAIL_FIELDS = ['address', 'city', 'state', 'phone', 'whatsapp_number', 'email', 'contact_person_phone', 'contact_person_whatsapp', 'gstin', 'pan'];

    private static function table(string $kind): string
    {
        return self::KINDS[$kind]['table'] ?? throw new \InvalidArgumentException("Unknown vendor kind {$kind}");
    }

    public static function find(string $kind, int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM ' . self::table($kind) . ' WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<string,mixed> the bank fields with the account number decrypted, plus has_bank / verified status */
    public static function bankDetails(?array $vendor): array
    {
        $out = [];
        foreach (self::BANK_FIELDS as $f) {
            $out[$f] = $vendor[$f] ?? null;
        }
        $out['bank_account_number'] = EncryptionService::decrypt($vendor['bank_account_number'] ?? null);
        $out['bank_updated_at'] = $vendor['bank_updated_at'] ?? null;
        $out['bank_updated_source'] = $vendor['bank_updated_source'] ?? null;
        $out['bank_verified_at'] = $vendor['bank_verified_at'] ?? null;
        $out['has_bank'] = $out['bank_account_number'] !== null && $out['bank_account_number'] !== '';
        return $out;
    }

    /** "••••1234" — what the vendor and the directory list see. */
    public static function maskAccount(?string $number): string
    {
        return $number === null || $number === '' ? '—' : '••••' . substr($number, -4);
    }

    /**
     * @param array<string,?string> $bank the BANK_FIELDS plus bank_account_number; a null account number keeps the one on file
     * @param string $source 'staff' or 'vendor'
     */
    public static function saveBankDetails(string $kind, int $id, array $bank, string $source, ?int $verifiedBy): void
    {
        $set = [];
        $params = ['id' => $id, 'source' => $source, 'verified_by' => $verifiedBy];
        foreach (self::BANK_FIELDS as $f) {
            $set[] = "{$f} = :{$f}";
            $params[$f] = $bank[$f] ?? null;
        }
        if (!empty($bank['bank_account_number'])) {
            $set[] = 'bank_account_number = :bank_account_number';
            $params['bank_account_number'] = EncryptionService::encrypt((string) $bank['bank_account_number']);
        }
        $set[] = 'bank_updated_at = NOW()';
        $set[] = 'bank_updated_source = :source';
        $set[] = 'bank_verified_by = :verified_by';
        $set[] = 'bank_verified_at = ' . ($verifiedBy !== null ? 'NOW()' : 'NULL');
        Database::connection()->prepare('UPDATE ' . self::table($kind) . ' SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
    }

    public static function markBankVerified(string $kind, int $id, int $userId): void
    {
        Database::connection()->prepare('UPDATE ' . self::table($kind) . ' SET bank_verified_at = NOW(), bank_verified_by = :by WHERE id = :id')
            ->execute(['by' => $userId, 'id' => $id]);
    }

    /** The vendor's own corrections from the link — only the DETAIL_FIELDS (and the contact person's name). */
    public static function saveDetailsFromVendor(string $kind, int $id, array $details): void
    {
        $columns = array_merge(self::DETAIL_FIELDS, [self::KINDS[$kind]['contact']]);
        $set = [];
        $params = ['id' => $id];
        foreach ($columns as $c) {
            if (array_key_exists($c, $details)) {
                $set[] = "{$c} = :{$c}";
                $params[$c] = $details[$c];
            }
        }
        if ($set !== []) {
            Database::connection()->prepare('UPDATE ' . self::table($kind) . ' SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
        }
    }

    /**
     * The no-login Vendor Details Link — same hash / encrypted copy / expiry
     * convention as the Buyer Action Link. One use: it is cleared when the
     * vendor submits, so an old link can never change the bank account again.
     */
    public static function generateDetailsToken(string $kind, int $id, int $ttlDays = 30): string
    {
        $raw = bin2hex(random_bytes(32));
        Database::connection()->prepare(
            'UPDATE ' . self::table($kind) . ' SET details_token_hash = :hash, details_token_plain = :plain, details_token_expires_at = :expires WHERE id = :id'
        )->execute([
            'hash' => hash('sha256', $raw),
            'plain' => EncryptionService::encrypt($raw),
            'expires' => date('Y-m-d H:i:s', time() + $ttlDays * 86400),
            'id' => $id,
        ]);
        return $raw;
    }

    /** The live link's token for staff to re-copy, or null when there is none. */
    public static function liveDetailsToken(?array $vendor): ?string
    {
        if (empty($vendor['details_token_plain']) || empty($vendor['details_token_expires_at']) || strtotime((string) $vendor['details_token_expires_at']) <= time()) {
            return null;
        }
        return EncryptionService::decrypt($vendor['details_token_plain']);
    }

    /** @return array{0: string, 1: array}|null the kind and the vendor row for a live, unused token */
    public static function findByValidDetailsToken(string $raw): ?array
    {
        foreach (array_keys(self::KINDS) as $kind) {
            $stmt = Database::connection()->prepare(
                'SELECT id FROM ' . self::table($kind) . ' WHERE details_token_hash = :hash AND details_token_expires_at > NOW() AND is_active = 1'
            );
            $stmt->execute(['hash' => hash('sha256', $raw)]);
            $row = $stmt->fetch();
            if ($row) {
                return [$kind, self::find($kind, (int) $row['id'])];
            }
        }
        return null;
    }

    public static function markDetailsSubmitted(string $kind, int $id): void
    {
        Database::connection()->prepare(
            'UPDATE ' . self::table($kind) . ' SET details_submitted_at = NOW(), details_token_hash = NULL, details_token_plain = NULL, details_token_expires_at = NULL WHERE id = :id'
        )->execute(['id' => $id]);
    }
}
