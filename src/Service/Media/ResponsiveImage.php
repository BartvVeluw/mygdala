<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Service\Language\AdminTranslator;

/**
 * THE RESPONSIVE MEDIA CONTRACT (Responsive Media 3.0, MEDIA.md "Responsive
 * Media"): how one picture of a content block is shown on a large screen and
 * on a phone. One value per picture, the same for every block that has one;
 * a block only says which parts it offers (App\Service\Media\ResponsiveImageSlot).
 *
 *   focus x/y         which point of the picture stays in view when its frame
 *                     crops it, as whole percentages 0–100: exactly what CSS
 *                     object-position means. 50/50 (the middle) is the default
 *                     and what the browser does by itself. The nine points an
 *                     editor can pick with one click are App\Service\Media\ImageFocus.
 *   zoom              how far the picture is enlarged inside its frame, as a
 *                     whole percentage ZOOM_MIN-ZOOM_MAX (100-200): 100 is the
 *                     frame filled exactly as object-fit: cover fills it (the
 *                     default, and what every picture showed before zoom
 *                     existed); 150 shows two thirds of that crop, enlarged
 *                     around the focus point, which therefore stays where it
 *                     is. Only with cover: a contained picture ignores it,
 *                     and it stays stored, so going back to cover brings it
 *                     back.
 *   mobile image      optional: another Media Library picture for a phone.
 *                     None means "the desktop picture", so nothing changes for
 *                     a block that never chose one.
 *   mobile focus x/y  optional: the phone's own point. None means "follow the
 *                     desktop focus"; a mobile image of its own always has its
 *                     own point (the middle until an editor moves it).
 *   mobile zoom       the zoom of the phone's own point, and only of that: it
 *                     is stored with mobile focus x/y and is none whenever
 *                     they are. A phone that follows the desktop point
 *                     follows the desktop zoom too; a phone point stored
 *                     before zoom existed reads as 100.
 *   fit               cover (fill the frame, crop the rest; the default and
 *                     what every block did) or contain (the whole picture,
 *                     leaving the frame's own background around it) — only
 *                     where the picture has a frame of its own ($slot->fit)
 *   mobile fit        optional: the phone's own choice; none means "as on a
 *                     large screen"
 *   mobile height     optional: compact, normal or large on a phone instead of
 *                     the height the block works out itself ($slot->mobileHeight)
 *
 * A PHONE IS AT MOST MOBILE_MAX_WIDTH PIXELS WIDE: the one breakpoint of this
 * contract. The <source media> of a mobile picture is built from it here, and
 * assets/css/responsive-media.css and the blocks' mobile rules use the same
 * number (Tests\Service\ResponsiveMediaContractTest pins both). A
 * tablet shows the desktop picture.
 *
 * WHERE THINGS HAPPEN. The row's own columns are read by fromRow() and
 * written by App\Repository\ResponsiveImageRepository through toRow(); a
 * posted form becomes a value in fromRequest(), which refuses what does not
 * fit instead of guessing; forRender() turns a value and the block's desktop
 * picture into what partials/responsive-image.php prints. No block builds its
 * own <picture>, its own object-position or its own mobile rule.
 *
 * Everything that reaches CSS comes out of this class as a whole number or a
 * key from a closed list, never as text from the database or a request.
 */
final class ResponsiveImage
{
    /** A screen at most this wide is a phone: its picture, its point, its fit, its height. */
    public const MOBILE_MAX_WIDTH = 640;

    /** The middle, on both axes: the browser's own choice, and every picture's start. */
    public const DEFAULT_FOCUS = 50;

    /** No zoom: the frame filled exactly as object-fit: cover fills it. */
    public const DEFAULT_ZOOM = 100;

    public const ZOOM_MIN = 100;

    public const ZOOM_MAX = 200;

    public const FIT_COVER = 'cover';

    public const FIT_CONTAIN = 'contain';

    /** @var list<string> */
    public const FITS = [self::FIT_COVER, self::FIT_CONTAIN];

