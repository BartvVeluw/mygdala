<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Service\ContactFormContent;
use App\Service\Forms\FormFieldTypes;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * What db/migrations/20260909310000_migrate_the_contact_form_into_a_form.php
 * promises every installation that still has the old quote-form block: each
 * `contact_form` block ends up rendering one Form definition, built from the
 * fields the block's old markup had, and nothing the block itself carried is
 * lost on the way.
 *
 * Proven on a throwaway database that stands where such an installation
 * stood — migrated up to the last migration before Core Forms — holding
 * contact blocks this test places itself, on pages with made-up slugs. The
 * migration selects on the data, never on a page name, so a fixture on a
 * page called `contact` would prove nothing about the block an editor put on
 * a page of their own. The rest of the migrations then run exactly as
 * `phinx migrate` runs them on a real upgrade (Tests\Support\ScratchInstall).
 *
 * The other half of the contract — no contact block, no form — is what a
 * fresh install gets, and Tests\Install\FreshInstallTest owns it. What one
 * particular site's contact page looked like afterwards is that site's
 * history, not this CMS's contract (TESTING.md, the `migration-backfill`
 * group).
 */
#[Group('migration-backfill')]
final class ContactFormMigrationTest extends TestCase
{
    private const DATABASE = 'mygdala_scratch_contact_form';

    /** The last migration before Core Forms: where an older installation stood. */
    private const BEFORE_FORMS = '20260909270000';

    private const FORM_MIGRATION = '20260909310000';

    /**
     * The last migration before the block's heading moved into
     * block_translations (Multilingual 2.0 phase 3B, 20260917180000). This
     * test is about the forms migration, so its installation stops there and
     * keeps storing a block the way the forms migration found one.
     */
    private const BEFORE_BLOCK_WORDS = '20260917170000';

    /**
     * The five fields, in the order partials/section-contact-form.php used
     * to render them, with the keys the old markup posted under. These come
     * from this CMS's own former markup, not from any site's content.
     */
    private const MIGRATED_FIELDS = [
        ['naam', 'text', 'Naam', 'Name', true],
        ['email', 'email', 'E-mail', 'Email', true],
        ['telefoon', 'tel', 'Telefoonnummer', 'Phone number', false],
        ['voor-wie', 'radio', 'Voor wie is de aanvraag?', 'Who is this request for?', true],
        ['omschrijving', 'textarea', 'Omschrijving van je idee', 'Describe your idea', true],
    ];

    private static ?ScratchInstall $install = null;

    /** @var array<string, string> fixture name => the page_slug its block sits on */
    private static array $slugs = [];

    /** @var array{forms: list<array<string, mixed>>, fields: list<array<string, mixed>>} */
    private static array $afterFirstRun = ['forms' => [], 'fields' => []];

    private static int $editorFormId = 0;

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$install = ScratchInstall::upTo(self::DATABASE, self::BEFORE_FORMS);

        foreach (['quote', 'hidden', 'later', 'editor'] as $name) {
            self::$slugs[$name] = 'zz-contact-' . bin2hex(random_bytes(4));
        }

        // An installation from before Core Forms: two quote blocks on pages
        // of their own, one of them switched off.
        self::placeBlock(self::$slugs['quote'], 'main', 'Stel je vraag', 'Ask your question', true);
        self::placeBlock(self::$slugs['hidden'], 'custom-1a2b3c4d', 'Tweede formulier', null, false);

        self::$install->catchUp(self::BEFORE_BLOCK_WORDS);
        self::$afterFirstRun = self::migratedFormSnapshot();

        // The site lives on: a block that is still unlinked, and one an
        // editor already pointed at a form of their own. Then the migration
        // runs a second time.
        self::placeBlock(self::$slugs['later'], 'main', 'Later geplaatst', null, true);
        self::$editorFormId = self::createEditorForm();
        self::placeBlock(self::$slugs['editor'], 'main', 'Eigen keuze', null, true, self::$editorFormId);

