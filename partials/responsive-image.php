<?php

use App\Service\Media\BlockImage;
use App\Service\Media\ResponsiveImage;

/**
 * THE markup of one picture of a content block (Responsive Media 2.0,
 * App\Service\Media\ResponsiveImage::forRender()). Every block that shows a
 * picture with a focus point prints it through this function, and so does
 * every slide of a media sequence (partials/media-sequence.php): there is no
 * second <picture> and no second object-position anywhere.
 *
 * WITHOUT PHONE SETTINGS it is one <img>, as it always was: the focus point
 * as an inline object-position, contain as an inline object-fit.
 *
 * WITH A PHONE PICTURE it is a <picture> with one <source> for a phone
 * (ResponsiveImage::mobileMedia(), the one breakpoint) around the same <img>.
 * The browser downloads one of the two, never both, and the alt text stays on
 * the one <img>: it is the same content. `.rm-picture` has display: contents
 * (assets/css/responsive-media.css), so the <img> sits in its frame exactly
 * as a bare one would.
 *
 * WITH A PHONE POINT OR FIT the <img> carries it as a custom property
 * (--rm-mobile-position, --rm-mobile-fit) and a data attribute that switches
 * it on for a phone; assets/css/responsive-media.css holds that one rule.
 * Every value printed here is a whole number or a key from a closed list
 * (ResponsiveImage), and everything is escaped anyway.
 *
 * @param array{src: string, alt: string, width: int|null, height: int|null,
 *              mobile: array{src: string, width: int|null, height: int|null}|null,
 *              position: string, mobile_position: string|null, fit: string, mobile_fit: string|null} $picture
 * @param array{class?: string, loading?: string, decoding?: bool, fetchpriority?: bool, decorative?: bool,
 *              aria_hidden?: bool, position?: string, compact_max_width?: int} $options
 *        loading: 'lazy' (the default) or 'eager'; decoding: add decoding="async";
 *        fetchpriority: the first thing on the page (fetchpriority="high");
 *        decorative: alt="" whatever the picture's alt text;
 *        position: 'omit_center' (the default: no inline style for the middle,
 *        which the browser does by itself) or 'always';
 *        compact_max_width: the width below which the place itself turns
 *        compact, for a block with a layout breakpoint of its own (the
 *        Kaarten-carrousel); its stylesheet then applies the phone's point and
 *        fit below that same width
 */
function responsive_image_html(array $picture, array $options = []): string
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    $style = [];
    if (($options['position'] ?? 'omit_center') === 'always' || $picture['position'] !== ResponsiveImage::objectPosition(ResponsiveImage::DEFAULT_FOCUS, ResponsiveImage::DEFAULT_FOCUS)) {
        $style[] = 'object-position: ' . $picture['position'];
    }
    if ($picture['fit'] === ResponsiveImage::FIT_CONTAIN) {
        $style[] = 'object-fit: contain';
    }

    $flags = '';
    if (($picture['mobile_position'] ?? null) !== null) {
        $style[] = '--rm-mobile-position: ' . $picture['mobile_position'];
        $flags .= ' data-rm-mobile-position';
    }
    if (($picture['mobile_fit'] ?? null) !== null) {
        $style[] = '--rm-mobile-fit: ' . $picture['mobile_fit'];
        $flags .= ' data-rm-mobile-fit';
    }

    $class = (string) ($options['class'] ?? '');
    $eager = ($options['loading'] ?? 'lazy') === 'eager';

    $img = '<img'
        . ($class !== '' ? ' class="' . $h($class) . '"' : '')
        . ' src="' . $h($picture['src']) . '"'
        . ' alt="' . (!empty($options['decorative']) ? '' : $h($picture['alt'])) . '"'
        . BlockImage::dimensionAttributes(['width' => $picture['width'] ?? null, 'height' => $picture['height'] ?? null])
        . ' loading="' . ($eager ? 'eager' : 'lazy') . '"'
        . (!empty($options['decoding']) ? ' decoding="async"' : '')
        . (!empty($options['fetchpriority']) ? ' fetchpriority="high"' : '')
        . (!empty($options['aria_hidden']) ? ' aria-hidden="true"' : '')
        . ($style !== [] ? ' style="' . $h(implode('; ', $style) . ';') . '"' : '')
        . $flags
        . '>';

    $mobile = $picture['mobile'] ?? null;
    if ($mobile === null) {
        return $img;
    }

    return '<picture class="rm-picture">'
        . '<source media="' . $h(ResponsiveImage::mobileMedia(isset($options['compact_max_width']) ? (int) $options['compact_max_width'] : null)) . '" srcset="' . $h(responsive_image_srcset_url($mobile['src'])) . '"'
        . BlockImage::dimensionAttributes(['width' => $mobile['width'] ?? null, 'height' => $mobile['height'] ?? null])
        . '>'
        . $img
        . '</picture>';
}

/** Prints responsive_image_html(). */
function render_responsive_image(array $picture, array $options = []): void
{
    echo responsive_image_html($picture, $options);
}

/**
 * A URL as one candidate of a srcset: a space or a comma would split it, so
 * those two are percent-encoded. A Media Library path never has either (its
 * names are hexadecimal); this only keeps a hand-placed file from breaking.
 */
function responsive_image_srcset_url(string $url): string
{
    return str_replace([' ', ','], ['%20', '%2C'], $url);
}
