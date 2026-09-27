<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\ClientRepository;
use App\Repositories\CompanyHolidayRepository;
use App\Repositories\EmailTemplateRepository;
use App\Repositories\HsCodeRepository;
use App\Repositories\InternalReferenceDocRepository;
use App\Repositories\ProductRepository;
use App\Repositories\SignatoryRepository;
use App\Repositories\WatermarkSettingsRepository;
use App\Tests\Support\DbTestCase;

/**
 * QA-4 P3 (docs/QA/TEST_PLAN.md Section 6): standard create/read/update/
 * delete correctness for the modules with no state-machine or
 * cross-tenant dimension of their own — Clients, Product Catalog,
 * Reference Docs, HS Codes, Watermarks, Holidays, Email Templates,
 * Signatories.
 */
final class CoreCrudTest extends DbTestCase
{
    public function testClientCrud(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $id = ClientRepository::create([
            'company_legal_name' => 'PHPUnit CRUD Client',
            'billing_address'    => 'Address v1',
            'consignee_name'     => 'Consignee v1',
            'consignee_address'  => 'Consignee Addr v1',
            'email'              => 'crud-client@example.test',
        ], $userId, 'CRUD-TEST-' . bin2hex(random_bytes(4)));

        $found = ClientRepository::find($id);
        self::assertSame('PHPUnit CRUD Client', $found['company_legal_name']);
        self::assertSame('Address v1', $found['billing_address']);
        self::assertSame(1, (int) $found['is_active']);

        ClientRepository::update($id, [
            'company_legal_name' => 'PHPUnit CRUD Client Updated',
            'billing_address'    => 'Address v2',
            'consignee_name'     => 'Consignee v1',
            'consignee_address'  => 'Consignee Addr v1',
            'email'              => 'crud-client@example.test',
        ]);
        $updated = ClientRepository::find($id);
        self::assertSame('PHPUnit CRUD Client Updated', $updated['company_legal_name']);
        self::assertSame('Address v2', $updated['billing_address']);

        ClientRepository::setActive($id, false);
        self::assertSame(0, (int) ClientRepository::find($id)['is_active']);
        $inactiveIds = array_column(ClientRepository::allInactive(), 'id');
        self::assertContains($id, $inactiveIds);

        ClientRepository::setActive($id, true);
        self::assertSame(1, (int) ClientRepository::find($id)['is_active']);
    }

    public function testHsCodeCrud(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $code = (string) random_int(100000, 999999);
        $id = HsCodeRepository::create($code, 'PHPUnit test description', $userId);

        $found = HsCodeRepository::findByCode($code);
        self::assertSame('PHPUnit test description', $found['description']);
        self::assertTrue(HsCodeRepository::isActiveCode($code));

        HsCodeRepository::updateDescription($id, 'Updated description');
        self::assertSame('Updated description', HsCodeRepository::findByCode($code)['description']);

        HsCodeRepository::toggleActive($id);
        self::assertFalse(HsCodeRepository::isActiveCode($code));
        HsCodeRepository::toggleActive($id);
        self::assertTrue(HsCodeRepository::isActiveCode($code));

        self::assertSame(0, HsCodeRepository::usageCount($code), 'a brand-new HS code must start with zero usages');

        HsCodeRepository::delete($id);
        self::assertNull(HsCodeRepository::findByCode($code));
    }

    private function watermarkData(string $textContent, int $opacity): array
    {
        return [
            'mode' => 'text', 'text_content' => $textContent, 'font' => 'Helvetica', 'font_size' => 48,
            'color' => '#888888', 'opacity' => $opacity, 'angle' => 45,
            'image_asset_id' => null, 'image_opacity' => null, 'image_position' => null,
        ];
    }

    public function testWatermarkSettingsUpsertIsGenuinelyAnUpsertNotADuplicateInsert(): void
    {
        $userId = $this->createTestUser('Export Executive');
        // 'global' draft-mode is a real, shared, company-wide row (the one
        // DocumentGenerationService::draftWatermark() actually reads) —
        // capture and restore it rather than leaving test data behind.
        $before = WatermarkSettingsRepository::findGlobal(true);

        WatermarkSettingsRepository::upsertGlobal(true, $this->watermarkData('DRAFT v1', 30), $userId);
        $first = WatermarkSettingsRepository::findGlobal(true);
        self::assertSame('DRAFT v1', $first['text_content']);

        WatermarkSettingsRepository::upsertGlobal(true, $this->watermarkData('DRAFT v2', 45), $userId);
        $second = WatermarkSettingsRepository::findGlobal(true);
        self::assertSame('DRAFT v2', $second['text_content']);
        self::assertSame((int) $first['id'], (int) $second['id'], 'a second upsertGlobal() call for the same scope must update the existing row, not insert a duplicate');

        if ($before) {
            WatermarkSettingsRepository::upsertGlobal(true, [
                'mode' => $before['mode'], 'text_content' => $before['text_content'], 'font' => $before['font'],
                'font_size' => $before['font_size'], 'color' => $before['color'], 'opacity' => $before['opacity'],
                'angle' => $before['angle'], 'image_asset_id' => $before['image_asset_id'],
                'image_opacity' => $before['image_opacity'], 'image_position' => $before['image_position'],
            ], $userId);
        }
    }

