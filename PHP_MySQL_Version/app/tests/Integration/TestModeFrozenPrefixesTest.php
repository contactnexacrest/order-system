<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Middleware\TestModeGate;
use App\Tests\Support\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * QA-5 TM-06 (Owner Decision #4: "All decision-making screens frozen; only
 * operational screens available"): /hs-codes, /email-templates,
 * /admin/roles, and /admin/permission-definitions were entirely missing
 * from TestModeGate::FROZEN_PREFIXES — HS codes, buyer-facing email
 * wording, and the roles/permissions that decide who can do what could all
 * still be created or edited while Test Mode was supposedly frozen.
 *
 * TestModeGate::blockAdminWritesInTestMode() itself calls header()+exit()
 * on the blocking path, which would kill the PHPUnit process, so this
 * drives the underlying prefix-matching directly via reflection —
 * FROZEN_PREFIXES is exactly what decides whether that method's blocking
 * branch is ever reached at all, independent of whatever it does once it
 * gets there.
 */
final class TestModeFrozenPrefixesTest extends DbTestCase
{
    /** @return array<int, array{0: string}> */
    public static function newlyFrozenPathsProvider(): array
    {
        return [
            ['/hs-codes'],
            ['/hs-codes/3/update'],
            ['/email-templates'],
            ['/email-templates/5/update'],
            ['/admin/roles'],
            ['/admin/roles/2/update'],
            ['/admin/permission-definitions'],
            ['/admin/permission-definitions/1/update'],
        ];
    }

    #[DataProvider('newlyFrozenPathsProvider')]
    public function testFrozenPrefixesNowCoverThePath(string $path): void
    {
        self::assertTrue(self::matchesFrozenPrefix($path), "{$path} must match TestModeGate::FROZEN_PREFIXES");
    }

    private static function matchesFrozenPrefix(string $path): bool
    {
        $reflection = new \ReflectionClass(TestModeGate::class);
        $frozenPrefixes = $reflection->getConstant('FROZEN_PREFIXES');
        $matchesPrefix = $reflection->getMethod('matchesPrefix');
        $matchesPrefix->setAccessible(true);
        return (bool) $matchesPrefix->invoke(null, $path, $frozenPrefixes);
    }
}
