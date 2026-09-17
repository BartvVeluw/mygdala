<?php

declare(strict_types=1);

namespace App\Service\Blocks;

use App\Service\Forms\FormDefinition;
use App\Service\Language\LanguageRegistry;
use App\Service\Language\LocalizedValue;
use App\Service\RichTextSanitizer;

/**
 * THE sample content the Contentblokken library previews a block with — every
 * word, the one picture and the one link a preview may show, in one file.
 *
 * WHY SAMPLES AND NOT A SCREENSHOT. A preview has to answer "hoe ziet dit
 * blok eruit op mijn site?", and only the block's own partial, its own CSS
 * and this installation's theme can answer that honestly. So the library
 * renders the real partial (BlockDefinition::renderSample()) with content
 * from here. A change to a partial or a stylesheet shows up in the preview
 * the moment it is made, and there is no picture to go stale.
 *
 * SAMPLE CONTENT IS NOT A FALLBACK. Nothing on the public site ever reads
 * this class: a block without a stored row still renders nothing
 * (CONTENT-BLOCKS.md, "Het inhoudscontract"), and a new block still starts
 * with its own editable starting values. This is demo content for one admin
 * screen, and it must never become the DEFAULTS the Content classes no longer
 * have — Tests\Service\NoFallbackCopyTest keeps those clean, and
 * Tests\Service\BlockSampleContractTest keeps this class out of every render
 * path but the preview's.
 *
 * WHAT THE WORDS MAY SAY. They describe the block they sit in and nothing
 * else: no company, no product, no contact details that reach anyone, no
 * price and no promise. Any installation shows them, whatever it sells. The
 * e-mail address is on example.com, which is reserved for exactly this, and
 * every link points at a fragment of the preview itself.
 *
 * PURE AND IN MEMORY. No database, no settings, no request and no session:
 * the same content on every installation, in both site languages.
 *
 * Who decides the SHAPE of a sample is the block, not this class. A
 * definition asks for words by role ("a title", "the third item") and puts
 * them under the keys its own partial reads, so a block contributed by a
 * module (Portfolio's Projecten, the Shop's collection tiles) brings its own
 * sample with it, and nothing in Core names a module's block.
 */
final class BlockSamples
{
    /** The one picture a preview shows, owned by the block library. */
    public const IMAGE_PATH = '/assets/images/block-preview/sample.svg';

    public const IMAGE_WIDTH = 1600;

    public const IMAGE_HEIGHT = 1000;

    /**
     * Where every sample link points: a fragment of the preview itself. The
     * preview document also stops a click on it (assets/js/block-preview.js),
     * so a link in a preview never leaves the preview.
     */
    public const LINK = '#voorbeeld';

    /** On example.com, which is reserved for documentation (RFC 2606). */
    public const EMAIL = 'naam@example.com';

    /**
     * The sample form's internal key. Underscores are something
     * FormCatalog::internalKeyFor() never produces (a-z, 0-9 and hyphens), so
     * no stored form can carry it, and api/form-submit.php answers a post
     * naming it like any unknown form: refused, nothing stored, nothing sent.
     */
    public const FORM_KEY = '__block_preview__';

