<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config\Env;
use App\Controllers\AuthController;
use PHPUnit\Framework\TestCase;

/**
 * "would it be quick??? ... DEV_SERVER ... on all pages there will be a kind
 * of header or strip saying DEV SERVER/DEV Environment in red color" — and,
 * once Test Mode already existed as a separate sitewide banner, the explicit
 * follow-up: "test mode and test environment banner both serve as per the
 * need - not replace of each other". DEV_SERVER is independent of both
 * APP_ENV and the DB-driven Test Mode flag (see Env::isDevServer()'s own
 * docblock), so this only needs to prove the env toggle itself and that the
 * banner actually reaches a real page through the shared bare layout — the
 * additive, non-interfering relationship with the Test Mode banner is
 * structural (two unconditionally-independent `if` blocks, never a shared
 * one) and was also confirmed live on both stacks.
 */
final class DevServerBannerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['DEV_SERVER'], $_SESSION['_auth_user_id']);
        parent::tearDown();
    }

    public function testIsDevServerDefaultsToFalse(): void
    {
        unset($_ENV['DEV_SERVER']);
        self::assertFalse(Env::isDevServer());
    }

    public function testIsDevServerIsTrueWhenEnvVarSet(): void
    {
        $_ENV['DEV_SERVER'] = 'true';
        self::assertTrue(Env::isDevServer());
    }

    public function testDevServerBannerAppearsOnARealPageWhenEnabled(): void
    {
        $_ENV['DEV_SERVER'] = 'true';

        ob_start();
        (new AuthController())->showLogin([]);
        $output = ob_get_clean();

        self::assertStringContainsString('dev-server-banner', $output);
        self::assertStringContainsString('DEV / TEST ENVIRONMENT', $output);
    }

    public function testDevServerBannerIsAbsentWhenDisabled(): void
    {
        $_ENV['DEV_SERVER'] = 'false';

        ob_start();
        (new AuthController())->showLogin([]);
        $output = ob_get_clean();

        self::assertStringNotContainsString('dev-server-banner', $output);
    }
}
