<?php

declare(strict_types=1);

/**
 * Shared validation for the admin Portfolio endpoints: the words an item's
 * forms send in one website language, the categories they send, the page an
 * item's editor chooses as its project page, and a new category's slug (same
 * algorithm as api/admin/_product_validation.php's generateUniqueSlug(),
 * reused against PortfolioCategoryRepository).
 */

use App\Repository\PageRepository;
use App\Repository\PortfolioCategoryRepository;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageCode;
use App\Service\Language\LanguageFallback;
use App\Service\Language\SiteLanguages;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;

/**
 * The words an item's form submits, in the ONE website language it names, and
 * what is wrong with them (Multilingual 2.0 phase 5 wave A).
 *
 * A new item is born in the default language, like a new page, so $isNew
 * decides the language rather than the form: `language_code` is only read for
 * an existing item. Nothing about these three fields is required, in any
 * language — the picture is the item (see create-portfolio-item.php) — so
 * only their declared length is checked, through
 * App\Service\Language\EntityTranslations::problems(), which is where those
 * lengths live.
 *
 * @param array<string, mixed> $input raw $_POST
 * @return array{0: list<string>, 1: string, 2: array<string, string>} [errors, the language, the words by field]
 */
function validatePortfolioItemWords(array $input, bool $isNew): array
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

    if ($language === '' || !SiteLanguages::isActive($language)) {
        return [[AdminTranslator::trans('validation.language_unknown')], $language, $words];
    }

    $messages = [
        PortfolioLocalization::ALT => 'validation.alt_tekst_mag_maximaal_255',
        PortfolioLocalization::TITLE => 'validation.titel_mag_maximaal_150_tekens',
        PortfolioLocalization::SUBTITLE => 'validation.onderschrift_mag_maximaal_150_tekens',
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
 * The project page an editor chose on admin/portfolio-item.php: null for "Geen
 * gekoppelde pagina" (an empty or absent value), the page's id for a page an
 * item may link to (App\Service\PortfolioGalleryContent::isLinkablePage()),
 * and false for anything else — an id no page has any more (deleted in another
 * tab since the screen loaded), a page with a template of its own, or no id at
 * all.
 *
 * Unlike an unknown category, an unusable page is not silently dropped: the
 * caller refuses false, because turning it into "no page" would quietly take
 * away a link the editor meant to keep.
 *
 * @param mixed $submitted raw $_POST['page_id'] value
 */
function validatePortfolioPageChoice(mixed $submitted, PageRepository $pages): int|false|null
{
    if ($submitted === null || (is_string($submitted) && trim($submitted) === '')) {
        return null;
    }

    if (!is_string($submitted)) {
        return false;
    }

    $pageId = filter_var(trim($submitted), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($pageId === false) {
        return false;
    }

    $page = $pages->findById($pageId);

    return $page !== null && PortfolioGalleryContent::isLinkablePage($page) ? (int) $page['id'] : false;
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
