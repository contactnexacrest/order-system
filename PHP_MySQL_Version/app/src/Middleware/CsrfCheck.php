<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Csrf;

final class CsrfCheck
{
    public static function verify(): callable
    {
        return function (array $params): bool {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                return true;
            }

            if (self::isLikelyOversizedUploadDrop()) {
                http_response_code(413);
                $max = (string) ini_get('post_max_size') ?: 'the configured limit';
                echo '<h1>413 — File too large</h1><p>The file you tried to upload is too large for this server to accept (max ' . htmlspecialchars($max) . '). Please use a smaller file and try again.</p>';
                return false;
            }

            if (!Csrf::verify($_POST['_csrf'] ?? null)) {
                http_response_code(419);
                echo '<h1>419 — Session expired</h1><p>Please go back, refresh the page, and try again.</p>';
                return false;
            }
            return true;
        };
    }

    /**
     * QA-5 UP-03: when a POST body exceeds php.ini's post_max_size, PHP
     * silently empties $_POST and $_FILES entirely before any application
     * code runs (and raises an E_WARNING nobody sees), rather than failing
     * just the offending file. CsrfCheck then saw no _csrf field and
     * reported the generic "419 — Session expired" — indistinguishable
     * from an actually-stale session, sending a staff member trying to
     * upload a large amendment/dispute document down the wrong
     * troubleshooting path entirely instead of telling them the real
     * problem. Detected by: a POST request with both $_POST and $_FILES
     * empty (the drop only clears these two — request headers survive) and
     * a declared Content-Length that actually exceeds post_max_size; a
     * genuinely empty form submission never has a Content-Length anywhere
     * near that size, so this can't misfire on a legitimate near-empty POST.
     */
    public static function isLikelyOversizedUploadDrop(): bool
    {
        if (!empty($_POST) || !empty($_FILES)) {
            return false;
        }
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength <= 0) {
            return false;
        }
        $postMaxSize = self::iniSizeToBytes((string) ini_get('post_max_size'));
        if ($postMaxSize <= 0) {
            return false; // 0 (or unset) means "no limit" in PHP's own semantics
        }
        return $contentLength > $postMaxSize;
    }

    /** Parses a php.ini shorthand size (e.g. "8M", "512K", "2G", "1024") into bytes. */
    public static function iniSizeToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $lastChar = strtolower($value[strlen($value) - 1]);
        $number = (int) $value;
        return match ($lastChar) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
