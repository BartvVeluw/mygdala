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
use App\Service\Forms\FormFieldKey;
use App\Service\Forms\FormRenderState;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;

/**
 * What a form actually looks like on a public page, and what happens when
 * somebody sends it — over real HTTP, against the test web server and the
 * test database.
 *
 * Its own throwaway page and its own throwaway form every time, so nothing
 * here depends on (or disturbs) the site's real contact page. The public
 * page is published on purpose: an unpublished one answers 404, and this
 * test is about what a visitor sees.
 *
 * The assertions are on MARKERS — an id, an attribute, a label — never on a
 * block of HTML, so ordinary styling changes do not break them.
 */
class FormRenderingTest extends TestCase
{
    private const TEST_PAGE = 'zz-formulier-testpagina';
    private const SECOND_PAGE = 'zz-formulier-testpagina-twee';

    private FormRepository $forms;

    /** @var list<int> */
    private array $createdFormIds = [];

    /** @var list<int> */
    private array $createdPageSectionIds = [];

    protected function setUp(): void
    {
        if (!TestEnvironment::siteIsReachable()) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }

        $this->forms = new FormRepository();

        $this->removePage(self::TEST_PAGE);
        $this->removePage(self::SECOND_PAGE);
        $this->clearRateLimit();
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

        $submissions = new FormSubmissionRepository();
        foreach ($this->createdFormIds as $id) {
            foreach ($submissions->findAllForAdmin($id) as $submission) {
                $submissions->delete((int) $submission['id']);
            }
            $this->forms->delete($id);
        }
        $this->createdFormIds = [];

        $this->removePage(self::TEST_PAGE);
        $this->removePage(self::SECOND_PAGE);
        $this->clearRateLimit();
        FormCatalog::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* Rendering                                                           */
    /* ------------------------------------------------------------------ */

    public function testAFormRendersEveryFieldWithARealLabelAndTheRightControl(): void
    {
        $formId = $this->createFullForm();
        $page = $this->createPage(self::TEST_PAGE, 'Formulier testpagina');
        $token = $this->placeForm($page, self::TEST_PAGE, $formId);

        $response = $this->get('/' . self::TEST_PAGE);
        $this->assertSame(200, $response['status']);
        $body = $response['body'];

        // A label that really points at its control, per field and per type.
        foreach ([
            'naam' => 'type="text"',
            'e-mail' => 'type="email"',
            'telefoon' => 'type="tel"',
            'bericht' => '<textarea',
            'onderwerp' => '<select',
            'akkoord' => 'type="checkbox"',
        ] as $key => $control) {
            $id = $token . '-' . $key;

            $this->assertStringContainsString('for="' . $id . '"', $body, $key . ' has no label pointing at it');
            $this->assertStringContainsString('id="' . $id . '"', $body, $key . ' has no control with that id');
            $this->assertStringContainsString($control, $body, $key . ' did not render as ' . $control);
        }

        // A radio group is a fieldset with a legend, not a stray label.
        $this->assertStringContainsString('<legend', $body);
        $this->assertStringContainsString('id="' . $token . '-voor-wie-0"', $body);

        $this->assertStringContainsString('Verstuur dit', $body, 'the submit label comes from the form');
    }

    public function testRequiredFieldsAreMarkedForBothPeopleAndAssistiveTechnology(): void
    {
        $formId = $this->createFullForm();
        $page = $this->createPage(self::TEST_PAGE, 'Formulier testpagina');
        $token = $this->placeForm($page, self::TEST_PAGE, $formId);

        $body = $this->get('/' . self::TEST_PAGE)['body'];

        $this->assertMatchesRegularExpression(
            '/id="' . preg_quote($token, '/') . '-naam"[^>]*\brequired\b/',
            $body,
            'a required field must carry the required attribute'
        );
        $this->assertMatchesRegularExpression(
            '/id="' . preg_quote($token, '/') . '-naam"[^>]*aria-required="true"/',
            $body
        );
        $this->assertStringNotContainsString(
            'id="' . $token . '-telefoon" required',
            $body,
            'an optional field must not be marked required'
        );

        // Every control points at the element its error message lands in.
        $this->assertStringContainsString('aria-describedby="' . $token . '-naam-error"', $body);
        $this->assertStringContainsString('id="' . $token . '-naam-error"', $body);
    }

