<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Database;
use App\Repository\ContactFormRepository;
use App\Repository\FormRepository;
use App\Service\ContactFormContent;
use App\Service\Forms\FormCatalog;
use App\Service\Forms\FormFieldTypes;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * What db/migrations/20260909310000_migrate_the_contact_form_into_a_form.php
 * promised: the quote form this site had as markup and validation still
 * exists, now as a Form definition, and every `contact_form` block points at
 * it.
 *
 * HISTORICAL GUARANTEES ONLY. These assertions are about what the migration
 * did, never about a current total: a second form the editor adds tomorrow,
 * a sixth field on this one, or another block placed somewhere else are all
 * ordinary CMS data and must not fail this test. So it asks "does the
 * migrated thing still exist, exactly once, with the content it was given",
 * never "is this the only one" (TESTING.md, the `migration-backfill` group).
 */
#[Group('migration-backfill')]
class ContactFormMigrationTest extends TestCase
{
    /**
     * The five fields, in the order partials/section-contact-form.php used
     * to render them, with the keys the old markup posted under.
     */
    private const MIGRATED_FIELDS = [
        ['naam', 'text', 'Naam', 'Name', true],
        ['email', 'email', 'E-mail', 'Email', true],
        ['telefoon', 'tel', 'Telefoonnummer', 'Phone number', false],
        ['voor-wie', 'radio', 'Voor wie is de aanvraag?', 'Who is this request for?', true],
        ['omschrijving', 'textarea', 'Omschrijving van je idee', 'Describe your idea', true],
    ];

    protected function setUp(): void
    {
        FormCatalog::clearCache();
    }

    public function testTheMigratedContactFormExistsExactlyOnce(): void
    {
        $count = Database::connection()->prepare('SELECT COUNT(*) FROM forms WHERE internal_key = :key');
        $count->execute(['key' => ContactFormContent::MIGRATED_FORM_KEY]);

        $this->assertSame(1, (int) $count->fetchColumn(), 'the migrated form must exist, and exactly once');
    }

    public function testItKeptTheSettingsTheOldEndpointBehavedWith(): void
    {
        $form = $this->migratedForm();

        $this->assertTrue($form->isActive);
        $this->assertSame('Verstuur aanvraag', $form->submitLabel->nl, 'the button the site has always shown');
        $this->assertSame('Send request', $form->submitLabel->en);
        $this->assertStringContainsString('Bedankt', $form->successMessage->nl);
        $this->assertTrue($form->storesSubmissions, 'the old endpoint stored every request, so this one does too');
        $this->assertSame('email', $form->replyToFieldKey, 'the visitor address was always the Reply-To');
    }

    public function testTheFiveFieldsExistOnceEachWithTheirOldLabelsAndRequirednessAndOrder(): void
    {
        $form = $this->migratedForm();
        $keys = array_column(self::MIGRATED_FIELDS, 0);

        $this->assertSame(
            $keys,
            array_slice($form->fieldKeys(), 0, count($keys)),
            'the migrated fields must still come first, in the order the old markup rendered them'
        );

        foreach (self::MIGRATED_FIELDS as [$key, $type, $labelNl, $labelEn, $required]) {
            $field = $form->field($key);

            $this->assertNotNull($field, 'the migrated field "' . $key . '" is gone');
            $this->assertSame($type, $field->type->key(), $key);
            $this->assertSame($labelNl, $field->label->nl, $key);
            $this->assertSame($labelEn, $field->label->en, $key);
            $this->assertSame($required, $field->isRequired, $key);
        }

        // Exactly once each — a duplicate would mean the migration ran twice.
        $rows = (new FormRepository())->fieldsFor($form->id);
        foreach ($keys as $key) {
            $matches = array_filter($rows, static fn (array $row): bool => (string) $row['field_key'] === $key);
            $this->assertCount(1, $matches, 'the migrated field "' . $key . '" exists more than once');
        }
    }

