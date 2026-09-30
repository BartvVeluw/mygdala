<?php

declare(strict_types=1);

namespace App\Service\Theme;

use App\Module\ModuleRegistry;
use App\Repository\FontLibraryRepository;
use App\Service\Language\AdminTranslator;

/**
 * Font Library 1.0: the font families an administrator uploads, and every
 * rule around them — names, variants, the size limits, which family the
 * website uses for its headings and its body text, who else uses a family,
 * the delete protection, and the `@font-face` rules a page needs. Core, on
 * Vormgeving → Lettertypen, behind settings.manage. See THEMING.md,
 * "Font Library".
 *
 * ONE LIBRARY, TWO USERS. The website (theme_font_roles, read through
 * App\Service\Theme\ThemeSettings like every other part of its look) and a
 * page theme (page_themes.heading_font_family_id / body_font_family_id)
 * choose from the same families. Neither has an upload of its own. A colour
 * palette has nothing to do with fonts at all: activating one never changes
 * a font.
 *
 * NOTHING AN ADMINISTRATOR TYPES BECOMES CSS. A family's name is CMS
 * metadata, printed escaped and nowhere else. In CSS a family is
 * `mygdala-font-<id>` (cssFamilyName()), a weight and style come from the
 * closed App\Service\Theme\FontVariant list, a file URL from a generated
 * name that must match FontStorage::NAME_PATTERN, and a format from the
 * verified bytes. The original file name, the family name and the source
 * link cannot reach a stylesheet, whatever they contain.
 *
 * ONLY WHAT A PAGE USES IS LOADED. fontFaceCss() is asked for the families
 * the site's roles and the page's own theme actually use, never the whole
 * library: twenty stored families cost a visitor nothing. Within a used
 * family every variant is declared, and the browser downloads only the
 * faces the page's text needs (an italic file only for italic text).
 *
 * IN USE MEANS IT STAYS. A family the website or a page theme uses cannot be
 * deleted (the CMS says who uses it, and the foreign keys are RESTRICT), and
 * its last variant cannot be removed. There is never a silent fallback
 * after a delete; the only fallback is the browser's, for a file that is
 * missing on disk: the stack goes on to a font every device has, so the
 * text stays readable.
 */
final class FontLibrary
{
    /** All fonts together; a family is a few hundred KB, so this is dozens of families. */
    public const MAX_LIBRARY_BYTES = 50 * 1024 * 1024;

    public const MAX_NAME_LENGTH = 80;

    public const MAX_SOURCE_LENGTH = 500;

    /** What a family falls back to while it loads, or when a file is missing. */
    public const CATEGORIES = [
        'sans' => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif",
        'serif' => "Georgia, 'Times New Roman', Times, serif",
    ];

    /** The role keys ThemeSettings and a page theme use, per role. */
    public const ROLE_KEYS = [
        'heading' => 'heading_font_family_id',
        'body' => 'body_font_family_id',
    ];

    /** @var array<int, array<string, mixed>>|null families with at least one variant, by id */
    private static ?array $usable = null;

    /** @var array<string, int>|null */
    private static ?array $siteRoles = null;

    private static ?FontStorage $storage = null;

    /** Set by overrideForTests(): forget() then keeps the pretended state. */
    private static bool $overridden = false;

    // ------------------------------------------------------------ reading

    /**
     * Every family for the CMS, by name, each with its variants, its size
     * on disk and whether a stored file is missing.
     *
     * @return list<array<string, mixed>>
     */
    public static function families(): array
    {
        $repository = new FontLibraryRepository();
        $families = $repository->families();
        $files = [];
        foreach ($repository->files(array_map(static fn (array $f): int => (int) $f['id'], $families)) as $file) {
            $files[(int) $file['font_family_id']][] = $file;
        }

        foreach ($families as &$family) {
            $family['variants'] = $files[(int) $family['id']] ?? [];
            $family['missing_files'] = self::missingCount($family['variants']);
        }
        unset($family);

        return $families;
    }

    /** @return array<string, mixed>|null one family with its variants */
    public static function family(int $id): ?array
    {
        $repository = new FontLibraryRepository();
        $family = $repository->family($id);
        if ($family === null) {
            return null;
        }

        $family['variants'] = $repository->files([$id]);
        $family['missing_files'] = self::missingCount($family['variants']);

        return $family;
    }

