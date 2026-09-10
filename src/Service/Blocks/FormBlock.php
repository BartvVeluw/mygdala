<?php

namespace App\Service\Blocks;

use App\Repository\FormBlockRepository;
use App\Service\FormBlockContent;
use App\Service\Forms\FormCatalog;

require_once dirname(__DIR__, 3) . '/partials/section-form.php';

/**
 * The reusable "Formulier" block: pick a form, and it appears here.
 *
 * This is the CMS side of Core Forms and the block a new form is placed
 * with. It holds no fields of its own — a form is defined once under Beheer
 * → Formulieren, and the same definition can be placed on the contact page,
 * on a landing page and twice on one page if that is what an editor wants
 * (FORMS.md, "Eén definitie, meerdere plaatsingen").
 *
 * ALLOWED MORE THAN ONCE PER PAGE, unlike the older `contact_form` block it
 * grew out of. That block is capped at one instance because its markup
 * carries fixed DOM ids; every id this one prints is prefixed with a token
 * derived from `(page_slug, section_key)`
 * (App\Service\Forms\FormRenderState), so two instances share nothing.
 */
final class FormBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'form';
    }

    public function meta(): array
    {
        return [
            'label' => 'Formulier',
            'manual_add' => true,
            'allow_multiple' => true,
            'max_instances' => null,
            'allowed_pages' => null,
            'deletable' => true,
            'note' => 'Toont een formulier dat je onder Beheer → Formulieren hebt gemaakt. Hetzelfde formulier kan op meerdere pagina\'s staan; de velden beheer je op één plek.',
        ];
    }

    public function description(): string
    {
        return 'Zet een formulier dat je bij Formulieren hebt gemaakt op deze pagina, met een eigen kop en inleiding erboven.';
    }

    public function category(): string
    {
        return BlockCategories::ACTION;
    }

    public function icon(): string
    {
        return '<rect x="5" y="3" width="14" height="18" rx="1.5"/><path d="M9 8h6"/><path d="M9 12h6"/><path d="M9 16h3"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::HEADING, BlockPreview::FIELDS];
    }

    public function useCases(): array
    {
        return [
            'een aanmelding of inschrijving',
            'een aanvraag of terugbelverzoek',
            'een korte vragenlijst',
        ];
    }

    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new FormBlockRepository();
        // Deliberately empty: a new block asks the editor which form to
        // show rather than guessing at one, and it renders nothing until
        // they have chosen.
        $repository->upsertSection($pageSlug, $key, [
            'form_id' => null,
            'title_nl' => '',
            'title_en' => '',
            'intro_nl' => '',
            'intro_en' => '',
            'is_active' => true,
        ]);

        return [(int) $repository->findBySlugAndKey($pageSlug, $key)['id'], $key];
    }

    public function deleteContent(array $pageSection): void
    {
        (new FormBlockRepository())->deleteSection($this->sectionId($pageSection));
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $pageSlug = $this->pageSlug($pageSection);
        $sectionKey = $this->sectionKey($pageSection);

        $content = FormBlockContent::forSection($pageSlug, $sectionKey);
        if ($content['state'] === FormBlockContent::STATE_HIDDEN) {
            return;
        }

        render_section_form($content, $pageSlug, $sectionKey);
    }

    public function editUrl(array $pageSection): ?string
    {
        return $this->sectionEditUrl('form-block', $pageSection);
    }

    /**
     * What the page builder shows beside "Formulier", so an editor with
     * three of them on one page can tell them apart — and so a block that
     * still needs attention says so instead of looking finished.
     */
    public function instanceTitle(array $pageSection): string
    {
        $content = FormBlockContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));

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
        FormBlockContent::clearCache();
    }

    public function contentTable(): ?string
    {
        return 'form_blocks';
    }
}