        self::$install->replay(self::FORM_MIGRATION, self::BEFORE_BLOCK_WORDS);
    }

    public static function tearDownAfterClass(): void
    {
        self::$install?->drop();
        self::$install = null;
    }

    public function testTheOldBlocksBecameOneFormDefinitionExactlyOnce(): void
    {
        $this->assertCount(
            1,
            $this->install()->rows('SELECT id FROM forms WHERE internal_key = ?', [ContactFormContent::MIGRATED_FORM_KEY]),
            'the migrated form must exist, and exactly once — also after the migration ran a second time'
        );
    }

    public function testTheFormKeptTheSettingsTheOldEndpointBehavedWith(): void
    {
        $form = $this->migratedForm();

        $this->assertSame(1, (int) $form['is_active']);
        $this->assertSame('Verstuur aanvraag', (string) $form['submit_label_nl'], 'the button the block has always shown');
        $this->assertSame('Send request', (string) $form['submit_label_en']);
        $this->assertStringContainsString('Bedankt', (string) $form['success_message_nl']);
        $this->assertSame(1, (int) $form['store_submissions'], 'the old endpoint stored every request, so this one does too');
        $this->assertSame('email', (string) $form['reply_to_field_key'], 'the visitor address was always the Reply-To');
    }

    public function testTheFiveFieldsExistOnceEachWithTheirOldLabelsAndRequirednessAndOrder(): void
    {
        $fields = $this->migratedFields();

        $this->assertSame(
            array_column(self::MIGRATED_FIELDS, 0),
            array_map(static fn (array $row): string => (string) $row['field_key'], $fields),
            'the five fields, once each, in the order the old markup rendered them'
        );

        foreach (self::MIGRATED_FIELDS as $position => [$key, $type, $labelNl, $labelEn, $required]) {
            $field = $fields[$position];

            $this->assertSame($type, (string) $field['field_type'], $key);
            $this->assertSame($labelNl, (string) $field['label_nl'], $key);
            $this->assertSame($labelEn, (string) $field['label_en'], $key);
            $this->assertSame($required, (int) $field['is_required'] === 1, $key);
        }
    }

    public function testTheAudienceChoiceKeptBothOfItsOptionsInBothLanguages(): void
    {
        $options = $this->optionLines('voor-wie');

        $this->assertSame(['Particulier', 'Zakelijk'], array_column($options, 0));
        $this->assertSame(['Personal', 'Business'], array_column($options, 1));
    }

    /**
     * The hardcoded markup had `checked` on "Particulier", and
     * db/migrations/20260909320000_add_a_default_choice_to_form_fields.php
     * puts that back as a property of the field.
     */
    public function testTheAudienceChoiceStartsOnParticulier(): void
    {
        $field = $this->field('voor-wie');

        $this->assertSame('Particulier', (string) $field['default_value']);
        $this->assertContains(
            (string) $field['default_value'],
            array_column($this->optionLines('voor-wie'), 0),
            'a default must always be one of the options actually offered'
        );
    }

    /** Only that one field: the migrations gave nothing else a default. */
    public function testNoOtherFieldWasGivenADefault(): void
    {
        foreach ($this->migratedFields() as $field) {
            if ((string) $field['field_key'] === 'voor-wie') {
                continue;
            }

            $this->assertNull($field['default_value'], 'field "' . $field['field_key'] . '" was given a default it never had');
        }
    }

    public function testTheHintUnderTheDescriptionSurvivedInBothLanguages(): void
    {
        $field = $this->field('omschrijving');

        $this->assertStringContainsString('formaat', (string) $field['help_text_nl']);
        $this->assertStringContainsString('size', (string) $field['help_text_en']);
    }

    /**
     * Every migrated field names a type the registry knows — otherwise the
     * form would render short a field without anything saying so.
     */
    public function testEveryMigratedFieldNamesATypeThisCmsStillSupports(): void
    {
        foreach ($this->migratedFields() as $field) {
            $this->assertTrue(
                FormFieldTypes::has((string) $field['field_type']),
                'field "' . $field['field_key'] . '" names an unsupported type'
            );
        }
    }

    /**
     * Selected on the DATA: a block on a page nobody knew the name of is
     * linked, a switched-off one too, and so is a block that was still
     * unlinked when the migration ran again.
     */
    public function testEveryUnlinkedContactBlockPointsAtTheMigratedForm(): void
    {
        $formId = (int) $this->migratedForm()['id'];

        foreach (['quote', 'hidden', 'later'] as $name) {
            $this->assertSame(
                $formId,
                (int) $this->block($name)['form_id'],
                "the \"{$name}\" block must render the migrated form"
            );
        }
    }

    public function testABlockThatAlreadyHadAFormIsLeftAlone(): void
    {
        $this->assertSame(
            self::$editorFormId,
            (int) $this->block('editor')['form_id'],
            'a block an editor already pointed somewhere is not the migration\'s to repoint'
        );
    }

    /**
     * The block keeps what it was: its heading in both languages, whether it
     * is shown, and — because every existing instance rendered it — the
     * optional attachment field.
     */
    public function testEachMigratedBlockKeptItsHeadingItsVisibilityAndItsAttachmentField(): void
    {
        $quote = $this->block('quote');
        $this->assertSame('Stel je vraag', (string) $quote['title_nl']);
        $this->assertSame('Ask your question', (string) $quote['title_en']);
        $this->assertSame(1, (int) $quote['is_active']);
        $this->assertSame(1, (int) $quote['allow_attachment'], 'the optional file upload must not have been taken away');

        $hidden = $this->block('hidden');
        $this->assertSame('Tweede formulier', (string) $hidden['title_nl']);
        $this->assertNull($hidden['title_en']);
        $this->assertSame(0, (int) $hidden['is_active'], 'a switched-off block must stay switched off');
        $this->assertSame(1, (int) $hidden['allow_attachment']);
    }

    public function testRunningTheMigrationAgainChangesNothingItAlreadyDid(): void
    {
        $this->install();

        $this->assertNotSame([], self::$afterFirstRun['forms']);
        $this->assertSame(
            self::$afterFirstRun,
            self::migratedFormSnapshot(),
            'a second run must not recreate, rename or re-seed the form or its fields'
        );
    }

    // --------------------------------------------------------------- helpers

    private function install(): ScratchInstall
    {
        if (self::$install === null) {
            $this->markTestSkipped(
                'Replaying an upgrade needs the MySQL root account (DB_ROOT_PASSWORD in .env).'
            );
        }

        return self::$install;
    }

    /** @return array<string, mixed> */
    private function migratedForm(): array
    {
        $rows = $this->install()->rows('SELECT * FROM forms WHERE internal_key = ?', [ContactFormContent::MIGRATED_FORM_KEY]);
        $this->assertCount(1, $rows, 'the migrated contact form is missing');

        return $rows[0];
    }

    /** @return list<array<string, mixed>> */
    private function migratedFields(): array
    {
        return $this->install()->rows(
            'SELECT * FROM form_fields WHERE form_id = ? ORDER BY sort_order, id',
            [(int) $this->migratedForm()['id']]
        );
    }

    /**
     * The option lines of a field as the column held them back then: one per
     * line, the Dutch half before the pipe and the English one after it.
     * Multilingual 2.0 phase 4 gave every option a row of its own, so that
     * reading is no longer a class of the application (App\Service\Forms\
     * FormFieldOptions) but part of the history this test stands in.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function optionLines(string $key): array
    {
        $lines = [];
        foreach (explode("\n", (string) $this->field($key)['options']) as $line) {
            [$nl, $en] = array_pad(explode('|', $line, 2), 2, '');
            $lines[] = [trim($nl), trim($en)];
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    private function field(string $key): array
    {
        foreach ($this->migratedFields() as $field) {
            if ((string) $field['field_key'] === $key) {
                return $field;
            }
        }

        $this->fail('the migrated field "' . $key . '" is gone');
    }

    /** @return array<string, mixed> */
    private function block(string $name): array
    {
        $rows = $this->install()->rows('SELECT * FROM contact_form_sections WHERE page_slug = ?', [self::$slugs[$name]]);
        $this->assertCount(1, $rows, "the \"{$name}\" block must still exist, once");

        return $rows[0];
    }

    /**
     * @return array{forms: list<array<string, mixed>>, fields: list<array<string, mixed>>}
     */
    private static function migratedFormSnapshot(): array
    {
        return [
            'forms' => self::$install->rows('SELECT * FROM forms WHERE internal_key = ?', [ContactFormContent::MIGRATED_FORM_KEY]),
            'fields' => self::$install->rows(
                'SELECT f.* FROM form_fields f JOIN forms fo ON fo.id = f.form_id WHERE fo.internal_key = ? ORDER BY f.id',
                [ContactFormContent::MIGRATED_FORM_KEY]
            ),
        ];
    }

    private static function placeBlock(
        string $pageSlug,
        string $sectionKey,
        string $titleNl,
        ?string $titleEn,
        bool $isActive,
        ?int $formId = null
    ): void {
        $columns = ['page_slug', 'section_key', 'title_nl', 'title_en', 'is_active', 'created_at', 'updated_at'];
        $values = [$pageSlug, $sectionKey, $titleNl, $titleEn, $isActive ? 1 : 0, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')];

        if ($formId !== null) {
            $columns[] = 'form_id';
            $values[] = $formId;
        }

        self::$install->pdo()
            ->prepare(
                'INSERT INTO contact_form_sections (' . implode(', ', $columns) . ')'
                . ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
            )
            ->execute($values);
    }

    private static function createEditorForm(): int
    {
        $pdo = self::$install->pdo();
        $pdo->prepare(
            'INSERT INTO forms
                (name, internal_key, is_active, submit_label_nl, submit_label_en,
                 success_message_nl, success_message_en, store_submissions, created_at, updated_at)
             VALUES (?, ?, 1, ?, ?, ?, ?, 1, NOW(), NOW())'
        )->execute(['Eigen formulier', 'zz-eigen-formulier', 'Versturen', 'Send', 'Dank je wel.', 'Thank you.']);

        return (int) $pdo->lastInsertId();
    }
}
