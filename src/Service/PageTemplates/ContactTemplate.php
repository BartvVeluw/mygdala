<?php

declare(strict_types=1);

namespace App\Service\PageTemplates;

/**
 * A contact page: heading, a form block, and a card with the direct details.
 *
 * THE FORM BLOCK STARTS EMPTY, ON PURPOSE. Creating a page must never create
 * a form definition as a side effect — a form is a thing with fields,
 * notification addresses and stored submissions, and inventing one behind
 * the editor's back is exactly the kind of invisible write this project
 * avoids. The block is created pointing at no form at all, which is a state
 * the Formulier block already handles everywhere: the page builder asks the
 * editor to pick one, and the public page renders nothing for it until they
 * have (see partials/section-form.php, "a block pointing at nothing renders
 * nothing"). So there is no broken reference and no half-made form — only an
 * empty slot with an obvious next step.
 *
 * The contact card's button falls back to the e-mail address from
 * Site-instellingen when its URL is left empty, so it is useful without
 * being filled in and without this template knowing the address.
 */
final class ContactTemplate extends PageTemplateDefinition
{
    public function key(): string
    {
        return 'contact';
    }

    public function label(): string
    {
        return 'Contact';
    }

    public function description(): string
    {
        return 'Kop, een formulierblok waarin je zelf een bestaand formulier kiest, en een kaart met je directe gegevens.';
    }

    public function blocks(): array
    {
        return ['page_hero', 'form', 'contact_card'];
    }

    public function icon(): ?string
    {
        return '<rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="m3.5 7 8.5 6 8.5-6"></path>';
    }
}