    public function testAHelpTextIsAnnouncedAlongsideTheFieldItExplains(): void
    {
        $formId = $this->createFullForm();
        $page = $this->createPage(self::TEST_PAGE, 'Formulier testpagina');
        $token = $this->placeForm($page, self::TEST_PAGE, $formId);

        $body = $this->get('/' . self::TEST_PAGE)['body'];

        $this->assertStringContainsString('id="' . $token . '-bericht-hint"', $body);
        $this->assertStringContainsString(
            'aria-describedby="' . $token . '-bericht-hint ' . $token . '-bericht-error"',
            $body
        );
    }

    public function testBothLanguagesTravelWithTheMarkupAndEmptyEnglishFallsBackToDutch(): void
    {
        $formId = $this->createFullForm();
        $page = $this->createPage(self::TEST_PAGE, 'Formulier testpagina');
        $this->placeForm($page, self::TEST_PAGE, $formId);

        $body = $this->get('/' . self::TEST_PAGE)['body'];

        $this->assertStringContainsString('data-nl="Naam" data-en="Name"', $body);
        // "Telefoon" was given no English label at all.
        $this->assertStringContainsString('data-nl="Telefoon" data-en="Telefoon"', $body);
    }

    /**
     * ONE DEFINITION, MORE THAN ONE PLACEMENT. Two blocks showing the same
     * form must share no DOM id at all, or the second one's labels would
     * point at the first one's fields.
     */
    public function testTheSameFormCanBeRenderedTwiceOnOnePageWithoutCollidingIds(): void
    {
        $formId = $this->createFullForm();
        $page = $this->createPage(self::TEST_PAGE, 'Formulier testpagina');

        $first = $this->placeForm($page, self::TEST_PAGE, $formId);
        $second = $this->placeForm($page, self::TEST_PAGE, $formId);

        $this->assertNotSame($first, $second, 'two placements must get different instance tokens');

        $body = $this->get('/' . self::TEST_PAGE)['body'];

        $this->assertSame(2, substr_count($body, 'data-form-block'), 'both forms should render');
        $this->assertStringContainsString('id="' . $first . '-naam"', $body);
        $this->assertStringContainsString('id="' . $second . '-naam"', $body);

        // No id may appear twice anywhere in the document.
        preg_match_all('/\bid="([^"]+)"/', $body, $matches);
        $this->assertSame(
            count($matches[1]),
            count(array_unique($matches[1])),
            'a duplicate DOM id: ' . implode(', ', array_diff_assoc($matches[1], array_unique($matches[1])))
        );
    }

