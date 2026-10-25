<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Config\Env;

/**
 * External QA report L-45: generated PDFs were 2.2-2.7MB — too large for
 * many recipients' inboxes. Root cause: every raster image a document
 * embeds (company logo/signature/seal/watermark — up to
 * App\Controllers\AssetController::MAX_BYTES = 5MB each, and Annexure A
 * product photos, which have no size cap of their own at all beyond the
 * shared file_upload_contexts limit) was read straight off disk and
 * base64-embedded as-is in DocumentDataAssembler/DocumentGenerationService
 * — even though none of these ever needs to render larger than a few
 * centimetres on an A4 page. A handful of phone-camera product photos, or
 * even just one near-the-cap logo/seal/signature/watermark upload,
 * directly accounts for the exact size range reported.
 *
 * This resizes/recompresses a *copy* for embedding only — the original
 * upload on disk (and AssetController::preview()'s management-UI view of
 * it, and FileStoreRepository's own download of an Annexure photo) is
 * never touched, so nothing about asset management/history changes.
 * Results are cached on disk (storage/pdf_image_cache, same reasoning as
 * DocumentGenerationService's own font cache: this app's one
 * guaranteed-writable directory) keyed by the source file's own path, so
 * repeat document generation against an unchanged upload never
 * re-decodes/re-encodes it.
 */
final class DocumentImageOptimizer
{
    private const MAX_DIMENSION = 1000; // px — generous for a logo/seal/signature/watermark/product photo on an A4 page
    private const JPEG_QUALITY = 82;

    public static function dataUriForEmbed(string $path, string $mimeType): string
    {
        $raw = (string) file_get_contents($path);

        if ($mimeType === 'image/svg+xml') {
            // Vector — already small, resizing makes no sense.
            return 'data:' . $mimeType . ';base64,' . base64_encode($raw);
        }

        $cachePath = self::cachePathFor($path);
        if (is_file($cachePath) && filemtime($cachePath) >= filemtime($path)) {
            return (string) file_get_contents($cachePath);
        }

        $dataUri = self::render($raw, $mimeType)
            ?? ('data:' . $mimeType . ';base64,' . base64_encode($raw)); // unreadable/corrupt — fall back to the raw file rather than fail the whole document

        $cacheDir = dirname($cachePath);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        file_put_contents($cachePath, $dataUri);

        return $dataUri;
    }

    private static function render(string $raw, string $mimeType): ?string
    {
        $source = @imagecreatefromstring($raw);
        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, self::MAX_DIMENSION / max($width, $height));

        if ($scale >= 1.0) {
            $resized = $source;
            // imagecreatefromstring() leaves saveAlpha off, so without this
            // imagepng() below drops the alpha channel and every transparent
            // pixel (typically stored as 0,0,0) comes out solid black — the
            // "black box" behind every signature/seal small enough to skip
            // resizing. The resize branch already sets both.
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        } else {
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($source);
        }

        $hasAlpha = self::hasTransparency($resized);

        ob_start();
        if ($hasAlpha) {
            imagepng($resized, null, 9);
            $outMime = 'image/png';
        } else {
            imagejpeg($resized, null, self::JPEG_QUALITY);
            $outMime = 'image/jpeg';
        }
        $bytes = (string) ob_get_clean();
        imagedestroy($resized);

        return 'data:' . $outMime . ';base64,' . base64_encode($bytes);
    }

    /**
     * A handful of sample points rather than scanning every pixel — enough
     * to catch the overwhelmingly common case (a logo/signature/seal PNG
     * with a transparent background). A false negative here only costs a
     * slightly larger JPEG where a PNG would have been smaller/cleaner,
     * never a broken image.
     *
     * @param \GdImage $image
     */
    private static function hasTransparency($image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $stepX = max(1, (int) ($width / 10));
        $stepY = max(1, (int) ($height / 10));
        for ($x = 0; $x < $width; $x += $stepX) {
            for ($y = 0; $y < $height; $y += $stepY) {
                $rgba = imagecolorat($image, $x, $y);
                if ((($rgba & 0x7F000000) >> 24) !== 0) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function cachePathFor(string $path): string
    {
        $dir = rtrim(Env::storageBasePath(), '/') . '/pdf_image_cache';
        // 'v2|' — bumped when render() stopped flattening transparency to
        // black, so data URIs cached before that fix are never served again.
        return $dir . '/' . hash('sha256', 'v2|' . $path) . '.cache';
    }
}
