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
