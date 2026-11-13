<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * QA round 13 Nov 2026 — UI, navigation and form fixes (UI-004 …). Parity:
 * qaUiBatchNov13.test.js (which also runs the Reorder script against a small fake DOM).
 */
final class QaUiBatchNov13Test extends TestCase
{
    private const VIEWS = __DIR__ . '/../../views';
    private const NODE_VIEWS = __DIR__ . '/../../../../NODE_MySQL_Version/app/views';

    /** UI-004: "+ Add product line" clones a row kept from page load when the buyer/staff removed every line. */
    public function testUi004AddProductLineDoesNotNeedARemainingRow(): void
    {
        foreach ([
            self::VIEWS . '/client_portal/reorder.php', self::VIEWS . '/orders/create.php',
            self::NODE_VIEWS . '/client_portal/reorder.njk', self::NODE_VIEWS . '/orders/create.njk',
        ] as $file) {
            $text = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/rows\.firstElementChild\.cloneNode/', $text, $file);
            self::assertMatchesRegularExpression('/var \w+Template = document\.getElementById\(\'product-rows\'\)\.firstElementChild\.cloneNode\(true\);/', $text, $file);
            self::assertMatchesRegularExpression('/\(rows\.firstElementChild \|\| \w+Template\)\.cloneNode/', $text, $file);
        }
    }

    /** UI-011: an order line's quantity of 0 is stopped in the browser (app.js data-min-exclusive; the Jest twin runs the rule). */
    public function testUi011QuantityZeroIsCaughtInTheBrowser(): void
    {
        foreach ([__DIR__ . '/../../../public_html/assets/js/app.js', __DIR__ . '/../../../../NODE_MySQL_Version/app/public/js/app.js'] as $file) {
            $js = (string) file_get_contents($file);
            self::assertStringContainsString("el.dataset.minExclusive !== undefined", $js, $file);
            self::assertStringContainsString("parseFloat(value) <= parseFloat(el.dataset.minExclusive)", $js, $file);
            self::assertStringContainsString("el.dataset.minExclusiveUnless", $js, $file);
        }
        foreach ([
            [self::VIEWS . '/orders/create.php', 'product_quantity[]', false], [self::NODE_VIEWS . '/orders/create.njk', 'product_quantity[]', false],
            [self::VIEWS . '/orders/_products_table.php', 'quantity', true], [self::NODE_VIEWS . '/orders/_products_table.njk', 'quantity', true],
        ] as [$file, $name, $hasTbc]) {
            self::assertSame(1, preg_match('#<input type="text" name="' . preg_quote($name, '#') . '"[^>]*>#', (string) file_get_contents($file), $m), $file);
            self::assertStringContainsString('data-min-exclusive="0" data-min-exclusive-message="The quantity must be more than 0."', $m[0], $file);
            self::assertSame($hasTbc, str_contains($m[0], 'data-min-exclusive-unless="quantity_is_tbc"'), $file);
        }
    }

    /** UI-012: every typed setting on Settings › Company shows a placeholder (the Jest twin compares both lists). */
    public function testUi012SettingsPlaceholders(): void
    {
        self::assertSame('e.g. Company Name Private Limited', \App\Helpers\SettingLabels::placeholder('legal_name', 'string'));
        self::assertSame('e.g. +91-0000000000', \App\Helpers\SettingLabels::placeholder('phone', 'string'));
        self::assertSame('e.g., 30', \App\Helpers\SettingLabels::placeholder('some_days', 'number'));
        self::assertSame('e.g. info@email.com', \App\Helpers\SettingLabels::placeholder('new_mail', 'email'));
        self::assertSame('e.g. md@email.com, ed@email.com', \App\Helpers\SettingLabels::placeholder('new_list', 'email_list'));
        self::assertSame('Type the lut expiry note', \App\Helpers\SettingLabels::placeholder('lut_expiry_note', 'string'));
        foreach (\App\Helpers\SettingLabels::PLACEHOLDERS as $key => $text) {
            preg_match_all('/[\w.+-]+@[\w.-]+/', $text, $emails);
            foreach ($emails[0] as $email) {
                self::assertMatchesRegularExpression('/^(?:info|md|ed|admin)@email\.com$/', $email, $key);
            }
        }
        // The view never makes the placeholder depend on the value type (FormPlaceholderTest checks every view).
        $view = (string) file_get_contents(self::VIEWS . '/settings/index.php');
        self::assertSame(2, substr_count($view, 'placeholder="<?= htmlspecialchars(\App\Helpers\SettingLabels::placeholder('));
    }

