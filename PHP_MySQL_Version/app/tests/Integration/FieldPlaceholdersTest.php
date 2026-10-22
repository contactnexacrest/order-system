<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\SettingsController;
use App\Tests\Support\DbTestCase;

/**
 * External QA report U-03: "Fields without placeholders" (owner asked for
 * placeholders on every field) across ~20 pages — Users (name, phone),
 * Roles (name, description), Permission definitions, Reference
 * categories/edit, Annexure (name, description, technical notes), Reports
 * (save name), Signatories (title), Clients (unique-number override,
 * reason), Company Settings (all numeric settings). Each check reads the
 * view source directly (no DB/HTTP setup needed for a static-markup check)
 * and asserts the specific field's placeholder attribute is present.
 */
final class FieldPlaceholdersTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
    }

    private function view(string $relativePath): string
    {
        return (string) file_get_contents(__DIR__ . '/../../views/' . $relativePath);
    }

    public function testUsersCreateFormNameAndPhoneHavePlaceholders(): void
    {
        $html = $this->view('users/index.php');

        self::assertMatchesRegularExpression('/name="name"[^\n]*?placeholder=/', $html);
        self::assertMatchesRegularExpression('/name="phone"[^\n]*?placeholder=/', $html);
    }

    public function testRoleEditFormNameAndDescriptionHavePlaceholders(): void
    {
        $html = $this->view('permission_admin/role_edit.php');

        self::assertMatchesRegularExpression('/name="name"[^\n]*?placeholder=/', $html);
        self::assertMatchesRegularExpression('/name="description"[^\n]*?placeholder=/', $html);
    }

    public function testPermissionDefinitionEditFormHasPlaceholders(): void
    {
        $html = $this->view('permission_admin/permission_edit.php');

        self::assertMatchesRegularExpression('/name="name"[^\n]*?placeholder=/', $html);
        self::assertMatchesRegularExpression('/name="category"[^\n]*?placeholder=/', $html);
        self::assertMatchesRegularExpression('/name="description"[^\n]*?placeholder=/', $html);
    }

    public function testReferenceCategoryNameFieldsHavePlaceholders(): void
    {
        $html = $this->view('reference_docs/categories.php');

        self::assertSame(2, preg_match_all('/name="name"[^\n]*?placeholder=/', $html));
    }

    public function testAnnexureProductFieldsHavePlaceholders(): void
    {
        $html = $this->view('annexure/index.php');

        foreach (['name', 'description', 'technical_notes'] as $field) {
            self::assertSame(
                2,
                preg_match_all('/name="' . $field . '"[^\n]*?placeholder=/', $html),
                "expected both the edit-row and add-form \"$field\" field to have a placeholder"
            );
        }
    }

    public function testSavedReportRenameNameFieldHasPlaceholder(): void
    {
        $html = $this->view('reports/index.php');

        self::assertMatchesRegularExpression('/name="name"[^\n]*?placeholder=/', $html);
    }

    public function testSignatoryDesignationTitleFieldHasPlaceholder(): void
    {
        $html = $this->view('signatories/index.php');

        self::assertMatchesRegularExpression('/name="title"[^\n]*?placeholder=/', $html);
    }

    public function testClientUniqueNumberOverrideAndReasonHavePlaceholders(): void
    {
        $html = $this->view('clients/show.php');

        self::assertMatchesRegularExpression('/name="client_unique_number"[^\n]*?placeholder=/', $html);
        self::assertMatchesRegularExpression('/class="override-reason" name="reason"[^\n]*?placeholder=/', $html);
    }

    public function testCompanySettingsNumericFieldGetsAPlaceholder(): void
    {
        ob_start();
        (new SettingsController())->index([]);
        $html = (string) ob_get_clean();

        $marker = 'name="settings[quantity_shortfall_tolerance_pct]"';
        $pos = strpos($html, $marker);
        self::assertNotFalse($pos, 'quantity_shortfall_tolerance_pct must still be rendered as a settings field');
        $inputTag = substr($html, $pos, strpos($html, '>', $pos) - $pos);

        self::assertStringContainsString(
            'placeholder=',
            $inputTag,
            'a numeric Company Settings field must render with a placeholder hint'
        );
    }
}
