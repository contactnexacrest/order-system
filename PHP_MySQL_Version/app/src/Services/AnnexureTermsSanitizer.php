<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Locks down the "Additional Terms" WYSIWYG content (order_annexure_terms.
 * content_html) to a small allowlist of formatting tags/attributes before
 * it's ever written to the DB, and again every time it's read back for
 * document generation (DocumentDataAssembler emits it unescaped — |raw in
 * Twig — into every generated PDF, and PhpWord's Html::addHtml() parses it
 * directly into a DOCX, so this is the one and only barrier between a
 * stored <script>/onerror= payload and it actually executing in whatever
 * renders the output). Re-sanitizing on read as well as on save means a
 * row written before a stricter config ships, or inserted by anything
 * other than updateTerms(), still comes out clean.
 *
 * <img> is restricted to data: URIs only (same reasoning as
 * DocumentDataAssembler::annexureProductsBlock() — DOMPDF's
 * isRemoteEnabled=false means a generated PDF can never depend on a live
 * HTTP fetch) which also rules out tracking-pixel-style remote images and
 * the privacy/tracking-pixel leak a remote <img> would otherwise allow
 * every time this annexure is opened. <video>/<audio> are not in the
 * allowlist at all: neither a PDF nor a DOCX can play embedded media, so
 * there is nothing safe to keep — HTMLPurifier drops disallowed elements
 * and their content outright.
 */
final class AnnexureTermsSanitizer
{
    private static ?\HTMLPurifier $purifier = null;

    public static function sanitize(string $html): string
    {
        $purified = self::purifier()->purify($html);
        return trim(self::postProcess($purified));
    }

    /**
     * Two restrictions HTMLPurifier's own config can't express because
     * URI.AllowedSchemes and HTML.Allowed's attribute list are both global
     * (not per-attribute/per-tag):
     *
     * - <img src> must be a data: URI (see class docblock) — URI.
     *   AllowedSchemes has to include http/https/mailto too, for <a href>,
     *   so it can't be narrowed to 'data' alone.
     * - <a href> must NOT be a data: URI — a data:text/html link is a
     *   disguised way to smuggle a clickable payload, which an <img src>
     *   data: URI can never be since it's only ever decoded as pixel data.
     */
    private static function postProcess(string $html): string
    {
        if (stripos($html, 'data:') === false && stripos($html, '<img') === false) {
            return $html;
        }
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        foreach (iterator_to_array($doc->getElementsByTagName('a')) as $a) {
            $href = $a->getAttribute('href');
            if ($href !== '' && stripos($href, 'data:') === 0) {
                $a->removeAttribute('href');
            }
        }
        foreach (iterator_to_array($doc->getElementsByTagName('img')) as $img) {
            $src = $img->getAttribute('src');
            if (stripos($src, 'data:image/') !== 0) {
                $img->parentNode->removeChild($img);
            }
        }

        $wrapper = $doc->getElementsByTagName('div')->item(0);
        $out = '';
        foreach (iterator_to_array($wrapper->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    private static function purifier(): \HTMLPurifier
    {
        if (self::$purifier === null) {
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('HTML.Allowed', implode(',', [
                'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike',
                'h3', 'h4', 'blockquote',
                'ul', 'ol', 'li',
                'table', 'thead', 'tbody', 'tr', 'td', 'th',
                'span[style]',
                'a[href|target|rel]',
                'img[src|alt|width|height]',
            ]));
            $config->set('CSS.AllowedProperties', ['color', 'background-color', 'font-weight', 'font-style', 'text-decoration']);
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'data' => true]);
            $config->set('HTML.TargetBlank', true);
            $config->set('HTML.Nofollow', true);
            $config->set('Attr.AllowedFrameTargets', ['_blank']);
            self::$purifier = new \HTMLPurifier($config);
        }
        return self::$purifier;
    }
}