    /**
     * The families a role can choose: those with at least one variant, by
     * name. An empty library is an empty list, and the CMS then shows the
     * built-in pairings only.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function usableFamilies(): array
    {
        if (self::$usable === null) {
            self::$usable = [];
            foreach ((new FontLibraryRepository())->families() as $family) {
                if ((int) $family['variant_count'] > 0) {
                    self::$usable[(int) $family['id']] = $family;
                }
            }
        }

        return self::$usable;
    }

    public static function isUsable(int $familyId): bool
    {
        return $familyId > 0 && array_key_exists($familyId, self::usableFamilies());
    }

    /** The family's name in CSS: made from the id, never from the name. */
    public static function cssFamilyName(int $familyId): string
    {
        return 'mygdala-font-' . $familyId;
    }

    /**
     * The font stack for a usable family: its own name, then the fallback
     * of its kind. Null for anything else, so the caller keeps the pairing.
     */
    public static function stack(int $familyId): ?string
    {
        $family = self::usableFamilies()[$familyId] ?? null;
        if ($family === null) {
            return null;
        }

        $category = (string) ($family['category'] ?? 'sans');

        return "'" . self::cssFamilyName($familyId) . "', " . (self::CATEGORIES[$category] ?? self::CATEGORIES['sans']);
    }

    /**
     * The `@font-face` rules for exactly these families, one per variant,
     * with the family name, weight, style, URL and format all made here
     * (see the class docblock). An empty string for none. The same family
     * asked for twice is declared once.
     *
     * @param list<int> $familyIds
     */
    public static function fontFaceCss(array $familyIds): string
    {
        $ids = [];
        foreach ($familyIds as $id) {
            if (self::isUsable((int) $id)) {
                $ids[(int) $id] = (int) $id;
            }
        }

        if ($ids === []) {
            return '';
        }

        $css = '';
        foreach ((new FontLibraryRepository())->files(array_values($ids)) as $file) {
            $rule = self::fontFaceRule($file);
            if ($rule !== null) {
                $css .= $rule . "\n";
            }
        }

        return $css;
    }

    /**
     * One rule, or null for a row that is not exactly what the library
     * writes (a hand edit): it is left out rather than repaired.
     *
     * @param array<string, mixed> $file
     */
    public static function fontFaceRule(array $file): ?string
    {
        $familyId = (int) ($file['font_family_id'] ?? 0);
        $weight = (int) ($file['weight'] ?? 0);
        $style = (string) ($file['style'] ?? '');
        $format = (string) ($file['format'] ?? '');
        $name = (string) ($file['file_name'] ?? '');

        if ($familyId < 1 || !FontVariant::isValid($weight, $style)
            || !array_key_exists($format, FontFileInspector::FORMATS)
            || preg_match(FontStorage::NAME_PATTERN, $name) !== 1
            || !str_ends_with($name, '.' . $format)
        ) {
            return null;
        }

        return '@font-face{font-family:"' . self::cssFamilyName($familyId) . '";'
            . 'src:url("' . FontStorage::url($name) . '") format("' . FontFileInspector::FORMATS[$format] . '");'
            . 'font-weight:' . $weight . ';font-style:' . $style . ';font-display:swap;}';
    }

    /**
     * The website's own family per role (role => id), only roles that point
     * at a usable family. Empty = both roles follow the font pairing.
     *
     * @return array<string, int>
     */
    public static function siteRoles(): array
    {
        if (self::$siteRoles === null) {
            self::$siteRoles = (new FontLibraryRepository())->siteRoles();
        }

        return self::$siteRoles;
    }

    // ------------------------------------------------------------ usage

