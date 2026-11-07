<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;

/**
 * D-267 (pending owner double confirmation): a stored render context
 * (documents.render_context_json — what a revision was rendered from, so its
 * FINAL / SUPERSEDED copies are rebuilt from exactly what was reviewed and sent)
 * used to embed every image (company seal, signatory seal, signature, logo,
 * watermark) as a base64 data URI — up to 3.7 MB per document, repeated in every
 * document.
 *
 * Now each image is kept ONCE, exactly as embedded (the very same data URI
 * string, byte for byte), in the insert-only table document_image_blobs, keyed
 * by its SHA-256. The stored context holds only a small reference:
 *   {"__image_ref": "sha256:<64 hex>", "store": "document_image_blobs", "mime": "image/png", "length": <bytes>}
 * Re-rendering puts the identical data URI back after checking its SHA-256, so a
 * rebuilt document is byte-identical in its images. A missing or altered image
 * stops the re-render with an error naming the document and the image — never a
 * silent substitution with a different or current image.
 *
 * The image is copied as it was embedded (after DocumentImageOptimizer), not
 * re-derived from the asset file: replacing a seal / logo later, deleting an
 * asset file, or a different image library in years to come cannot change an
 * old document. Rows are never updated or deleted (DB triggers refuse it).
 * Contexts stored before this change still hold the data URIs inline and are
 * left untouched (they render exactly as before).
 */
final class RenderContextImages
{
    public const REF_KEY = '__image_ref';
    public const STORE = 'document_image_blobs';
    private const DATA_URI = '#^data:([a-z]+/[a-z0-9.+-]+);base64,#i';

    /**
     * Replaces every embedded image with a reference, saving the image once.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function pack(array $context): array
    {
        return self::packValue($context);
    }

    /** @return mixed */
    private static function packValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::packValue($v);
            }
            return $out;
        }
        if (is_string($value) && preg_match(self::DATA_URI, $value, $m) === 1) {
            $sha = hash('sha256', $value);
            $mime = strtolower($m[1]);
            Database::connection()->prepare(
                'INSERT IGNORE INTO document_image_blobs (sha256, mime_type, byte_length, data_uri) VALUES (:s, :m, :l, :d)'
            )->execute(['s' => $sha, 'm' => $mime, 'l' => strlen($value), 'd' => $value]);
            return [self::REF_KEY => 'sha256:' . $sha, 'store' => self::STORE, 'mime' => $mime, 'length' => strlen($value)];
        }
        return $value;
    }

    /**
     * Puts each referenced image back, exactly as it was stored.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     * @throws \RuntimeException when an image is missing or does not match its SHA-256
     */
    public static function unpack(array $context, int $documentId): array
    {
        return self::unpackValue($context, $documentId, '');
    }

    /** @return mixed */
    private static function unpackValue(mixed $value, int $documentId, string $path): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (isset($value[self::REF_KEY]) && is_string($value[self::REF_KEY])) {
            return self::resolve($value, $documentId, $path);
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::unpackValue($v, $documentId, $path === '' ? (string) $k : $path . '.' . $k);
        }
        return $out;
    }

    /** @param array<string,mixed> $ref */
    private static function resolve(array $ref, int $documentId, string $path): string
    {
        $sha = substr((string) $ref[self::REF_KEY], strlen('sha256:'));
        $stmt = Database::connection()->prepare('SELECT data_uri FROM document_image_blobs WHERE sha256 = :s');
        $stmt->execute(['s' => $sha]);
        $dataUri = $stmt->fetchColumn();
        if (!is_string($dataUri) || $dataUri === '') {
            throw new \RuntimeException(sprintf(
                'Document #%d cannot be re-rendered: its stored image "%s" (sha256 %s) is missing from %s. Nothing was changed; restore that image from a backup.',
                $documentId, $path, $sha, self::STORE
            ));
        }
        if (!hash_equals($sha, hash('sha256', $dataUri))) {
            throw new \RuntimeException(sprintf(
                'Document #%d cannot be re-rendered: its stored image "%s" does not match its SHA-256 (%s) — it has been altered. Nothing was changed; restore that image from a backup.',
                $documentId, $path, $sha
            ));
        }
        return $dataUri;
    }
}