    /** @var list<string> a phone's own height, when the block offers one; none is "automatic" */
    public const MOBILE_HEIGHTS = ['compact', 'normal', 'large'];

    /**
     * The shape of the pictures of a flat row of cards (the Kaarten-carrousel
     * side by side, and every carousel on a phone): 'auto' keeps the block's
     * own fixed height, the others are aspect ratios.
     *
     * @var list<string>
     */
    public const FLAT_RATIOS = ['auto', '1-1', '4-3', '3-4', '16-9'];

    /** The form's choice for the phone's picture. */
    public const SOURCE_DESKTOP = 'desktop';

    public const SOURCE_OWN = 'own';

    /** The zoom of the phone's own point, or none while a phone follows the desktop. */
    public readonly ?int $mobileZoom;

    public function __construct(
        public readonly int $focusX = self::DEFAULT_FOCUS,
        public readonly int $focusY = self::DEFAULT_FOCUS,
        public readonly ?int $mobileMediaId = null,
        public readonly ?int $mobileFocusX = null,
        public readonly ?int $mobileFocusY = null,
        public readonly string $fit = self::FIT_COVER,
        public readonly ?string $mobileFit = null,
        public readonly ?string $mobileHeight = null,
        public readonly int $zoom = self::DEFAULT_ZOOM,
        ?int $mobileZoom = null,
    ) {
        foreach ([$focusX, $focusY] as $percent) {
            if ($percent < 0 || $percent > 100) {
                throw new \InvalidArgumentException('A focus point is a percentage from 0 to 100.');
            }
        }
        if (($mobileFocusX === null) !== ($mobileFocusY === null)
            || ($mobileFocusX !== null && ($mobileFocusX < 0 || $mobileFocusX > 100 || $mobileFocusY < 0 || $mobileFocusY > 100))) {
            throw new \InvalidArgumentException('A phone\'s own focus point is a pair of percentages from 0 to 100, or none.');
        }
        if (!in_array($fit, self::FITS, true) || ($mobileFit !== null && !in_array($mobileFit, self::FITS, true))) {
            throw new \InvalidArgumentException('A fit is cover or contain.');
        }
        if ($mobileHeight !== null && !in_array($mobileHeight, self::MOBILE_HEIGHTS, true)) {
            throw new \InvalidArgumentException('A phone\'s height is one of MOBILE_HEIGHTS, or none.');
        }
        if ($zoom < self::ZOOM_MIN || $zoom > self::ZOOM_MAX
            || ($mobileZoom !== null && ($mobileZoom < self::ZOOM_MIN || $mobileZoom > self::ZOOM_MAX))) {
            throw new \InvalidArgumentException('A zoom is a percentage from ZOOM_MIN to ZOOM_MAX.');
        }
        if ($mobileZoom !== null && $mobileFocusX === null) {
            throw new \InvalidArgumentException('A phone\'s own zoom belongs to its own focus point.');
        }
        if ($mobileMediaId !== null && $mobileMediaId < 1) {
            throw new \InvalidArgumentException('A mobile picture is a Media Library id, or none.');
        }

        // One value per meaning: a phone point of its own always has a zoom
        // (100 unless one was given), and without one there is none.
        $this->mobileZoom = $mobileFocusX === null ? null : ($mobileZoom ?? self::DEFAULT_ZOOM);
    }

    // ------------------------------------------------------------ the row

