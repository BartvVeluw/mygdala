<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Sanitizes rich-text CMS long-form fields (currently: Portfolio item
 * Introtekst/Projectbeschrijving, NL+EN — see admin/portfolio-item.php and
 * api/admin/update-portfolio-item.php) down to a small, fixed allowlist:
 * paragraphs, headings (h2/h3), lists, bold/italic, links, and line breaks.
 * Everything else (scripts, iframes, inline styles/event handlers, unknown
 * tags/attributes, unsafe URL schemes) is stripped.
 *
 * Backed by the established ezyang/htmlpurifier Composer package (added
 * 2026-09-06) rather than a hand-rolled DOMDocument walker: this class used
 * to implement its own allowlist parser (same approach as
 * DescriptionSanitizer, which still does, for product descriptions'
 * separate, smaller allowlist), but rich text specifically is the more
 * security-sensitive, more heavily-edited surface, and a widely-audited
 * library is the safer foundation for it going forward. The public API
 * (`sanitize(?string): ?string`) is unchanged, so neither call site
 * (api/admin/update-portfolio-item.php on write,
 * PortfolioGalleryContent::itemForDetailPage() on read) needed to change.
 *
 * Performance: HTMLPurifier's definition-building step (parsing the
 * doctype/allowlist into an internal ruleset) is the expensive part, not the
 * per-string purify() call itself. Its own disk-based definition cache is
 * deliberately left disabled (Cache.DefinitionImpl = null) — vendor/ is
 * root-owned in this project's Docker/Vimexx deployment, so HTMLPurifier's
 * default cache directory (vendor/ezyang/.../DefinitionCache/Serializer)
 * isn't writable by the web server user, which would otherwise mean either a
 * silently-ignored cache-write failure or an extra writable-directory
 * dependency to manage on shared hosting. Instead, the configured
 * HTMLPurifier instance is cached in-process (static property) so a single
 * request sanitizing multiple fields (e.g. 4 rich-text fields on one
 * Portfolio item save, or on one detail-page render) only pays the
 * definition-build cost once. This is "good enough" for this site's traffic
 * — correctness/security took priority over further optimization, per
 * MAIN.MD.
 */
class RichTextSanitizer
{
    private static ?\HTMLPurifier $purifier = null;

    public static function sanitize(?string $html): ?string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return null;
        }

        $html = self::normalizeQuillLists($html);
        $clean = trim(self::purifier()->purify($html));

        return $clean === '' ? null : $clean;
    }

    /**
     * Quill 2 (admin/assets/vendor/quill/) represents BOTH bullet and
     * ordered lists as `<ol><li data-list="bullet|ordered">`, plus an
     * injected `<span class="ql-ui">` marker span it uses to render the
     * actual bullet/number via CSS — neither `data-list` nor that marker
     * exist in plain HTML, and HTMLPurifier's allowlist (correctly) has no
     * idea a bullet list is even meant here: left alone, it would just
     * strip the attribute/span and silently store a plain `<ol>`, turning
     * every bullet list into a numbered list. This runs before purification
     * to translate Quill's representation back into standard
     * `<ul>`/`<ol>`/`<li>` first, which HTMLPurifier (and
     * assets/css/core.css's .rich-content, and Quill's own
     * dangerouslyPasteHTML() on next load) already understand natively.
     *
     * A fast no-op path (string search before ever building a DOMDocument)
     * keeps this free for the vast majority of saves that touch no lists,
     * and for content that never passed through Quill in the first place.
     */
    private static function normalizeQuillLists(string $html): string
    {
        if (stripos($html, 'data-list') === false) {
            return $html;
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $priorSetting = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="UTF-8"?><!DOCTYPE html><html><body>' . $html . '</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NOBLANKS
        );
        libxml_clear_errors();
        libxml_use_internal_errors($priorSetting);

        $xpath = new \DOMXPath($dom);

        // A Quill list block is one <ol> whose <li> children all share the
        // same data-list value — bullet-type blocks become <ul>, ordered
        // ones stay <ol> (only the data-list attribute needs removing).
        foreach (iterator_to_array($xpath->query('//ol')) as $ol) {
            $firstItem = $xpath->query('./li[@data-list]', $ol)->item(0);
            if ($firstItem === null || $firstItem->getAttribute('data-list') !== 'bullet') {
                continue;
            }

            $ul = $dom->createElement('ul');
            while ($ol->firstChild !== null) {
                $ul->appendChild($ol->firstChild);
            }
            $ol->parentNode->replaceChild($ul, $ol);
        }

        foreach (iterator_to_array($xpath->query('//li[@data-list]')) as $li) {
            $li->removeAttribute('data-list');
        }

        foreach (iterator_to_array($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " ql-ui ")]')) as $marker) {
            $marker->parentNode->removeChild($marker);
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return $html;
        }

        $out = '';
        foreach (iterator_to_array($body->childNodes) as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    private static function purifier(): \HTMLPurifier
    {
        if (self::$purifier === null) {
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('Core.Encoding', 'UTF-8');

            // The complete allowlist, element-by-element with only the
            // attributes each one actually needs — b/i are included
            // alongside strong/em so pasted legacy markup keeps its bold/
            // italic meaning instead of being silently unwrapped (Quill's
            // own output only ever uses strong/em, but the server must
            // handle arbitrary posted HTML, not just well-behaved editor
            // output — see api/admin/update-portfolio-item.php).
            $config->set('HTML.Allowed', 'p,br,strong,em,b,i,h2,h3,ul,ol,li,a[href|title]');

            // Never allow style attributes, classes, or ids from pasted
            // content — presentation is this site's CSS's job
            // (assets/css/core.css .rich-content), never inline/editor markup.
            $config->set('Attr.EnableID', false);
            $config->set('CSS.AllowedProperties', []);
            $config->set('AutoFormat.RemoveEmpty', true);

            // Safe URL schemes only — javascript:/data: (and anything else)
            // are rejected outright, same as the old sanitizer's allowlist.
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);

            // rel/target on <a> are never user-controlled: HTMLPurifier
            // injects them itself once these are on (TargetBlank's injector
            // adds "noopener noreferrer" automatically as part of its own
            // safety handling, on top of Nofollow's "nofollow" — no separate
            // NoOpener directive exists), so a crafted rel="opener" in
            // submitted HTML can never survive — only the library's own safe
            // value does.
            $config->set('HTML.TargetBlank', true);
            $config->set('HTML.Nofollow', true);

            // See this class's docblock: deliberately no disk cache here.
            $config->set('Cache.DefinitionImpl', null);

            self::$purifier = new \HTMLPurifier($config);
        }

        return self::$purifier;
    }
}
