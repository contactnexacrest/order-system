<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * QA Round 8, R8-N08: every form field has a label, and a label shows "*"
 * exactly when its field must be filled.
 *
 *  - Every visible input, select and textarea has a <label> (wrapping it or
 *    label[for]) or an aria-label / aria-labelledby.
 *  - A label written with "*" belongs to a field marked `required` (or
 *    required by a rule: data-required-if / data-required-unless).
 *  - A required field whose label has no "*" gets one in the browser:
 *    markRequiredLabels() in the sitewide app.js, run on load and whenever a
 *    field becomes (or stops being) required.
 *  - Required drop-downs with no sensible default start with "Choose…";
 *    number fields where a negative makes no sense have a min.
 *
 * The Node suite has the same test (formLabelConventions.test.js).
 */
final class FormLabelConventionsTest extends TestCase
{
    private const VIEWS = __DIR__ . '/../../views';
    private const APP_JS = __DIR__ . '/../../../public_html/assets/js/app.js';
    private const NOT_FIELDS = ['hidden', 'submit', 'button', 'reset', 'image'];

    /** Views with <script> blocks blanked out (keeping line numbers): "<select>" in a JS comment is not a field. */
    private static function views(): array
    {
        $out = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::VIEWS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $text = (string) file_get_contents($file->getPathname());
            $text = (string) preg_replace_callback('/<script\b.*?<\/script>/s', static fn(array $m): string => str_repeat("\n", substr_count($m[0], "\n")), $text);
            $out[substr($file->getPathname(), strlen(self::VIEWS) + 1)] = $text;
        }
        ksort($out);
        return $out;
    }

    /** The opening tag that starts at $start, reading past PHP blocks that contain ">". */
    private static function openingTag(string $text, int $start): string
    {
        $j = $start + 1;
        $len = strlen($text);
        while ($j < $len) {
            if (substr($text, $j, 2) === '<?') {
                $k = strpos($text, '?>', $j);
                $j = $k === false ? $len : $k + 2;
                continue;
            }
            if ($text[$j] === '>') {
                break;
            }
            $j++;
        }
        return substr($text, $start, $j - $start + 1);
    }

    public function testEveryVisibleFieldHasALabel(): void
    {
        $missing = [];
        foreach (self::views() as $rel => $text) {
            preg_match_all('/<label[^>]*\bfor="([^"]+)"/', $text, $f);
            $labelledIds = array_flip($f[1]);
            preg_match_all('/<(input|select|textarea)\b/', $text, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[0] as [, $start]) {
                $tag = self::openingTag($text, $start);
                $type = preg_match('/\btype\s*=\s*["\']([a-zA-Z-]+)["\']/', $tag, $t) ? strtolower($t[1]) : 'text';
                if (str_starts_with($tag, '<input') && in_array($type, self::NOT_FIELDS, true)) {
                    continue;
                }
                if (str_contains($tag, 'aria-label')) {
                    continue;
                }
                if (preg_match('/\bid\s*=\s*"([^"]+)"/', $tag, $id) && isset($labelledIds[$id[1]])) {
                    continue;
                }
                // client_intake's country picker is a closure called inside <label>Country *</label>.
                if (str_contains($tag, '$idPrefix')) {
                    continue;
                }
                $before = substr($text, 0, $start);
                if (substr_count($before, '<label') - substr_count($before, '</label>') > 0) {
                    continue;
                }
                $missing[] = $rel . ':' . (substr_count($before, "\n") + 1);
            }
        }
        self::assertSame([], $missing, 'Every visible form field needs a <label> or an aria-label (QA Round 8, R8-N08).');
    }

    public function testALabelWrittenWithAStarBelongsToARequiredField(): void
    {
        $wrong = [];
        foreach (self::views() as $rel => $text) {
            preg_match_all('/<label\b[^>]*>(.*?)<\/label>/s', $text, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[1] as $i => [$body]) {
                if (!preg_match('/<(input|select|textarea)\b/', $body, $c, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                $own = (string) preg_replace('/<\?.*?\?>/s', '', substr($body, 0, $c[0][1]));
                $own = strip_tags($own);
                if (!preg_match('/(^|\s)\*(\s|$)/', $own)) {
                    continue;
                }
                $tag = self::openingTag($body, $c[0][1]);
                if (preg_match('/\brequired\b|data-required-(if|unless)/', $tag)) {
                    continue;
                }
                $wrong[] = $rel . ':' . (substr_count($text, "\n", 0, $m[0][$i][1]) + 1) . ' ' . trim(preg_replace('/\s+/', ' ', $own));
            }
        }
        self::assertSame([], $wrong, 'A label with "*" must belong to a required field (QA Round 8, R8-N08).');
    }

    public function testTheSitewideScriptStarsEveryRequiredLabelOnce(): void
    {
        $js = (string) file_get_contents(self::APP_JS);
        self::assertStringContainsString('function markRequiredLabels()', $js);
        self::assertStringContainsString("star.className = 'req-star';", $js);
        self::assertStringContainsString("star.textContent = ' *';", $js);
        // Never a second star: a label already written with "*" is left alone, and the added one goes when the field stops being required.
        self::assertStringContainsString("writtenLabelText(label).indexOf('*') !== -1", $js);
        self::assertStringContainsString('added.remove();', $js);
        self::assertStringContainsString("attributeFilter: ['required', 'disabled']", $js);
        self::assertMatchesRegularExpression('/function init\(\) \{.*initRequiredLabels\(\);/s', $js);
    }

    /** @return array<string, array{0: string, 1: string}> required drop-downs given a "Choose…" first line */
    public static function chooseSelects(): array
    {
        return [
            'cross-verify result' => ['orders/show.php', '<select name="result" required aria-label="Result">'],
            'logistics bill for' => ['orders/show.php', '<select name="bill_for" required>'],
            'expense / cost category' => ['orders/show.php', '<select name="category" required>'],
            'travel expense %' => ['orders/show.php', '<select name="expense_pct" required>'],
            'inspection fee payer' => ['orders/show.php', '<select name="fee_payer" required>'],
            'export benefit scheme' => ['orders/show.php', '<select name="scheme_name" required>'],
            'force-generate document type' => ['orders/show.php', '<select name="document_type" required>'],
            'new order incoterm' => ['orders/create.php', '<select name="incoterm_id" id="incoterm-select" required onchange="updateFreightFieldsAvailability(true)">'],
            'new order payment preset' => ['orders/create.php', '<select name="payment_preset_id" required>'],
            'new port role' => ['ports/create.php', '<select name="port_role" required>'],
            'new designation level' => ['departments/admin.php', '<select name="level" required>'],
        ];
    }

    #[DataProvider('chooseSelects')]
    public function testRequiredDropDownWithoutADefaultStartsWithChoose(string $view, string $opening): void
    {
        $text = (string) file_get_contents(self::VIEWS . '/' . $view);
        $count = substr_count($text, $opening);
        self::assertGreaterThan(0, $count, "{$opening} not found in {$view}");
        self::assertSame($count, preg_match_all('/' . preg_quote($opening, '/') . '\s*<option value="">Choose…<\/option>/', $text));
    }

    /**
     * Every drop-down's empty first option reads "Choose…" (or "Choose a …"), on
     * any drop-down, required or not — never the older "Select…", "— Select —",
     * "— choose —" or "Please select". Checks the views (scripts included: an
     * option built in JS) and the sitewide app.js.
     */
    public function testNoEmptyOptionUsesTheOldSelectWording(): void
    {
        $old = '/^\s*(?:[—–-]+\s*)?(?:please\s+)?(?:select|pick)\b|^\s*[—–-]+\s*choose\s*[—–-]*\s*$/iu';
        $sources = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::VIEWS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $sources[substr($file->getPathname(), strlen(self::VIEWS) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }
        $sources['public_html/assets/js/app.js'] = (string) file_get_contents(self::APP_JS);
        $wrong = [];
        foreach ($sources as $rel => $text) {
            $found = [];
            preg_match_all('/<option\b[^>]*\bvalue=(["\'])\1[^>]*>([^<]*)</', $text, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[2] as [$label, $at]) {
                $found[] = [$label, $at];
            }
            // An option built in JS: var x = document.createElement('option'); … x.textContent = '…';
            preg_match_all('/\b(\w+)\s*=\s*document\.createElement\([\'"]option[\'"]\)/', $text, $v);
            foreach (array_unique($v[1]) as $var) {
                preg_match_all('/\b' . preg_quote($var, '/') . '\.(?:textContent|text|innerText)\s*=\s*([\'"])(.*?)\1/', $text, $t, PREG_OFFSET_CAPTURE);
                foreach ($t[2] as [$label, $at]) {
                    $found[] = [$label, $at];
                }
            }
            foreach ($found as [$label, $at]) {
                if (preg_match($old, html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) {
                    $wrong[] = $rel . ':' . (substr_count($text, "\n", 0, $at) + 1) . ' ' . trim($label);
                }
            }
        }
        self::assertSame([], $wrong, 'An empty first option reads "Choose…" (or "Choose a …"), not "Select…" / "— Select —" / "— choose —".');
    }

    public function testNumberFieldsThatCannotBeNegativeHaveAMin(): void
    {
        $audit = (string) file_get_contents(self::VIEWS . '/audit_log/index.php');
        self::assertStringContainsString('<input type="number" min="1" step="1" name="entity_id"', $audit);
        self::assertStringContainsString('<input type="number" min="1" step="1" name="user_id"', $audit);
        $form = (string) file_get_contents(self::VIEWS . '/products/_form.php');
        $show = (string) file_get_contents(self::VIEWS . '/products/show.php');
        // D-335 (12 Nov 2026): the product form has "Costs to FOB" (CHA + transport per container, packing + loading
        // per unit — typed amounts, never negative); the old default cost fields are gone from it.
        self::assertStringContainsString('name="packing_loading_cost" data-max-decimals="2"', $form);
        self::assertStringContainsString('name="cha_transport_per_container" data-max-decimals="2"', $form);
        // D-338 (12 Nov 2026): a new supplier price is one typed FOB value; the cost parts appear only when an
        // existing "Computed" price is edited. Every such number field still carries min="0".
        foreach (['factory', 'transportation', 'packing', 'loading', 'cha'] as $cost) {
            self::assertStringNotContainsString("name=\"default_{$cost}_cost\"", $form);
            self::assertGreaterThanOrEqual(1, substr_count($show, "<input type=\"number\" step=\"0.01\" min=\"0\" name=\"{$cost}_cost\""));
            self::assertSame(substr_count($show, "name=\"{$cost}_cost\""), substr_count($show, "<input type=\"number\" step=\"0.01\" min=\"0\" name=\"{$cost}_cost\""));
        }
        self::assertGreaterThanOrEqual(2, substr_count($show, '<input type="number" step="0.01" min="0" name="fob_value"'));
        self::assertSame(substr_count($show, 'name="fob_value"'), substr_count($show, '<input type="number" step="0.01" min="0" name="fob_value"'));
    }
}
