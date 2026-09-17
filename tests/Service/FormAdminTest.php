<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\FormBlockRepository;
use App\Repository\FormRepository;
use App\Repository\FormSubmissionRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\Forms\FormCatalog;
use App\Service\Forms\FormRenderState;
use App\Service\Forms\FormUsage;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\FormFixture;

/**
 * Core Forms against the test database: defining a form, ordering its
 * fields, working out where it is used, refusing to delete one that still
 * matters, and what a stored submission keeps.
 *
 * Everything here creates its own throwaway form and its own throwaway page
 * and removes both again — nothing touches a form or a page the editor
 * manages (TESTING.md).
 */
class FormAdminTest extends TestCase
{
    /** Underscores keep this out of the shape a real slug has. */
    private const TEST_PAGE = '__test_forms__';

    private FormRepository $forms;
    private FormSubmissionRepository $submissions;

    /** @var list<int> forms created by the running test */
    private array $createdFormIds = [];

    /** @var list<int> page_sections ids created by the running test */
    private array $createdPageSectionIds = [];

    protected function setUp(): void
    {
        $this->forms = new FormRepository();
        $this->submissions = new FormSubmissionRepository();

        $this->removeTestPage();
        FormCatalog::clearCache();
    }

    protected function tearDown(): void
    {
        $sections = new PageSectionRepository();

        foreach ($this->createdPageSectionIds as $id) {
            $row = $sections->findById($id);
            if ($row !== null) {
                SectionRegistry::delete($row, $sections);
            }
        }
        $this->createdPageSectionIds = [];

        foreach ($this->createdFormIds as $id) {
            // Submissions and their values cascade from the form only via
            // the code path below; delete them explicitly so a failed test
            // cannot leave personal-looking rows behind.
            foreach ($this->submissions->findAllForAdmin($id) as $submission) {
                $this->submissions->delete((int) $submission['id']);
            }
            $this->forms->delete($id);
        }
        $this->createdFormIds = [];

        $this->removeTestPage();
        FormCatalog::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* Definitions                                                         */
    /* ------------------------------------------------------------------ */

    public function testAFormIsCreatedInactiveOfNothingAndStoresNothingByDefault(): void
    {
        $id = $this->createForm('Aanmeldformulier');
        $row = $this->forms->find($id);

        $this->assertNotNull($row);
        $this->assertSame('Aanmeldformulier', $row['name']);
        $this->assertSame(0, (int) $row['store_submissions'], 'retaining personal data must be a deliberate choice');
        $this->assertNull($row['notification_email'], 'no address is invented; the site setting is the fallback');
    }

    public function testAnInternalKeyIsGeneratedAndMadeUniqueAgainstWhatAlreadyExists(): void
    {
        $first = $this->createForm('Herhaalde naam');
        $second = $this->createForm('Herhaalde naam');

        $keys = [$this->forms->find($first)['internal_key'], $this->forms->find($second)['internal_key']];

        $this->assertNotSame($keys[0], $keys[1], 'two forms with one name must not share a key');
        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $key);
        }
    }

    public function testAFormCanBeSwitchedOffAndOnAgainWithoutLosingAnything(): void
    {
        $id = $this->createForm('Aan en uit');
        $this->addField($id, 'Naam', 'text');

        $this->forms->update($id, $this->values($id, ['is_active' => false]));
        FormCatalog::clearCache();

        $off = FormCatalog::find($id);
        $this->assertFalse($off->isActive);
        $this->assertFalse($off->isRenderable(), 'a form that is off may not be shown');
        $this->assertTrue($off->hasFields(), 'its fields are untouched');

        $this->forms->update($id, $this->values($id, ['is_active' => true]));
        FormCatalog::clearCache();

        $this->assertTrue(FormCatalog::find($id)->isRenderable());
    }

    /* ------------------------------------------------------------------ */
    /* Fields                                                              */
    /* ------------------------------------------------------------------ */

    public function testFieldsComeBackInTheOrderTheEditorPutThemIn(): void
    {
        $id = $this->createForm('Volgorde');
        $this->addField($id, 'Naam', 'text');
        $this->addField($id, 'E-mail', 'email');
        $this->addField($id, 'Bericht', 'textarea');

        FormCatalog::clearCache();
        $this->assertSame(['naam', 'e-mail', 'bericht'], FormCatalog::find($id)->fieldKeys());
    }

    public function testMovingAFieldChangesTheOrderByExactlyOnePlace(): void
    {
        $id = $this->createForm('Verplaatsen');
        $naam = $this->addField($id, 'Naam', 'text');
        $email = $this->addField($id, 'E-mail', 'email');
        $bericht = $this->addField($id, 'Bericht', 'textarea');

        $this->forms->moveField($id, $bericht, 'up');
        FormCatalog::clearCache();
        $this->assertSame(['naam', 'bericht', 'e-mail'], FormCatalog::find($id)->fieldKeys());

        $this->forms->moveField($id, $naam, 'down');
        FormCatalog::clearCache();
        $this->assertSame(['bericht', 'naam', 'e-mail'], FormCatalog::find($id)->fieldKeys());

        // Moving past the end does nothing rather than wrapping around.
        $this->forms->moveField($id, $bericht, 'up');
        FormCatalog::clearCache();
        $this->assertSame(['bericht', 'naam', 'e-mail'], FormCatalog::find($id)->fieldKeys());

        $this->assertNotSame(0, $email, 'field ids are used by the admin screens');
    }

    public function testDeletingAFieldClosesTheGapAndFreesTheReplyTo(): void
    {
        $id = $this->createForm('Verwijderen');
        $this->addField($id, 'Naam', 'text');
        $email = $this->addField($id, 'E-mail', 'email');
        $this->addField($id, 'Bericht', 'textarea');

        $this->forms->update($id, $this->values($id, ['reply_to_field_key' => 'e-mail']));

        $this->forms->clearReplyToField($id, 'e-mail');
        $this->forms->deleteField($email);
        $this->forms->renumberFields($id);
        FormCatalog::clearCache();

        $form = FormCatalog::find($id);
        $this->assertSame(['naam', 'bericht'], $form->fieldKeys());
        $this->assertNull($form->replyToField(), 'a Reply-To must never outlive the field it names');
        $this->assertSame([0, 1], array_map(
            static fn (array $row): int => (int) $row['sort_order'],
            $this->forms->fieldsFor($id)
        ));
    }

    public function testAChoiceFieldWithoutOptionsIsNotRenderedButIsStillStored(): void
    {
        $id = $this->createForm('Lege keuze');
        $this->addField($id, 'Keuze', 'select');
        FormCatalog::clearCache();

        $this->assertSame([], FormCatalog::find($id)->fieldKeys(), 'an empty dropdown is not shown to a visitor');
        $this->assertCount(1, $this->forms->fieldsFor($id), 'but the editor has not lost their row');
    }

    /* ------------------------------------------------------------------ */
    /* Default values                                                      */
    /* ------------------------------------------------------------------ */

    public function testAChoiceFieldsDefaultSurvivesTheRoundTripToTheDatabase(): void
    {
        $id = $this->createForm('Met standaardwaarde');
        $this->addField($id, 'Voorkeur', 'radio', "Ochtend|Morning\nMiddag|Afternoon", 'Middag');
        FormCatalog::clearCache();

        $this->assertSame('Middag', FormCatalog::find($id)->field('voorkeur')->defaultValue);
    }

    /**
     * A field created through the admin starts with no default at all: the
     * editor sets one on purpose, on the field's own screen, once its
     * options exist.
     */
    public function testAFreshlyCreatedFieldHasNoDefault(): void
    {
        $id = $this->createForm('Zonder standaardwaarde');
        $this->addField($id, 'Voorkeur', 'radio', "Ochtend\nMiddag");
        FormCatalog::clearCache();

        $this->assertSame('', FormCatalog::find($id)->field('voorkeur')->defaultValue);
        $this->assertNull($this->forms->fieldsFor($id)[0]['default_value'], 'the column stays NULL');
    }

    /** And so does a whole form: nothing is pre-selected unless asked for. */
    public function testAFreshFormHasNoDefaultOnAnyField(): void
    {
        $id = $this->createForm('Helemaal nieuw');
        $this->addField($id, 'Naam', 'text');
        $this->addField($id, 'Voorkeur', 'radio', "Ochtend\nMiddag");
        $this->addField($id, 'Aantal', 'select', "Een\nTwee");
        FormCatalog::clearCache();

        foreach (FormCatalog::find($id)->fields as $field) {
            $this->assertFalse($field->hasDefaultValue(), $field->key . ' should start without a default');
        }
    }

    /**
     * A default that named an option the editor has since renamed is
     * dropped on the way out, so the renderer never pre-selects a choice
     * that is not offered.
     */
    public function testADefaultStopsApplyingOnceItsOptionIsGone(): void
    {
        $id = $this->createForm('Optie hernoemd');
        $fieldId = $this->addField($id, 'Voorkeur', 'radio', "Ochtend\nMiddag", 'Middag');

        // The options are replaced: the old rows go, new ones take their
        // place, and the stored default names none of them any more.
        (new \App\Repository\FormFieldOptionRepository())->deleteForField($fieldId);
        FormFixture::options($fieldId, "Ochtend\nAvond");
        $this->forms->updateField($fieldId, [
            'field_type' => 'radio',
            'is_required' => false,
            'default_value' => 'Middag',
        ]);
        FormCatalog::clearCache();

        $this->assertSame('', FormCatalog::find($id)->field('voorkeur')->defaultValue);
    }

    /**
     * The precedence, against a real definition read back out of the
     * database: a fresh form starts on the default, and a visitor's own
     * answer wins after a failed submission.
     */
    public function testTheStoredDefaultAppliesOnlyUntilTheVisitorAnswers(): void
    {
        $id = $this->createForm('Voorrang');
        $this->addField($id, 'Voorkeur', 'radio', "Ochtend\nMiddag", 'Middag');
        FormCatalog::clearCache();

        $field = FormCatalog::find($id)->field('voorkeur');
        $token = 'form-abc1234567';

        $this->assertSame('Middag', FormRenderState::fresh($token)->valueFor($field));
        $this->assertSame(
            'Ochtend',
            FormRenderState::withErrors($token, ['voorkeur' => 'Ochtend'], [], [])->valueFor($field)
        );
    }

    /* ------------------------------------------------------------------ */
    /* Usage and safe deletion                                             */
    /* ------------------------------------------------------------------ */

    public function testAFormThatIsUsedNowhereAndHasNoSubmissionsCanBeDeleted(): void
    {
        $id = $this->createForm('Ongebruikt');
        $this->addField($id, 'Naam', 'text');

        $this->assertSame([], FormUsage::placements($id));
        $this->assertSame([], FormUsage::deletionBlockers($id));
        $this->assertTrue(FormUsage::canBeDeleted($id));
    }

    public function testAFormThatIsOnAPageCannotBeDeletedAndSaysWhere(): void
    {
        $id = $this->createForm('In gebruik');
        $this->addField($id, 'Naam', 'text');

        $page = $this->createTestPage();
        $this->placeFormBlock($page, $id);

        $placements = FormUsage::placements($id);

        $this->assertCount(1, $placements);
        $this->assertSame('Formulierentestpagina', $placements[0]['page_title']);
        $this->assertStringContainsString('/admin/form-block.php?section=', $placements[0]['edit_url']);

        $this->assertFalse(FormUsage::canBeDeleted($id));
        $this->assertStringContainsString('pagina', strtolower(FormUsage::deletionBlockers($id)[0]));
    }

    public function testTheSameFormCanBePlacedTwiceAndBothPlacementsAreListed(): void
    {
        $id = $this->createForm('Twee keer');
        $this->addField($id, 'Naam', 'text');

        $page = $this->createTestPage();
        $this->placeFormBlock($page, $id);
        $this->placeFormBlock($page, $id);

        $this->assertCount(2, FormUsage::placements($id), 'one definition, two placements');
    }

    public function testAFormWithStoredSubmissionsCannotBeDeletedEitherAndSaysWhy(): void
    {
        $id = $this->createForm('Met inzendingen');
        $this->addField($id, 'Naam', 'text');

        $this->createdFormIds[] = $id;
        $this->submissions->create($id, 'Met inzendingen', '/ergens', [
            ['field_key' => 'naam', 'field_label' => 'Naam', 'field_type' => 'text', 'value' => 'Iemand'],
        ]);

        $blockers = FormUsage::deletionBlockers($id);

        $this->assertFalse(FormUsage::canBeDeleted($id));
        $this->assertStringContainsString('inzending', strtolower(implode(' ', $blockers)));
    }

    /* ------------------------------------------------------------------ */
    /* Stored submissions                                                  */
    /* ------------------------------------------------------------------ */

    public function testASubmissionKeepsItsOwnCopyOfEveryLabelAndAnswer(): void
    {
        $id = $this->createForm('Momentopname');
        $this->addField($id, 'Naam', 'text');
        $this->addField($id, 'Bericht', 'textarea');

        $submissionId = $this->submissions->create($id, 'Momentopname', '/contact.php', [
            ['field_key' => 'naam', 'field_label' => 'Naam', 'field_type' => 'text', 'value' => 'Iemand'],
            ['field_key' => 'bericht', 'field_label' => 'Bericht', 'field_type' => 'textarea', 'value' => "Regel een\nRegel twee"],
        ]);

        $values = $this->submissions->valuesFor($submissionId);

        $this->assertSame(['Naam', 'Bericht'], array_column($values, 'field_label'));
        $this->assertSame("Regel een\nRegel twee", $values[1]['value'], 'a textarea keeps its line breaks');
    }

    /**
     * THE reason a submission snapshots its labels: an editor renaming or
     * deleting a field months later must not make an old enquiry
     * unreadable.
     */
    public function testEditingTheFormLaterDoesNotChangeWhatAnOldSubmissionSays(): void
    {
        $id = $this->createForm('Later gewijzigd');
        $naam = $this->addField($id, 'Naam', 'text');
        $wens = $this->addField($id, 'Wens', 'textarea');

        $submissionId = $this->submissions->create($id, 'Later gewijzigd', '/contact.php', [
            ['field_key' => 'naam', 'field_label' => 'Naam', 'field_type' => 'text', 'value' => 'Iemand'],
            ['field_key' => 'wens', 'field_label' => 'Wens', 'field_type' => 'textarea', 'value' => 'Een houten bord'],
        ]);

        // The editor renames one field, deletes the other, and renames the
        // whole form.
        $this->forms->updateField($naam, [
            'field_type' => 'text',
            'label_nl' => 'Hoe heet je?',
            'label_en' => null,
            'placeholder_nl' => null,
            'placeholder_en' => null,
            'help_text_nl' => null,
            'help_text_en' => null,
            'is_required' => true,
            'options' => null,
        ]);
        $this->forms->deleteField($wens);
        $this->forms->update($id, $this->values($id, ['name' => 'Heel andere naam']));
        FormCatalog::clearCache();

        $values = $this->submissions->valuesFor($submissionId);

        $this->assertSame(['Naam', 'Wens'], array_column($values, 'field_label'), 'the labels of the moment survive');
        $this->assertSame('Een houten bord', $values[1]['value'], 'the answer to a deleted field is still there');
        $this->assertSame(
            'Later gewijzigd',
            $this->submissions->findForAdmin($submissionId)['form_name'],
            'the form name is a copy, not a join'
        );
    }

    public function testASubmissionIsUnreadUntilSomebodyOpensItAndCanBeDeletedForGood(): void
    {
        $id = $this->createForm('Lezen en wissen');
        $this->addField($id, 'Naam', 'text');

        $submissionId = $this->submissions->create($id, 'Lezen en wissen', null, [
            ['field_key' => 'naam', 'field_label' => 'Naam', 'field_type' => 'text', 'value' => 'Iemand'],
        ]);

        $this->assertSame(0, (int) $this->submissions->findForAdmin($submissionId)['is_read']);

        $this->submissions->setReadState($submissionId, true);
        $this->assertSame(1, (int) $this->submissions->findForAdmin($submissionId)['is_read']);

        $this->assertTrue($this->submissions->delete($submissionId));
        $this->assertNull($this->submissions->findForAdmin($submissionId));
        $this->assertSame([], $this->submissions->valuesFor($submissionId), 'the answers go with it');
    }

    public function testAFailedNotificationLeavesTheSubmissionVisibleRatherThanLost(): void
    {
        $id = $this->createForm('Mail mislukt');
        $this->addField($id, 'Naam', 'text');

        $submissionId = $this->submissions->create($id, 'Mail mislukt', null, [
            ['field_key' => 'naam', 'field_label' => 'Naam', 'field_type' => 'text', 'value' => 'Iemand'],
        ]);

        $this->assertNull(
            $this->submissions->findForAdmin($submissionId)['notification_sent_at'],
            'an unsent notification is visible as such, not hidden'
        );

        $this->submissions->setNotificationSentAt($submissionId);
        $this->assertNotNull($this->submissions->findForAdmin($submissionId)['notification_sent_at']);
    }

    public function testTheOverviewCountsAndPreviewsWithoutAQueryPerRow(): void
    {
        $id = $this->createForm('Overzicht');
        $this->addField($id, 'Naam', 'text');

        $first = $this->submissions->create($id, 'Overzicht', null, [
            ['field_key' => 'naam', 'field_label' => 'Naam', 'field_type' => 'text', 'value' => 'Eerste'],
        ]);
        $second = $this->submissions->create($id, 'Overzicht', null, [
            ['field_key' => 'naam', 'field_label' => 'Naam', 'field_type' => 'text', 'value' => 'Tweede'],
        ]);

        $this->assertSame(2, $this->submissions->countForForm($id));
        $this->assertSame([$id => 2], $this->submissions->countsForForms([$id]));

        $previews = $this->submissions->previewsFor([$first, $second]);
        $this->assertSame('Eerste', $previews[$first]);
        $this->assertSame('Tweede', $previews[$second]);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function createForm(string $name): int
    {
        $id = $this->forms->create([
            'name' => $name,
            'internal_key' => FormCatalog::internalKeyFor('zz test ' . $name, $this->forms),
            'is_active' => true,
            'notification_email' => null,
            'reply_to_field_key' => null,
            'store_submissions' => false,
        ]);

        $this->createdFormIds[] = $id;
        FormFixture::formWords($id, [
            'nl' => ['submit_label' => 'Versturen', 'success_message' => 'Bedankt.'],
            'en' => ['submit_label' => 'Send', 'success_message' => 'Thanks.'],
        ]);
        FormCatalog::clearCache();

        return $id;
    }

    private function addField(
        int $formId,
        string $label,
        string $type,
        ?string $options = null,
        ?string $defaultValue = null
    ): int {
        $taken = array_map(
            static fn (array $row): string => (string) $row['field_key'],
            $this->forms->fieldsFor($formId)
        );

        $id = FormFixture::field($formId, [
            'field_key' => \App\Service\Forms\FormFieldKey::fromLabel($label, $taken),
            'field_type' => $type,
            'is_required' => false,
            'default_value' => $defaultValue,
        ], ['nl' => ['label' => $label]], $options);

        FormCatalog::clearCache();

        return $id;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function values(int $formId, array $overrides): array
    {
        $row = $this->forms->find($formId);

        $words = \App\Service\Forms\FormLocalization::forms()->words($formId);

        return $overrides + [
            'name' => (string) $row['name'],
            'is_active' => (bool) $row['is_active'],
            'language_code' => 'nl',
            'submit_label' => (string) ($words['nl']['submit_label'] ?? ''),
            'success_message' => (string) ($words['nl']['success_message'] ?? ''),
            'notification_email' => (string) ($row['notification_email'] ?? ''),
            'reply_to_field_key' => (string) ($row['reply_to_field_key'] ?? ''),
            'store_submissions' => (bool) $row['store_submissions'],
        ];
    }

    /** @return array<string, mixed> the throwaway page's row */
    private function createTestPage(): array
    {
        $pages = new PageRepository();

        \Tests\Support\PageFixture::create([
            'content_key' => self::TEST_PAGE,
            'slug' => self::TEST_PAGE,
            'status' => 'draft',
        ], 'Formulierentestpagina');

        $page = $pages->findByContentKey(self::TEST_PAGE);
        $this->assertNotNull($page);

        return $page;
    }

    /**
     * @param array<string, mixed> $page
     */
    private function placeFormBlock(array $page, int $formId): void
    {
        [$sectionId, $sectionKey] = SectionRegistry::create('form', self::TEST_PAGE);

        $this->createdPageSectionIds[] = (new PageSectionRepository())->create(
            (int) $page['id'],
            self::TEST_PAGE,
            'form',
            $sectionKey,
            $sectionId
        );

        (new FormBlockRepository())->upsertSection(
            self::TEST_PAGE,
            (string) $sectionKey,
            ['form_id' => $formId, 'is_active' => true]
        );
    }

    private function removeTestPage(): void
    {
        $pages = new PageRepository();
        $page = $pages->findByContentKey(self::TEST_PAGE);

        if ($page === null) {
            return;
        }

        $sections = new PageSectionRepository();
        foreach ($sections->findForPage((int) $page['id']) as $row) {
            SectionRegistry::delete($row, $sections);
        }

        Database::connection()
            ->prepare('DELETE FROM pages WHERE id = :id')
            ->execute(['id' => (int) $page['id']]);
    }
}