    /**
     * Who uses a family: the website's roles and whatever a module reports
     * (a page theme, through ModuleDefinition::fontFamilyUsage(), asked of
     * every registered module, on or off — a switched-off module's themes
     * still hold their choice).
     *
     * @return array{site: list<string>, others: list<array{label: string, url: string}>}
     */
    public static function usage(int $familyId): array
    {
        $site = [];
        $roles = (new FontLibraryRepository())->siteRoles();
        foreach (FontLibraryRepository::ROLES as $role) {
            if (($roles[$role] ?? null) === $familyId) {
                $site[] = $role;
            }
        }

        $others = [];
        foreach (ModuleRegistry::all() as $module) {
            foreach ($module->fontFamilyUsage($familyId) as $use) {
                $others[] = ['label' => (string) $use['label'], 'url' => (string) $use['url']];
            }
        }

        return ['site' => $site, 'others' => $others];
    }

    /** @param array{site: list<string>, others: list<array{label: string, url: string}>} $usage */
    public static function inUse(array $usage): bool
    {
        return $usage['site'] !== [] || $usage['others'] !== [];
    }

    /**
     * The plain-language sentence for a refused delete: who uses it, and
     * what to do. Text only; the screen escapes it.
     *
     * @param array{site: list<string>, others: list<array{label: string, url: string}>} $usage
     */
    public static function usageSentence(array $usage): string
    {
        $parts = [];
        if ($usage['site'] !== []) {
            $roles = array_map(static fn (string $role): string => AdminTranslator::trans('fonts.role_' . $role), $usage['site']);
            $parts[] = AdminTranslator::trans('fonts.usage_site', ['roles' => implode(', ', $roles)]);
        }

        foreach ($usage['others'] as $use) {
            $parts[] = $use['label'];
        }

        return AdminTranslator::trans('fonts.in_use_refused', ['users' => implode('; ', $parts)]);
    }

    // ------------------------------------------------------------ writing

    /**
     * Validates a family's own fields: a name (required, unique, at most
     * MAX_NAME_LENGTH), its kind, and an optional http(s) source link.
     *
     * @param array<string, mixed> $input
     * @return array{values: array{name: string, category: string, source_url: string|null}, errors: array<string, string>}
     */
    public static function validateFamily(array $input, ?int $id = null, ?FontLibraryRepository $repository = null): array
    {
        $repository ??= new FontLibraryRepository();
        $errors = [];

        $name = is_scalar($input['name'] ?? null) ? (string) $input['name'] : '';
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '');
        if ($name === '') {
            $errors['name'] = AdminTranslator::trans('fonts.error_name_required');
        } elseif (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $errors['name'] = AdminTranslator::trans('fonts.error_name_length', ['max' => self::MAX_NAME_LENGTH]);
        } elseif ($repository->nameTaken($name, $id)) {
            $errors['name'] = AdminTranslator::trans('fonts.error_name_taken');
        }

        $category = is_scalar($input['category'] ?? null) ? (string) $input['category'] : 'sans';
        if (!array_key_exists($category, self::CATEGORIES)) {
            $errors['category'] = AdminTranslator::trans('fonts.error_category');
            $category = 'sans';
        }

        $source = trim(is_scalar($input['source_url'] ?? null) ? (string) $input['source_url'] : '');
        if ($source !== '' && !self::isSourceUrl($source)) {
            $errors['source_url'] = AdminTranslator::trans('fonts.error_source');
        }

