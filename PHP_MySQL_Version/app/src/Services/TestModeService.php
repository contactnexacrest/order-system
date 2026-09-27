<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\TestModeRepository;

/**
 * Test Mode business rules (docs/schema.sql Section V). Thin over
 * TestModeRepository — the repository owns SQL, this owns the two rules
 * that aren't just a query: you can't disable while test data exists, and
 * every test-mode reference number gets the same literal prefix so it's
 * identifiable and safely deletable (requirement: reference numbers must
 * contain "TEST-").
 */
final class TestModeService
{
    public const REFERENCE_PREFIX = 'TEST-';

    public static function isEnabled(): bool
    {
        $settings = TestModeRepository::getSettings();
        return $settings !== null && (int) $settings['is_enabled'] === 1;
    }

    public static function getSettings(): ?array
    {
        return TestModeRepository::getSettings();
    }

    public static function enable(int $userId): void
    {
        TestModeRepository::setEnabled(true, $userId);
    }

    /** @throws \RuntimeException if test data still exists */
    public static function disable(): void
    {
        if (TestModeRepository::hasTestData()) {
            throw new \RuntimeException('Cannot disable Test Mode while test data still exists. Delete all test data first.');
        }
        TestModeRepository::setEnabled(false, null);
    }

    public static function setTestEmail(string $email): void
    {
        TestModeRepository::setTestEmail($email);
    }

    /**
     * QA-5 TM-07/TM-08: the single Test Mode email gate — every outbound
     * transport (Zoho Mail, SMTP) must call this before doing anything
     * else, so no choice of transport can bypass it. $isSecurityEmail is
     * the one carve-out by design: staff's own 2FA codes and
     * password-reset links must keep going to the real address they
     * belong to, or Test Mode would lock staff out of their own accounts.
     *
     * Returns null when Test Mode is on and no test_email is configured —
     * the caller must treat that as "do not send this email at all", never
     * fall back to the real address (TM-07). docs/schema.sql Section V's
     * guarantee is "no real buyer is ever emailed while Test Mode is on",
     * not "unless nobody happened to configure a test address yet".
     */
    public static function resolveEmailRecipient(string $toEmail, bool $isSecurityEmail, string $subject): ?string
    {
        if ($isSecurityEmail) {
            return $toEmail;
        }
        $settings = self::getSettings();
        if (!$settings || (int) $settings['is_enabled'] !== 1) {
            return $toEmail;
        }
        if (empty($settings['test_email'])) {
            error_log("[TEST MODE — no test_email configured, BLOCKING send that would otherwise reach the real address] To: {$toEmail} | Subject: {$subject}");
            return null;
        }
        error_log("[TEST MODE — email redirected] Original To: {$toEmail} -> Test: {$settings['test_email']} | Subject: {$subject}");
        return (string) $settings['test_email'];
    }

    public static function testDataCounts(): array
    {
        return TestModeRepository::testDataCounts();
    }

    public static function hasTestData(): bool
    {
        return TestModeRepository::hasTestData();
    }

    public static function deleteAllTestData(): array
    {
        return TestModeRepository::clearAllTestData();
    }

    /** Prefixes a freshly-generated reference/number when Test Mode is active; a no-op otherwise. */
    public static function applyReferencePrefix(?string $reference, bool $testModeEnabled): ?string
    {
        if (!$testModeEnabled || !$reference) {
            return $reference;
        }
        return str_starts_with($reference, self::REFERENCE_PREFIX) ? $reference : self::REFERENCE_PREFIX . $reference;
    }

    public static function isTestEntity(?string $entityType, ?int $entityId): bool
    {
        return TestModeRepository::isTestEntity($entityType, $entityId);
    }
}
