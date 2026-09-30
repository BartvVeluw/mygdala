<?php

namespace App\Service\Blocks;

use App\Repository\DetailSectionRepository;
use App\Service\DetailSectionContent;

require_once dirname(__DIR__, 3) . '/partials/section-detail-section.php';

/**
 * The long-form detail section the Diensten page is built out of (phase 3):
 * title, lead, rich text, an optional main image left or right, features, a
 * gallery and a closing note. It knows nothing about "diensten", so it is
 * allowed everywhere and repeatable.
 *
 * A section with an anchor shows up automatically in the QuicknavBlock of the
 * same page, and its alternating background/position markers are derived from
 * the active detail sections on that page rather than a fixed list.
 *
 * It owns uploaded files (a main image plus a gallery), so it cleans those up
 * before its rows disappear.
 *
 * The words of the section, of every feature and of every gallery image's
 * alt text are stored per website language in block_translations
 * (BlockLocalization), each child row's on its own row.
 */
final class DetailSectionBlock extends BlockDefinition implements ContributesAnchor, InspectsContent
{
    public function type(): string
    {
        return 'detail_section';
    }

    public function meta(): array
    {
        return [
            'label' => 'Detailsectie',
            'manual_add' => true,
            'allow_multiple' => true,
            'max_instances' => null,
            'allowed_pages' => null,
            'deletable' => true,
            'note' => 'Titel, lead, rich tekst, optioneel een hoofdafbeelding links of rechts, kenmerken, een galerij en een slotnotitie. Een sectie met een anker verschijnt automatisch in de Snelnavigatie van dezelfde pagina.',
        ];
    }

    public function description(): string
    {
        return 'Een brede sectie over een onderwerp: kop, uitgebreide tekst, kenmerken op een rij en optioneel beeld ernaast. Bedoeld om er meerdere onder elkaar te zetten.';
    }

    public function category(): string
    {
        return BlockCategories::MEDIA;
    }

    public function icon(): string
    {
        return '<rect x="3" y="4" width="8" height="7" rx="1.5"/><path d="M13 6h8"/><path d="M13 9.5h6"/><path d="M3 15h18"/><path d="M3 19h12"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::IMAGE_LEFT, BlockPreview::COLUMNS];
    }

    public function useCases(): array
    {
        return [
            'een dienst of materiaal uitgebreid beschrijven',
            'een lange pagina in hoofdstukken verdelen',
        ];
    }

    /**
     * The words of the section, of each feature and of each gallery image,
     * per website language; the anchor, the image position, the CTA URL, the
     * media, the order and visibility are the same in every language and stay
     * in their tables. The lengths are the ones the editor always allowed,
     * and the rich body the one the Tekstblok allows; what is required is
     * what the editor always required: the section's title, and both texts of
     * a feature. The main image's alt text is edited on the image form.
     */
    public function translatableFields(): array
    {
        return [
            'detail_sections' => [
                TranslatableField::plain('nav_label', 100),
                // The own words of the label mode "Eigen tekst" (LabelMode).
                TranslatableField::plain('label', 40),
                TranslatableField::plain('title', 255)->required(),
                TranslatableField::plain('lead', 500),
                TranslatableField::rich('body', 50000),
                TranslatableField::plain('main_image_alt', 255),
                TranslatableField::plain('closing_note', 1000),
                TranslatableField::plain('cta_label', 150),
            ],
            'detail_section_points' => [
                TranslatableField::plain('title', 255)->required(),
                TranslatableField::plain('body', 500)->required(),
            ],
            'detail_section_images' => [
                TranslatableField::plain('alt', 255),
            ],
        ];
    }

    public function childTables(): array
    {
        return [
            'detail_section_points' => ['parent' => 'detail_sections', 'column' => 'section_id'],
            'detail_section_images' => ['parent' => 'detail_sections', 'column' => 'section_id'],
        ];
    }

    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new DetailSectionRepository();
        $repository->upsertSection($pageSlug, $key, DetailSectionContent::startingValues() + ['is_active' => true]);
        $sectionId = (int) $repository->findBySlugAndKey($pageSlug, $key)['id'];

        // The starting title in the website's default language, the language
        // every other language falls back to until it is written.
        BlockLocalization::save('detail_sections', $sectionId, BlockLocalization::defaultLanguage(), DetailSectionContent::startingWords());

