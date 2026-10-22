<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;

/**
 * External QA report L-24: orders.buyer_action_token_plain used to store
 * the raw Buyer Action Link token next to its SHA-256 hash — needed
 * because staff re-view/re-copy the live link from the order page at any
 * time before it expires (views/orders/show.php), not just once at
 * generation — so "show it once and never store it" isn't viable without
 * changing that UX. Anyone with DB or backup read access could instead
 * use every live link (OC acknowledgement, Buyer PO upload, payment
 * report) directly from the raw column. Reversible AES-256-GCM encryption
 * under APP_ENCRYPTION_KEY (already provisioned in .env, previously
 * unused anywhere in the codebase) closes that exposure while keeping the
 * re-display feature: the column holds ciphertext, decrypted only when
 * actually rendering the link back to staff.
 */
final class EncryptionService
{
    private const CIPHER = 'aes-256-gcm';

    /** sha256() accepts APP_ENCRYPTION_KEY at any length and derives a real 32-byte AES-256 key from it. */
    private static function key(): string
    {
        $raw = Env::get('APP_ENCRYPTION_KEY');
        if (!$raw) {
            throw new \RuntimeException('APP_ENCRYPTION_KEY is not configured in .env.');
        }
        return hash('sha256', $raw, true);
    }

    /** @return string base64(iv . authTag . ciphertext) — a single opaque column value. */
    public static function encrypt(string $plaintext): string
    {
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivLength);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return base64_encode($iv . $tag . $ciphertext);
    }

    /** @return string|null null if $encoded is empty/not valid ciphertext (e.g. a pre-encryption plaintext row, or tampering) rather than throwing. */
    public static function decrypt(?string $encoded): ?string
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            return null;
        }
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $tagLength = 16;
        if (strlen($raw) < $ivLength + $tagLength) {
            return null;
        }
        $iv = substr($raw, 0, $ivLength);
        $tag = substr($raw, $ivLength, $tagLength);
        $ciphertext = substr($raw, $ivLength + $tagLength);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plaintext === false ? null : $plaintext;
    }
}
