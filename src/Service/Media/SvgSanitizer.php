<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * Turns an uploaded SVG into one the Media Library may serve from this site's
 * own origin, or refuses it. MEDIA.md, "SVG".
 *
 * WHY AN SVG NEEDS THIS. An SVG is an XML document, not a picture: it can
 * carry <script>, event handlers, links to javascript: URLs, embedded HTML
 * (<foreignObject>) and references to other files. Inside an <img> none of
 * that runs, but the library serves every file at a public URL on this
 * domain, and whoever opens that URL directly runs the document as a page of
 * this site: stored XSS with the admin's cookies in reach. So the file that
 * is stored is never the file that was sent; it is what this class writes
 * back out of a parsed tree.
 *
 * THE CONTRACT: REFUSE WHAT IS ACTIVE, REMOVE WHAT IS MERELY FOREIGN.
 *
 *   - Refused, with a reason an editor can act on: a DOCTYPE or entity
 *     declaration (entity expansion and XXE are only possible through one),
 *     script, event handlers (on…), embedded documents (foreignObject,
 *     iframe, object, embed), animation elements (they can rewrite an href
 *     into javascript: after the fact), and any reference that leaves the
 *     file: an href, a url() or an @import that is not "#id" inside the file
 *     itself. A raster picture embedded as a data: URI is allowed, because
 *     design tools write those and a PNG cannot run anything.
 *   - Removed without a word: comments, processing instructions, metadata,
 *     and every element and attribute this list does not know, which is
 *     where editors' own bookkeeping lives (Inkscape, Sodipodi, Illustrator).
 *     Removing those never changes what a browser draws.
 *
 * Refusing rather than silently stripping the active parts is deliberate: a
 * logo whose script or link is quietly dropped may look different from what
 * its maker exported, and the editor would never learn why.
 *
 * ALLOWLIST, NOT BLOCKLIST, for elements. An element that is neither on the
 * allowlist nor on the refusal list is removed with its content. Attributes
 * are judged by rule instead of by name, because SVG has hundreds of
 * harmless presentation attributes and exactly two ways for one to reach
 * outside the file: an href and a url(). Both are checked on every value.
 *
 * Parsing happens with LIBXML_NONET and without LIBXML_NOENT or
 * LIBXML_DTDLOAD, so libxml never fetches anything and never expands an
 * entity; a DOCTYPE is refused on the raw bytes before the parser sees it.
 */
final class SvgSanitizer
{
    /** What an editor is told a refused file contains; keys of media.upload.svg_reason.*. */
    public const REASON_UNREADABLE = 'unreadable';
    public const REASON_DOCTYPE = 'doctype';
    public const REASON_SCRIPT = 'script';
    public const REASON_EVENT = 'event';
    public const REASON_EMBEDDED = 'embedded';
    public const REASON_ANIMATION = 'animation';
    public const REASON_EXTERNAL = 'external';

    private const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';
    private const XLINK_NAMESPACE = 'http://www.w3.org/1999/xlink';
    private const XML_NAMESPACE = 'http://www.w3.org/XML/1998/namespace';

    /** Elements whose presence refuses the whole file, and why. */
    private const REFUSED_ELEMENTS = [
        'script' => self::REASON_SCRIPT,
        'handler' => self::REASON_SCRIPT,
        'listener' => self::REASON_SCRIPT,
        'foreignObject' => self::REASON_EMBEDDED,
        'iframe' => self::REASON_EMBEDDED,
        'object' => self::REASON_EMBEDDED,
        'embed' => self::REASON_EMBEDDED,
        'audio' => self::REASON_EMBEDDED,
        'video' => self::REASON_EMBEDDED,
        'animate' => self::REASON_ANIMATION,
        'animateMotion' => self::REASON_ANIMATION,
        'animateTransform' => self::REASON_ANIMATION,
        'animateColor' => self::REASON_ANIMATION,
        'set' => self::REASON_ANIMATION,
        'discard' => self::REASON_ANIMATION,
    ];