        return [
            'values' => ['name' => $name, 'category' => $category, 'source_url' => $source === '' ? null : $source],
            'errors' => $errors,
        ];
    }

    /**
     * A new family with its first variants, all or nothing: when one file
     * is refused, nothing is stored and every reason comes back.
     *
     * @param array{name: string, category: string, source_url: string|null} $values from validateFamily()
     * @param list<array{file: array<string, mixed>, variant: string}> $uploads
     * @return array{id: int|null, errors: list<string>}
     */
    public static function createFamily(array $values, array $uploads, bool $requireUploadedFile = true): array
    {
        if ($uploads === []) {
            return ['id' => null, 'errors' => [AdminTranslator::trans('fonts.error_no_file')]];
        }

        $checked = self::checkUploads($uploads, [], 0, $requireUploadedFile);
        if ($checked['errors'] !== []) {
            return ['id' => null, 'errors' => $checked['errors']];
        }

        $repository = new FontLibraryRepository();
        $stored = [];

        try {
            $stored = self::storeAll($checked['files'], $requireUploadedFile);
            $repository->beginTransaction();
            $id = $repository->createFamily($values['name'], $values['category'], $values['source_url']);
            foreach ($stored as $file) {
                $repository->addFile($id, $file);
            }
            $repository->commit();
        } catch (\Throwable $e) {
            $repository->rollBack();
            self::discard($stored);
            error_log('[FontLibrary] create refused: ' . $e->getMessage());

            return ['id' => null, 'errors' => [AdminTranslator::trans('fonts.error_store')]];
        }

        self::clearCache();

        return ['id' => $id, 'errors' => []];
    }

    /** @param array{name: string, category: string, source_url: string|null} $values from validateFamily() */
    public static function updateFamily(int $id, array $values): void
    {
        (new FontLibraryRepository())->updateFamily($id, $values['name'], $values['category'], $values['source_url']);
        self::clearCache();
    }

    /**
     * More variants for an existing family, all or nothing. A variant the
     * family already has is refused with the advice to use Vervangen.
     *
     * @param list<array{file: array<string, mixed>, variant: string}> $uploads
     * @return list<string> the reasons it was refused; empty when stored
     */
    public static function addVariants(int $familyId, array $uploads, bool $requireUploadedFile = true): array
    {
        $family = self::family($familyId);
        if ($family === null) {
            return [AdminTranslator::trans('fonts.error_family_gone')];
        }

        if ($uploads === []) {
            return [AdminTranslator::trans('fonts.error_no_file')];
        }

        $existing = [];
        foreach ($family['variants'] as $variant) {
            $existing[] = FontVariant::key((int) $variant['weight'], (string) $variant['style']);
        }

        $checked = self::checkUploads($uploads, $existing, 0, $requireUploadedFile);
        if ($checked['errors'] !== []) {
            return $checked['errors'];
        }

        $repository = new FontLibraryRepository();
        $stored = [];

        try {
            $stored = self::storeAll($checked['files'], $requireUploadedFile);
            $repository->beginTransaction();
            foreach ($stored as $file) {
                $repository->addFile($familyId, $file);
            }
            $repository->commit();
        } catch (\Throwable $e) {
            $repository->rollBack();
            self::discard($stored);
            error_log('[FontLibrary] add refused: ' . $e->getMessage());

            // The unique index: another save added this variant meanwhile.
            return [AdminTranslator::trans('fonts.error_store')];
        }

        self::clearCache();

        return [];
    }

    /**
     * A new file for an existing variant. The variant keeps its weight and
     * style; the file gets a new name, the old file is deleted.
     *
     * @param array<string, mixed> $file one entry of $_FILES
     * @return list<string> the reasons it was refused; empty when replaced
     */
    public static function replaceVariant(int $fileId, array $file, bool $requireUploadedFile = true): array
    {
        $repository = new FontLibraryRepository();
        $current = $repository->file($fileId);
        if ($current === null) {
            return [AdminTranslator::trans('fonts.error_variant_gone')];
        }

        $variant = FontVariant::key((int) $current['weight'], (string) $current['style']);
        $checked = self::checkUploads([['file' => $file, 'variant' => $variant]], [], (int) $current['byte_size'], $requireUploadedFile);
        if ($checked['errors'] !== []) {
            return $checked['errors'];
        }

        $stored = [];
        try {
            $stored = self::storeAll($checked['files'], $requireUploadedFile);
            $repository->replaceFile($fileId, $stored[0]);
        } catch (\Throwable $e) {
            self::discard($stored);
            error_log('[FontLibrary] replace refused: ' . $e->getMessage());

            return [AdminTranslator::trans('fonts.error_store')];
        }

        self::storage()->delete((string) $current['file_name']);
        self::clearCache();

        return [];
    }

    /**
     * Removes one variant and its file. Refused for the LAST variant of a
     * family that is in use: that would leave the website or a page theme
     * pointing at a family without a file.
     *
     * @return string|null the reason it was refused; null when removed
     */
    public static function removeVariant(int $fileId): ?string
    {
        $repository = new FontLibraryRepository();
        $current = $repository->file($fileId);
        if ($current === null) {
            return null;
        }

        $familyId = (int) $current['font_family_id'];
        if (count($repository->files([$familyId])) <= 1) {
            $usage = self::usage($familyId);
            if (self::inUse($usage)) {
                return AdminTranslator::trans('fonts.last_variant_refused') . ' ' . self::usageSentence($usage);
            }
        }

        $repository->deleteFile($fileId);
        self::storage()->delete((string) $current['file_name']);
        self::clearCache();

        return null;
    }

    /**
     * Deletes a family and its files — only when nothing uses it. The
     * foreign keys refuse as well, for a use that appears between the check
     * and the delete.
     *
     * @return array{deleted: bool, reason: string|null}
     */
    public static function deleteFamily(int $familyId): array
    {
        $repository = new FontLibraryRepository();
        if ($repository->family($familyId) === null) {
            return ['deleted' => false, 'reason' => null];
        }

        $usage = self::usage($familyId);
        if (self::inUse($usage)) {
            return ['deleted' => false, 'reason' => self::usageSentence($usage)];
        }

        $files = $repository->files([$familyId]);

        try {
            $repository->deleteFamily($familyId);
        } catch (\PDOException $e) {
            error_log('[FontLibrary] delete refused: ' . $e->getMessage());

            return ['deleted' => false, 'reason' => self::usageSentence(self::usage($familyId))];
        }

        foreach ($files as $file) {
            self::storage()->delete((string) $file['file_name']);
        }

        self::clearCache();

        return ['deleted' => true, 'reason' => null];
    }

    /**
     * Sets (or clears, with null) the website's family for one role. Only
     * App\Service\Theme\ThemeSettings::save() calls this, after its own
     * validation.
     */
    public static function setSiteRole(string $role, ?int $familyId): void
    {
        if ($familyId !== null && !self::isUsable($familyId)) {
            return;
        }

        (new FontLibraryRepository())->setSiteRole($role, $familyId);
        self::clearCache();
    }

    /** Both roles back to the font pairing; "Standaardvormgeving herstellen". */
    public static function clearSiteRoles(): void
    {
        (new FontLibraryRepository())->clearSiteRoles();
        self::clearCache();
    }

    public static function totalBytes(): int
    {
        return (new FontLibraryRepository())->totalBytes();
    }

    /**
     * Normalises $_FILES['font_files'] plus the posted variant keys into one
     * list of uploads, index by index. Files the browser did not send at all
     * (an empty extra input) are skipped; a file without a variant keeps an
     * empty key, which checkUploads() refuses by name.
     *
     * @param mixed $files the raw $_FILES entry
     * @param mixed $variants the raw posted variant list
     * @return list<array{file: array<string, mixed>, variant: string}>
     */
    public static function uploadsFromRequest(mixed $files, mixed $variants): array
    {
        if (!is_array($files) || !is_array($files['name'] ?? null)) {
            return [];
        }

        $variants = is_array($variants) ? array_values($variants) : [];
        $uploads = [];

        foreach (array_values(array_keys($files['name'])) as $position => $index) {
            $file = [
                'name' => $files['name'][$index] ?? '',
                'type' => $files['type'][$index] ?? '',
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index] ?? 0,
            ];

            if ((int) $file['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $variant = $variants[$position] ?? '';
            $uploads[] = ['file' => $file, 'variant' => is_scalar($variant) ? (string) $variant : ''];
        }

        return $uploads;
    }

    /** After a write: this class's per-request state and the theme built on it. */
    public static function clearCache(): void
    {
        ThemeSettings::clearCache();
    }

    /** Only this class's per-request state; ThemeSettings::clearCache() calls it. */
    public static function forget(): void
    {
        if (self::$overridden) {
            return;
        }

        self::$usable = null;
        self::$siteRoles = null;
    }

    /**
     * Test seams, same discipline as ThemeSettings::overrideForTests():
     * pretend these families are the usable ones (id => row with at least
     * `name` and `category`) and these the site's roles, without a
     * database. Null goes back to storage.
     *
     * @param array<int, array<string, mixed>>|null $families
     * @param array<string, int> $siteRoles
     */
    public static function overrideForTests(?array $families, array $siteRoles = []): void
    {
        self::$overridden = $families !== null;
        self::$usable = $families;
        self::$siteRoles = $families === null ? null : $siteRoles;
        ThemeSettings::clearCache();
    }

    /** Test seam: store files somewhere else than the site's library folder. */
    public static function useStorageForTests(?FontStorage $storage): void
    {
        self::$storage = $storage;
    }

    public static function storage(): FontStorage
    {
        return self::$storage ??= new FontStorage();
    }

    // ------------------------------------------------------------ internals

    /**
     * Every upload inspected, its variant checked (a known key, not twice
     * in one save, not one the family already has) and the library's total
     * size respected. Every reason is collected, per file.
     *
     * @param list<array{file: array<string, mixed>, variant: string}> $uploads
     * @param list<string> $existing variant keys the family already has
     * @param int $freed bytes that go away with this save (a replaced file)
     * @return array{files: list<array{path: string, weight: int, style: string, format: string, original_filename: string, byte_size: int}>, errors: list<string>}
     */
    private static function checkUploads(array $uploads, array $existing, int $freed, bool $requireUploadedFile): array
    {
        $inspector = new FontFileInspector();
        $errors = [];
        $files = [];
        $seen = [];
        $bytes = 0;

        foreach ($uploads as $upload) {
            $name = FontFileInspector::displayName((string) ($upload['file']['name'] ?? ''));

            try {
                $format = $inspector->inspectUpload($upload['file'], $requireUploadedFile);
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
                continue;
            }

            $variant = FontVariant::parse($upload['variant']);
            if ($variant === null) {
                $errors[] = AdminTranslator::trans('fonts.error_variant_missing', ['file' => $name]);
                continue;
            }

            $key = FontVariant::key($variant['weight'], $variant['style']);
            $label = FontVariant::label($variant['weight'], $variant['style'], [AdminTranslator::class, 'trans']);
            if (in_array($key, $existing, true)) {
                $errors[] = AdminTranslator::trans('fonts.error_variant_exists', ['file' => $name, 'variant' => $label]);
                continue;
            }

            if (isset($seen[$key])) {
                $errors[] = AdminTranslator::trans('fonts.error_variant_twice', ['file' => $name, 'other' => $seen[$key], 'variant' => $label]);
                continue;
            }

            $seen[$key] = $name;
            $size = (int) filesize((string) $upload['file']['tmp_name']);
            $bytes += $size;
            $files[] = [
                'path' => (string) $upload['file']['tmp_name'],
                'weight' => $variant['weight'],
                'style' => $variant['style'],
                'format' => $format,
                'original_filename' => $name,
                'byte_size' => $size,
            ];
        }

        if ($errors === [] && self::totalBytes() - $freed + $bytes > self::MAX_LIBRARY_BYTES) {
            $errors[] = AdminTranslator::trans('fonts.error_library_full', ['max' => (int) round(self::MAX_LIBRARY_BYTES / (1024 * 1024))]);
        }

        return ['files' => $errors === [] ? $files : [], 'errors' => $errors];
    }

    /**
     * @param list<array{path: string, weight: int, style: string, format: string, original_filename: string, byte_size: int}> $files
     * @return list<array{weight: int, style: string, format: string, file_name: string, original_filename: string, byte_size: int}>
     */
    private static function storeAll(array $files, bool $uploaded): array
    {
        $stored = [];
        try {
            foreach ($files as $file) {
                $name = self::storage()->store($file['path'], $file['format'], $uploaded);
                unset($file['path']);
                $stored[] = $file + ['file_name' => $name];
            }
        } catch (\Throwable $e) {
            self::discard($stored);

            throw $e;
        }

        return $stored;
    }

    /** @param list<array{file_name: string}> $stored */
    private static function discard(array $stored): void
    {
        foreach ($stored as $file) {
            self::storage()->delete($file['file_name']);
        }
    }

    /** @param list<array<string, mixed>> $variants */
    private static function missingCount(array $variants): int
    {
        $missing = 0;
        foreach ($variants as $variant) {
            if (!self::storage()->exists((string) $variant['file_name'])) {
                $missing++;
            }
        }

        return $missing;
    }

    private static function isSourceUrl(string $url): bool
    {
        if (strlen($url) > self::MAX_SOURCE_LENGTH || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && (string) parse_url($url, PHP_URL_HOST) !== '';
    }
}
