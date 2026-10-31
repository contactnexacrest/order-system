<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Owner decision D-214 (2026-10-31), phase 2: the one rule for "may this
 * person, in the department they are working as, see this kind of money?".
 * Every page, list, report, document download, file download and dossier
 * asks here instead of repeating its own check. Sales sees the selling side,
 * Procurement the buying side, Logistics only logistics costs; Finance,
 * management, the auditors and the CA see everything. Port: moneyVisibility.js.
 */
final class MoneyVisibility
{
    public const SELLING = 'selling';
    public const BUYER_PAYMENTS = 'buyer_payments';
    public const PURCHASE = 'purchase';
    public const LOGISTICS = 'logistics';

    /** money class => the permission that shows it */
    public const PERMISSIONS = [
        self::SELLING => 'view_selling_prices',
        self::BUYER_PAYMENTS => 'view_buyer_payments',
        self::PURCHASE => 'view_purchase_prices',
        self::LOGISTICS => 'view_logistics_costs',
    ];

    /** Document type code => the money it carries (types not listed carry none). */
    public const DOCUMENT_CLASSES = [
        'QT' => self::SELLING, 'BUYERPO' => self::SELLING, 'PI' => self::SELLING, 'OC' => self::SELLING,
        'FDN' => self::SELLING, 'CI' => self::SELLING, 'AMD' => self::SELLING, 'COOPREP' => self::SELLING, 'ESI' => self::SELLING,
        'CHECKLIST_2_FINANCE' => self::BUYER_PAYMENTS,
        'SUPPO' => self::PURCHASE,
        'BLI' => self::LOGISTICS,
    ];

    /** What money a document type carries, or null for none. */
    public static function documentClass(?string $typeCode): ?string
    {
        return self::DOCUMENT_CLASSES[strtoupper((string) $typeCode)] ?? null;
    }

    /** Whether the signed-in person may see this class of money. */
    public static function can(string $class): bool
    {
        $user = AuthService::currentUser();
        if ($user === null || !isset(self::PERMISSIONS[$class])) {
            return false;
        }
        return PermissionService::can((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null, self::PERMISSIONS[$class]);
    }

    /** Whether the signed-in person may see the money a document type carries. */
    public static function canSeeDocument(?string $typeCode): bool
    {
        $class = self::documentClass($typeCode);
        return $class === null || self::can($class);
    }

    /** Order costs and expense receipts: Finance and management only. */
    public const FINANCIALS = 'financials';

    /** Whether the signed-in person may have this stored file, by the money it shows. */
    public static function canSeeFile(int $fileId): bool
    {
        $class = \App\Repositories\FileStoreRepository::moneyClassFor($fileId);
        if ($class === null) {
            return true;
        }
        if ($class === self::FINANCIALS) {
            $user = AuthService::currentUser();
            return $user !== null && PermissionService::can((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null, 'manage_order_financials');
        }
        return self::can($class);
    }

    /** @return array{selling:bool,buyer_payments:bool,purchase:bool,logistics:bool} for views */
    public static function flags(): array
    {
        $flags = [];
        foreach (array_keys(self::PERMISSIONS) as $class) {
            $flags[$class] = self::can($class);
        }
        return $flags;
    }
}
