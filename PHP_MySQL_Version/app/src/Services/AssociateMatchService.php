<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;

/**
 * Owner decision 1 Nov 2026 (AS-5, guarantee 1): an associate's client is
 * recognised by company identity — legal name, VAT / registration number,
 * e-mail domain, phone — however it comes back, not by the link or code it
 * first used.
 */
final class AssociateMatchService
{
    private const FREE_MAIL = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com', 'icloud.com', 'aol.com', 'proton.me', 'protonmail.com', 'gmx.com', 'mail.com', 'yandex.com', 'rediffmail.com'];

    public static function normaliseName(?string $name): string
    {
        $n = strtolower(trim((string) $name));
        $n = preg_replace('/[^a-z0-9 ]+/', ' ', $n) ?? '';
        $n = preg_replace('/\b(ltd|limited|llc|inc|gmbh|bv|b v|srl|sarl|sa|s a|spa|pvt|private|co|company|corp|corporation|plc|ag|oy|ab|as|aps|kg|the)\b/', ' ', $n) ?? '';
        return trim(preg_replace('/\s+/', ' ', $n) ?? '');
    }

    public static function emailDomain(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return null;
        }
        $domain = substr($email, $at + 1);
        return in_array($domain, self::FREE_MAIL, true) ? null : $domain;
    }

    public static function isFreeMail(?string $email): bool
    {
        $email = strtolower(trim((string) $email));
        $at = strrpos($email, '@');
        return $at !== false && in_array(substr($email, $at + 1), self::FREE_MAIL, true);
    }

    public static function digits(?string $phone): string
    {
        $d = preg_replace('/\D+/', '', (string) $phone) ?? '';
        return strlen($d) >= 8 ? substr($d, -9) : '';
    }

    /**
     * The client of an associate this company identity matches, if any.
     * @return array<string,mixed>|null client row with associate_id and associate_name
     */
    public static function associatedClient(?string $company, ?string $vat, ?string $email, ?string $phone): ?array
    {
        $rows = Database::connection()->query(
            'SELECT c.id, c.company_legal_name, c.vat_eori_tax_no, c.email, c.phone, c.associate_id, a.name AS associate_name
               FROM clients c JOIN associates a ON a.id = c.associate_id WHERE c.associate_id IS NOT NULL'
        )->fetchAll();
        foreach ($rows as $r) {
            if (self::sameCompany($company, $vat, $email, $phone, $r['company_legal_name'], $r['vat_eori_tax_no'], $r['email'], $r['phone'])) {
                return $r;
            }
        }
        return null;
    }

    /** Any client at all (with or without an associate) this identity matches. */
    public static function anyClient(?string $company, ?string $vat, ?string $email, ?string $phone, ?int $exceptId = null): ?array
    {
        foreach (Database::connection()->query('SELECT id, company_legal_name, vat_eori_tax_no, email, phone, associate_id FROM clients')->fetchAll() as $r) {
            if ($exceptId !== null && (int) $r['id'] === $exceptId) {
                continue;
            }
            if (self::sameCompany($company, $vat, $email, $phone, $r['company_legal_name'], $r['vat_eori_tax_no'], $r['email'], $r['phone'])) {
                return $r;
            }
        }
        return null;
    }

    public static function sameCompany(?string $nameA, ?string $vatA, ?string $emailA, ?string $phoneA, ?string $nameB, ?string $vatB, ?string $emailB, ?string $phoneB): bool
    {
        $vA = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $vatA) ?? '');
        $vB = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $vatB) ?? '');
        if ($vA !== '' && $vA === $vB) {
            return true;
        }
        $nA = self::normaliseName($nameA);
        if ($nA !== '' && $nA === self::normaliseName($nameB)) {
            return true;
        }
        $dA = self::emailDomain($emailA);
        if ($dA !== null && $dA === self::emailDomain($emailB)) {
            return true;
        }
        $pA = self::digits($phoneA);
        return $pA !== '' && $pA === self::digits($phoneB);
    }

    /**
     * Warnings about contact details an associate-referred buyer gave (part 4.4):
     * the associate's own, a free-mail address, an e-mail not on the website's domain.
     * @return list<string>
     */
    public static function contactFlags(array $associate, ?string $email, ?string $phone, ?string $website): array
    {
        $flags = [];
        if ($email !== null && strcasecmp(trim($email), trim((string) ($associate['email'] ?? ''))) === 0) {
            $flags[] = 'The buyer\'s e-mail is the associate\'s own e-mail.';
        }
        if (self::digits($phone) !== '' && self::digits($phone) === self::digits($associate['phone'] ?? null)) {
            $flags[] = 'The buyer\'s phone is the associate\'s own phone.';
        }
        if (self::isFreeMail($email)) {
            $flags[] = 'The buyer used a free-mail address (not a company domain).';
        }
        $site = strtolower((string) parse_url(str_contains((string) $website, '://') ? (string) $website : 'https://' . $website, PHP_URL_HOST));
        $site = preg_replace('/^www\./', '', $site) ?? '';
        $domain = self::emailDomain($email);
        if ($site !== '' && $domain !== null && $domain !== $site && !str_ends_with($domain, '.' . $site)) {
            $flags[] = "The buyer's e-mail domain ({$domain}) is not the website's ({$site}).";
        }
        return $flags;
    }
}
