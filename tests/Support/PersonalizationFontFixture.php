<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Repository\PersonalizationFontRepository;
use App\Service\Personalization\PersonalizationFonts;

/**
 * Creates and removes rows in the GLOBAL engraving font library for a test,
 * through the real repository — never by writing SQL by hand.
 *
 * Every fixture font uses an obviously-fake key prefix so it can never
 * collide with the owner's real library, and remove() takes them all away
 * again. The five built-in fonts the Phase 3 migration seeded are never
 * touched: a test that needs "an inactive font" creates its own rather than
 * switching one of the shop's real ones off.
 */
final class PersonalizationFontFixture
{
    public const KEY_PREFIX = 'zz_test_font_';

    /** @var list<int> */
    private array $ids = [];

    /**
     * A font in the library. `source` defaults to 'builtin' because a
     * builtin needs no file on disk — a test about activation, ordering or
     * validation does not care what is behind the key.
     */
    public function create(string $label, bool $isActive = true, array $overrides = []): array
    {
        $repository = new PersonalizationFontRepository();

        $key = $overrides['font_key'] ?? self::KEY_PREFIX . bin2hex(random_bytes(4));

        $id = $repository->create($overrides + [
            'font_key' => $key,
            'label' => $label,
            'source' => 'builtin',
            'css_stack' => "'Fixture', Georgia, serif",
            'file_path' => null,
            'file_format' => null,
            'original_filename' => null,
            'byte_size' => null,
            'is_active' => $isActive,
        ]);

        $this->ids[] = $id;
        PersonalizationFonts::clearCache();

        return ['id' => $id, 'font_key' => $key, 'label' => $label];
    }

    /** An UPLOADED font row, so a test can exercise the @font-face path. */
    public function createUpload(string $label, string $filePath, string $format = 'woff2', bool $isActive = true): array
    {
        return $this->create($label, $isActive, [
            'source' => 'upload',
            'css_stack' => null,
            'file_path' => $filePath,
            'file_format' => $format,
            'original_filename' => 'fixture.' . $format,
            'byte_size' => 1234,
        ]);
    }

    public function setActive(int $id, bool $isActive): void
    {
        (new PersonalizationFontRepository())->setActive($id, $isActive);
        PersonalizationFonts::clearCache();
    }

    /** Removes every font this fixture created. Safe to call twice. */
    public function remove(): void
    {
        $repository = new PersonalizationFontRepository();

        foreach ($this->ids as $id) {
            $repository->delete($id);
        }

        $this->ids = [];
        PersonalizationFonts::clearCache();
    }
}
