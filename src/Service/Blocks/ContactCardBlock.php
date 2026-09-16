<?php

namespace App\Service\Blocks;

use App\Repository\ContactCardRepository;
use App\Service\ContactCardContent;

require_once dirname(__DIR__, 3) . '/partials/section-contact-card.php';

/**
 * A small "neem contact op" card: title, body and one button. An empty button
 * URL means "mail the address from Site-instellingen", so the card keeps
 * working when that address changes.
 */
final class ContactCardBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'contact_card';
    }

    public function meta(): array
    {
        return [
            'label' => 'Contactkaart',
            'manual_add' => true,
            'allow_multiple' => true,
            'max_instances' => null,
            'allowed_pages' => null,
            'deletable' => true,
        ];
    }

    public function description(): string
    {
        return 'Een klein kaartje met een kop, een zin en een knop, bijvoorbeeld om direct te mailen of te bellen.';
    }

    public function category(): string
    {
        return BlockCategories::ACTION;
    }

    public function icon(): string
    {
        return '<path d="M20.5 16.6V19a1.5 1.5 0 0 1-1.7 1.5A17 17 0 0 1 4.5 5.7 1.5 1.5 0 0 1 6 4h2.4a1.5 1.5 0 0 1 1.5 1.3c.1.9.3 1.7.6 2.5a1.5 1.5 0 0 1-.4 1.6L9 10.5a14 14 0 0 0 4.5 4.5l1.1-1.1a1.5 1.5 0 0 1 1.6-.4c.8.3 1.6.5 2.5.6a1.5 1.5 0 0 1 1.3 1.5z"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::BAND];
    }

    public function useCases(): array
    {
        return [
            'liever direct mailen of bellen',
            'een korte oproep naast een formulier',
        ];
    }

    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new ContactCardRepository();
        $repository->upsertSection($pageSlug, $key, [
            'title_nl' => 'Nieuwe kaart — pas deze titel aan',
            'title_en' => '',
            'body_nl' => '',
            'body_en' => '',
            'button_label_nl' => 'Mail direct',
            'button_label_en' => '',
            // Empty = mailto: the address from Site-instellingen.
            'button_url' => '',
            'is_active' => true,
        ]);

        return [(int) $repository->findBySlugAndKey($pageSlug, $key)['id'], $key];
    }

    public function deleteContent(array $pageSection): void
    {
        (new ContactCardRepository())->deleteSection($this->sectionId($pageSection));
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $content = ContactCardContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));
        if ($content['state'] === ContactCardContent::STATE_HIDDEN) {
            return;
        }

        render_section_contact_card($content);
    }

    public function sampleContent(BlockSamples $samples): ?array
    {
        return [
            ...$samples->fields('title', 'short_title'),
            ...$samples->fields('body', 'body'),
            ...$samples->fields('button_label', 'button_secondary'),
            'button_url' => BlockSamples::LINK,
        ];
    }

    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_contact_card($content);
    }

    public function instanceTitle(array $pageSection): string
    {
        return (string) (ContactCardContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection))['title_nl'] ?? '');
    }

    public function editUrl(array $pageSection): ?string
    {
        return $this->sectionEditUrl('contact-card', $pageSection);
    }

    public function clearCache(): void
    {
        ContactCardContent::clearCache();
    }

    public function contentTable(): ?string
    {
        return 'contact_cards';
    }
}