    /**
     * UI-016: the order-message file field is attachments[] on both stacks, and a
     * field posted without "[]" (one file) is no longer silently dropped.
     */
    public function testUi016MessageFilesUnderEitherFieldName(): void
    {
        foreach ([self::VIEWS . '/orders/show.php', self::VIEWS . '/client_portal/order_show.php',
            self::NODE_VIEWS . '/orders/show.njk', self::NODE_VIEWS . '/client_portal/order_show.njk'] as $file) {
            self::assertStringContainsString('<input type="file" name="attachments[]" multiple', (string) file_get_contents($file), $file);
        }
        $saved = $_FILES;
        try {
            $_FILES = ['attachments' => ['name' => 'photo.jpg', 'type' => 'image/jpeg', 'tmp_name' => '/tmp/none', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0]];
            self::assertSame([], \App\Services\FileUploadService::handleMultipleUploads('attachments', 'order_comment_media', 'x', null, 1, 1));
            // A single file under "attachments" is now read (here a broken upload, so it is refused rather than ignored).
            $_FILES['attachments']['error'] = UPLOAD_ERR_PARTIAL;
            try {
                \App\Services\FileUploadService::handleMultipleUploads('attachments', 'order_comment_media', 'x', null, 1, 1);
                self::fail('a single file under "attachments" was ignored');
            } catch (\RuntimeException $e) {
                self::assertSame('Upload failed for "photo.jpg".', $e->getMessage());
            }
        } finally {
            $_FILES = $saved;
        }
    }

    /** UI-010: the field names checked (each one VARCHAR column of one length in docs/schema.sql); same list as the Jest twin. */
    private const MAXLENGTH_COLUMNS = 'acknowledged_note advance_trigger_text amended_balance_terms amendment_reference bank_account_name bank_branch
        bank_name bank_upi_id billing_address_line1 billing_address_line2 billing_city billing_country billing_postcode bl_number buyer_own_reference
        buyers_po_ref clause_title client_unique_number colour_reference company_legal_name consignee_address_line1 consignee_address_line2
        consignee_city consignee_contact_person consignee_country consignee_email consignee_name consignee_phone consignee_postcode
        consignee_vat_eori_tax_no contact_email contact_person contact_person_name contact_person_phone contact_person_whatsapp contact_phone
        container_no container_type_text country_of_destination courier_tracking_number crate_no decision_notes delivery_location dimensions
        est_lead_time_text est_shipment_date_text estimated_package_count finish from_party grade hs_code incoterm_confirmed incoterm_preference
        lc_bank_name lc_number location material_stone_type notify_party notify_party_address_line1 notify_party_address_line2 notify_party_city
        notify_party_contact_person notify_party_country notify_party_email notify_party_phone notify_party_postcode order_reference origin
        packing_requirement pan partner_name payer_bank_details port_of_discharge_text preset_name production_status_text ref_format
        reference_number refund_notes resolved_reason seal_no shipping_line state supplier_legal_name supplier_name transaction_ref unlock_reason
        usage_note vat_eori_tax_no vessel_name vessel_voyage voyage_number whatsapp_number';