    /**
     * The value a row stores. Whatever does not fit the contract (a value
     * written by hand, a column this slot does not have) reads as its
     * default, so a page never breaks over one odd cell.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row, ResponsiveImageSlot $slot): self
    {
        $mobileX = self::percentOrNull($row[$slot->column('mobile_focus_x')] ?? null);
        $mobileY = self::percentOrNull($row[$slot->column('mobile_focus_y')] ?? null);
        if ($mobileX === null || $mobileY === null) {
            $mobileX = null;
            $mobileY = null;
        }

        $mobileMedia = (int) ($row[$slot->column('mobile_media_id')] ?? 0);

        return new self(
            focusX: self::percentOrNull($row[$slot->column('focus_x')] ?? null) ?? self::DEFAULT_FOCUS,
            focusY: self::percentOrNull($row[$slot->column('focus_y')] ?? null) ?? self::DEFAULT_FOCUS,
            mobileMediaId: $mobileMedia > 0 ? $mobileMedia : null,
            mobileFocusX: $mobileX,
            mobileFocusY: $mobileY,
            fit: $slot->fit ? (self::fitOrNull($row[$slot->column('fit')] ?? null) ?? self::FIT_COVER) : self::FIT_COVER,
            mobileFit: $slot->fit ? self::fitOrNull($row[$slot->column('mobile_fit')] ?? null) : null,
            mobileHeight: $slot->mobileHeight ? self::mobileHeightOrNull($row[$slot->column('mobile_height')] ?? null) : null,
            zoom: self::zoomOrNull($row[$slot->column('zoom')] ?? null) ?? self::DEFAULT_ZOOM,
            mobileZoom: $mobileX === null ? null : self::zoomOrNull($row[$slot->column('mobile_zoom')] ?? null),
        );
    }

    /**
     * The columns to store, exactly the slot's own (App\Repository\ResponsiveImageRepository).
     *
     * @return array<string, int|string|null>
     */
    public function toRow(ResponsiveImageSlot $slot): array
    {
        $row = [
            $slot->column('focus_x') => $this->focusX,
            $slot->column('focus_y') => $this->focusY,
            $slot->column('mobile_media_id') => $this->mobileMediaId,
            $slot->column('mobile_focus_x') => $this->mobileFocusX,
            $slot->column('mobile_focus_y') => $this->mobileFocusY,
            $slot->column('zoom') => $this->zoom,
            $slot->column('mobile_zoom') => $this->mobileFocusX === null ? null : $this->ownMobileZoom(),
        ];

        if ($slot->fit) {
            $row[$slot->column('fit')] = $this->fit;
            $row[$slot->column('mobile_fit')] = $this->mobileFit;
        }

        if ($slot->mobileHeight) {
            $row[$slot->column('mobile_height')] = $this->mobileHeight;
        }

        return $row;
    }

    /** The same value with another desktop focus point. */
    public function withFocus(int $x, int $y): self
    {
        return new self(self::clamp($x), self::clamp($y), $this->mobileMediaId, $this->mobileFocusX, $this->mobileFocusY, $this->fit, $this->mobileFit, $this->mobileHeight, $this->zoom, $this->mobileZoom);
    }

    /**
     * The same value in a place that always fills its frame: a picture behind
     * text (a Paginakop's band), where "the whole picture" would leave the
     * text on bare ground. What was chosen stays stored for the place that
     * does offer it.
     */
    public function coverOnly(): self
    {
        return new self($this->focusX, $this->focusY, $this->mobileMediaId, $this->mobileFocusX, $this->mobileFocusY, self::FIT_COVER, null, $this->mobileHeight, $this->zoom, $this->mobileZoom);
    }

    // --------------------------------------------------------- the request

