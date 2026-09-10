<?php

declare(strict_types=1);

namespace App\Service\Personalization;

use App\Repository\PersonalizationFontRepository;

/**
 * THE registry of every font a customer may pick for an engraving — one
 * GLOBAL library for the whole shop, managed in the CMS under
 * Personalisatie -> Lettertypes and stored in `personalization_fonts`.
 *
 * ## Why global, and not per zone
 *
 * Phase 2 kept this list in a PHP constant and made every ZONE tick which of
 * those fonts it offered. Both halves were wrong: the owner could not add a
 * font without a code change, and re-picking fonts on every zone of every
 * product is work with no payoff — the shop engraves with the same set of
 * faces whatever it is engraving. So a font is never assigned to anything.
 * Every text zone of every personalizable product offers exactly the fonts
 * that are ACTIVE at that moment, and turning a font off removes it from the
 * whole shop in one action.
 *
 * ## Keys are forever
 *
 * `font_key` is a stable identifier, never a display name: it ends up in an
 * order row and must keep meaning the same thing years later even if the
 * label, the file or the CSS behind it changes. The five keys the code
 * registry used are still the same keys — the Phase 3 migration backfilled
 * them as `source = 'builtin'` rows — so nothing that already referred to
 * `quattrocento_sans` changed meaning.
 *
 * ## Two kinds of font
 *
 *   'builtin' — a family the browser already has, or one the site's own
 *               stylesheet loads (see the Google Fonts import at the top of
 *               assets/css/core.css). `css_stack` is the whole definition.
 *   'upload'  — a font file the owner uploaded through the CMS. It is served
 *               from the site's own assets/fonts/personalization/ folder, and
 *               faceCss() emits the `@font-face` that makes it usable. There
 *               is no remote font service anywhere in this feature.
 *
 * An uploaded font's stack always ENDS in a real fallback family, so a face
 * that fails to load degrades to something sane instead of to the browser
 * default.
 *
 * ## Reads are cached per request
 *
 * Same convention as App\Service\SiteSettings and the Content classes: the
 * library is read once per request and cleared explicitly by the admin save
 * handlers (and by tests) through clearCache().
 */
class PersonalizationFonts
{
    /**
     * The key used when the library cannot answer at all — an empty library,
     * or a database that is momentarily unreachable. It matches the site's
     * own body font and is only ever a last resort: in a healthy shop
     * fallbackKey() returns the first ACTIVE font instead.
     */
    public const FALLBACK = 'quattrocento_sans';

    /** The stack the fallback key renders as when the library is unavailable. */
    private const FALLBACK_STACK =
        "'Quattrocento Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";

    /**
     * What every uploaded font's stack falls back to. Deliberately the site's
     * own body font rather than a bare generic: a failed webfont should still
     * look like this site.
     */
    private const UPLOAD_FALLBACK_STACK = "'Quattrocento Sans', Helvetica, Arial, sans-serif";

    /** @var array<string, array<string, mixed>>|null key => record, ALL fonts */
    private static ?array $cache = null;

    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /* ------------------------------------------------------------------ */
    /* The library                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Every font in the library, active or not, in library order — what the
     * CMS screen lists, and what makes a stored key from a retired font still
     * resolvable for a historical order.
     *
     * @return list<array<string, mixed>>
     */
    public static function library(): array
    {
        return array_values(self::load());
    }

    /**
     * The fonts a customer may actually pick, in library order. This is the
     * one list the storefront ever renders.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return array_values(array_filter(
            self::load(),
            static fn (array $font): bool => $font['is_active'] === true
        ));
    }

    /** @return list<string> */
    public static function activeKeys(): array
    {
        return array_map(static fn (array $font): string => (string) $font['key'], self::all());
    }

    /** @return list<string> every key in the library, active or not */
    public static function keys(): array
    {
        return array_keys(self::load());
    }

    public static function isValid(mixed $key): bool
    {
        return is_string($key) && isset(self::load()[$key]);
    }

    public static function isActive(mixed $key): bool
    {
        return is_string($key) && (self::load()[$key]['is_active'] ?? false) === true;
    }

    /**
     * The whole record for one key, active or not — what the order snapshot
     * copies so a later deactivation or deletion cannot change what a
     * historical order was engraved in.
     *
     * @return array<string, mixed>|null
     */
    public static function record(string $key): ?array
    {
        return self::load()[$key] ?? null;
    }

    public static function label(string $key): string
    {
        return (string) (self::load()[$key]['label'] ?? $key);
    }

    public static function stack(string $key): string
    {
        return (string) (self::load()[$key]['stack'] ?? self::FALLBACK_STACK);
    }

    /**
     * The font a text zone starts on: the first ACTIVE font in library order.
     * The owner controls it by ordering the library, not by configuring a
     * product.
     */
    public static function fallbackKey(): string
    {
        $active = self::activeKeys();

        return $active[0] ?? self::FALLBACK;
    }

    /* ------------------------------------------------------------------ */
    /* Sanitising submitted values                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Turns anything — a comma-separated column, a submitted array, garbage —
     * into a canonical list of real font keys: unknown names dropped,
     * duplicates removed, always in library order so stored data never
     * depends on form field order.
     *
     * @return list<string>
     */
    public static function sanitize(mixed $submitted): array
    {
        if (is_string($submitted)) {
            $submitted = $submitted === '' ? [] : explode(',', $submitted);
        }

        if (!is_array($submitted)) {
            return [];
        }

        $library = self::load();
        $valid = [];

        foreach ($submitted as $key) {
            if (is_string($key) && isset($library[trim($key)])) {
                $valid[trim($key)] = true;
            }
        }

        return array_values(array_filter(self::keys(), static fn (string $k): bool => isset($valid[$k])));
    }

