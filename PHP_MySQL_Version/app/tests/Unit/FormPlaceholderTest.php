<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Owner rule (2026-11-01): every input form must have placeholders. Every
 * typed input (text, email, tel, number, search, url, password) and every
 * textarea, existing or new, shows a placeholder with an example or the
 * expected format. Not on date, file, checkbox, radio, hidden, button or
 * read-only display fields. The Node suite has the same test
 * (formPlaceholder.test.js).
 */
final class FormPlaceholderTest extends TestCase
{
    private const VIEWS = __DIR__ . '/../../views';
    private const NOT_TYPED = ['hidden', 'checkbox', 'radio', 'file', 'date', 'datetime-local', 'time', 'month', 'week',
        'submit', 'button', 'reset', 'image', 'color', 'range'];

    /** @return list<array{0: int, 1: string}> each opening <input>/<textarea> tag and its offset, reading past PHP blocks that contain ">" */
    private static function tags(string $text): array
    {
        $out = [];
        preg_match_all('/<(input|textarea)\b/', $text, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [$open, $start]) {
            $j = $start + strlen($open);
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
            $out[] = [$start, substr($text, $start, $j - $start + 1)];
        }
        return $out;
    }

    public function testEveryTypedInputAndTextareaHasAPlaceholder(): void
    {
        $missing = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::VIEWS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $text = (string) file_get_contents($file->getPathname());
            foreach (self::tags($text) as [$offset, $tag]) {
                $type = preg_match('/\btype\s*=\s*["\']([a-zA-Z-]+)["\']/', $tag, $t) ? strtolower($t[1]) : 'text';
                if (str_starts_with($tag, '<input') && in_array($type, self::NOT_TYPED, true)) {
                    continue;
                }
                if (preg_match('/\btype\s*=\s*["\'][^"\']*<\?/', $tag) || preg_match('/\breadonly\b/', $tag) || str_contains($tag, 'placeholder')) {
                    continue;
                }
                $missing[] = substr($file->getPathname(), strlen(self::VIEWS) + 1) . ':' . (substr_count($text, "\n", 0, $offset) + 1);
            }
        }
        self::assertSame([], $missing, 'Every typed input and textarea needs a placeholder (owner rule 2026-11-01).');
    }
}
