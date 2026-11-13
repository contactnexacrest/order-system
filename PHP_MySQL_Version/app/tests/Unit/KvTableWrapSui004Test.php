<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** QA SUI-004 (13 Nov 2026): key/value tables wrap long values so a phone page never scrolls sideways. */
final class KvTableWrapSui004Test extends TestCase
{
    public function testKvTableCellsWrapLongValues(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public_html/assets/css/app.css');
        $this->assertMatchesRegularExpression('/table\.kv td, table\.kv th \{ overflow-wrap: anywhere; \}/', $css);
    }
}