    /**
     * A posted form as a value to store, and why a part of it was refused.
     *
     * The form names every part after its column (image_focus_x,
     * image_mobile_media_id, …; admin/_responsive_image_field.php), plus three
     * words of its own:
     *
     *   <prefix>presentation      "1": this form carries the field at all, so
     *                             a switch that is off can be told from a form
     *                             without one — which keeps what is stored
     *   <prefix>mobile_source     'desktop' or 'own': the phone's picture
     *   <prefix>mobile_focus_own  "1": the phone has its own point while it
     *                             shows the desktop picture
     *
     * Nothing is guessed. A point that is a number is clamped to 0–100 (a
     * slider cannot send more, a hand-made request can); anything else, a
     * choice outside its closed list, or a mobile picture that is not a
     * picture of the Media Library is refused with a sentence for the editor,
     * keyed by part: focus, mobile_media, mobile_focus, fit, mobile_fit,
     * mobile_height, zoom, mobile_zoom. A zoom that is a number is clamped to
     * ZOOM_MIN-ZOOM_MAX, as a point is to 0-100. A part the form does not
     * carry keeps what is stored.
     *
     * @param array<string, mixed> $input the fields of this one picture (the
     *                                    request, or one row of a row list)
     *
     * @return array{0: self, 1: array<string, string>}
     */
    public static function fromRequest(array $input, ResponsiveImageSlot $slot, self $stored): array
    {
        $errors = [];
        $field = static fn (string $part): string => $slot->column($part);
        $present = ($input[$field('presentation')] ?? null) === '1';

        // The desktop point.
        $focusX = $stored->focusX;
        $focusY = $stored->focusY;
        if (array_key_exists($field('focus_x'), $input) || array_key_exists($field('focus_y'), $input)) {
            $x = self::postedPercent($input[$field('focus_x')] ?? null);
            $y = self::postedPercent($input[$field('focus_y')] ?? null);
            if ($x === null || $y === null) {
                $errors['focus'] = AdminTranslator::trans('media.responsive.error_focus');
            } else {
                $focusX = $x;
                $focusY = $y;
            }
        }

        // How far the picture is enlarged.
        $zoom = $stored->zoom;
        if (array_key_exists($field('zoom'), $input)) {
            $posted = self::postedZoom($input[$field('zoom')]);
            if ($posted === null) {
                $errors['zoom'] = AdminTranslator::trans('media.responsive.error_zoom');
            } else {
                $zoom = $posted;
            }
        }

        // The phone's picture.
        $mobileMediaId = $stored->mobileMediaId;
        $source = $input[$field('mobile_source')] ?? null;
        if ($source !== null) {
            if ($source === self::SOURCE_DESKTOP) {
                $mobileMediaId = null;
            } elseif ($source === self::SOURCE_OWN) {
                $posted = trim((string) (is_scalar($input[$field('mobile_media_id')] ?? null) ? $input[$field('mobile_media_id')] : ''));
                $media = ctype_digit($posted) ? self::phonePicture((int) $posted) : null;
                if ($media === null) {
                    $errors['mobile_media'] = AdminTranslator::trans($posted === '' ? 'media.responsive.error_mobile_media_missing' : 'media.responsive.error_mobile_media');
                } else {
                    $mobileMediaId = $media->id;
                }
            } else {
                $errors['mobile_media'] = AdminTranslator::trans('media.responsive.error_mobile_media');
            }
        }

        // The phone's own point: always with a picture of its own, else only
        // when the switch says so.
        $mobileFocusX = $stored->mobileFocusX;
        $mobileFocusY = $stored->mobileFocusY;
        $mobileZoom = $stored->mobileFocusX === null ? null : $stored->ownMobileZoom();
        if ($present) {
            $own = $mobileMediaId !== null || ($input[$field('mobile_focus_own')] ?? null) === '1';
            if (!$own) {
                $mobileFocusX = null;
                $mobileFocusY = null;
            } else {
                $x = self::postedPercent($input[$field('mobile_focus_x')] ?? (string) ($stored->mobileFocusX ?? self::DEFAULT_FOCUS));
                $y = self::postedPercent($input[$field('mobile_focus_y')] ?? (string) ($stored->mobileFocusY ?? self::DEFAULT_FOCUS));
                if ($x === null || $y === null) {
                    $errors['mobile_focus'] = AdminTranslator::trans('media.responsive.error_focus');
                } else {
                    $mobileFocusX = $x;
                    $mobileFocusY = $y;
                }

                $postedZoom = self::postedZoom($input[$field('mobile_zoom')] ?? (string) $stored->ownMobileZoom());
                if ($postedZoom === null) {
                    $errors['mobile_zoom'] = AdminTranslator::trans('media.responsive.error_zoom');
                } else {
                    $mobileZoom = $postedZoom;
                }
            }
        }
        // A phone's zoom lives with its own point, never without one.
        if ($mobileFocusX === null) {
            $mobileZoom = null;
        } elseif ($mobileZoom === null) {
            $mobileZoom = self::DEFAULT_ZOOM;
        }

        // Cover or contain, where the picture has a frame of its own.
        $fit = $stored->fit;
        $mobileFit = $stored->mobileFit;
        if ($slot->fit) {
            if (array_key_exists($field('fit'), $input)) {
                $posted = self::fitOrNull($input[$field('fit')]);
                if ($posted === null) {
                    $errors['fit'] = AdminTranslator::trans('media.responsive.error_fit');
                } else {
                    $fit = $posted;
                }
            }
            if (array_key_exists($field('mobile_fit'), $input)) {
                $raw = $input[$field('mobile_fit')];
                $posted = self::fitOrNull($raw);
                if ($raw === '' || $raw === null) {
                    $mobileFit = null;
                } elseif ($posted === null) {
                    $errors['mobile_fit'] = AdminTranslator::trans('media.responsive.error_fit');
                } else {
                    $mobileFit = $posted;
                }
            }
        }

        // A phone's own height, where the block offers one.
        $mobileHeight = $stored->mobileHeight;
        if ($slot->mobileHeight && array_key_exists($field('mobile_height'), $input)) {
            $raw = $input[$field('mobile_height')];
            $posted = self::mobileHeightOrNull($raw);
            if ($raw === '' || $raw === null) {
                $mobileHeight = null;
            } elseif ($posted === null) {
                $errors['mobile_height'] = AdminTranslator::trans('media.responsive.error_mobile_height');
            } else {
                $mobileHeight = $posted;
            }
        }

        return [
            new self($focusX, $focusY, $mobileMediaId, $mobileFocusX, $mobileFocusY, $fit, $mobileFit, $mobileHeight, $zoom, $mobileZoom),
            $errors,
        ];
    }