    /**
     * One text per role, as [Dutch, English].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const TEXT = [
        'eyebrow' => ['Voorbeeld', 'Example'],
        'title' => ['Zo ziet een titel eruit', 'This is what a title looks like'],
        // A part of 'title', for the homepage hero's highlighted word.
        'title_highlight' => ['titel', 'title'],
        'short_title' => ['Een korte titel', 'A short title'],
        'lead' => [
            'Een korte inleiding in een of twee zinnen. Na het toevoegen vervang je deze voorbeeldtekst door je eigen woorden.',
            'A short introduction in one or two sentences. After adding the block you replace this sample text with your own words.',
        ],
        'body' => [
            'Een korte omschrijving in een of twee regels, zodat je ziet hoeveel ruimte er is.',
            'A short description in one or two lines, so you can see how much room there is.',
        ],
        'note' => [
            'Een korte afsluitende zin onder de sectie.',
            'A short closing sentence under the section.',
        ],
        'button' => ['Meer lezen', 'Read more'],
        'button_secondary' => ['Contact opnemen', 'Get in touch'],
        'image_alt' => ['Voorbeeldafbeelding', 'Sample image'],
        'badge_title' => ['Uitgelicht', 'Highlighted'],
        'badge_text' => ['Een korte toelichting', 'A short note'],
        'form_title' => ['Stuur een bericht', 'Send a message'],
        'form_intro' => [
            'Vul het formulier in. In dit voorbeeld wordt niets verstuurd.',
            'Fill in the form. Nothing is sent from this example.',
        ],
        'field_name' => ['Naam', 'Name'],
        'field_email' => ['E-mailadres', 'Email address'],
        'field_message' => ['Bericht', 'Message'],
        'submit' => ['Versturen', 'Send'],
        'form_success' => ['Bedankt voor je bericht.', 'Thank you for your message.'],
        'city' => ['Voorbeeldstad', 'Sample town'],
    ];

    /**
     * Numbered texts per role, as [Dutch, English]. A definition asks for one
     * by position and wraps around the end, so a block may show more items
     * than a list holds.
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private const LISTS = [
        'item' => [
            ['Eerste onderwerp', 'First topic'],
            ['Tweede onderwerp', 'Second topic'],
            ['Derde onderwerp', 'Third topic'],
            ['Vierde onderwerp', 'Fourth topic'],
            ['Vijfde onderwerp', 'Fifth topic'],
            ['Zesde onderwerp', 'Sixth topic'],
        ],
        'item_body' => [
            ['Een korte omschrijving van dit onderwerp in een of twee regels.', 'A short description of this topic in one or two lines.'],
            ['Nog een omschrijving, iets langer, zodat je ziet hoe kaarten met verschillende teksten naast elkaar staan.', 'Another description, a little longer, so you can see how cards with different texts sit side by side.'],
            ['Een derde omschrijving van gemiddelde lengte.', 'A third description of average length.'],
        ],
        'step' => [
            ['Eerste stap', 'First step'],
            ['Tweede stap', 'Second step'],
            ['Derde stap', 'Third step'],
            ['Vierde stap', 'Fourth step'],
        ],
        'question' => [
            ['Waar gaat deze vraag over?', 'What is this question about?'],
            ['Hoe klap ik een antwoord open?', 'How do I open an answer?'],
            ['Kan ik de volgorde later aanpassen?', 'Can I change the order later?'],
            ['Waar vind ik meer informatie?', 'Where can I find more information?'],
        ],
        'answer' => [
            ['Hier staat het antwoord. Een bezoeker klikt de vraag open om het te lezen.', 'The answer goes here. A visitor opens the question to read it.'],
            ['Door op de vraag te klikken. Nog een keer klikken klapt het antwoord weer dicht.', 'By clicking the question. Clicking again closes the answer.'],
            ['Ja. De vragen staan in de volgorde die je in de editor van het blok kiest.', 'Yes. The questions follow the order you choose in the block\'s editor.'],
            ['In een antwoord kun je verwijzen naar een andere pagina van je site.', 'An answer can point to another page of your site.'],
        ],
        'figure' => [
            ['12', '12'],
            ['3', '3'],
            ['48', '48'],
            ['7', '7'],
        ],
        'figure_caption' => [
            ['Een kengetal', 'A key figure'],
            ['Een tweede kengetal', 'A second figure'],
            ['Een derde kengetal', 'A third figure'],
            ['Een vierde kengetal', 'A fourth figure'],
        ],
        'word' => [
            ['Voorbeeld', 'Example'],
            ['Kernwoord', 'Keyword'],
            ['Thema', 'Theme'],
            ['Onderwerp', 'Topic'],
            ['Trefwoord', 'Tag'],
            ['Begrip', 'Idea'],
        ],
        'tag' => [
            ['Label', 'Label'],
            ['Kenmerk', 'Feature'],
        ],
        'category' => [
            ['Categorie A', 'Category A'],
            ['Categorie B', 'Category B'],
        ],
        'collection' => [
            ['Eerste collectie', 'First collection'],
            ['Tweede collectie', 'Second collection'],
            ['Derde collectie', 'Third collection'],
        ],
    ];

    /**
     * Sample rich text, as [Dutch, English]. Markup and not words, so it is
     * not run through $text; it still goes through RichTextSanitizer, because
     * a partial prints rich text as sanitized markup and a sample may not be
     * the one exception.
     *
     * @var array{0: string, 1: string}
     */
    private const RICH_TEXT = [
        '<h2>Een tussenkop</h2><p>Dit is een alinea voorbeeldtekst met <strong>vetgedrukte</strong> en <em>schuine</em> woorden en <a href="#voorbeeld">een link</a>. Zo zie je hoe lopende tekst in dit blok staat.</p><ul><li>Een eerste punt</li><li>Een tweede punt</li><li>Een derde punt</li></ul><p>Een tweede alinea laat zien hoe het blok met meer tekst omgaat.</p>',
        '<h2>A subheading</h2><p>This is a paragraph of sample text with <strong>bold</strong> and <em>italic</em> words and <a href="#voorbeeld">a link</a>. It shows how running text sits in this block.</p><ul><li>A first point</li><li>A second point</li><li>A third point</li></ul><p>A second paragraph shows how the block handles more text.</p>',
    ];

    /** @var \Closure(string): string */
    private \Closure $text;

