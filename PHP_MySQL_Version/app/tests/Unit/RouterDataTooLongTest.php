<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config\Database;
use App\Helpers\Flash;
use App\Helpers\Router;
use PHPUnit\Framework\TestCase;

/**
 * External QA report R-01/R-03/R-04/R-09/R-11/R-12: no form anywhere
 * validated a field against what the database would actually accept —
 * text longer than its column (R-01/R-12, SQLSTATE 22001), an impossible
 * date like 30-Feb or a non-numeric amount (R-03/R-09/R-11, SQLSTATE
 * 22007), or an id referencing a row that doesn't exist (R-04, SQLSTATE
 * 23000). Each crashed the request as an uncaught PDOException: a raw
 * HTTP 500, no flash message, every field the user had typed lost.
 * Router::dispatch() is the single chokepoint every one of those forms'
 * requests already passes through, so catching these there closes the
 * crash everywhere at once.
 */
final class RouterDataTooLongTest extends TestCase
{
    private function freshRouterWithOverLongInsertRoute(): Router
    {
        // Database::connection() is a singleton reused across every test
        // in this PHPUnit process, so a plain CREATE TEMPORARY TABLE would
        // collide with itself on the second test method here.
        $pdo = Database::connection();
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS router_r01_test');
        $pdo->exec('CREATE TEMPORARY TABLE router_r01_test (c VARCHAR(5) NOT NULL)');

        $router = new Router();
        $router->post('/router-r01-test', function (array $params): void {
            Database::connection()
                ->prepare('INSERT INTO router_r01_test (c) VALUES (:c)')
                ->execute(['c' => (string) ($_POST['notes'] ?? '')]);
        });
        return $router;
    }

    protected function tearDown(): void
    {
        Flash::pull();
        Flash::pullOld();
        parent::tearDown();
    }

    public function testDispatchNeverLetsAnOverLongFieldCrashAsAnUncaughtException(): void
    {
        $router = $this->freshRouterWithOverLongInsertRoute();
        $_POST = ['notes' => str_repeat('x', 500), 'other_field' => 'kept too'];
        $_SERVER['HTTP_REFERER'] = '';

        // The real assertion: this call must not throw. Before this fix,
        // this threw PDOException("SQLSTATE[22001]: ... Data too long for
        // column 'c'") straight out of dispatch(), exactly the uncaught
        // 500 the report describes.
        $router->dispatch('POST', '/router-r01-test');

        $this->addToAssertionCount(1);
    }

    public function testDispatchSetsAFriendlyFlashErrorAndPreservesTheSubmittedFields(): void
    {
        $router = $this->freshRouterWithOverLongInsertRoute();
        $_POST = ['notes' => str_repeat('x', 500), 'other_field' => 'kept too'];
        $_SERVER['HTTP_REFERER'] = '';

        $router->dispatch('POST', '/router-r01-test');

        $flash = Flash::pull();
        self::assertNotEmpty($flash);
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('too long', $flash[0]['message']);

        $old = Flash::pullOld();
        self::assertSame(str_repeat('x', 500), $old['notes'] ?? null, 'the user must not have to retype a field that was fine, just the one that was too long');
        self::assertSame('kept too', $old['other_field'] ?? null);
    }

    public function testDispatchReturnsAJsonErrorInsteadOfRedirectingForAnAjaxRequest(): void
    {
        $router = $this->freshRouterWithOverLongInsertRoute();
        $_POST = ['notes' => str_repeat('x', 500)];
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        ob_start();
        $router->dispatch('POST', '/router-r01-test');
        $output = ob_get_clean();
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);

        $decoded = json_decode($output, true);
        self::assertIsArray($decoded, 'an AJAX caller must get a JSON body, not a redirect it can\'t follow');
        self::assertStringContainsString('too long', $decoded['error'] ?? '');
    }

    public function testDispatchNeverLetsAnImpossibleDateCrashAsAnUncaughtException(): void
    {
        $pdo = Database::connection();
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS router_r03_test');
        $pdo->exec('CREATE TEMPORARY TABLE router_r03_test (d DATE NOT NULL)');

        $router = new Router();
        $router->post('/router-r03-test', function (array $params): void {
            Database::connection()
                ->prepare('INSERT INTO router_r03_test (d) VALUES (:d)')
                ->execute(['d' => (string) ($_POST['notice_date'] ?? '')]);
        });
        $_POST = ['notice_date' => '2026-02-30'];
        $_SERVER['HTTP_REFERER'] = '';

        // Before this fix, this threw PDOException("SQLSTATE[22007]: ...
        // Incorrect date value") straight out of dispatch().
        $router->dispatch('POST', '/router-r03-test');

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString("isn't valid", $flash[0]['message']);
    }

    public function testDispatchNeverLetsAForeignKeyViolationCrashAsAnUncaughtException(): void
    {
        $router = new Router();
        $router->post('/router-r04-test', function (array $params): void {
            // A real FK relationship from the actual schema (documents.order_id -> orders.id).
            Database::connection()
                ->prepare("INSERT INTO documents (order_id, document_type_id, status) VALUES (:order_id, 1, 'draft')")
                ->execute(['order_id' => 999999999]);
        });
        $_POST = [];
        $_SERVER['HTTP_REFERER'] = '';

        // Before this fix, this threw PDOException("SQLSTATE[23000]: ...
        // a foreign key constraint fails") straight out of dispatch().
        $router->dispatch('POST', '/router-r04-test');

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('no longer exists', $flash[0]['message']);
    }

    public function testADifferentPdoExceptionIsNeverSwallowed(): void
    {
        $router = new Router();
        $router->post('/router-r01-other-error-test', function (array $params): void {
            // A genuinely different DB error (unknown column) must still
            // surface as a real crash — this fix is only for "too long",
            // never a blanket catch-all that hides other bugs.
            Database::connection()->query('SELECT this_column_does_not_exist FROM users LIMIT 1');
        });

        $this->expectException(\PDOException::class);
        $router->dispatch('POST', '/router-r01-other-error-test');
    }

    public function testSafeRedirectBackOnlyEverReturnsASameOriginRelativePath(): void
    {
        $method = new \ReflectionMethod(Router::class, 'safeRedirectBack');
        $method->setAccessible(true);

        $_SERVER['HTTP_HOST'] = 'order-system.test';
        $_SERVER['HTTP_REFERER'] = 'https://order-system.test/orders/123/create?foo=bar';
        self::assertSame('/orders/123/create?foo=bar', $method->invoke(null));

        // Open-redirect guard: a scheme-relative or absolute-elsewhere
        // Referer must never be trusted as a redirect target.
        $_SERVER['HTTP_REFERER'] = '//evil.example.com/phish';
        self::assertSame('/', $method->invoke(null));

        $_SERVER['HTTP_REFERER'] = 'https://evil.example.com/orders/123/create';
        self::assertSame('/', $method->invoke(null));

        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);
        self::assertSame('/', $method->invoke(null));
    }
}