        return [$sectionId, $key];
    }

    /**
     * Deliberately nothing.
     *
     * This block's images are Media Library items now, and a media item is
     * SHARED: the same photo may be on three other pages and in the site's
     * branding. Removing an instance of this block removes its references
     * (deleteContent() below), never the files behind them. Deleting a file
     * is the Media Library's own decision, and it refuses while anything
     * still uses it — see App\Service\Media\MediaService::delete().
     *
     * Left as an explicit override rather than falling through to the base
     * class, so that "why does this block not clean up its images?" has an
     * answer in the file somebody would look in.
     */
    public function deleteFiles(array $pageSection): void
    {
    }

    public function deleteContent(array $pageSection): void
    {
        (new DetailSectionRepository())->deleteSection($this->sectionId($pageSection));
    }

    /**
     * This section's link in its page's anchor navigation
     * (App\Service\Blocks\AnchorNavigation): the same rule the Snelnavigatie
     * always used (DetailSectionContent::anchorItem()).
     */
    public function anchorFor(array $pageSection): ?array
    {
        return DetailSectionContent::anchorItem($this->pageSlug($pageSection), $this->sectionKey($pageSection));
    }

    /** The gallery's linked items and its one-at-a-time strip on a phone (Detailsectie 2.0). */
    public function styles(): array
    {
        // The gallery's pictures print through partials/responsive-image.php
        // (their focus points), whose stylesheet comes first (MEDIA.md).
        return ['assets/css/responsive-media.css', 'assets/css/blocks/detail-section.css'];
    }

    /** The phone strip's arrows (assets/js/blocks/detail-section.js); the grid needs nothing. */
    public function scripts(): array
    {
        return ['assets/js/blocks/detail-section.js'];
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $pageSlug = $this->pageSlug($pageSection);

        $content = DetailSectionContent::forSection($pageSlug, $this->sectionKey($pageSection));
        if ($content['state'] === DetailSectionContent::STATE_HIDDEN) {
            return;
        }

        render_section_detail_section(
            $content,
            DetailSectionContent::positionMarkers($pageSlug, (int) $content['id'], $content['label_mode'], $content['label']),
            $revealGroup
        );
    }

    public function sampleContent(BlockSamples $samples): ?array
    {
        $image = $samples->image();

        $points = [];
        foreach (range(0, 2) as $index) {
            $points[] = [
                'title' => $samples->localizedItem('item', $index),
                'body' => $samples->localizedItem('item_body', $index),
            ];
        }

        return [
            'id' => 0,
            'anchor' => '',
            'label_mode' => LabelMode::PADDED,
            'label' => '',
            'nav_label' => $samples->localized('short_title'),
            'title' => $samples->localized('title'),
            'lead' => $samples->localized('lead'),
            'body' => $samples->localizedRichText(),
            'main_image_path' => $image['image_path'],
            'main_image_alt' => $image['alt'],
            'main_image_width' => $image['width'],
            'main_image_height' => $image['height'],
            'image_position' => 'image_right',
            'closing_note' => $samples->localized('note'),
            'cta_label' => $samples->localized('button'),
            'cta_url' => BlockSamples::LINK,
            'points' => $points,
            'images' => [],
        ];
    }

    /**
     * The markers are what render() counts from the page (the section's
     * number, and whether it takes the soft background); a preview is the
     * first detail section of its page.
     */
    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_detail_section($content, ['index_label' => LabelMode::text($content['label_mode'] ?? LabelMode::PADDED, 1, (string) ($content['label'] ?? '')), 'bg_soft' => false], $revealGroup);
    }

    public function instanceTitle(array $pageSection): string
    {
        return BlockLocalization::name('detail_sections', $this->sectionId($pageSection), 'title');
    }

    public function editUrl(array $pageSection): ?string
    {
        return $this->sectionEditUrl('detail-section', $pageSection);
    }

    public function clearCache(): void
    {
        DetailSectionContent::clearCache();
    }

    public function contentTable(): ?string
    {
        return 'detail_sections';
    }

    /**
     * Content: any of its words, its picture, a point or a gallery image (InspectsContent, SectionRegistry::isEmpty()).
     */
    public function hasContent(array $pageSection): bool
    {
        $content = DetailSectionContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));

        if ($content['state'] !== DetailSectionContent::STATE_ACTIVE) {
            return true;
        }

        return (string) $content['title'] !== '' || (string) $content['lead'] !== '' || trim((string) $content['body']) !== ''
            || (string) $content['cta_label'] !== '' || (string) $content['main_image_path'] !== ''
            || $content['points'] !== [] || $content['images'] !== [];
    }
}