    /**
     * @param (\Closure(string): string)|null $text applied to every sample
     *        word before a block sees it. Only a test passes one — hostile
     *        words that prove every block escapes its sample like its stored
     *        content (Tests\Service\BlockSampleContractTest).
     */
    public function __construct(?\Closure $text = null)
    {
        $this->text = $text ?? static fn (string $value): string => $value;
    }

    /**
     * One role's text as a block's own field pair: fields('title', 'title')
     * is ['title_nl' => …, 'title_en' => …].
     *
     * @return array<string, string>
     */
    public function fields(string $field, string $role): array
    {
        return $this->pair($field, self::TEXT[$role] ?? throw new \InvalidArgumentException('No sample text for ' . $role));
    }

    /**
     * One role's text as ONE value in every language: the shape a block whose
     * words are stored per website language (BlockLocalization) hands its
     * partial, where fields() is the shape of a block still on `_nl`/`_en`
     * columns.
     */
    public function localized(string $role): LocalizedValue
    {
        $pair = self::TEXT[$role] ?? throw new \InvalidArgumentException('No sample text for ' . $role);

        return LocalizedValue::of([
            LanguageRegistry::DUTCH => ($this->text)($pair[0]),
            LanguageRegistry::ENGLISH => ($this->text)($pair[1]),
        ]);
    }

    /** The $index-th text of a list role, as ONE value in every language (localized()). */
    public function localizedItem(string $role, int $index): LocalizedValue
    {
        $list = self::LISTS[$role] ?? throw new \InvalidArgumentException('No sample list for ' . $role);
        $pair = $list[$index % count($list)];

        return LocalizedValue::of([
            LanguageRegistry::DUTCH => ($this->text)($pair[0]),
            LanguageRegistry::ENGLISH => ($this->text)($pair[1]),
        ]);
    }

    /** A field this sample leaves empty, as one value in every language. */
    public function none(): LocalizedValue
    {
        return LocalizedValue::of([]);
    }

    /**
     * The $index-th text of a list role, as a field pair.
     *
     * @return array<string, string>
     */
    public function itemFields(string $field, string $role, int $index): array
    {
        $list = self::LISTS[$role] ?? throw new \InvalidArgumentException('No sample list for ' . $role);

        return $this->pair($field, $list[$index % count($list)]);
    }

    /** The Dutch half of one role, for a block that stores a single value. */
    public function dutch(string $role): string
    {
        return $this->fields('value', $role)['value_nl'];
    }

    /**
     * The sample picture, in the shapes App\Service\Media\BlockImage resolves
     * a stored one to: `alt` as one value in every language (fromOwner()),
     * and the alt_nl/alt_en pair of fromRow().
     *
     * @return array{image_path: string, alt: LocalizedValue, alt_nl: string, alt_en: string, width: int, height: int}
     */
    public function image(): array
    {
        $alt = $this->fields('alt', 'image_alt');

        return [
            'image_path' => self::IMAGE_PATH,
            'alt' => $this->localized('image_alt'),
            'alt_nl' => $alt['alt_nl'],
            'alt_en' => $alt['alt_en'],
            'width' => self::IMAGE_WIDTH,
            'height' => self::IMAGE_HEIGHT,
        ];
    }

    /** @return array{nl: string, en: string} sanitized markup */
    public function richText(): array
    {
        return [
            'nl' => (string) RichTextSanitizer::sanitize(self::RICH_TEXT[0]),
            'en' => (string) RichTextSanitizer::sanitize(self::RICH_TEXT[1]),
        ];
    }

    /**
     * A form built in memory, the way App\Service\Forms\FormCatalog builds
     * a stored one: through FormDefinition::fromRows(), so it renders with
     * the real field types and nothing about a form is written twice. It has
     * no id and no notification address, and there is nothing it could be
     * sent to — see admin/block-preview.php for why it cannot be sent at all.
     */
    public function form(): FormDefinition
    {
        $field = fn (int $order, string $key, string $type, string $role, bool $required): array => [
            'id' => 0,
            'field_key' => $key,
            'field_type' => $type,
            'is_required' => $required,
            'sort_order' => $order,
        ] + $this->fields('label', $role);

        return FormDefinition::fromRows(
            [
                'id' => 0,
                'name' => $this->dutch('form_title'),
                'internal_key' => self::FORM_KEY,
                'is_active' => true,
                'store_submissions' => false,
            ] + $this->fields('submit_label', 'submit') + $this->fields('success_message', 'form_success'),
            [
                $field(1, 'naam', 'text', 'field_name', true),
                $field(2, 'email', 'email', 'field_email', true),
                $field(3, 'bericht', 'textarea', 'field_message', false),
            ]
        );
    }

    /**
     * @param array{0: string, 1: string} $pair
     *
     * @return array<string, string>
     */
    private function pair(string $field, array $pair): array
    {
        return [
            $field . '_nl' => ($this->text)($pair[0]),
            $field . '_en' => ($this->text)($pair[1]),
        ];
    }
}
