<?php

namespace App\Service\Theme;

/**
 * The closed list of font pairings a theme can use.
 *
 * CLOSED on purpose, for the same reason the content-block registry and the
 * vendor-script map are closed: nothing a request or a database row says can
 * become a font-family string or a URL the browser loads. An administrator
 * picks a KEY; this file owns everything that key means.
 *
 * Each pairing owns four things:
 *
 *   label     what the administrator reads in the dropdown
 *   heading   the full CSS font stack for --font-display
 *   body      the full CSS font stack for --font-body
 *   url       the one stylesheet that has to be downloaded, or null for a
 *             pairing that needs no download at all
 *
 * DEFAULT_KEY reproduces the site's original pairing exactly, including the
 * @import URL that used to sit at the top of assets/css/core.css — the
 * import moved here so a site on another pairing never downloads Trirong.
 * Only the selected pairing's stylesheet is ever requested.
 */
final class ThemeFonts
{
    public const DEFAULT_KEY = 'trirong-quattrocento';

    private const SANS_FALLBACK = "-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";

    /** @var array<string, array{label: string, heading: string, body: string, url: string|null}> */
    private const PAIRINGS = [
        'trirong-quattrocento' => [
            'label' => 'Trirong + Quattrocento Sans (warm, klassiek)',
            'heading' => "'Trirong', Georgia, serif",
            'body' => "'Quattrocento Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
            'url' => 'https://fonts.googleapis.com/css2?family=Trirong:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&family=Quattrocento+Sans:wght@400;700&display=swap',
        ],
        'playfair-source-sans' => [
            'label' => 'Playfair Display + Source Sans 3 (redactioneel)',
            'heading' => "'Playfair Display', Georgia, serif",
            'body' => "'Source Sans 3', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
            'url' => 'https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,500;0,600;0,700;1,400&family=Source+Sans+3:wght@400;700&display=swap',
        ],
        'cormorant-inter' => [
            'label' => 'Cormorant Garamond + Inter (licht, elegant)',
            'heading' => "'Cormorant Garamond', Georgia, serif",
            'body' => "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
            'url' => 'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400&family=Inter:wght@400;700&display=swap',
        ],
        'lora-montserrat' => [
            'label' => 'Lora + Montserrat (zacht met strakke sans)',
            'heading' => "'Lora', Georgia, serif",
            'body' => "'Montserrat', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
            'url' => 'https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,500;0,600;0,700;1,400&family=Montserrat:wght@400;700&display=swap',
        ],
        'poppins-inter' => [
            'label' => 'Poppins + Inter (modern, zonder schreef)',
            'heading' => "'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
            'body' => "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
            'url' => 'https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,400;0,500;0,600;0,700;1,400&family=Inter:wght@400;700&display=swap',
        ],
        'system' => [
            'label' => 'Systeemlettertypen (geen externe download)',
            'heading' => "Georgia, 'Times New Roman', Times, serif",
            'body' => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif",
            'url' => null,
        ],
    ];

    /**
     * @return array<string, array{label: string, heading: string, body: string, url: string|null}>
     */
    public static function all(): array
    {
        return self::PAIRINGS;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::PAIRINGS);
    }

    public static function isValidKey(string $key): bool
    {
        return array_key_exists($key, self::PAIRINGS);
    }

    /**
     * The pairing behind a key, falling back to the default for anything
     * unknown — a stored value from an older/other install can never leave a
     * page without a font stack.
     *
     * @return array{label: string, heading: string, body: string, url: string|null}
     */
    public static function pairing(string $key): array
    {
        return self::PAIRINGS[$key] ?? self::PAIRINGS[self::DEFAULT_KEY];
    }
}
