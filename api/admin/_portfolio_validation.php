<?php

declare(strict_types=1);

/**
 * Shared validation for the admin Portfolio endpoints: the words an item's
 * forms send in one website language, the categories they send, the item's
 * own project page (switch and slug), what may happen to a legacy linked
 * page, and a new category's slug (same algorithm as
 * api/admin/_product_validation.php's generateUniqueSlug(), reused against
 * PortfolioCategoryRepository).
 */

use App\Repository\PortfolioCategoryRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageCode;
use App\Service\Language\LanguageFallback;
use App\Service\Language\SiteLanguages;
use App\Service\Media\MediaItem;
use App\Service\Media\MediaService;
use App\Service\PortfolioLocalization;
use App\Service\PortfolioSlug;
use App\Service\RichTextSanitizer;

/**
 * The words an item's form submits, in the ONE website language it names, and
 * what is wrong with them (Multilingual 2.0 phase 5 wave A).
 *
 * A new item is born in the default language, like a new page, so $isNew
 * decides the language rather than the form: `language_code` is only read for
 * an existing item. Nothing about these fields is required, in any language —
 * the picture is the item (see create-portfolio-item.php) — so only their
 * declared length is checked, through
 * App\Service\Language\EntityTranslations::problems(), which is where those
 * lengths live.
 *
 * $withProjectText adds the project page's two rich fields, intro and
 * description, which only the item's editor shows. They are sanitized here,
 * on the way in (RichTextSanitizer is the security boundary; the editor's
 * Quill is a convenience), and again on the way out
 * (PortfolioLocalization::itemRich()). A form that does not send them never
 * empties them: the create form leaves them out altogether.
 *
 * @param array<string, mixed> $input raw $_POST
 * @return array{0: list<string>, 1: string, 2: array<string, string>} [errors, the language, the words by field]
 */
function validatePortfolioItemWords(array $input, bool $isNew, bool $withProjectText = false): array
{
    $text = static fn (string $name): string => is_string($input[$name] ?? null) ? trim($input[$name]) : '';

    $language = $isNew
        ? LanguageFallback::defaultLanguage()
        : (LanguageCode::normalise($text('language_code')) ?? '');

    $words = [
        PortfolioLocalization::ALT => $text(PortfolioLocalization::ALT),
        PortfolioLocalization::TITLE => $text(PortfolioLocalization::TITLE),
        PortfolioLocalization::SUBTITLE => $text(PortfolioLocalization::SUBTITLE),
    ];

    if ($withProjectText) {
        foreach (PortfolioLocalization::RICH_FIELDS as $field) {
            // Only a field the request sends is written: saveItem() keeps
            // what is stored for a field it is not given.
            if (array_key_exists($field, $input)) {
                $words[$field] = (string) (RichTextSanitizer::sanitize($text($field)) ?? '');
            }
        }
    }

    if ($language === ''|| !SiteLanguages::isActive($language)) {
        return [[AdminTranslator::trans('validation.language_unknown')], $language, $words];
    }

    $messages = [
        PortfolioLocalization::ALT => 'validation.alt_tekst_mag_maximaal_255',
        PortfolioLocalization::TITLE => 'validation.titel_mag_maximaal_150_tekens',
        PortfolioLocalization::SUBTITLE => 'validation.onderschrift_mag_maximaal_150_tekens',
        PortfolioLocalization::INTRO => 'validation.portfolio_intro_too_long',
        PortfolioLocalization::DESCRIPTION => 'validation.portfolio_description_too_long',
    ];

    $errors = [];
    foreach (PortfolioLocalization::items()->problems($language, $words) as $field => $problem) {
        if ($problem === 'too_long' && isset($messages[$field])) {
            $errors[] = AdminTranslator::trans($messages[$field]);
        }
    }

    return [$errors, $language, $words];
}

/**
 * Validates a submitted `categories[]` array (from admin/portfolio-item.php's
 * dynamic checkbox list) down to only ids that actually exist — unknown/
 * stale ids (e.g. a category deleted in another tab since the page loaded)
 * are silently dropped rather than failing the whole save. Used by both
 * create-portfolio-item.php and update-portfolio-item.php.
 *
 * @param mixed $submitted raw $_POST['categories'] value
 * @return list<int>
 */
function validatePortfolioCategoryIds(mixed $submitted, PortfolioCategoryRepository $repository): array
{
    if (!is_array($submitted)) {
        return [];
    }

    $submittedIds = array_values(array_unique(array_map('intval', $submitted)));
    if ($submittedIds === []) {
        return [];
    }

    $existing = $repository->findByIds($submittedIds);

    return array_map(static fn (array $category): int => (int) $category['id'], $existing);
}