    /**
     * THE authorisation decision for a submitted font, as a pure function: a
     * customer may only ever end up with a font that is in the list they were
     * offered. A missing or forged value silently resolves to the default
     * rather than failing the whole checkout — the customer never chose it,
     * and a font is not worth rejecting an order over.
     *
     * @param list<string> $allowed
     */
    public static function resolveSubmitted(mixed $submitted, array $allowed, string $default): string
    {
        if (is_string($submitted) && in_array($submitted, $allowed, true)) {
            return $submitted;
        }

        return in_array($default, $allowed, true) ? $default : ($allowed[0] ?? self::FALLBACK);
    }

    /* ------------------------------------------------------------------ */
    /* Output                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * The fonts a page needs, in the shape the browser wants. Unknown keys
     * are dropped by sanitize() first, so a payload can never advertise a
     * font the shop does not have.
     *
     * @param list<string> $keys
     * @return list<array{key: string, label: string, stack: string}>
     */
    public static function payload(array $keys): array
    {
        $payload = [];
        foreach (self::sanitize($keys) as $key) {
            $payload[] = [
                'key' => $key,
                'label' => self::label($key),
                'stack' => self::stack($key),
            ];
        }

        return $payload;
    }

    /**
     * The `@font-face` rules for the UPLOADED fonts among these keys, ready
     * to be printed inside a <style> block. Built-in fonts contribute
     * nothing: their families are already available.
     *
     * Everything interpolated here is server-controlled — the family name is
     * derived from the validated `font_key` charset, the URL from the
     * server-generated `file_path` — so a CMS label can never reach this
     * string at all.
     *
     * @param list<string>|null $keys null = every active font
     */
    public static function faceCss(?array $keys = null): string
    {
        $records = $keys === null
            ? self::all()
            : array_values(array_filter(array_map(
                static fn (string $k): ?array => self::record($k),
                self::sanitize($keys)
            )));

        $css = '';

        foreach ($records as $font) {
            if ($font['source'] !== 'upload' || $font['file_path'] === null) {
                continue;
            }

            $family = self::familyName((string) $font['key']);
            $url = self::encodePath('/' . ltrim((string) $font['file_path'], '/'));
            $format = self::cssFormat((string) $font['file_format']);

            $css .= "@font-face{font-family:'" . $family . "';"
                . "src:url('" . $url . "')" . ($format === '' ? '' : " format('" . $format . "')") . ';'
                . "font-display:swap;font-weight:normal;font-style:normal;}\n";
        }

        return $css;
    }

    /**
     * The CSS family name an uploaded font is registered under. Namespaced so
     * it can never collide with a real family the browser knows, and derived
     * only from the validated key charset ([a-z0-9_-]).
     */
    public static function familyName(string $fontKey): string
    {
        return 'vvld-' . $fontKey;
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            $rows = (new PersonalizationFontRepository())->findAll();
        } catch (\Throwable $e) {
            // A font lookup failing must never take down a product page. An
            // empty library reads as "no font choice", and every text layer
            // still renders in the fallback stack.
            error_log('[PersonalizationFonts] ' . $e->getMessage());
            $rows = [];
        }

        $library = [];

        foreach ($rows as $row) {
            $key = (string) $row['font_key'];
            $source = (string) $row['source'];
            $filePath = $row['file_path'] !== null && trim((string) $row['file_path']) !== ''
                ? trim((string) $row['file_path'])
                : null;

            $library[$key] = [
                'id' => (int) $row['id'],
                'key' => $key,
                'label' => (string) $row['label'],
                'source' => $source,
                'stack' => self::stackFor($key, $source, $row['css_stack'] ?? null, $filePath),
                'file_path' => $filePath,
                'file_format' => $row['file_format'] !== null ? (string) $row['file_format'] : null,
                'original_filename' => $row['original_filename'] !== null ? (string) $row['original_filename'] : null,
                'byte_size' => $row['byte_size'] !== null ? (int) $row['byte_size'] : null,
                'is_active' => (int) $row['is_active'] === 1,
                'sort_order' => (int) $row['sort_order'],
            ];
        }

        return self::$cache = $library;
    }

    private static function stackFor(string $key, string $source, mixed $cssStack, ?string $filePath): string
    {
        if ($source === 'upload' && $filePath !== null) {
            return "'" . self::familyName($key) . "', " . self::UPLOAD_FALLBACK_STACK;
        }

        $stack = is_string($cssStack) ? trim($cssStack) : '';

        return $stack === '' ? self::FALLBACK_STACK : $stack;
    }

    /** The `format()` hint for a `src:` descriptor, or '' when unknown. */
    private static function cssFormat(string $extension): string
    {
        return match (strtolower($extension)) {
            'woff2' => 'woff2',
            'woff' => 'woff',
            'ttf' => 'truetype',
            'otf' => 'opentype',
            default => '',
        };
    }

    /**
     * Percent-encodes a path for use inside a CSS url(), one segment at a
     * time so the separators survive. Only ever applied to a
     * server-generated path, so this is belt-and-braces rather than a trust
     * boundary.
     */
    private static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
