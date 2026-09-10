<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Sanitizes rich-text product descriptions down to a small, fixed whitelist:
 * paragraphs, bold/italic, links, and line breaks. Everything else (scripts,
 * inline event handlers, unknown tags, unsafe URL schemes) is stripped.
 *
 * Used by the admin product create/update validation before anything reaches
 * the database, and again defensively when the public API reads a
 * description back out — nothing renders a description without going
 * through this first. An empty/plain-text input (no tags) passes through as
 * plain escaped text, so existing descriptions keep rendering unchanged.
 */
class DescriptionSanitizer
{
    private const ALLOWED_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'a'];

    private const ALLOWED_URL_SCHEMES = ['http', 'https', 'mailto'];

    public static function sanitize(?string $html): ?string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return null;
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $priorSetting = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="UTF-8"?><!DOCTYPE html><html><body>' . $html . '</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NOBLANKS
        );
        libxml_clear_errors();
        libxml_use_internal_errors($priorSetting);

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return null;
        }

        $fragment = trim(self::sanitizeChildren($dom, $body));

        return $fragment === '' ? null : $fragment;
    }

    private static function sanitizeChildren(\DOMDocument $dom, \DOMNode $node): string
    {
        $out = '';
        foreach (iterator_to_array($node->childNodes) as $child) {
            $out .= self::sanitizeNode($dom, $child);
        }

        return $out;
    }

    private static function sanitizeNode(\DOMDocument $dom, \DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            return htmlspecialchars($node->wholeText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (!$node instanceof \DOMElement) {
            // Comments, processing instructions, CDATA, etc. — drop entirely.
            return '';
        }

        $tag = strtolower($node->tagName);

        // Never keep the content of script/style — remove tag and contents.
        if ($tag === 'script' || $tag === 'style') {
            return '';
        }

        $innerHtml = self::sanitizeChildren($dom, $node);

        if (!in_array($tag, self::ALLOWED_TAGS, true)) {
            // Unwrap: drop the tag itself, keep its sanitized content.
            return $innerHtml;
        }

        if ($tag === 'br') {
            return '<br>';
        }

        // Normalize b/i to strong/em — same semantics, one canonical output.
        $canonicalTag = $tag === 'b' ? 'strong' : ($tag === 'i' ? 'em' : $tag);

        $attrHtml = '';
        if ($tag === 'a') {
            $safeHref = self::sanitizeUrl($node->getAttribute('href'));
            if ($safeHref === null) {
                // Unsafe/unrecognized URL — drop the link, keep its text.
                return $innerHtml;
            }
            $attrHtml = ' href="' . htmlspecialchars($safeHref, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                . '" rel="noopener noreferrer nofollow" target="_blank"';
        }

        if (trim($innerHtml) === '') {
            return '';
        }

        return '<' . $canonicalTag . $attrHtml . '>' . $innerHtml . '</' . $canonicalTag . '>';
    }

    private static function sanitizeUrl(string $href): ?string
    {
        $href = trim($href);
        if ($href === '') {
            return null;
        }

        // Control characters (incl. tabs/newlines) are a classic trick for
        // smuggling "java\tscript:" past naive scheme checks — reject outright.
        if (preg_match('/[\x00-\x1F\x7F]/', $href) === 1) {
            return null;
        }

        $parts = parse_url($href);
        if ($parts === false) {
            return null;
        }

        if (!isset($parts['scheme'])) {
            // No scheme: a relative/anchor URL (e.g. "product.html?id=1",
            // "#section") — safe, nothing to execute.
            return $href;
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, self::ALLOWED_URL_SCHEMES, true)) {
            return null;
        }

        return $href;
    }
}
