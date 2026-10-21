<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\NotificationRepository;
use App\Tests\Support\DbTestCase;

require_once __DIR__ . '/../../cron/check_alerts.php';

/**
 * 90-item backlog "expiry alerts": orders.quotation_valid_until and
 * pi_valid_until (rendered on every QT/PI as the buyer-facing "valid for
 * N days" disclaimer since the very first version of these documents)
 * had no cron alert at all — unlike LUT/RCMC expiry and FDN/dispute
 * overdue, which check_alerts.php already covered. A buyer accepting
 * stale pricing/terms after the window lapses, with nobody on staff
 * aware the order is sitting on an expired Quotation or PI, is exactly
 * the kind of risk this alert system exists to catch.
 */
final class ExpiryAlertsTest extends DbTestCase
{
    private function setOrderDates(int $orderId, array $fields): void
    {
        $pdo = Database::connection();
        $sets = [];
        $params = ['id' => $orderId];
        foreach ($fields as $column => $value) {
            $sets[] = "{$column} = :{$column}";
            $params[$column] = $value;
        }
        $pdo->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
    }

    private function setOrderStage(int $orderId, int $stageNumber): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM stages_master WHERE stage_number = :n');
        $stmt->execute(['n' => $stageNumber]);
        $stageId = (int) $stmt->fetchColumn();
        $pdo->prepare('UPDATE orders SET current_stage_id = :stage_id WHERE id = :id')
            ->execute(['stage_id' => $stageId, 'id' => $orderId]);
    }

    public function testAlertsForAnOrderWithAnExpiredQuotationAndNoPiYet(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $adminId = $this->createTestUser('Admin');
        $this->setOrderDates($orderId, ['quotation_valid_until' => '2020-01-01']);

        \run();

        self::assertTrue(NotificationRepository::existsToday($adminId, 'quotation_expired', $orderId));
    }

    public function testDoesNotAlertWhenQuotationIsStillValid(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $adminId = $this->createTestUser('Admin');
        // createTestOrder() already sets quotation_valid_until 30 days out.

        \run();

        self::assertFalse(NotificationRepository::existsToday($adminId, 'quotation_expired', $orderId));
    }

    public function testDoesNotAlertOnQuotationExpiryOnceAPiHasBeenIssued(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $adminId = $this->createTestUser('Admin');
        $this->setOrderDates($orderId, ['quotation_valid_until' => '2020-01-01', 'pi_date' => '2024-01-01']);

        \run();

        self::assertFalse(NotificationRepository::existsToday($adminId, 'quotation_expired', $orderId));
    }

    public function testAlertsForAnOrderWithAnExpiredPiStillNotConfirmed(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $adminId = $this->createTestUser('Admin');
        $this->setOrderDates($orderId, ['pi_date' => '2020-01-01', 'pi_valid_until' => '2020-01-16']);
        $this->setOrderStage($orderId, 3);

        \run();

        self::assertTrue(NotificationRepository::existsToday($adminId, 'pi_expired', $orderId));
    }

    public function testDoesNotAlertOnPiExpiryOnceOrderConfirmationIsReached(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $adminId = $this->createTestUser('Admin');
        $this->setOrderDates($orderId, ['pi_date' => '2020-01-01', 'pi_valid_until' => '2020-01-16']);
        $this->setOrderStage($orderId, 4);

        \run();

        self::assertFalse(NotificationRepository::existsToday($adminId, 'pi_expired', $orderId));
    }

    public function testDoesNotAlertForALockedOrder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $adminId = $this->createTestUser('Admin');
        $this->setOrderDates($orderId, ['quotation_valid_until' => '2020-01-01']);
        Database::connection()->prepare('UPDATE orders SET is_locked = 1, status = \'lost\' WHERE id = :id')
            ->execute(['id' => $orderId]);

        \run();

        self::assertFalse(NotificationRepository::existsToday($adminId, 'quotation_expired', $orderId));
    }
}