    public function testCompanyHolidayCrud(): void
    {
        $date = '2030-01-26';
        $id = CompanyHolidayRepository::create($date, 'PHPUnit Test Holiday', null);

        $found = CompanyHolidayRepository::find($id);
        self::assertSame('PHPUnit Test Holiday', $found['description']);
        $inRange = CompanyHolidayRepository::datesBetween('2030-01-01', '2030-01-31');
        self::assertContains($date, $inRange);

        CompanyHolidayRepository::update($id, $date, 'Updated Holiday Name');
        self::assertSame('Updated Holiday Name', CompanyHolidayRepository::find($id)['description']);

        CompanyHolidayRepository::delete($id);
        self::assertNull(CompanyHolidayRepository::find($id));
    }

    public function testEmailTemplateCrud(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $key = 'phpunit_test_template_' . bin2hex(random_bytes(3));
        self::assertFalse(EmailTemplateRepository::keyExists($key));

        $id = EmailTemplateRepository::create($key, 'Subject v1', 'Body v1', null, $userId);
        self::assertTrue(EmailTemplateRepository::keyExists($key));

        $found = EmailTemplateRepository::find($key);
        self::assertSame('Subject v1', $found['subject']);
        self::assertSame(1, (int) $found['is_active'], 'a newly created template must be active by default');

        EmailTemplateRepository::update($id, 'Subject v2', 'Body v2', 'Footer v2', $userId);
        $updated = EmailTemplateRepository::find($key);
        self::assertSame('Subject v2', $updated['subject']);
        self::assertSame('Footer v2', $updated['footer']);

        EmailTemplateRepository::setActive($id, false, $userId);
        self::assertSame(0, (int) EmailTemplateRepository::find($key)['is_active']);
    }

    public function testProductCatalogCrud(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $id = ProductRepository::create([
            'name'           => 'PHPUnit Test Product',
            'specifications' => 'Spec v1',
            'hs_code'        => '6802.93',
        ], $userId);

        $found = ProductRepository::find($id);
        self::assertSame('PHPUnit Test Product', $found['name']);
        self::assertSame(1, (int) $found['is_active']);

        ProductRepository::update($id, [
            'name'           => 'PHPUnit Test Product Updated',
            'specifications' => 'Spec v2',
            'hs_code'        => '6802.93',
            'is_active'      => 0,
        ], $userId);
        $updated = ProductRepository::find($id);
        self::assertSame('PHPUnit Test Product Updated', $updated['name']);
        self::assertSame(0, (int) $updated['is_active']);

        $activeOnlyIds = array_column(ProductRepository::all(true), 'id');
        self::assertNotContains($id, $activeOnlyIds, 'an inactive product must not appear in the active-only catalog listing');

        ProductRepository::delete($id);
        self::assertNull(ProductRepository::find($id));
    }

    public function testInternalReferenceDocUpsertIsGenuinelyAnUpsert(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $before = InternalReferenceDocRepository::findByCode('WALLREF');
        $documentTypeId = (int) $before['document_type_id'];

        InternalReferenceDocRepository::upsert($documentTypeId, 'PHPUnit content v1', $userId);
        self::assertSame('PHPUnit content v1', InternalReferenceDocRepository::findByCode('WALLREF')['content']);

        InternalReferenceDocRepository::upsert($documentTypeId, 'PHPUnit content v2', $userId);
        self::assertSame('PHPUnit content v2', InternalReferenceDocRepository::findByCode('WALLREF')['content']);

        // Restore whatever real content existed before this test touched it
        // — WALLREF is a real, shared, company-wide row, not a disposable
        // per-test fixture.
        InternalReferenceDocRepository::upsert($documentTypeId, (string) ($before['content'] ?? ''), $userId);
    }

    public function testSignatoryDesignationCrud(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $id = SignatoryRepository::createDesignation('PHPUnit Test Designation', $userId);

        $found = SignatoryRepository::findDesignation($id);
        self::assertSame('PHPUnit Test Designation', $found['title']);
        self::assertSame(1, (int) $found['is_active']);
        self::assertSame(0, SignatoryRepository::designationUsageCount($id));

        SignatoryRepository::updateDesignationTitle($id, 'Updated Designation Title');
        self::assertSame('Updated Designation Title', SignatoryRepository::findDesignation($id)['title']);

        SignatoryRepository::toggleDesignationActive($id);
        self::assertSame(0, (int) SignatoryRepository::findDesignation($id)['is_active']);
        SignatoryRepository::toggleDesignationActive($id);
        self::assertSame(1, (int) SignatoryRepository::findDesignation($id)['is_active']);

        SignatoryRepository::deleteDesignation($id);
        self::assertNull(SignatoryRepository::findDesignation($id));
    }
}
