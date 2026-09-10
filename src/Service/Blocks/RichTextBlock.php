<?php

namespace App\Service\Blocks;

use App\Repository\RichTextRepository;
use App\Service\RichTextContent;

require_once dirname(__DIR__, 3) . '/partials/section-rich-text.php';

/**
 * A free rich-text block: one purified HTML body, repeatable anywhere. It has
 * no title of its own, so the page builder tells two of them apart by the
 * first words of their text.
 */
final class RichTextBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'rich_text';
    }

    public function meta(): array
    {
        return [
            'label' => 'Tekstblok',
            'manual_add' => true,
            'allow_multiple' => true,
            'max_instances' => null,
            'allowed_pages' => null,
            'deletable' => true,
        ];
    }

    public function description(): string
    {
        return 'Vrije tekst met koppen, opsommingen, vet en links. Het meest gebruikte blok: alles wat gewoon uitgeschreven moet worden.';
    }

    public function category(): string
    {
        return BlockCategories::CONTENT;
    }

    public function icon(): string
    {
        return '<path d="M5 5h14"/><path d="M5 9.5h14"/><path d="M5 14h10"/><path d="M5 18.5h12"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::HEADING, BlockPreview::TEXT];
    }

    public function useCases(): array
    {
        return [
            'uitleg en achtergrondinformatie',
            'voorwaarden en privacyteksten',
            'een nieuws- of blogbericht',
        ];
    }

    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new RichTextRepository();
        $repository->upsertSection($pageSlug, $key, ['content_html' => null, 'is_active' => true]);

        return [(int) $repository->findBySlugAndKey($pageSlug, $key)['id'], $key];
    }

    public function deleteContent(array $pageSection): void
    {
        (new RichTextRepository())->deleteSection($this->sectionId($pageSection));
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $content = RichTextContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));
        if ($content['state'] === RichTextContent::STATE_HIDDEN) {
            return;
        }

        render_section_rich_text($content);
    }

    /**
     * A short, plain-text preview of the first words, so two rich-text blocks
     * on one page stay distinguishable in the page builder's list. HTML is
     * stripped and entities decoded first — this is an admin-facing label,
     * escaped again by the template that prints it.
     */
    public function instanceTitle(array $pageSection): string
    {
        $html = RichTextContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection))['content_html'] ?? '';
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));

        if ($text === '') {
            return '';
        }

        return mb_strlen($text) > 60 ? mb_substr($text, 0, 60) . '…' : $text;
    }

    public function editUrl(array $pageSection): ?string
    {
        return $this->sectionEditUrl('rich-text', $pageSection);
    }

    public function clearCache(): void
    {
        RichTextContent::clearCache();
    }

    public function contentTable(): ?string
    {
        return 'rich_text_sections';
    }
}
