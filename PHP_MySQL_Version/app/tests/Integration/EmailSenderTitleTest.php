<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\CompanySettingsRepository;
use App\Services\EmailDispatchService;
use App\Tests\Support\DbTestCase;

/**
 * QA-5 (EML-08 — external QA report cross-verification): {sender_title}
 * (and the {sender_signature} fallback that embeds it) always resolved to
 * the company-wide md_title setting ("Founder & Managing Director"),
 * regardless of who actually sent the email. A Logistics or Accounts
 * Executive's approved send to a buyer would go out signed with the MD's
 * own title, misrepresenting who at the company the buyer is actually
 * dealing with.
 */
final class EmailSenderTitleTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    public function testSendPreviewUsesTheSendersOwnDesignationNotTheMdsTitle(): void
    {
        $mdTitle = (string) CompanySettingsRepository::get('md_title');
        self::assertNotSame('', $mdTitle, 'sanity check: md_title must actually be configured for this test to mean anything');

        $designationId = $this->createTestDesignation('Export Manager');
        $senderId = $this->createTestUser('Export Executive');
        Database::connection()
            ->prepare('UPDATE users SET designation_id = :did WHERE id = :id')
            ->execute(['did' => $designationId, 'id' => $senderId]);

        $clientId = $this->createTestClient();
        $this->setClientEmail($clientId, 'buyer@example.test');
        $orderId = $this->createTestOrder($clientId);

        $preview = EmailDispatchService::buildPreview($orderId, null, 'payment_followup', $senderId);

        self::assertStringContainsString('Export Manager', $preview['body']);
        self::assertStringNotContainsString($mdTitle, $preview['body'], "the sender's own designation must replace the MD's title, not sit alongside it");
    }

    /**
     * External QA report L-12: a sender with no designation set still fell
     * all the way back to md_title ("Founder & Managing Director"), so e.g.
     * an Export Executive's email went out signed with the MD's own title.
     * It must fall back to the sender's own role name instead.
     */
    public function testSendPreviewFallsBackToTheSendersRoleNameWhenTheSenderHasNoDesignation(): void
    {
        $mdTitle = (string) CompanySettingsRepository::get('md_title');
        $senderId = $this->createTestUser('Export Executive'); // designation_id left NULL

        $clientId = $this->createTestClient();
        $this->setClientEmail($clientId, 'buyer@example.test');
        $orderId = $this->createTestOrder($clientId);

        $preview = EmailDispatchService::buildPreview($orderId, null, 'payment_followup', $senderId);

        self::assertStringContainsString('Export Executive', $preview['body']);
        self::assertStringNotContainsString($mdTitle, $preview['body'], "a sender with no designation must be signed with their own role name, never the MD's title");
    }

    private function createTestDesignation(string $title): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO designations (title) VALUES (:title)');
        $stmt->execute(['title' => $title . '-' . bin2hex(random_bytes(3))]);
        return (int) $pdo->lastInsertId();
    }

    private function setClientEmail(int $clientId, string $email): void
    {
        Database::connection()
            ->prepare('UPDATE clients SET email = :email WHERE id = :id')
            ->execute(['email' => $email, 'id' => $clientId]);
    }
}