    public function testTheSameFormCanBeRenderedOnTwoDifferentPages(): void
    {
        $formId = $this->createFullForm();

        $first = $this->createPage(self::TEST_PAGE, 'Formulier testpagina');
        $second = $this->createPage(self::SECOND_PAGE, 'Tweede testpagina');

        $this->placeForm($first, self::TEST_PAGE, $formId);
        $this->placeForm($second, self::SECOND_PAGE, $formId);

        foreach ([self::TEST_PAGE, self::SECOND_PAGE] as $slug) {
            $body = $this->get('/' . $slug)['body'];
            $this->assertStringContainsString('data-form-block', $body, $slug . ' should render the form');
            $this->assertStringContainsString('name="naam"', $body, $slug . ' should render the shared fields');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Default values                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * A choice field configured with a default comes up with that option
     * already selected, and nothing else does.
     */
    public function testAConfiguredDefaultIsPreSelectedOnAFreshForm(): void
    {
        $formId = $this->createFullForm();
        $page = $this->createPage(self::TEST_PAGE, 'Formulier testpagina');
        $token = $this->placeForm($page, self::TEST_PAGE, $formId);

        $body = $this->get('/' . self::TEST_PAGE)['body'];

        $this->assertMatchesRegularExpression(
            '/id="' . preg_quote($token, '/') . '-voor-wie-1"[^>]*\bchecked\b/',
            $body,
            'the configured default option should come up selected'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="' . preg_quote($token, '/') . '-voor-wie-0"[^>]*\bchecked\b/',
            $body,
            'only the default option is selected'
        );

        // The dropdown has no default configured, so it starts on its
        // empty entry rather than on an answer nobody gave.
        $this->assertMatchesRegularExpression('/<option value=""[^>]*selected/', $body);
    }

    /**
     * THE regression this property exists to prevent, over real HTTP: a
     * visitor who moved away from the default and then tripped a
     * validation error gets their own choice back, not the default.
     */
    public function testAfterAFailedSubmissionTheVisitorsChoiceSurvivesTheDefault(): void
    {
        $formId = $this->createFullForm();
        $page = $this->createPage(self::TEST_PAGE, 'Formulier testpagina');
        $token = $this->placeForm($page, self::TEST_PAGE, $formId);

        // A browser POST (no JSON), so the answers travel back through the
        // public session exactly as they do for a visitor without
        // JavaScript.
        $failed = $this->post([
            'form-key' => $this->internalKey($formId),
            'form-instance' => $token,
            'form-source' => '/' . self::TEST_PAGE,
            'form-ts' => (string) (time() - 30),
            'naam' => '',
            'voor-wie' => 'Particulier',
        ], false);

        $this->assertSame(303, $failed['status']);
        $this->assertStringContainsString('form-status=error', $failed['location']);

        $body = $this->get(
            '/' . self::TEST_PAGE . '?form-status=error&form=' . $token,
            $failed['cookie']
        )['body'];

        $this->assertMatchesRegularExpression(
            '/id="' . preg_quote($token, '/') . '-voor-wie-0"[^>]*\bchecked\b/',
            $body,
            "the visitor picked Particulier; the field's default must not overrule that"
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="' . preg_quote($token, '/') . '-voor-wie-1"[^>]*\bchecked\b/',
            $body
        );
    }

    /* ------------------------------------------------------------------ */
    /* Failing safely                                                      */
    /* ------------------------------------------------------------------ */

    public function testABlockWithNoFormChosenRendersNothingRatherThanAnEmptyBox(): void
    {
        $page = $this->createPage(self::TEST_PAGE, 'Formulier testpagina');
        $this->placeForm($page, self::TEST_PAGE, null);

        $response = $this->get('/' . self::TEST_PAGE);

        $this->assertSame(200, $response['status'], 'the rest of the page must render normally');
        $this->assertStringNotContainsString('data-form-block', $response['body']);
    }

    public function testAnInactiveFormRendersNothingAndTakesNoPageDown(): void
    {
        $formId = $this->createFullForm();
        $page = $this->createPage(self::TEST_PAGE, 'Formulier testpagina');
        $this->placeForm($page, self::TEST_PAGE, $formId);

        $this->forms->update($formId, $this->values($formId, ['is_active' => false]));

        $response = $this->get('/' . self::TEST_PAGE);

        $this->assertSame(200, $response['status']);
        $this->assertStringNotContainsString('data-form-block', $response['body']);
        $this->assertStringContainsString('Formulier testpagina', $response['body'], 'the page itself still renders');
    }

    public function testAFormThatWasDeletedLeavesTheBlockRenderingNothing(): void
    {
        $formId = $this->createFullForm();
        $page = $this->createPage(self::TEST_PAGE, 'Formulier testpagina');
        $this->placeForm($page, self::TEST_PAGE, $formId);

        $this->forms->delete($formId);
        $this->createdFormIds = array_values(array_diff($this->createdFormIds, [$formId]));

        $response = $this->get('/' . self::TEST_PAGE);

        $this->assertSame(200, $response['status']);
        $this->assertStringNotContainsString('data-form-block', $response['body']);
    }

    /* ------------------------------------------------------------------ */
    /* Assets                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * The whole point of the per-block asset contract: a page without a form
     * downloads no Forms CSS and no Forms JS at all.
     */
    public function testFormAssetsLoadOnlyOnAPageThatActuallyRendersAForm(): void
    {
        $page = $this->createPage(self::TEST_PAGE, 'Formulier testpagina');

        $withoutForm = $this->get('/' . self::TEST_PAGE)['body'];
        $this->assertStringNotContainsString('blocks/form.css', $withoutForm);
        $this->assertStringNotContainsString('blocks/form.js', $withoutForm);

        $formId = $this->createFullForm();
        $this->placeForm($page, self::TEST_PAGE, $formId);

        $withForm = $this->get('/' . self::TEST_PAGE)['body'];
        $this->assertStringContainsString('blocks/form.css', $withForm);
        $this->assertStringContainsString('blocks/form.js', $withForm);
    }

    public function testTwoFormsOnOnePageStillLoadOneCopyOfEachAsset(): void
    {
        $formId = $this->createFullForm();
        $page = $this->createPage(self::TEST_PAGE, 'Formulier testpagina');
        $this->placeForm($page, self::TEST_PAGE, $formId);
        $this->placeForm($page, self::TEST_PAGE, $formId);

        $body = $this->get('/' . self::TEST_PAGE)['body'];

        $this->assertSame(1, substr_count($body, 'blocks/form.css'));
        $this->assertSame(1, substr_count($body, 'blocks/form.js'));
    }

    /* ------------------------------------------------------------------ */
    /* Submitting                                                          */
    /* ------------------------------------------------------------------ */

    public function testAValidSubmissionIsStoredWithTheLabelsItWasSentWith(): void
    {
        $formId = $this->createFullForm(['store_submissions' => true]);
        $page = $this->createPage(self::TEST_PAGE, 'Formulier testpagina');
        $token = $this->placeForm($page, self::TEST_PAGE, $formId);

        $response = $this->post([
            'form-key' => $this->internalKey($formId),
            'form-instance' => $token,
            'form-source' => '/' . self::TEST_PAGE,
            'form-ts' => (string) (time() - 30),
            'naam' => 'Test Bezoeker',
            'e-mail' => 'bezoeker@example.com',
            'telefoon' => '+31 6 12345678',
            'voor-wie' => 'Zakelijk',
            'onderwerp' => 'Vraag',
            'bericht' => "Regel een\nRegel twee",
            'akkoord' => 'Ja',
        ]);

        $this->assertSame(200, $response['status'], $response['body']);
        $this->assertStringContainsString('"ok":true', $response['body']);

        $submissions = (new FormSubmissionRepository())->findAllForAdmin($formId);
        $this->assertCount(1, $submissions);

        $stored = (new FormSubmissionRepository())->valuesFor((int) $submissions[0]['id']);

        $this->assertSame(
            ['naam', 'e-mail', 'telefoon', 'voor-wie', 'onderwerp', 'bericht', 'akkoord'],
            array_column($stored, 'field_key')
        );
        $this->assertSame('Test Bezoeker', $stored[0]['value']);
        $this->assertSame("Regel een\nRegel twee", $stored[5]['value']);
        $this->assertSame('/' . self::TEST_PAGE, $submissions[0]['source_path']);
    }

    public function testAnInvalidSubmissionIsRefusedFieldByFieldAndStoresNothing(): void
    {
        $formId = $this->createFullForm(['store_submissions' => true]);
        $page = $this->createPage(self::TEST_PAGE, 'Formulier testpagina');
        $token = $this->placeForm($page, self::TEST_PAGE, $formId);

        $response = $this->post([
            'form-key' => $this->internalKey($formId),
            'form-instance' => $token,
            'form-ts' => (string) (time() - 30),
            'naam' => '',
            'e-mail' => 'geen-adres',
            'voor-wie' => 'Iets anders',
            'bericht' => 'wel ingevuld',
        ]);

        $this->assertSame(422, $response['status']);

        $body = json_decode($response['body'], true);
        $this->assertFalse($body['ok']);
        $this->assertArrayHasKey('naam', $body['errors']);
        $this->assertArrayHasKey('e-mail', $body['errors']);
        $this->assertArrayHasKey('voor-wie', $body['errors'], 'a choice outside the list is refused');
        $this->assertArrayHasKey('akkoord', $body['errors'], 'a consent box must be ticked');

        // Both languages travel with every message.
        foreach ($body['errors'] as $key => $message) {
            $this->assertNotSame('', $message['nl'], $key);
            $this->assertNotSame('', $message['en'], $key);
        }

        $this->assertSame([], (new FormSubmissionRepository())->findAllForAdmin($formId));
    }

    /**
     * A form that does not store must leave NOTHING behind — the e-mail is
     * the delivery, and the database keeps no copy of what was in it.
     */
    public function testAFormThatDoesNotStoreLeavesNoSubmissionBehind(): void
    {
        $formId = $this->createFullForm(['store_submissions' => false]);
        $page = $this->createPage(self::TEST_PAGE, 'Formulier testpagina');
        $token = $this->placeForm($page, self::TEST_PAGE, $formId);

        $response = $this->post([
            'form-key' => $this->internalKey($formId),
            'form-instance' => $token,
            'form-ts' => (string) (time() - 30),
            'naam' => 'Niet Bewaren',
            'e-mail' => 'nietbewaren@example.com',
            'voor-wie' => 'Particulier',
            'onderwerp' => 'Vraag',
            'bericht' => 'mag nergens blijven staan',
            'akkoord' => 'Ja',
        ]);

        $this->assertSame(200, $response['status'], $response['body']);
        $this->assertSame([], (new FormSubmissionRepository())->findAllForAdmin($formId));

        $count = Database::connection()->prepare(
            "SELECT COUNT(*) FROM form_submission_values WHERE value LIKE :needle"
        );
        $count->execute(['needle' => '%mag nergens blijven staan%']);
        $this->assertSame(0, (int) $count->fetchColumn(), 'no trace of the payload may remain');
    }

    public function testAFilledHoneypotLooksLikeSuccessAndStoresNothing(): void
    {
        $formId = $this->createFullForm(['store_submissions' => true]);
        $page = $this->createPage(self::TEST_PAGE, 'Formulier testpagina');
        $token = $this->placeForm($page, self::TEST_PAGE, $formId);

        $response = $this->post([
            'form-key' => $this->internalKey($formId),
            'form-instance' => $token,
            'form-ts' => (string) (time() - 30),
            'hp-note' => 'ik ben een bot',
            'naam' => 'Bot',
            'e-mail' => 'bot@example.com',
            'voor-wie' => 'Particulier',
            'onderwerp' => 'Vraag',
            'bericht' => 'spam',
            'akkoord' => 'Ja',
        ]);

        $this->assertSame(200, $response['status'], 'a bot is told nothing at all');
        $this->assertSame([], (new FormSubmissionRepository())->findAllForAdmin($formId));
    }

    public function testASubmissionThatArrivesTooFastIsDiscardedJustAsQuietly(): void
    {
        $formId = $this->createFullForm(['store_submissions' => true]);
        $page = $this->createPage(self::TEST_PAGE, 'Formulier testpagina');
        $token = $this->placeForm($page, self::TEST_PAGE, $formId);

        $response = $this->post([
            'form-key' => $this->internalKey($formId),
            'form-instance' => $token,
            'form-ts' => (string) time(),
            'naam' => 'Bot',
            'e-mail' => 'bot@example.com',
            'voor-wie' => 'Particulier',
            'onderwerp' => 'Vraag',
            'bericht' => 'spam',
            'akkoord' => 'Ja',
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertSame([], (new FormSubmissionRepository())->findAllForAdmin($formId));
    }

    /** Posting to a form nobody has heard of says nothing about what exists. */
    public function testAnUnknownFormKeyIsRefusedWithoutConfirmingAnything(): void
    {
        $response = $this->post([
            'form-key' => 'zz-bestaat-niet',
            'form-ts' => (string) (time() - 30),
            'naam' => 'X',
        ]);

        $this->assertSame(400, $response['status']);
        $this->assertStringNotContainsString('bestaat niet', strtolower($response['body']));
    }

    public function testAnInactiveFormRefusesSubmissionsToo(): void
    {
        $formId = $this->createFullForm(['store_submissions' => true, 'is_active' => false]);

        $response = $this->post([
            'form-key' => $this->internalKey($formId),
            'form-ts' => (string) (time() - 30),
            'naam' => 'Iemand',
            'e-mail' => 'iemand@example.com',
            'voor-wie' => 'Particulier',
            'onderwerp' => 'Vraag',
            'bericht' => 'hallo',
            'akkoord' => 'Ja',
        ]);

        // Exactly the answer a form key that does not exist gets: a public
        // endpoint has no business confirming which forms are switched on.
        $this->assertSame(400, $response['status']);
        $this->assertStringNotContainsString('actief', strtolower($response['body']));
        $this->assertSame([], (new FormSubmissionRepository())->findAllForAdmin($formId));
    }

    /**
     * A no-JS POST is answered with a redirect back to the page, never with
     * raw JSON, and never to a host the request named.
     */
    public function testABrowserPostComesBackAsARedirectToTheSameSite(): void
    {
        $formId = $this->createFullForm(['store_submissions' => false]);
        $page = $this->createPage(self::TEST_PAGE, 'Formulier testpagina');
        $token = $this->placeForm($page, self::TEST_PAGE, $formId);

        $response = $this->post([
            'form-key' => $this->internalKey($formId),
            'form-instance' => $token,
            'form-source' => 'https://evil.example.com',
            'form-ts' => (string) (time() - 30),
            'naam' => 'Iemand',
            'e-mail' => 'iemand@example.com',
            'voor-wie' => 'Particulier',
            'onderwerp' => 'Vraag',
            'bericht' => 'hallo',
            'akkoord' => 'Ja',
        ], false);

        $this->assertSame(303, $response['status']);
        $this->assertStringStartsWith('/', $response['location']);
        $this->assertStringNotContainsString('evil.example.com', $response['location']);
        $this->assertStringContainsString('form-status=success', $response['location']);
        $this->assertSame('', trim($response['body']), 'a browser must never be shown raw JSON');
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * A form with one field of every supported type, so a rendering test
     * covers the whole registry rather than a favourite corner of it.
     *
     * @param array<string, mixed> $overrides
     */
    private function createFullForm(array $overrides = []): int
    {
        $id = $this->forms->create($overrides + [
            'name' => 'Testformulier',
            'internal_key' => FormCatalog::internalKeyFor('zz test render', $this->forms),
            'is_active' => true,
            'submit_label_nl' => 'Verstuur dit',
            'submit_label_en' => 'Send this',
            'success_message_nl' => 'Bedankt, het is verstuurd.',
            'success_message_en' => 'Thanks, it has been sent.',
            'notification_email' => 'formulier-test@example.com',
            'reply_to_field_key' => 'e-mail',
            'store_submissions' => false,
        ]);

        $this->createdFormIds[] = $id;

        $fields = [
            ['Naam', 'Name', 'text', true, null, null, null],
            ['E-mail', 'Email', 'email', true, null, null, null],
            ['Telefoon', null, 'tel', false, null, null, null],
            ['Voor wie', 'Who for', 'radio', true, "Particulier|Personal\nZakelijk|Business", null, 'Zakelijk'],
            ['Onderwerp', 'Subject', 'select', true, "Vraag|Question\nKlacht|Complaint", null, null],
            ['Bericht', 'Message', 'textarea', true, null, 'Vertel wat je zoekt.', null],
            ['Akkoord', 'Agree', 'consent', true, null, null, null],
        ];

        foreach ($fields as [$labelNl, $labelEn, $type, $required, $options, $help, $default]) {
            $taken = array_map(
                static fn (array $row): string => (string) $row['field_key'],
                $this->forms->fieldsFor($id)
            );

            $this->forms->createField($id, [
                'field_key' => FormFieldKey::fromLabel($labelNl, $taken),
                'field_type' => $type,
                'label_nl' => $labelNl,
                'label_en' => $labelEn,
                'placeholder_nl' => null,
                'placeholder_en' => null,
                'help_text_nl' => $help,
                'help_text_en' => null,
                'is_required' => $required,
                'options' => $options,
                'default_value' => $default,
            ]);
        }

        FormCatalog::clearCache();

        return $id;
    }

    private function internalKey(int $formId): string
    {
        return (string) $this->forms->find($formId)['internal_key'];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function values(int $formId, array $overrides): array
    {
        $row = $this->forms->find($formId);

        return $overrides + [
            'name' => (string) $row['name'],
            'is_active' => (bool) $row['is_active'],
            'submit_label_nl' => (string) $row['submit_label_nl'],
            'submit_label_en' => (string) ($row['submit_label_en'] ?? ''),
            'success_message_nl' => (string) $row['success_message_nl'],
            'success_message_en' => (string) ($row['success_message_en'] ?? ''),
            'notification_email' => (string) ($row['notification_email'] ?? ''),
            'reply_to_field_key' => (string) ($row['reply_to_field_key'] ?? ''),
            'store_submissions' => (bool) $row['store_submissions'],
        ];
    }

    /** @return array<string, mixed> */
    private function createPage(string $slug, string $title): array
    {
        $pages = new PageRepository();

        \Tests\Support\PageFixture::create([
            'content_key' => $slug,
            'slug' => $slug,
            'status' => 'published',
        ], $title);

        $page = $pages->findByContentKey($slug);
        $this->assertNotNull($page);

        return $page;
    }

    /**
     * Places a "Formulier" block on a page and returns the instance token
     * every DOM id of that placement is prefixed with.
     *
     * @param array<string, mixed> $page
     */
    private function placeForm(array $page, string $slug, ?int $formId): string
    {
        [$sectionId, $sectionKey] = SectionRegistry::create('form', $slug);

        $this->createdPageSectionIds[] = (new PageSectionRepository())->create(
            (int) $page['id'],
            $slug,
            'form',
            $sectionKey,
            $sectionId
        );

        (new FormBlockRepository())->upsertSection($slug, (string) $sectionKey, [
            'form_id' => $formId,
            'is_active' => true,
        ]);

        return FormRenderState::tokenFor($slug, (string) $sectionKey);
    }

    /**
     * @param string $cookie the public session cookie a failed submission
     *                       handed back, when this request needs to read
     *                       what it remembered
     * @return array{status: int, body: string}
     */
    private function get(string $path, string $cookie = ''): array
    {
        $header = ['Accept: text/html'];
        if ($cookie !== '') {
            $header[] = 'Cookie: ' . $cookie;
        }

        $context = stream_context_create([
            'http' => [
                'header' => implode("\r\n", $header),
                'ignore_errors' => true,
                'timeout' => 5,
                'follow_location' => 0,
            ],
        ]);

        $body = @file_get_contents(TestEnvironment::baseUrl() . $path, false, $context);

        return ['status' => $this->statusOf($http_response_header ?? []), 'body' => (string) $body];
    }

    /**
     * @param array<string, string> $fields
     * @return array{status: int, body: string, location: string, cookie: string}
     */
    private function post(array $fields, bool $asJson = true): array
    {
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        if ($asJson) {
            $headers[] = 'Accept: application/json';
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => http_build_query($fields),
                'ignore_errors' => true,
                'timeout' => 10,
                'follow_location' => 0,
            ],
        ]);

        $body = @file_get_contents(TestEnvironment::baseUrl() . '/api/form-submit.php', false, $context);
        $responseHeaders = $http_response_header ?? [];

        $location = '';
        $cookie = '';
        foreach ($responseHeaders as $header) {
            if (stripos($header, 'location:') === 0) {
                $location = trim(substr($header, strlen('location:')));
            }

            // Only the name=value pair; the attributes are for a browser.
            if (stripos($header, 'set-cookie:') === 0) {
                $cookie = trim(explode(';', substr($header, strlen('set-cookie:')), 2)[0]);
            }
        }

        return [
            'status' => $this->statusOf($responseHeaders),
            'body' => (string) $body,
            'location' => $location,
            'cookie' => $cookie,
        ];
    }

    /** @param list<string> $headers */
    private function statusOf(array $headers): int
    {
        $status = 0;
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return $status;
    }

    /**
     * The throttle is keyed on the requesting IP, and every test in this
     * file arrives from the same one — so it is cleared between tests rather
     * than letting the fourth submission of the run fail for the wrong
     * reason.
     */
    private function clearRateLimit(): void
    {
        Database::connection()->exec('DELETE FROM contact_rate_limit_hits');
    }

    private function removePage(string $slug): void
    {
        $pages = new PageRepository();
        $page = $pages->findByContentKey($slug);

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
