<?php

namespace App\Service\Blocks;

use App\Repository\ContactFormRepository;
use App\Service\ContactFormContent;
use App\Service\Forms\FormCatalog;

require_once dirname(__DIR__, 3) . '/partials/section-contact-form.php';

/**
 * The contact block: a form and the "Direct contact" details card, side by
 * side in one two-column grid.
 *
 * SINCE CORE FORMS IT IS A WRAPPER around an ordinary Form definition, not a
 * form of its own (FORMS.md, "Het contactformulier"). It keeps its type key
 * and every `page_sections` row it has — retiring a block type is a data
 * migration and there is no reason to run one here — and what it adds to the
 * generic engine is exactly two things a generic form block should not have:
 * the contact details card beside it, and the optional file attachment this
 * site's quote form has accepted since long before Forms existed.
 *
 * For a plain form on any page, the block to add is "Formulier"
 * (App\Service\Blocks\FormBlock). This one is offered as well, because the
 * pairing with the contact details is a layout an editor may genuinely want
 * on a landing page too.
 *
 * STILL CAPPED AT ONE PER PAGE. Not because of DOM ids any more — those are
 * scoped per instance now (App\Service\Forms\FormRenderState) — but because
 * two "Direct contact" cards repeating the same address on one page is not a
 * layout anybody wants.
 */
final class ContactFormBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'contact_form';
    }

    public function meta(): array
    {
        return [
            'label' => 'Offerte-/contactformulier',
            'manual_add' => true,
            'allow_multiple' => false,
            'max_instances' => 1,
            'allowed_pages' => null,
            'deletable' => true,
            'note' => 'Een formulier uit Beheer → Formulieren, met daarnaast de kaart "Direct contact" (e-mailadres en werkplaats uit Site-instellingen). Wil je alleen een formulier zonder die kaart, gebruik dan het blok "Formulier".',
        ];
    }

    public function description(): string
    {
        return 'Het contactformulier met daarnaast een kaartje met je directe gegevens, zoals e-mailadres en plaats.';
    }

    public function category(): string
    {
        return BlockCategories::ACTION;
    }

    public function icon(): string
    {
        return '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 7l8.5 6 8.5-6"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::TWO_CARDS];
    }

    public function useCases(): array
    {
        return [
            'de contactpagina',
            'een offerteaanvraag met je gegevens ernaast',
        ];
    }

    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new ContactFormRepository();
        $repository->upsertSection($pageSlug, $key, [
            'title_nl' => 'Neem contact op',
            'title_en' => 'Get in touch',
            // No form guessed at: the editor picks one, and the block shows
            // its heading and the contact card until they have.
            'form_id' => null,
            'allow_attachment' => false,
            'is_active' => true,
        ]);

        return [(int) $repository->findBySlugAndKey($pageSlug, $key)['id'], $key];
    }

    public function deleteContent(array $pageSection): void
    {
        (new ContactFormRepository())->deleteSection($this->sectionId($pageSection));
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $pageSlug = $this->pageSlug($pageSection);
        $sectionKey = $this->sectionKey($pageSection);

        $content = ContactFormContent::forSection($pageSlug, $sectionKey);
        if ($content['state'] === ContactFormContent::STATE_HIDDEN) {
            return;
        }

        render_section_contact_form($content, $pageSlug, $sectionKey);
    }

    public function editUrl(array $pageSection): ?string
    {
        return $this->sectionEditUrl('contact-form', $pageSection);
    }

    /**
     * Which form this instance shows, so the page builder can say that a
     * block still needs one instead of looking finished.
     */
    public function instanceTitle(array $pageSection): string
    {
        $content = ContactFormContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));

        $form = FormCatalog::find($content['form_id'] ?? 0);

        if ($form === null) {
            return $content['form_id'] === null ? 'nog geen formulier gekozen' : 'formulier bestaat niet meer';
        }

        if (!$form->isActive) {
            return $form->name . ' (staat uit)';
        }

        if (!$form->hasFields()) {
            return $form->name . ' (nog geen velden)';
        }

        return $form->name;
    }

    /**
     * The same two files the generic form block asks for: this block renders
     * the very same form markup, so it needs the very same behaviour and the
     * very same rules. App\Service\PageAssets de-duplicates them when both
     * blocks happen to be on one page.
     */
    public function styles(): array
    {
        return ['assets/css/blocks/form.css'];
    }

    public function scripts(): array
    {
        return ['assets/js/blocks/form.js'];
    }

    public function clearCache(): void
    {
        ContactFormContent::clearCache();
    }

    public function contentTable(): ?string
    {
        return 'contact_form_sections';
    }
}