    /**
     * UI-010: a typed field saved to a VARCHAR column carries a maxlength no larger than
     * the column's length, so a too-long value (a 600-character company name) is stopped
     * in the browser beside the field (a stricter format limit, e.g. PAN 10, is fine).
     */
    public function testUi010TextFieldsCarryTheMaxlengthOfTheirColumn(): void
    {
        $schema = (string) file_get_contents(__DIR__ . '/../../../docs/schema.sql');
        $lengths = [];
        foreach (preg_split('/\s+/', trim(self::MAXLENGTH_COLUMNS)) as $name) {
            preg_match_all('/^\s*(?:(?:ADD|MODIFY) COLUMN\s+)?`?' . $name . '`?\s+(VARCHAR\((\d+)\)|TEXT|MEDIUMTEXT|LONGTEXT)/mi', $schema, $m, PREG_SET_ORDER);
            $found = array_values(array_unique(array_map(static fn (array $x): string => ($x[2] ?? '') !== '' ? $x[2] : strtoupper($x[1]), $m)));
            self::assertCount(1, $found, $name);
            $lengths[$name] = (int) $found[0];
        }
        self::assertSame(255, $lengths['company_legal_name']);
        $wrong = [];
        $checked = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::VIEWS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $text = (string) file_get_contents($file->getPathname());
            preg_match_all('/<(input|textarea)\b/', $text, $opens, PREG_OFFSET_CAPTURE);
            foreach ($opens[0] as [$open, $start]) {
                $j = $start + strlen($open);
                $len = strlen($text);
                while ($j < $len && $text[$j] !== '>') {
                    $j = substr($text, $j, 2) === '<?' ? (strpos($text, '?>', $j) === false ? $len : strpos($text, '?>', $j) + 2) : $j + 1;
                }
                $tag = substr($text, $start, $j - $start + 1);
                $type = preg_match('/\btype\s*=\s*["\']([a-zA-Z-]+)["\']/', $tag, $t) ? strtolower($t[1]) : 'text';
                if (str_starts_with($tag, '<input') && !in_array($type, ['text', 'email', 'tel', 'url', 'search', 'password'], true)) {
                    continue;
                }
                if (!preg_match('/\bname\s*=\s*["\']([A-Za-z0-9_]+)/', $tag, $n) || !isset($lengths[$n[1]])) {
                    continue;
                }
                $checked++;
                $max = preg_match('/\bmaxlength\s*=\s*["\'](\d+)["\']/', $tag, $x) ? (int) $x[1] : null;
                if ($max === null || $max > $lengths[$n[1]]) {
                    $wrong[] = substr($file->getPathname(), strlen(self::VIEWS) + 1) . ':' . (substr_count($text, "\n", 0, $start) + 1) . " {$n[1]} maxlength=" . ($max ?? 'none') . " (column {$lengths[$n[1]]})";
                }
            }
        }
        self::assertGreaterThan(200, $checked);
        self::assertSame([], $wrong);
    }

    /** MTX-002: the e2e roles/permissions matrix visits every route, so its inventory lists every route of index.php. */
    public function testMtx002TheQaRouteInventoryListsEveryRoute(): void
    {
        $inventory = json_decode((string) file_get_contents(__DIR__ . '/../../../../e2e/support/qa-inventory.json'), true);
        $norm = static fn (string $method, string $path): string => $method . ' ' . preg_replace('/\{\w+\}/', '{}', $path);
        $listed = array_flip(array_map(static fn (array $r): string => $norm($r['method'], $r['path']), $inventory['routes']));
        $missing = [];
        $loop = null;
        foreach (explode("\n", (string) file_get_contents(__DIR__ . '/../../../public_html/index.php')) as $line) {
            if (preg_match('/^\s*foreach \(\[(.*)\] as \$__a => \$__m\)/', $line, $l)) {
                preg_match_all("/'(\w+)' =>/", $l[1], $vals);
                $loop = $vals[1];
                continue;
            }
            if (!preg_match("/^\s*\\\$router->(get|post)\('([^']+)'(\s*\.\s*\\\$__a)?,/", $line, $m)) {
                continue;
            }
            foreach (!empty($m[3]) ? array_map(static fn (string $v): string => $m[2] . $v, $loop) : [$m[2]] as $path) {
                $key = $norm(strtoupper($m[1]), $path);
                if (!isset($listed[$key])) {
                    $missing[] = $key;
                }
            }
        }
        self::assertSame([], $missing);
        $keys = array_column($inventory['permissions'], 'key');
        self::assertContains('approve_buyer_details_change', $keys);
        self::assertContains('view_associate_commission_rules', $keys);
    }

    /**
     * UI-008: every fixed internal link in a view leads to a page route (the TDS
     * register linked to /associates/report, which does not exist). A route
     * parameter named …id only takes digits, as the controllers expect.
     */
    public function testUi008EveryFixedLinkInAViewHasAPageRoute(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../public_html/index.php');
        preg_match_all("#\\\$router->get\\('([^']+)'#", $source, $m);
        $routes = array_map(
            static fn (string $p): string => '#^' . preg_replace_callback(
                '#\\\\\{([^}]+)\\\\\}#',
                static fn (array $x): string => preg_match('/id$/i', $x[1]) ? '\d+' : '[^/]+',
                preg_quote($p, '#')
            ) . '/?$#',
            $m[1]
        );
        self::assertGreaterThan(200, count($routes));
        $dead = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::VIEWS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('#href="(/[^"<{\'$]*)"#', (string) file_get_contents($file->getPathname()), $hrefs);
            foreach ($hrefs[1] as $href) {
                $path = (string) preg_split('/[?#]/', $href)[0];
                if (preg_match('#^/(?:assets|css|js|img|favicon)#', $path) || str_starts_with($path, '//')) {
                    continue;
                }
                $found = false;
                foreach ($routes as $r) {
                    if (preg_match($r, $path)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $dead[] = substr($file->getPathname(), strlen(self::VIEWS) + 1) . ': ' . $href;
                }
            }
        }
        self::assertSame([], $dead);
    }
}
