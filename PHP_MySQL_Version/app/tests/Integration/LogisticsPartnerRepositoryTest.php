<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\LogisticsPartnerRepository;
use App\Tests\Support\DbTestCase;

/**
 * CHA / Transportation partner directory (docs/schema.sql Section AQ) —
 * covers the CRUD surface plus the one piece of real logic: a partner
 * with service_type 'both' must show up when filtering for either 'cha'
 * or 'transportation' alone, since that's the whole point of the flag
 * (the same company sometimes does one, sometimes the other, sometimes
 * both).
 */
final class LogisticsPartnerRepositoryTest extends DbTestCase
{
    private function minimalData(array $overrides = []): array
    {
        return array_merge([
            'partner_name' => 'PHPUnit Test Logistics Co',
            'service_type' => 'cha',
            'address'      => '1 Test Port Road',
            'city'         => 'Test City',
            'state'        => 'Test State',
            'phone'        => '9999900000',
            'whatsapp_number' => '9999900000',
            'email'        => 'ops@phpunit-test-logistics.test',
            'contact_person_name'     => 'Test Contact',
            'contact_person_phone'    => '9999900001',
            'contact_person_whatsapp' => '9999900001',
            'gstin'        => '27TESTG1234F1Z5',
            'pan'          => 'TESTG1234F',
            'notes'        => 'PHPUnit fixture',
        ], $overrides);
    }

    public function testCreateThenFindReturnsAllFields(): void
    {
        $userId = $this->createTestUser('Admin');
        $id = LogisticsPartnerRepository::create($this->minimalData(), $userId);

        $row = LogisticsPartnerRepository::find($id);

        self::assertNotNull($row);
        self::assertSame('PHPUnit Test Logistics Co', $row['partner_name']);
        self::assertSame('cha', $row['service_type']);
        self::assertSame('27TESTG1234F1Z5', $row['gstin']);
        self::assertSame(1, (int) $row['is_active']);
        self::assertSame($userId, (int) $row['created_by']);
    }

    public function testUpdateOverwritesFields(): void
    {
        $userId = $this->createTestUser('Admin');
        $id = LogisticsPartnerRepository::create($this->minimalData(), $userId);

        LogisticsPartnerRepository::update($id, $this->minimalData([
            'partner_name' => 'PHPUnit Renamed Logistics Co',
            'service_type' => 'both',
            'gstin'        => '27TESTG9999F1Z5',
        ]));

        $row = LogisticsPartnerRepository::find($id);
        self::assertSame('PHPUnit Renamed Logistics Co', $row['partner_name']);
        self::assertSame('both', $row['service_type']);
        self::assertSame('27TESTG9999F1Z5', $row['gstin']);
    }

    public function testToggleActiveFlipsBothWays(): void
    {
        $userId = $this->createTestUser('Admin');
        $id = LogisticsPartnerRepository::create($this->minimalData(), $userId);
        self::assertSame(1, (int) LogisticsPartnerRepository::find($id)['is_active']);

        LogisticsPartnerRepository::toggleActive($id);
        self::assertSame(0, (int) LogisticsPartnerRepository::find($id)['is_active']);

        LogisticsPartnerRepository::toggleActive($id);
        self::assertSame(1, (int) LogisticsPartnerRepository::find($id)['is_active']);
    }

    public function testDeleteRemovesTheRow(): void
    {
        $userId = $this->createTestUser('Admin');
        $id = LogisticsPartnerRepository::create($this->minimalData(), $userId);

        LogisticsPartnerRepository::delete($id);

        self::assertNull(LogisticsPartnerRepository::find($id));
    }

    public function testAllExcludesInactiveByDefaultButIncludesOnRequest(): void
    {
        $userId = $this->createTestUser('Admin');
        $activeId = LogisticsPartnerRepository::create($this->minimalData(['partner_name' => 'PHPUnit Active Co']), $userId);
        $inactiveId = LogisticsPartnerRepository::create($this->minimalData(['partner_name' => 'PHPUnit Inactive Co']), $userId);
        LogisticsPartnerRepository::toggleActive($inactiveId);

        $activeOnly = LogisticsPartnerRepository::all(false);
        $ids = array_column($activeOnly, 'id');
        self::assertContains($activeId, $ids);
        self::assertNotContains($inactiveId, $ids);

        $withInactive = LogisticsPartnerRepository::all(true);
        $idsWithInactive = array_column($withInactive, 'id');
        self::assertContains($activeId, $idsWithInactive);
        self::assertContains($inactiveId, $idsWithInactive);
    }

    public function testServiceTypeFilterMatchesItsOwnTypeAndBoth(): void
    {
        $userId = $this->createTestUser('Admin');
        $chaOnlyId = LogisticsPartnerRepository::create($this->minimalData([
            'partner_name' => 'PHPUnit CHA Only Co', 'service_type' => 'cha',
        ]), $userId);
        $transportOnlyId = LogisticsPartnerRepository::create($this->minimalData([
            'partner_name' => 'PHPUnit Transport Only Co', 'service_type' => 'transportation',
        ]), $userId);
        $bothId = LogisticsPartnerRepository::create($this->minimalData([
            'partner_name' => 'PHPUnit Both Co', 'service_type' => 'both',
        ]), $userId);

        $chaFiltered = array_column(LogisticsPartnerRepository::all(false, 'cha'), 'id');
        self::assertContains($chaOnlyId, $chaFiltered);
        self::assertContains($bothId, $chaFiltered, "a 'both' partner must appear in the CHA-filtered list too");
        self::assertNotContains($transportOnlyId, $chaFiltered);

        $transportFiltered = array_column(LogisticsPartnerRepository::all(false, 'transportation'), 'id');
        self::assertContains($transportOnlyId, $transportFiltered);
        self::assertContains($bothId, $transportFiltered, "a 'both' partner must appear in the transportation-filtered list too");
        self::assertNotContains($chaOnlyId, $transportFiltered);
    }

    public function testOptionalFieldsDefaultToNull(): void
    {
        $userId = $this->createTestUser('Admin');
        $id = LogisticsPartnerRepository::create([
            'partner_name' => 'PHPUnit Minimal Co',
            'service_type' => 'transportation',
        ], $userId);

        $row = LogisticsPartnerRepository::find($id);
        self::assertNull($row['address']);
        self::assertNull($row['gstin']);
        self::assertNull($row['contact_person_name']);
        self::assertNull($row['notes']);
    }
}
