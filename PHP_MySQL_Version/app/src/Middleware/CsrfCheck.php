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
            if (!Csrf::verify($_POST['_csrf'] ?? null)) {
                http_response_code(419);
                echo '<h1>419 — Session expired</h1><p>Please go back, refresh the page, and try again.</p>';
                return false;
            }
            return true;
        };
    }
}
