<?php

declare(strict_types=1);

namespace App\Tests\Integration;

/**
 * External QA report U-04: "Fields without a visible label" (accessibility;
 * unclear for new staff) across ~14 pages — Holidays (date), Super Admin
 * (delegate, target, expiry), Permissions (user, permission), Signatories
 * (user selects), Reports (name, visibility), Reference doc edit (content),
 * Clients (copy-link box). Each check reads the view source directly and
 * asserts the specific field now carries an accessible name — either a
 * visible <label> or an aria-label (both are acceptable per the QA report's
 * own "Add <label> / aria-label" fix column).
 */
final class FieldLabelsTest extends \PHPUnit\Framework\TestCase
{
    private function view(string $relativePath): string
    {
        return (string) file_get_contents(__DIR__ . '/../../views/' . $relativePath);
    }

    public function testHolidayDateFieldsHaveAnAccessibleLabel(): void
    {
        $html = $this->view('holidays/index.php');

        self::assertSame(2, preg_match_all('/name="holiday_date"[^\n]*?aria-label=/', $html));
    }

    public function testSuperAdminDelegateTargetAndExpiryHaveAnAccessibleLabel(): void
    {
        $html = $this->view('super_admin/index.php');

        self::assertMatchesRegularExpression('/name="target_id"[^\n]*?aria-label=/', $html);
        self::assertMatchesRegularExpression('/name="delegate_user_id"[^\n]*?aria-label=/', $html);
        self::assertMatchesRegularExpression('/name="expires_at"[^\n]*?aria-label=/', $html);
    }

    public function testGrantExtraPermissionUserAndPermissionHaveAnAccessibleLabel(): void
    {
        $html = $this->view('permission_admin/index.php');

        self::assertMatchesRegularExpression('/name="user_id"[^\n]*?aria-label=/', $html);
        self::assertMatchesRegularExpression('/name="permission_id"[^\n]*?aria-label=/', $html);
    }

    public function testSignatoryUserSelectsHaveAnAccessibleLabel(): void
    {
        $html = $this->view('signatories/index.php');

        self::assertSame(2, preg_match_all('/name="user_id"[^\n]*?aria-label=/', $html));
        self::assertMatchesRegularExpression('/name="designation_id"[^\n]*?aria-label=/', $html);
    }

    public function testSavedReportNameAndVisibilityHaveAnAccessibleLabel(): void
    {
        $html = $this->view('reports/index.php');

        self::assertMatchesRegularExpression('/name="name"[^\n]*?aria-label=/', $html);
        self::assertMatchesRegularExpression('/name="visibility"[^\n]*?aria-label=/', $html);

        $aggregateHtml = $this->view('reports/aggregate.php');
        self::assertStringContainsString('>Visibility', $aggregateHtml);
    }

    public function testReferenceDocEditContentHasAVisibleLabel(): void
    {
        $html = $this->view('reference_docs/edit.php');

        self::assertMatchesRegularExpression('/<label[^>]*for="reference-doc-content"[^>]*>Content<\/label>/', $html);
        self::assertStringContainsString('id="reference-doc-content"', $html);
    }

    public function testClientsCopyLinkBoxHasAnAccessibleLabel(): void
    {
        $html = $this->view('clients/index.php');

        self::assertMatchesRegularExpression('/readonly[^\n]*?aria-label=/', $html);
    }
}
