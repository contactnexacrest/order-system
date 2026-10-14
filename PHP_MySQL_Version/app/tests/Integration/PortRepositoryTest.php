<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\PortRepository;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #59: `ports` already existed (schema.sql) and already
 * backed LookupRepository::ports($role), but had no admin CRUD at all —
 * only the single seeded Chennai (loading) row existed, so the New Order
 * form's Port of Discharge dropdown was genuinely empty. This covers the
 * new CRUD surface plus setDefault()'s role-scoping: a 'both'-role port
 * appears in BOTH the loading and discharge dropdowns
 * (LookupRepository::ports() matches `port_role = :role OR port_role =
 * 'both'`), so setting a 'both' port as default must clear every other
 * port's default flag, or a filtered query could return two rows both
 * marked is_default and the view would mark two <option>s "selected"
 * simultaneously.
 */
final class PortRepositoryTest extends DbTestCase
{
    public function testCreateThenFindReturnsAllFields(): void
    {
        $id = PortRepository::create('PHPUnit Test Port', 'Testland', 'discharge', 5);

        $row = PortRepository::find($id);

        self::assertNotNull($row);
        self::assertSame('PHPUnit Test Port', $row['name']);
        self::assertSame('Testland', $row['country']);
        self::assertSame('discharge', $row['port_role']);
        self::assertSame(5, (int) $row['sort_order']);
        self::assertSame(0, (int) $row['is_default']);
        self::assertSame(1, (int) $row['is_active']);
    }

    public function testCountryIsOptional(): void
    {
        $id = PortRepository::create('PHPUnit No-Country Port', null, 'loading', 1);

        self::assertNull(PortRepository::find($id)['country']);
    }

    public function testUpdateOverwritesFields(): void
    {
        $id = PortRepository::create('PHPUnit Original Port', 'Testland', 'loading', 1);

        PortRepository::update($id, 'PHPUnit Renamed Port', 'Newland', 'both', 2);

        $row = PortRepository::find($id);
        self::assertSame('PHPUnit Renamed Port', $row['name']);
        self::assertSame('Newland', $row['country']);
        self::assertSame('both', $row['port_role']);
        self::assertSame(2, (int) $row['sort_order']);
    }

    public function testToggleActiveFlipsBothWays(): void
    {
        $id = PortRepository::create('PHPUnit Toggle Port', null, 'discharge', 1);
        self::assertSame(1, (int) PortRepository::find($id)['is_active']);

        PortRepository::toggleActive($id);
        self::assertSame(0, (int) PortRepository::find($id)['is_active']);

        PortRepository::toggleActive($id);
        self::assertSame(1, (int) PortRepository::find($id)['is_active']);
    }

    public function testAllExcludesInactiveByDefaultButIncludesOnRequest(): void
    {
        $activeId = PortRepository::create('PHPUnit Active Port', null, 'discharge', 1);
        $inactiveId = PortRepository::create('PHPUnit Inactive Port', null, 'discharge', 2);
        PortRepository::toggleActive($inactiveId);

        $activeOnly = array_column(PortRepository::all(false), 'id');
        self::assertContains($activeId, $activeOnly);
        self::assertNotContains($inactiveId, $activeOnly);

        $withInactive = array_column(PortRepository::all(true), 'id');
        self::assertContains($activeId, $withInactive);
        self::assertContains($inactiveId, $withInactive);
    }

    public function testSetDefaultOnALoadingPortClearsOtherLoadingAndBothPortsOnly(): void
    {
        $loadingA = PortRepository::create('PHPUnit Loading A', null, 'loading', 1);
        $loadingB = PortRepository::create('PHPUnit Loading B', null, 'loading', 2);
        $bothPort = PortRepository::create('PHPUnit Both Port', null, 'both', 3);
        $dischargeOnly = PortRepository::create('PHPUnit Discharge Only', null, 'discharge', 4);
        PortRepository::setDefault($loadingA);
        PortRepository::setDefault($bothPort);
        // $bothPort being default cleared $loadingA's flag too (it appears in
        // the loading list) — set $dischargeOnly default next so there's a
        // non-'both' default to prove the targeted (non-'both') branch below.
        PortRepository::setDefault($dischargeOnly);

        PortRepository::setDefault($loadingB);

        self::assertSame(1, (int) PortRepository::find($loadingB)['is_default']);
        self::assertSame(0, (int) PortRepository::find($bothPort)['is_default'], "a 'both' port appears in the loading dropdown too, so it must lose its default flag");
        self::assertSame(1, (int) PortRepository::find($dischargeOnly)['is_default'], "a discharge-only port never appears in the loading dropdown, so its default flag must survive");
    }

    public function testSetDefaultOnABothRolePortClearsEveryOtherPort(): void
    {
        $loading = PortRepository::create('PHPUnit Loading X', null, 'loading', 1);
        $discharge = PortRepository::create('PHPUnit Discharge X', null, 'discharge', 2);
        $bothPort = PortRepository::create('PHPUnit Both X', null, 'both', 3);
        PortRepository::setDefault($loading);
        PortRepository::setDefault($discharge);

        PortRepository::setDefault($bothPort);

        self::assertSame(1, (int) PortRepository::find($bothPort)['is_default']);
        self::assertSame(0, (int) PortRepository::find($loading)['is_default'], "a 'both' port appears in BOTH dropdowns, so setting it default must clear the loading port's flag too");
        self::assertSame(0, (int) PortRepository::find($discharge)['is_default'], "a 'both' port appears in BOTH dropdowns, so setting it default must clear the discharge port's flag too");
    }
}