    public function testTheAudienceChoiceKeptBothOfItsOptionsInBothLanguages(): void
    {
        $field = $this->migratedForm()->field('voor-wie');

        $this->assertNotNull($field);
        $this->assertSame(['Particulier', 'Zakelijk'], array_map(static fn ($o) => $o->nl, $field->options->all()));
        $this->assertSame(['Personal', 'Business'], array_map(static fn ($o) => $o->en, $field->options->all()));
    }

    /**
     * The hardcoded markup had `checked` on "Particulier", and
     * db/migrations/20260909320000_add_a_default_choice_to_form_fields.php
     * put that back as a property of the field rather than as a literal in
     * a renderer. This is the promise that it is still there.
     */
    public function testTheAudienceChoiceStillStartsOnParticulier(): void
    {
        $field = $this->migratedForm()->field('voor-wie');

        $this->assertNotNull($field);
        $this->assertSame('Particulier', $field->defaultValue);
        $this->assertTrue(
            $field->options->contains($field->defaultValue),
            'a default must always be one of the options actually offered'
        );
    }

    /** Only that one field: the migration gave nothing else a default. */
    public function testNoOtherFieldOfTheContactFormWasGivenADefault(): void
    {
        foreach ($this->migratedForm()->fields as $field) {
            if ($field->key === 'voor-wie') {
                continue;
            }

            $this->assertFalse(
                $field->hasDefaultValue(),
                'field "' . $field->key . '" was given a default it never had'
            );
        }
    }

    public function testTheHintUnderTheDescriptionSurvivedInBothLanguages(): void
    {
        $field = $this->migratedForm()->field('omschrijving');

        $this->assertNotNull($field);
        $this->assertStringContainsString('formaat', $field->helpText->nl);
        $this->assertStringContainsString('size', $field->helpText->en);
    }

    /**
     * The migration selected on the DATA, not on a list of known page slugs
     * — so a `contact_form` block an editor put on a page of their own is
     * linked just as the contact page's is.
     */
    public function testEveryContactFormBlockOnThisInstallPointsAtTheMigratedForm(): void
    {
        $form = $this->migratedForm();

        $unlinked = Database::connection()
            ->query('SELECT page_slug, section_key FROM contact_form_sections WHERE form_id IS NULL')
            ->fetchAll();

        $this->assertSame(
            [],
            $unlinked,
            'a contact block was left without a form: ' . json_encode($unlinked)
        );

        $wrong = Database::connection()->prepare(
            'SELECT page_slug FROM contact_form_sections WHERE form_id <> :id'
        );
        $wrong->execute(['id' => $form->id]);

        $this->assertSame(
            [],
            $wrong->fetchAll(),
            'every block that existed before Core Forms was migrated onto the one definition'
        );
    }

    public function testTheContactPagesBlockKeptItsHeadingAndItsAttachment(): void
    {
        $section = (new ContactFormRepository())->findBySlugAndKey('contact', ContactFormContent::MIGRATED_SECTION_KEY);

        if ($section === null) {
            $this->markTestSkipped('this install has no contact block on a page called "contact"');
        }

        $this->assertSame('Offerte aanvragen', $section['title_nl'], 'the heading the page has always shown');
        $this->assertSame(1, (int) $section['allow_attachment'], 'the optional file upload must not have been taken away');
        $this->assertSame(1, (int) $section['is_active']);
    }

    /**
     * Every migrated field names a type the registry knows — otherwise the
     * form would render short a field without anything saying so.
     */
    public function testEveryMigratedFieldNamesATypeThisCmsStillSupports(): void
    {
        foreach ((new FormRepository())->fieldsFor($this->migratedForm()->id) as $row) {
            $this->assertTrue(
                FormFieldTypes::has((string) $row['field_type']),
                'field "' . $row['field_key'] . '" names an unsupported type'
            );
        }
    }

    private function migratedForm(): \App\Service\Forms\FormDefinition
    {
        $form = FormCatalog::findByInternalKey(ContactFormContent::MIGRATED_FORM_KEY);

        $this->assertNotNull(
            $form,
            'the migrated contact form is missing — run `php vendor/bin/phinx migrate` and refresh the test database'
        );

        return $form;
    }
}
