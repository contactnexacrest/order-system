<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The data export writes the whole database to a temporary file (well over 1 GB on a busy
 * system). It must be deleted even when the browser goes away part-way: PHP stops a script
 * when the client disconnects, so the delete must not depend on reaching the end of readfile().
 */
final class DataExportTempFileCleanupTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/DataExportController.php');
    }

    public function testTheDownloadKeepsRunningAndRemovesTheFileEvenIfTheClientDisconnects(): void
    {
        $src = $this->source();
        self::assertStringContainsString('ignore_user_abort(true);', $src);
        self::assertMatchesRegularExpression('/register_shutdown_function\(static function \(\) use \(\$tmpPath\): void \{\s*if \(is_file\(\$tmpPath\)\) \{\s*@unlink\(\$tmpPath\);/', $src);
        self::assertMatchesRegularExpression('/try \{\s*readfile\(\$tmpPath\);\s*\} finally \{\s*if \(is_file\(\$tmpPath\)\) \{\s*@unlink\(\$tmpPath\);/', $src);
    }

    public function testNoBareUnlinkThatOnlyRunsAfterAFullDownload(): void
    {
        self::assertDoesNotMatchRegularExpression('/readfile\(\$tmpPath\);\s*unlink\(\$tmpPath\);/', $this->source());
    }
}
