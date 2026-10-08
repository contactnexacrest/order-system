<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientIntakeController;
use App\Tests\Support\DbTestCase;

/**
 * QA-5 INT-05: the public quotation-intake form (no auth, no CAPTCHA, no
 * per-order token) accepted unlimited submissions from a single IP — the QA
 * report's own reproduction sent 30 requests in a burst and all 30 were
 * accepted into the staff review queue. Pre-seeds rate_limit_hits with the
 * bucket already at its limit so a single real submit() call exercises the
 * blocking path directly, rather than needing 5 real submissions (each of
 * which would send a real correction-link email through EmailService).
 */
final class QuotationIntakeRateLimitTest extends DbTestCase
{
    private const BUCKET = 'quotation_intake_submit';

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
    }

    public function testSubmissionIsBlockedOnceTheLimitIsAlreadyReached(): void
    {
        $ip = '203.0.113.5';
        $this->seedHits(self::BUCKET, $ip, 5);

        $before = $this->submissionCount();
        $_SERVER['REMOTE_ADDR'] = $ip;
        $_POST = $this->validSubmission();

        ob_start();
        (new ClientIntakeController())->submit([]);
        ob_end_clean();

        self::assertSame($before, $this->submissionCount(), 'a blocked request must not create a submission row');
        $flash = $_SESSION['_flash'] ?? [];
        self::assertNotEmpty($flash);
        self::assertStringContainsString('Too many submissions', $flash[0]['message']);
    }

    public function testSubmissionSucceedsWhenUnderTheLimit(): void
    {
        $ip = '203.0.113.6';
        $this->seedHits(self::BUCKET, $ip, 3); // under the limit of 5

        $before = $this->submissionCount();
        $_SERVER['REMOTE_ADDR'] = $ip;
        $_POST = $this->validSubmission();

        ob_start();
        (new ClientIntakeController())->submit([]);
        ob_end_clean();

        self::assertSame($before + 1, $this->submissionCount(), 'a request under the limit must create a submission row');
    }

    private function seedHits(string $bucket, string $ip, int $count): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO rate_limit_hits (bucket_key, ip_address) VALUES (:bucket, :ip)');
        for ($i = 0; $i < $count; $i++) {
            $stmt->execute(['bucket' => $bucket, 'ip' => $ip]);
        }
    }

    private function submissionCount(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM client_intake_submissions')->fetchColumn();
    }

    /** @return array<string,string> */
    private function validSubmission(): array
    {
        return [
            'company_legal_name'     => 'Rate Limit Test Co',
            'billing_address_line1'  => '1 Test Street',
            'billing_city'           => 'Testville',
            'billing_country'        => 'Testland',
            'vat_eori_tax_no'        => 'VAT123',
            'contact_person'         => 'Jane Test',
            'email'                  => 'jane-' . bin2hex(random_bytes(4)) . '@example.test',
            'country_of_destination' => 'Testland',
            'incoterm_preference'    => 'FOB',
        ];
    }
}
