<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ProductController;
use App\Tests\Support\DbTestCase;

/**
 * External QA report R-07: /products/create logged a PHP warning
 * ("Trying to access array offset on value of type null",
 * views/products/_form.php) on every single page view. ProductController::
 * create() passes `'product' => null` (the create/add case), but
 * _form.php's four "Default ... Cost" fields indexed straight into
 * `$product['default_factory_cost']` etc. before checking `=== null` —
 * that raw access on a null $product is itself what warns, regardless of
 * what the check does next. Fixed by reading through `?? ''` first (the
 * same pattern the name/specifications/hs_code/origin fields above
 * already use), which never warns on a null array.
 */
final class ProductCreateFormNoWarningTest extends DbTestCase
{
    public function testRenderingTheEmptyCreateFormRaisesNoWarning(): void
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        }, E_WARNING | E_DEPRECATED | E_NOTICE);

        ob_start();
        (new ProductController())->create([]);
        $output = ob_get_clean();

        restore_error_handler();

        self::assertSame([], $warnings, 'rendering the empty "Add Product" form must not raise any PHP warning/deprecation/notice');
        self::assertStringContainsString('Add Product', $output);
    }
}