    // ---------------------------------------------------------- rendering

    /**
     * What partials/responsive-image.php prints for one picture: the desktop
     * picture as the block resolved it (App\Service\Media\BlockImage), this
     * value's points and fit, and — only when there is one — the phone's own
     * picture. A mobile picture that is no longer in the library is simply no
     * mobile picture: the desktop one shows everywhere, as before it was chosen.
     *
     * `mobile_position`, `mobile_fit` and `mobile_zoom` are only there when a
     * phone shows something else than a large screen, so a picture without
     * mobile settings prints exactly the <img> it always did. `zoom` and
     * `mobile_zoom` are what a frame shows: 100 wherever the picture is
     * contained, whatever is stored (zoomFor()).
     *
     * @param array{image_path?: string, src?: string, alt?: string, width?: int|null, height?: int|null} $image
     * @param bool $withMobileImage false where a phone keeps the desktop
     *                              picture whatever was chosen (every slide
     *                              of a media sequence, MEDIA.md)
     *
     * @return array{src: string, alt: string, width: int|null, height: int|null,
     *               mobile: array{src: string, width: int|null, height: int|null}|null,
     *               position: string, mobile_position: string|null, fit: string, mobile_fit: string|null,
     *               zoom: int, mobile_zoom: int|null}
     */
    public function forRender(array $image, bool $withMobileImage = true): array
    {
        $mobile = null;
        if ($withMobileImage && $this->mobileMediaId !== null) {
            $item = self::phonePicture($this->mobileMediaId);
            if ($item !== null) {
                $mobile = [
                    'src' => $item->publicPath(),
                    'width' => $item->hasDimensions() ? $item->width : null,
                    'height' => $item->hasDimensions() ? $item->height : null,
                ];
            }
        }

        $position = self::objectPosition($this->focusX, $this->focusY);
        // A phone's own point belongs to the picture it was set on. With a
        // phone picture of its own chosen, that is the phone picture: a slide
        // of a sequence, or the desktop picture standing in for a phone
        // picture that left the library, keeps the desktop point on a phone.
        $mobilePoint = $this->mobileMediaId !== null && $mobile === null ? null : $this->mobileFocus($mobile !== null);
        $mobilePosition = $mobilePoint === null ? null : self::objectPosition($mobilePoint[0], $mobilePoint[1]);

        // The zoom goes with the point: the phone's own with its own point,
        // else the desktop one; none on a contained picture.
        if ($mobilePoint === null) {
            $mobileStored = $this->zoom;
        } elseif ($this->mobileFocusX === null) {
            // A phone picture of its own, never given a point: the middle, unzoomed.
            $mobileStored = self::DEFAULT_ZOOM;
        } else {
            $mobileStored = $this->ownMobileZoom();
        }
        $zoom = self::zoomFor($this->fit, $this->zoom);
        $mobileZoom = self::zoomFor($this->effectiveMobileFit(), $mobileStored);

        return [
            'src' => (string) ($image['src'] ?? $image['image_path'] ?? ''),
            'alt' => (string) ($image['alt'] ?? ''),
            'width' => isset($image['width']) ? (int) $image['width'] : null,
            'height' => isset($image['height']) ? (int) $image['height'] : null,
            'mobile' => $mobile,
            'position' => $position,
            'mobile_position' => $mobilePosition !== null && $mobilePosition !== $position ? $mobilePosition : null,
            'fit' => $this->fit,
            'mobile_fit' => $this->mobileFit !== null && $this->mobileFit !== $this->fit ? $this->mobileFit : null,
            'zoom' => $zoom,
            'mobile_zoom' => $mobileZoom !== $zoom ? $mobileZoom : null,
        ];
    }

