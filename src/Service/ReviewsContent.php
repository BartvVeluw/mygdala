<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ReviewsRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\Language\SiteText;
use App\Service\Media\MediaItem;
use App\Service\Media\MediaService;
use App\Service\Media\ResponsiveImage;
use App\Service\Media\ResponsiveImageSlot;
use App\Service\Routing\LinkChoice;
use App\Service\Routing\RequestLanguage;
use App\Service\Routing\SafeUrl;
use App\Service\Theme\ButtonStyles;

/**
 * Read model of the Reviews block (App\Service\Blocks\ReviewsBlock): reviews
 * an editor typed in by hand, shown as a calm quote, cards, one featured
 * review or a carousel (CONTENT-BLOCKS.md, "Reviews").
 *
 * THE DATA AND ITS PRESENTATION ARE TWO THINGS. forSection() turns the
 * block's own rows into a list of plain REVIEWS — text, name, description,
 * stars, date, picture and source, each already resolved for the language
 * of the request — and the choice of how to show them (`layout`). The
 * partial (partials/section-reviews.php) only knows that shape, never a
 * table. A later Reviews module can hand the same partial the same shape
 * from its own tables; see review() and CONTENT-BLOCKS.md, "Later: een
 * Reviews-module".
 *
 * EVERY CHOICE IS A WORD FROM A CLOSED LIST (CONTENT-BLOCKS.md, "Een
 * weergavekeuze is een woord uit een gesloten lijst"). The first word of each
 * list is its default; a stored value this class does not know reads as that
 * default. What a word looks like is assets/css/blocks/reviews.css's.
 *
 * A REVIEW IS ITS TEXT. It shows when it has a text in the default language
 * (the editor requires one); name, description, stars, date, picture and
 * source are all optional, and a review without them looks just as finished.
 * Stars are a whole number from 1 to 5, or none; there is never an empty row
 * of stars and never a label such as "verified purchase" that this CMS
 * cannot check.
 *
 * A SOURCE LINK IS A WEB ADDRESS. The editor only stores http(s) addresses
 * (App\Service\Routing\SafeUrl::SCHEMES_WEB), and this class asks again, so
 * a value stored in any other way never becomes a link.
 *
 * WORDS PER LANGUAGE. The eyebrow, title, lead and button label, and each
 * review's text, name, description and source label are in
 * block_translations and arrive as one string each in the language of the
 * request, the fallback to the default language applied
 * (App\Service\Blocks\BlockLocalization). The default language decides
 * whether each one is there, as everywhere (docs/multilingual/ARCHITECTURE.md).
 *
 * The three states of every block (CONTENT-BLOCKS.md): no row or a failed
 * lookup is STATE_FALLBACK, a row switched off is STATE_HIDDEN. Both render
 * nothing, and so does an active block without a review to show.
 */
final class ReviewsContent
{
    public const STATE_FALLBACK = 'fallback';

    public const STATE_ACTIVE = 'active';

    public const STATE_HIDDEN = 'hidden';

    /** The owner tables of the words (ReviewsBlock::translatableFields()). */
    public const TABLE = 'review_blocks';

    public const ITEMS = 'review_block_items';

    /**
     * How the reviews are shown: a grid of cards (the start), one calm quote
     * after another, one large featured review, or a carousel of cards.
     */
    public const LAYOUTS = ['cards', 'minimal', 'featured', 'carousel'];

    /** Where the heading above the reviews sits. */
    public const HEADER_ALIGNMENTS = ['left', 'center'];

    /**
     * Each choice and its closed list; the first word is the default.
     *
     * @var array<string, list<string>>
     */
    public const CHOICES = [
        'layout' => self::LAYOUTS,
        'header_align' => self::HEADER_ALIGNMENTS,
    ];

    /** The most stars a review can have. */
    public const MAX_RATING = 5;

