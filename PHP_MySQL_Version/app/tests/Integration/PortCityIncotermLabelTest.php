<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\PortRepository;
use App\Services\DocumentDataAssembler;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog "ports city field": Incoterms® 2020 convention prints
 * the CITY alone (e.g. "FOB Mumbai"), never the formal terminal name
 * (e.g. "FOB Nhava Sheva (JNPT)") — the Ports admin screen's own Port
 * Name field explicitly invites a real terminal name
 * (placeholder "e.g., Nhava Sheva (JNPT)"), so once staff enter one,
 * every generated document's Incoterm label became wrong. ports.city
 * is a new, separate field DocumentDataAssembler/AmendmentService now
 * read for this, falling back to the port's own name/text for any
 * older/incomplete row.
 */
final class PortCityIncotermLabelTest extends DbTestCase
{
    public function testIncotermLabelUsesThePortsCityNotItsTerminalName(): void
    {
        $portId = PortRepository::create('Nhava Sheva (JNPT)', 'Mumbai', 'India', 'loading', 99);
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', $portId);

        $data = DocumentDataAssembler::assemble($orderId);

        self::assertSame('FOB Mumbai — Incoterms® 2020', $data['order']['incoterm_label']);
        self::assertSame('Mumbai', $data['order']['port_of_loading']);
    }

    public function testIncotermLabelFallsBackToThePortNameWhenCityIsBlank(): void
    {
        $portId = PortRepository::create('Nhava Sheva (JNPT)', null, 'India', 'loading', 98);
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', $portId);

        $data = DocumentDataAssembler::assemble($orderId);

        self::assertSame('FOB Nhava Sheva (JNPT) — Incoterms® 2020', $data['order']['incoterm_label'], 'no city on file yet must fall back to the port name, not disappear');
    }
}