    /** The zoom of the phone's own point: its own, 100 for one stored before zoom existed. */
    public function ownMobileZoom(): int
    {
        return $this->mobileZoom ?? self::DEFAULT_ZOOM;
    }

    /**
     * What a frame shows of a stored zoom: the zoom itself with cover, none
     * (100) with contain, where the whole picture is the point.
     */
    public static function zoomFor(string $fit, int $zoom): int
    {
        return $fit === self::FIT_CONTAIN ? self::DEFAULT_ZOOM : $zoom;
    }

    /** The CSS scale of a zoom percentage: 125 -> "1.25", 200 -> "2". */
    public static function scale(int $zoom): string
    {
        $zoom = max(self::ZOOM_MIN, min(self::ZOOM_MAX, $zoom));

        return rtrim(rtrim(sprintf('%.2F', $zoom / 100), '0'), '.');
    }

    /**
     * The phone's own point, or null when a phone follows the desktop one. A
     * phone picture of its own always has its own point: the middle, until an
     * editor moves it.
     *
     * @return array{0: int, 1: int}|null
     */
    public function mobileFocus(bool $hasMobileImage): ?array
    {
        if ($this->mobileFocusX !== null && $this->mobileFocusY !== null) {
            return [$this->mobileFocusX, $this->mobileFocusY];
        }

        return $hasMobileImage ? [self::DEFAULT_FOCUS, self::DEFAULT_FOCUS] : null;
    }

    /** The phone's fit: its own, else the large screen's. */
    public function effectiveMobileFit(): string
    {
        return $this->mobileFit ?? $this->fit;
    }

    /**
     * A rendered picture (forRender()) as its compact presentation on every
     * screen: the phone's own picture with the phone's point and fit, and no
     * <source> left to switch. For a place that is compact at any width — the
     * Kaarten-carrousel's "Kaarten naast elkaar", whose cards stand in the same
     * flat row on a phone and on a large screen. Without a phone picture of
     * its own the picture comes back unchanged: the desktop picture
     * everywhere, its phone point and fit still only below the breakpoint.
     *
     * @param array<string, mixed> $picture what forRender() returned
     *
     * @return array<string, mixed> the same shape
     */
    public static function compact(array $picture): array
    {
        $mobile = $picture['mobile'] ?? null;
        if (!is_array($mobile)) {
            return $picture;
        }

        return [
            'src' => (string) $mobile['src'],
            'alt' => (string) ($picture['alt'] ?? ''),
            'width' => $mobile['width'] ?? null,
            'height' => $mobile['height'] ?? null,
            'mobile' => null,
            'position' => (string) ($picture['mobile_position'] ?? $picture['position']),
            'mobile_position' => null,
            'fit' => (string) ($picture['mobile_fit'] ?? $picture['fit']),
            'mobile_fit' => null,
            'zoom' => (int) ($picture['mobile_zoom'] ?? $picture['zoom'] ?? self::DEFAULT_ZOOM),
            'mobile_zoom' => null,
        ];
    }