    /** The month names of a review's date: code-owned website text, like BlogContent's. */
    private const MONTHS = [
        'nl' => [
            1 => 'januari', 'februari', 'maart', 'april', 'mei', 'juni',
            'juli', 'augustus', 'september', 'oktober', 'november', 'december',
        ],
        'en' => [
            1 => 'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ],
    ];

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*); eyebrow, title
     *     and lead (a string each, '' when empty); layout and header_align,
     *     each a word of its list; 'reviews', a list of review() shapes;
     *     'featured', the index in 'reviews' that "featured" shows (0 when
     *     the chosen one is gone); 'button', null or href and label; and
     *     'button_style', a Button Styles choice or null for the default. Templates
     *     must check 'state' !== STATE_HIDDEN first; the fields are present
     *     (empty, the choices at their defaults) in every state.
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = RequestLanguage::current() . '|' . $pageSlug . ':' . $sectionKey;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $repository = new ReviewsRepository();
            $row = $repository->findBySlugAndKey($pageSlug, $sectionKey);
            $items = $row !== null && (bool) $row['is_active'] ? $repository->findItemsByBlockId((int) $row['id']) : [];
        } catch (\Throwable $e) {
            error_log('[ReviewsContent] lookup failed for "' . $pageSlug . ':' . $sectionKey . '": ' . $e->getMessage());
            $row = null;
            $items = [];
        }

        if ($row === null) {
            return self::$cache[$cacheKey] = ['state' => self::STATE_FALLBACK] + self::emptyContent();
        }

        if (!(bool) $row['is_active']) {
            return self::$cache[$cacheKey] = ['state' => self::STATE_HIDDEN] + self::emptyContent();
        }

        $blockId = (int) $row['id'];

        // The words of the block and of every review at once; nothing when
        // the page already loaded them (SectionRegistry::renderPage()).
        BlockLocalization::preloadBlocks([self::TABLE => [$blockId]]);

        // Every picture of the block in one query.
        MediaService::preload(array_map(static fn (array $item): ?int => isset($item['media_id']) ? (int) $item['media_id'] : null, $items));

        $reviews = [];
        $featured = 0;
        $featuredId = (int) ($row['featured_item_id'] ?? 0);
        foreach ($items as $item) {
            $review = self::storedReview($item);
            if ($review === null) {
                continue;
            }
            if ((int) $item['id'] === $featuredId) {
                $featured = count($reviews);
            }
            $reviews[] = $review;
        }

        $words = self::shownWords(self::TABLE, $blockId, ['eyebrow', 'title', 'lead', 'button_label']);

        return self::$cache[$cacheKey] = ['state' => self::STATE_ACTIVE]
            + ['eyebrow' => $words['eyebrow'], 'title' => $words['title'], 'lead' => $words['lead']]
            + self::settings($row)
            + [
                'reviews' => $reviews,
                'featured' => $featured,
                'button' => self::button($row, $words['button_label']),
                'button_style' => ButtonStyles::storedChoice($row['button_style_id'] ?? null),
            ];
    }

    /**
     * The choices of a stored row, a refused save's hand-back or a posted
     * form, each checked against its list: a value the list does not know is
     * the default. For the page and the editor alike.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, string> choice => word
     */
    public static function settings(array $values): array
    {
        $settings = [];
        foreach (self::CHOICES as $choice => $list) {
            $value = $values[$choice] ?? null;
            $settings[$choice] = is_scalar($value) && in_array((string) $value, $list, true) ? (string) $value : $list[0];
        }

        return $settings;
    }

    /**
     * ONE REVIEW as the partial gets it, from plain values: the shape every
     * source of reviews hands the partial — this block's own rows today, a
     * Reviews module tomorrow. Words arrive resolved and unescaped; the
     * partial escapes them. Stars outside 1..5 are no stars, a date that is
     * not a real Y-m-d date is no date, and a source address that is not a
     * safe web address is no link.
     *
     * @param array{text?: string, name?: string, role?: string, rating?: mixed, date?: mixed,
     *              source_label?: string, source_url?: string, image?: array<string, mixed>|null} $values
     *
     * @return array{text: string, name: string, role: string, rating: int, rating_label: string,
     *               date: string, date_iso: string, source_label: string, source_href: string,
     *               image: array<string, mixed>|null}
     */
    public static function review(array $values): array
    {
        $rating = self::rating($values['rating'] ?? null) ?? 0;
        $date = self::date($values['date'] ?? null);
        $sourceUrl = trim((string) ($values['source_url'] ?? ''));
        $sourceHref = $sourceUrl !== '' && SafeUrl::problem(SafeUrl::normalise($sourceUrl), SafeUrl::SCHEMES_WEB) === null ? $sourceUrl : '';
        $sourceLabel = trim((string) ($values['source_label'] ?? ''));
        if ($sourceLabel === '' && $sourceHref !== '') {
            // A link needs words: the address's own host, never the whole address.
            $sourceLabel = preg_replace('/^www\./i', '', (string) parse_url($sourceHref, PHP_URL_HOST)) ?? '';
        }

        return [
            'text' => trim((string) ($values['text'] ?? '')),
            'name' => trim((string) ($values['name'] ?? '')),
            'role' => trim((string) ($values['role'] ?? '')),
            'rating' => $rating,
            'rating_label' => $rating > 0 ? self::ratingLabel($rating) : '',
            'date' => $date !== null ? self::longDate($date) : '',
            'date_iso' => $date?->format('Y-m-d') ?? '',
            'source_label' => $sourceLabel,
            'source_href' => $sourceHref,
            'image' => $values['image'] ?? null,
        ];
    }

    /** A stored or posted rating as stars: 1..5, or null for none. */
    public static function rating(mixed $value): ?int
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        $rating = (int) $value;

        return $rating >= 1 && $rating <= self::MAX_RATING ? $rating : null;
    }

    /** A stored or posted date as a real calendar date (Y-m-d), or null. */
    public static function date(mixed $value): ?\DateTimeImmutable
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * What a screen reader says for the stars: "4 van de 5 sterren". The
     * stars themselves are decoration next to it.
     */
    public static function ratingLabel(int $rating): string
    {
        return str_replace([':n', ':max'], [(string) $rating, (string) self::MAX_RATING], SiteText::pick([
            'nl' => ':n van de :max sterren',
            'en' => ':n out of :max stars',
        ]));
    }

    /** A date as the page prints it, in the language being read: "12 maart 2026". */
    public static function longDate(\DateTimeImmutable $date): string
    {
        $month = SiteText::pick(array_map(
            static fn (array $names): string => $names[(int) $date->format('n')],
            self::MONTHS
        ));

        return $date->format('j') . ' ' . $month . ' ' . $date->format('Y');
    }

    /**
     * The picture a review may show: a picture of the library, never a video
     * or a file no kind claims. The endpoint reads a posted id through this
     * too, so what can be stored is what can be shown.
     */
    public static function picture(?int $id): ?MediaItem
    {
        $item = MediaService::find($id);

        return $item !== null && $item->isPicture() ? $item : null;
    }

    /**
     * Where a review keeps its picture's presentation (Responsive Media): the
     * image_ columns of review_block_items. The picture is cropped into a
     * round or square frame of the layout's own size, so a focus point and a
     * zoom matter; there is no "whole picture" and no phone height.
     */
    public static function imageSlot(): ResponsiveImageSlot
    {
        return new ResponsiveImageSlot('image_', 'media_id');
    }

    public static function clearCache(): void
    {
        self::$cache = [];
        BlockLocalization::clearCache();
    }

    /**
     * One stored review as the partial gets it, or null when it has no text.
     *
     * @param array<string, mixed> $item a review_block_items row
     *
     * @return array<string, mixed>|null
     */
    private static function storedReview(array $item): ?array
    {
        $itemId = (int) $item['id'];
        $words = self::shownWords(self::ITEMS, $itemId, ['body', 'name', 'role', 'source_label']);
        if ($words['body'] === '') {
            return null;
        }

        return self::review([
            'text' => $words['body'],
            'name' => $words['name'],
            'role' => $words['role'],
            'rating' => $item['rating'] ?? null,
            'date' => $item['review_date'] ?? null,
            'source_label' => $words['source_label'],
            'source_url' => (string) ($item['source_url'] ?? ''),
            'image' => self::portrait($item),
        ]);
    }

    /**
     * A review's picture for partials/responsive-image.php, or null. The
     * frame is small (a portrait of at most a few hundred pixels), so the
     * library's thumbnail is used where it has one (MediaItem::displayPath(),
     * 480 pixels on its long side); the dimensions stay the original's, which
     * have the same proportions and keep the frame from shifting.
     *
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>|null
     */
    private static function portrait(array $item): ?array
    {
        $picture = self::picture(isset($item['media_id']) ? (int) $item['media_id'] : null);
        if ($picture === null) {
            return null;
        }

        $image = [
            'src' => $picture->displayPath(),
            'alt' => trim($picture->altText),
            'width' => $picture->hasDimensions() ? $picture->width : null,
            'height' => $picture->hasDimensions() ? $picture->height : null,
        ];

        $presentation = ResponsiveImage::fromRow($item, self::imageSlot());
        $rendered = $presentation->forRender($image);
        $phone = $presentation->mobileMediaId !== null ? self::picture($presentation->mobileMediaId) : null;
        if (is_array($rendered['mobile'] ?? null) && $phone !== null) {
            $rendered['mobile']['src'] = $phone->displayPath();
        }

        return $image + ['picture' => $rendered];
    }

    /**
     * The block's optional button: a destination (LinkChoice) and a label in
     * the default language, or null. A destination without a label, or a
     * label without a destination, is no button.
     *
     * @param array<string, mixed> $row
     *
     * @return array{href: string, label: string}|null
     */
    private static function button(array $row, string $label): ?array
    {
        $href = LinkChoice::href($row['link_type'] ?? null, $row['link_target_id'] ?? 0, (string) ($row['link_url'] ?? ''));
        if ($href === '' || $label === '') {
            return null;
        }

        return ['href' => $href, 'label' => $label];
    }

    /**
     * The words of some fields of one owner, each in the request's language,
     * and each '' unless the default language has it.
     *
     * @param list<string> $fields
     *
     * @return array<string, string>
     */
    private static function shownWords(string $table, int $ownerId, array $fields): array
    {
        $words = [];
        foreach ($fields as $field) {
            $words[$field] = BlockLocalization::hasDefaultWords($table, $ownerId, $field)
                ? BlockLocalization::text($table, $ownerId, $field)
                : '';
        }

        return $words;
    }

    /**
     * Every field forSection() returns, empty and at its default.
     *
     * @return array<string, mixed>
     */
    private static function emptyContent(): array
    {
        return ['eyebrow' => '', 'title' => '', 'lead' => ''] + self::settings([]) + ['reviews' => [], 'featured' => 0, 'button' => null, 'button_style' => null];
    }
}
