<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;

/**
 * SMS is optional/pluggable per the user's instruction: "SMS should not
 * be a blocker — if available use both, else only email." isAvailable()
 * is the single gate every caller checks; nothing else in the app assumes
 * SMS exists. Wire the real provider's HTTP call inside send() once you've
 * signed up for one (Twilio, MSG91, etc.) and put its key in .env.
 */
final class SmsService
{
    public static function isAvailable(): bool
    {
        return (bool) Env::get('SMS_GATEWAY_API_KEY');
    }

    public static function send(string $toPhone, string $message): bool
    {
        if (!self::isAvailable()) {
            return false;
        }

        // Placeholder — implement the actual provider call here (Twilio/MSG91/etc.)
        // using Env::get('SMS_GATEWAY_PROVIDER'), Env::get('SMS_GATEWAY_API_KEY'),
        // Env::get('SMS_GATEWAY_SENDER_ID'). Left unimplemented until a
        // provider is chosen — isAvailable() being false is what keeps this
        // from being called at all until then.
        error_log("[SMS NOT SENT — gateway integration not yet implemented] To: {$toPhone} | Message: {$message}");
        return false;
    }
}