    /** The CSS object-position of a point: "37% 64%". */
    public static function objectPosition(int $x, int $y): string
    {
        return self::clamp($x) . '% ' . self::clamp($y) . '%';
    }

    /**
     * The <source media> of a phone's picture, from the one breakpoint. A
     * place that turns compact at a width of its own names that width (the
     * Kaarten-carrousel's flat strip, CardCarouselContent::COMPACT_MAX_WIDTH);
     * every other place keeps MOBILE_MAX_WIDTH.
     */
    public static function mobileMedia(?int $maxWidth = null): string
    {
        return '(max-width: ' . ($maxWidth ?? self::MOBILE_MAX_WIDTH) . 'px)';
    }

    /** A stored flat-row ratio of a card carousel, or 'auto' for anything unknown. */
    public static function flatRatio(mixed $stored): string
    {
        return is_string($stored) && in_array($stored, self::FLAT_RATIOS, true) ? $stored : 'auto';
    }

    // ------------------------------------------------------------ helpers

    /**
     * A library item a phone can be shown: a picture, raster or SVG
     * (MediaItem::isPicture()). Stricter than MediaService::findImage(), which
     * only turns a video away: a phone picture becomes the srcset of a
     * <source>, and a browser can only decode a picture there. The uploader
     * takes nothing else today; a row that came into the library another way
     * is simply no phone picture.
     */
    private static function phonePicture(int $id): ?MediaItem
    {
        $item = MediaService::findImage($id);

        return $item !== null && $item->isPicture() ? $item : null;
    }

    private static function clamp(int $percent): int
    {
        return max(0, min(100, $percent));
    }

    /** A stored percentage, clamped, or null for none or not a number. */
    private static function percentOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return self::clamp($value);
        }

        if (is_string($value) && preg_match('/^\s*-?\d+(\.\d+)?\s*$/', $value) === 1) {
            return self::clamp((int) round((float) $value));
        }

        if (is_float($value) && is_finite($value)) {
            return self::clamp((int) round($value));
        }

        return null;
    }

    /** A posted percentage: a number (clamped), or null for anything that is not one. */
    private static function postedPercent(mixed $value): ?int
    {
        return is_string($value) || is_int($value) ? self::percentOrNull($value) : null;
    }

    /** A stored zoom, clamped to ZOOM_MIN-ZOOM_MAX, or null for none or not a number. */
    private static function zoomOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return max(self::ZOOM_MIN, min(self::ZOOM_MAX, $value));
        }

        if (is_string($value) && preg_match('/^\s*-?\d+(\.\d+)?\s*$/', $value) === 1) {
            return max(self::ZOOM_MIN, min(self::ZOOM_MAX, (int) round((float) $value)));
        }

        if (is_float($value) && is_finite($value)) {
            return max(self::ZOOM_MIN, min(self::ZOOM_MAX, (int) round($value)));
        }

        return null;
    }

    /** A posted zoom: a number (clamped), or null for anything that is not one. */
    private static function postedZoom(mixed $value): ?int
    {
        return is_string($value) || is_int($value) ? self::zoomOrNull($value) : null;
    }

    private static function fitOrNull(mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::FITS, true) ? $value : null;
    }

    private static function mobileHeightOrNull(mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::MOBILE_HEIGHTS, true) ? $value : null;
    }
}