/**
 * What happens to an item's LEGACY linked page (phase 4B,
 * portfolio_gallery_items.page_id) on this save: it is kept, or it is
 * unlinked. Nothing else is possible since Portfolio 2.0: an item's project
 * page is its own, so no new link to an ordinary page can be made, and an
 * item without one never gets one (MODULES.md, "Portfolio").
 *
 * The editor sends `unlink_page` to unlink; without it the stored link is
 * kept exactly as it is. `page_id` is no longer read at all, so an old form
 * or a tampered request cannot link a page. Unlinking never touches the page
 * itself: it stays, with its content, where it was.
 *
 * @param array<string, mixed> $input raw $_POST
 * @param array<string, mixed> $item  the stored item row
 */
function portfolioLegacyPageAfterSave(array $input, array $item): ?int
{
    $current = (int) ($item['page_id'] ?? 0);

    if ($current < 1 || ($input['unlink_page'] ?? '') === '1') {
        return null;
    }

    return $current;
}

/**
 * The item's own project page as its editor submitted it: whether it is shown
 * ("Projectpagina tonen", `has_detail_page`) and its slug, and what is wrong
 * with them.
 *
 * The slug is normalised (PortfolioSlug::normalise()). A switched-on page
 * without a slug typed (or with one the screen filled in from the title by
 * itself) gets one made from the title in the default language, unique, so
 * switching the page on is enough; a typed slug is checked as typed and
 * refused, never silently changed, when another item has it. A switched-off
 * page with its slug field emptied keeps the slug it had, so switching it back
 * on returns the same address.
 *
 * Only a request that carries the section (`project_page_submitted`, the way
 * the gallery says `gallery_submitted`) changes either: an unticked switch
 * sends nothing at all, so without that marker a request that never showed
 * the section would switch the page off. Without it, both stay as stored.
 *
 * @param array<string, mixed> $input raw $_POST
 * @return array{0: list<string>, 1: bool, 2: ?string} [errors, shown, slug]
 */
function validatePortfolioProjectPage(array $input, PortfolioGalleryRepository $repository, int $itemId, bool $currentlyShown, ?string $currentSlug, string $defaultTitle): array
{
    if (($input['project_page_submitted'] ?? '') !== '1') {
        return [[], $currentlyShown, $currentSlug !== null && $currentSlug !== '' ? $currentSlug : null];
    }

    $shown = isset($input['has_detail_page']);
    // An address the screen filled in from the title by itself
    // (`slug_auto`, admin/assets/admin.js) is made here instead, unique.
    $typed = is_string($input['slug'] ?? null) && ($input['slug_auto'] ?? '') !== '1' ? trim($input['slug']) : '';
    $slug = PortfolioSlug::normalise($typed);

    if ($typed !== '' && $slug === '') {
        return [[AdminTranslator::trans('validation.portfolio_slug_empty')], $shown, null];
    }

    if ($slug === '') {
        if (!$shown) {
            return [[], false, $currentSlug !== null && $currentSlug !== '' ? $currentSlug : null];
        }

        return [[], true, PortfolioSlug::suggest($repository, $defaultTitle, $itemId)];
    }

    $problem = PortfolioSlug::problem($repository, $slug, $itemId);

    return [$problem === null ? [] : [$problem], $shown, $slug];
}

/**
 * Generates a URL-safe slug from a new category's name in the website's
 * DEFAULT language, and makes it unique against portfolio_categories.slug.
 * Only called on create — the slug is never regenerated on rename, so no
 * translation can ever move an address (see
 * db/migrations/20260906070000_create_portfolio_categories_table.php).
 */
function generatePortfolioCategorySlug(PortfolioCategoryRepository $repository, string $name): string
{
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $name) : $name;
    $base = strtolower((string) ($ascii !== false ? $ascii : $name));
    $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
    $base = trim($base, '-');

    if ($base === '') {
        $base = 'categorie';
    }

    $base = substr($base, 0, 90);

    $slug = $base;
    $suffix = 2;
    while ($repository->slugExists($slug)) {
        $slug = substr($base, 0, 95 - strlen((string) $suffix) - 1) . '-' . $suffix;
        $suffix++;
    }

    return $slug;
}

/**
 * The library picture an item's form chose (the `media_id` of the shared
 * picker, admin/_media_picker.php), or null when the form sent none or an id
 * that names no image in the library: the picker is a convenience, this is
 * the check (MEDIA.md, "De mediakiezer"). An item's picture is always a
 * library item since Media Library 2.0; nothing here reads $_FILES.
 */
function portfolioLibraryImage(mixed $posted): ?MediaItem
{
    $id = filter_var($posted, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    return $id === false ? null : MediaService::findImage($id);
}

/**
 * The two columns every public reader of an item reads, written along with
 * the library item (MEDIA.md, "Hoe een feature naar media verwijst"): its
 * path, and its thumbnail — or its path again for a file the library keeps no
 * thumbnail of (a GIF, an SVG), the same fallback the gallery applies to old
 * rows.
 *
 * @return array{media_id: int, image_path: string, thumbnail_path: string}
 */
function portfolioImageColumns(MediaItem $media): array
{
    return [
        'media_id' => $media->id,
        'image_path' => $media->path,
        'thumbnail_path' => $media->thumbnailPath ?? $media->path,
    ];
}
