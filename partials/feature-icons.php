<?php

/**
 * Theme-owned SVG markup for the Feature Grid's fixed icon set — see
 * App\Service\FeatureGridContent::ICON_KEYS. The CMS only ever stores one of
 * these keys, never markup; this file is the single place that maps a key to
 * its (trusted, hand-authored) SVG, exactly matching what used to be
 * hardcoded per card in index.php/over-mij.php. Never pass CMS/user input
 * into this function's return value as raw HTML other than the key itself.
 */

if (!function_exists('feature_grid_icon_svg')) {
    function feature_grid_icon_svg(string $iconKey): string
    {
        return match ($iconKey) {
            'heart' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 21s-7.5-4.9-10-9.3C.5 8 2 4.5 5.6 4c2-.3 3.9.6 5 2.2C11.7 4.6 13.6 3.7 15.6 4c3.6.5 5.1 4 3.6 7.7C19.5 16.1 12 21 12 21z"/></svg>',
            'diamond' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h12l4 6-10 12L2 9z"/><path d="M2 9h20M8.5 3L12 9l3.5-6M12 9l-4 12M12 9l4 12"/></svg>',
            'location' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" fill-rule="evenodd" aria-hidden="true"><path d="M12 22s7-7.4 7-12.5A7 7 0 105 9.5C5 14.6 12 22 12 22z" stroke-linejoin="round"/><circle cx="12" cy="9.5" r="2.4"/></svg>',
            'precision' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M2 12h2M20 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/></svg>',
            default => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M2 12h2M20 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/></svg>',
        };
    }
}
