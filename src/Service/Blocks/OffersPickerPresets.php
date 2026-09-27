<?php

declare(strict_types=1);

namespace App\Service\Blocks;

/**
 * A block the block picker shows as more than one card: each card a PRESET,
 * the same block type started with one setting already chosen.
 *
 * Presentation only. There is still one type, one table, one editor and one
 * render path; a preset decides nothing but the card an editor clicks (its
 * name, description, examples and category) and the one starting setting the
 * new instance is created with. Once it exists, the block behaves exactly as
 * one added without a preset, and the setting can be changed in its editor.
 *
 * The presets are a CLOSED list the block builds itself, per request, from
 * code: api/admin/add-page-section.php accepts a preset key only when it is
 * one of these (App\Service\SectionRegistry::offersPreset()), so a key from a
 * request can only hit or miss one of them.
 *
 * First and only user: the gallery (ItemGalleryBlock), one card per content
 * source an enabled module offers — Collectiegalerij under Shop,
 * Portfoliogalerij under Portfolio (PAGE-EDITOR.md). The Contentblokken
 * catalogue describes block TYPES and keeps showing it once.
 */
interface OffersPickerPresets
{
    /**
     * The cards this block shows in the picker, by preset key, in picker
     * order. Empty means "no presets": the block gets its ordinary card.
     *
     * @return array<string, array{label: string, description: string, category: string, use_cases: list<string>}>
     */
    public function pickerPresets(): array;

    /**
     * create(), with the preset's setting chosen. Only ever called with a
     * key pickerPresets() returned; anything else is a programming error.
     *
     * @return array{0: int, 1: string} [content row id, section key], as create()
     */
    public function createFromPreset(string $pageSlug, string $preset): array;
}