    /** Everything a drawing is made of. Case matters: SVG is XML. */
    private const ALLOWED_ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'switch', 'view',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textPath',
        'linearGradient', 'radialGradient', 'stop', 'pattern',
        'clipPath', 'mask', 'marker', 'image', 'style',
        'filter', 'feBlend', 'feColorMatrix', 'feComponentTransfer', 'feComposite',
        'feConvolveMatrix', 'feDiffuseLighting', 'feDisplacementMap', 'feDistantLight',
        'feDropShadow', 'feFlood', 'feFuncA', 'feFuncB', 'feFuncG', 'feFuncR',
        'feGaussianBlur', 'feImage', 'feMerge', 'feMergeNode', 'feMorphology',
        'feOffset', 'fePointLight', 'feSpecularLighting', 'feSpotLight', 'feTile',
        'feTurbulence',
    ];

    /**
     * An <a> is unwrapped rather than refused or removed: its children are
     * usually the very letters of a logo, and the link itself does nothing
     * inside an <img>. Its href goes with it.
     */
    private const UNWRAPPED_ELEMENTS = ['a'];

    /** A picture embedded in the file, which may stay: raster only, never another SVG. */
    private const EMBEDDED_RASTER = '~^data:image/(png|jpe?g|gif|webp);base64,[a-z0-9+/=\s]*$~i';

    /**
     * @return array{svg: string, width: int|null, height: int|null} the file to store, and its size when it names one
     *
     * @throws SvgRefused
     */
    public static function sanitize(string $source): array
    {
        // A byte-order mark or leading whitespace is not a reason to refuse.
        $source = ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $source) ?? $source);

        if ($source === '' || !mb_check_encoding($source, 'UTF-8')) {
            throw new SvgRefused(self::REASON_UNREADABLE);
        }

        // Before the parser sees anything: every entity trick (billion laughs,
        // an external entity reading /etc/passwd or fetching a URL) needs a
        // DOCTYPE with declarations. A drawing never does.
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $source) === 1) {
            throw new SvgRefused(self::REASON_DOCTYPE);
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $dom->loadXML($source, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $loaded ? $dom->documentElement : null;

        if ($root === null || $dom->doctype !== null) {
            throw new SvgRefused($dom->doctype !== null ? self::REASON_DOCTYPE : self::REASON_UNREADABLE);
        }

        if ($root->localName !== 'svg' || $root->namespaceURI !== self::SVG_NAMESPACE) {
            throw new SvgRefused(self::REASON_UNREADABLE);
        }

        self::cleanElement($root);

        // Processing instructions and comments at document level (an
        // xml-stylesheet instruction would load another file): only the root
        // is kept.
        $clean = new \DOMDocument('1.0', 'UTF-8');
        $clean->appendChild($clean->importNode($root, true));

        $svg = $clean->saveXML();

        if ($svg === false) {
            throw new SvgRefused(self::REASON_UNREADABLE);
        }

        [$width, $height] = self::dimensions($root);

        return ['svg' => $svg, 'width' => $width, 'height' => $height];
    }

    /** Whether these bytes look like an SVG at all, before any judgement of what it contains. */
    public static function looksLikeSvg(string $bytes): bool
    {
        return preg_match('/<svg[\s>]/i', substr($bytes, 0, 4096)) === 1;
    }

    /** @throws SvgRefused */
    private static function cleanElement(\DOMElement $element): void
    {
        self::cleanAttributes($element);

        if ($element->localName === 'style') {
            self::checkCss($element->textContent);
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                $name = (string) $child->localName;
                $inSvg = $child->namespaceURI === self::SVG_NAMESPACE;

                if (isset(self::REFUSED_ELEMENTS[$name])) {
                    // Refused in any namespace: an XHTML <script> inside an
                    // SVG runs just as well.
                    throw new SvgRefused(self::REFUSED_ELEMENTS[$name]);
                }

                if ($inSvg && in_array($name, self::UNWRAPPED_ELEMENTS, true)) {
                    self::cleanElement($child);
                    while ($child->firstChild !== null) {
                        $element->insertBefore($child->firstChild, $child);
                    }
                    $element->removeChild($child);
                    continue;
                }

                if (!$inSvg || !in_array($name, self::ALLOWED_ELEMENTS, true)) {
                    $element->removeChild($child);
                    continue;
                }

                self::cleanElement($child);
                continue;
            }

            if ($child instanceof \DOMText) {
                // Text and (with LIBXML_NOCDATA) CDATA content: letters, or
                // the CSS of a <style>, which checkCss() has judged.
                continue;
            }

            // Comments, processing instructions, entity references.
            $element->removeChild($child);
        }
    }

    /** @throws SvgRefused */
    private static function cleanAttributes(\DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            /** @var \DOMAttr $attribute */
            $name = (string) $attribute->localName;
            $namespace = $attribute->namespaceURI;
            $value = (string) $attribute->value;

            if (str_starts_with(strtolower($name), 'on')) {
                throw new SvgRefused(self::REASON_EVENT);
            }

            if (preg_match('/(java|vb)script\s*:/i', self::withoutSpace($value)) === 1) {
                throw new SvgRefused(self::REASON_SCRIPT);
            }

            if ($name === 'href' && ($namespace === null || $namespace === self::XLINK_NAMESPACE)) {
                self::checkReference($value, $element->localName === 'image');
                continue;
            }

            if ($namespace !== null) {
                // xml:space and xml:lang are part of how text is drawn;
                // xml:base would change what every relative reference means.
                if ($namespace === self::XML_NAMESPACE && in_array($name, ['space', 'lang'], true)) {
                    continue;
                }

                $element->removeAttributeNode($attribute);
                continue;
            }

            if ($name === 'style') {
                self::checkCss($value);
                continue;
            }

            if (stripos($value, 'url(') !== false) {
                self::checkUrls($value);
            }
        }
    }

    /**
     * An href may point inside this file ("#logo"), and an <image> may carry
     * an embedded raster picture. Anything else — a path, a URL, another SVG —
     * would make the drawing depend on, or load, something outside it.
     *
     * @throws SvgRefused
     */
    private static function checkReference(string $value, bool $isImage): void
    {
        $value = trim($value);

        if ($value === '' || str_starts_with($value, '#')) {
            return;
        }

        if ($isImage && preg_match(self::EMBEDDED_RASTER, $value) === 1) {
            return;
        }

        throw new SvgRefused(self::REASON_EXTERNAL);
    }

    /** @throws SvgRefused */
    private static function checkCss(string $css): void
    {
        $compact = self::withoutSpace(strtolower($css));

        foreach (['@import', 'expression(', '-moz-binding', 'behavior:'] as $needle) {
            if (str_contains($compact, $needle)) {
                throw new SvgRefused(self::REASON_EXTERNAL);
            }
        }

        if (str_contains($compact, 'url(')) {
            self::checkUrls($css);
        }
    }

    /**
     * Every url(...) in a value must name a fragment of this file:
     * fill="url(#gradient)" is how a drawing uses its own gradients.
     *
     * @throws SvgRefused
     */
    private static function checkUrls(string $value): void
    {
        preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/is', $value, $matches);

        foreach ($matches[2] as $target) {
            if (!str_starts_with(trim($target), '#')) {
                throw new SvgRefused(self::REASON_EXTERNAL);
            }
        }

        // An url( the pattern could not read (an unclosed one) is not
        // assumed harmless.
        if (substr_count(strtolower($value), 'url(') !== count($matches[2])) {
            throw new SvgRefused(self::REASON_EXTERNAL);
        }
    }

    private static function withoutSpace(string $value): string
    {
        return preg_replace('/[\s\x00]+/', '', $value) ?? $value;
    }

    /**
     * The size the drawing names for itself: width and height in pixels, or
     * else its viewBox. Percentages and other units say nothing a width
     * attribute on an <img> can use, so they give null.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private static function dimensions(\DOMElement $root): array
    {
        $width = self::pixels($root->getAttribute('width'));
        $height = self::pixels($root->getAttribute('height'));

        if ($width !== null && $height !== null) {
            return [$width, $height];
        }

        $viewBox = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox'))) ?: [];

        if (count($viewBox) === 4 && is_numeric($viewBox[2]) && is_numeric($viewBox[3])) {
            $boxWidth = (int) round((float) $viewBox[2]);
            $boxHeight = (int) round((float) $viewBox[3]);

            if ($boxWidth > 0 && $boxHeight > 0) {
                return [$boxWidth, $boxHeight];
            }
        }

        return [null, null];
    }

    private static function pixels(string $value): ?int
    {
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*(px)?\s*$/i', $value, $matches) !== 1) {
            return null;
        }

        $pixels = (int) round((float) $matches[1]);

        return $pixels > 0 ? $pixels : null;
    }
}
