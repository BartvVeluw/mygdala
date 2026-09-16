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
 */
final class DetailSectionBlock extends BlockDefinition
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

    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new DetailSectionRepository();
        $repository->upsertSection($pageSlug, $key, DetailSectionContent::defaultsForSection() + ['is_active' => true]);

        return [(int) $repository->findBySlugAndKey($pageSlug, $key)['id'], $key];
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

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $pageSlug = $this->pageSlug($pageSection);

        $content = DetailSectionContent::forSection($pageSlug, $this->sectionKey($pageSection));
        if ($content['state'] === DetailSectionContent::STATE_HIDDEN) {
            return;
        }

        render_section_detail_section(
            $content,
            DetailSectionContent::positionMarkers($pageSlug, (int) $content['id']),
            $revealGroup
        );
    }

    public function sampleContent(BlockSamples $samples): ?array
    {
        $body = $samples->richText();
        $image = $samples->image();

        $points = [];
        foreach (range(0, 2) as $index) {
            $points[] = [
                ...$samples->itemFields('title', 'item', $index),
                ...$samples->itemFields('body', 'item_body', $index),
            ];
        }

        return [
            'id' => 0,
            'anchor' => '',
            ...$samples->fields('nav_label', 'short_title'),
            ...$samples->fields('title', 'title'),
            ...$samples->fields('lead', 'lead'),
            'content_html' => $body['nl'],
            'content_html_en' => $body['en'],
            'main_image_path' => $image['image_path'],
            'main_image_alt_nl' => $image['alt_nl'],
            'main_image_alt_en' => $image['alt_en'],
            'main_image_width' => $image['width'],
            'main_image_height' => $image['height'],
            'image_position' => 'image_right',
            ...$samples->fields('closing_note', 'note'),
            ...$samples->fields('cta_label', 'button'),
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
        render_section_detail_section($content, ['index_label' => '01', 'bg_soft' => false], $revealGroup);
    }

    public function instanceTitle(array $pageSection): string
    {
        return (string) (DetailSectionContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection))['title_nl'] ?? '');
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
}
