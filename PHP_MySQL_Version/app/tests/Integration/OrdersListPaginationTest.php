<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Tests\Support\DbTestCase;

/**
 * External QA report U-02: "Orders list has no pagination — with ~200
 * orders the page is 17,600 px tall on desktop, 52,000 px on a phone;
 * will slow down as data grows." These assertions deliberately never
 * depend on an exact total order count (the test DB is shared across
 * this whole PHPUnit run, so other tests' fixture orders are always
 * mixed in) — only on invariants that must hold regardless of how many
 * orders already exist: no single page ever renders more than the page
 * size, and when there's more than one page, the pager actually says so.
 */
final class OrdersListPaginationTest extends DbTestCase
{
    private const PER_PAGE = 25;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
    }

    private function createOrders(int $count): void
    {
        // createTestOrder() always inserts sequence_no = 1, so each order
        // needs its own client (uq_client_sequence is unique per client+seq).
        for ($i = 0; $i < $count; $i++) {
            $this->createTestOrder($this->createTestClient());
        }
    }

    public function testNoSinglePageEverRendersMoreThanThePageSize(): void
    {
        $this->createOrders(self::PER_PAGE + 5);

        ob_start();
        (new OrderController())->index(['status' => 'all']);
        $html = (string) ob_get_clean();

        self::assertLessThanOrEqual(self::PER_PAGE, substr_count($html, '<a class="entity-card'));
    }

    public function testThePagerAppearsOnceThereIsMoreThanOnePageAndLinksToPage2(): void
    {
        $this->createOrders(self::PER_PAGE + 5);
        $_GET['status'] = 'all';

        ob_start();
        (new OrderController())->index(['status' => 'all']);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('page=2', $html, 'with more than one page of orders, a link to page 2 must be offered');
        self::assertMatchesRegularExpression('/Page 1 of \d+/', $html);
    }

    public function testPage2ShowsADifferentSetOfOrdersThanPage1(): void
    {
        $this->createOrders(self::PER_PAGE + 5);

        $_GET = ['status' => 'all'];
        ob_start();
        (new OrderController())->index(['status' => 'all']);
        $page1 = (string) ob_get_clean();
        preg_match_all('/\/orders\/(\d+)"/', $page1, $page1Matches);

        $_GET = ['status' => 'all', 'page' => '2'];
        ob_start();
        (new OrderController())->index(['status' => 'all']);
        $page2 = (string) ob_get_clean();
        preg_match_all('/\/orders\/(\d+)"/', $page2, $page2Matches);

        self::assertNotEmpty($page2Matches[1], 'page 2 must actually show something, not come back empty');
        self::assertEmpty(array_intersect($page1Matches[1], $page2Matches[1]), 'page 1 and page 2 must never show the same order card');
    }

    public function testAPageNumberPastTheLastPageClampsToTheLastPageRatherThanShowingNothing(): void
    {
        $this->createOrders(3);
        $_GET = ['status' => 'all', 'page' => '99999'];

        ob_start();
        (new OrderController())->index(['status' => 'all']);
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString('No orders match this filter', $html, 'an out-of-range page number must clamp to the last real page, not render as if the filter matched nothing');
    }
}
