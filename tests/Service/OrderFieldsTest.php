<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Mail\OrderConfirmationBuilder;
use App\Repository\OrderFieldRepository;
use App\Repository\OrderItemFieldRepository;
use App\Service\OrderFields\OrderFields;
use App\Service\OrderFields\OrderFieldValidationException;
use App\Service\OrderFields\ProductOrderFieldEditor;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\ShopStockFixture;

/**
 * Order questions per product (Shop Product & Ordering 2.0, MODULES.md
 * "Bestelvelden"), against the real database:
 *
 *   - the editor stores questions of all five types with their choices, in
 *     the order on screen, their words per language, and refuses what cannot
 *     be asked;
 *   - validate() takes only this product's questions: required, optional,
 *     length, a choice of THIS question, a tick box; a forged choice or text
 *     is refused with a message that names the question;
 *   - two different answers are two lines, the same answers one;
 *   - the order keeps a snapshot in the default language that a later change
 *     or removal of the question does not touch;
 *   - both mails print the answers under their line.
 */
final class OrderFieldsTest extends TestCase
{
    private ShopStockFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new ShopStockFixture();
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanUp();
        ShopLocalization::clearCache();
    }

    public function testTheEditorStoresEveryTypeWithItsChoicesInScreenOrderAndPerLanguage(): void
    {
        $product = $this->fixture->product('ZZ Vragen Editor');
        $ids = $this->ask($product);

        $fields = (new OrderFieldRepository())->fieldsForProduct($product);
        self::assertSame(['text', 'textarea', 'radio', 'select', 'checkbox'], array_column($fields, 'field_type'));
        self::assertSame([true, false, true, false, false], array_column($fields, 'is_required'));
        self::assertSame(12, $fields[0]['max_length']);
        self::assertCount(2, $fields[2]['options']);
        self::assertCount(0, $fields[0]['options']);
        self::assertTrue((new OrderFieldRepository())->isEnabled($product));

        // English words for the name question, the Dutch stay.
        $this->save($product, 'en', [
            'order_fields' => [(string) $ids['name'] => ['type' => 'text', 'label' => 'Name on the sign', 'help' => '', 'required' => '1', 'max_length' => '12']],
        ]);
        ShopLocalization::clearCache();
        self::assertSame('Naam op het bord', ShopLocalization::rawOrderField($ids['name'], ShopLocalization::LABEL, 'nl'));
        self::assertSame('Name on the sign', ShopLocalization::rawOrderField($ids['name'], ShopLocalization::LABEL, 'en'));
    }

    public function testRemovingAndReorderingQuestionsAndChoices(): void
    {
        $product = $this->fixture->product('ZZ Vragen Volgorde');
        $ids = $this->ask($product);
        $wood = (new OrderFieldRepository())->fieldsForProduct($product)[2]['options'];

        // The radio question first, its second choice first, the rest gone.
        $this->save($product, 'nl', [
            'order_fields' => [
                (string) $ids['wood'] => ['type' => 'radio', 'label' => 'Houtsoort', 'help' => '', 'required' => '1', 'max_length' => ''],
                (string) $ids['name'] => ['type' => 'text', 'label' => 'Naam op het bord', 'help' => '', 'required' => '1', 'max_length' => '12'],
            ],
            'order_field_options' => [(string) $ids['wood'] => [(string) $wood[1]['id'] => ['label' => 'Noten'], (string) $wood[0]['id'] => ['label' => 'Eiken']]],
        ]);

        $fields = (new OrderFieldRepository())->fieldsForProduct($product);
        self::assertSame([$ids['wood'], $ids['name']], array_column($fields, 'id'));
        self::assertSame([$wood[1]['id'], $wood[0]['id']], array_column($fields[0]['options'], 'id'));
    }

    public function testTheEditorRefusesWhatCannotBeAsked(): void
    {
        $product = $this->fixture->product('ZZ Vragen Fout');
        $editor = ProductOrderFieldEditor::fromRequest([
            'order_fields_present' => '1', 'order_fields_enabled' => '1',
            'order_fields' => [
                'new0' => ['type' => 'upload', 'label' => 'Foto'],
                'new1' => ['type' => 'text', 'label' => '', 'max_length' => '9999'],
                'new2' => ['type' => 'select', 'label' => 'Maat'],
                'new3' => ['type' => 'textarea', 'label' => str_repeat('x', 151)],
            ],
        ], $product, 'nl', Database::connection());

        $errors = $editor->validate();
        self::assertArrayHasKey('order_fields[new0][type]', $errors);
        self::assertArrayHasKey('order_fields[new1][label]', $errors);
        self::assertArrayHasKey('order_fields[new1][max_length]', $errors);
        self::assertArrayHasKey('order_fields[new2][options]', $errors, 'a dropdown needs a choice');
        self::assertArrayHasKey('order_fields[new3][label]', $errors);
    }

    public function testValidateTakesOnlyThisProductsAnswers(): void
    {
        $product = $this->fixture->product('ZZ Vragen Antwoorden');
        $other = $this->fixture->product('ZZ Vragen Ander');
        $ids = $this->ask($product);
        $this->ask($other);
        $wood = (new OrderFieldRepository())->fieldsForProduct($product)[2]['options'];
        $otherWood = (new OrderFieldRepository())->fieldsForProduct($other)[2]['options'];
        $sizes = (new OrderFieldRepository())->fieldsForProduct($product)[3]['options'];
        $service = new OrderFields();

        $answers = $service->validate($product, [
            (string) $ids['name'] => '  Luna ',
            (string) $ids['note'] => "Regel 1\r\nRegel 2\x07",
            (string) $ids['wood'] => (string) $wood[0]['id'],
            (string) $ids['size'] => $sizes[1]['id'],
            (string) $ids['gift'] => '1',
            '999999' => 'onbekende vraag',
        ], 'nl');
        self::assertSame([
            $ids['name'] => 'Luna',
            $ids['note'] => "Regel 1\nRegel 2",
            $ids['wood'] => (string) $wood[0]['id'],
            $ids['size'] => (string) $sizes[1]['id'],
            $ids['gift'] => '1',
        ], $answers, 'an unknown key is not an answer');

        $optionalLeftOut = $service->validate($product, [(string) $ids['name'] => 'Kyra', (string) $ids['wood'] => (string) $wood[1]['id']], 'nl');
        self::assertSame([$ids['name'] => 'Kyra', $ids['wood'] => (string) $wood[1]['id'], $ids['gift'] => '0'], $optionalLeftOut);

        $this->assertRefused($service, $product, [(string) $ids['wood'] => (string) $wood[0]['id']], $ids['name'], 'Vul "Naam op het bord" in.');
        $this->assertRefused($service, $product, [(string) $ids['name'] => str_repeat('x', 13), (string) $ids['wood'] => (string) $wood[0]['id']], $ids['name'], '"Naam op het bord" mag hooguit 12 tekens zijn.');
        $this->assertRefused($service, $product, [(string) $ids['name'] => 'Luna'], $ids['wood'], 'Kies een antwoord bij "Houtsoort".');
        $this->assertRefused($service, $product, [(string) $ids['name'] => 'Luna', (string) $ids['wood'] => (string) $otherWood[0]['id']], $ids['wood'], 'Het antwoord bij "Houtsoort" kan niet worden gekozen. Laad de pagina opnieuw.', 'a choice of another question');
        $this->assertRefused($service, $product, [(string) $ids['name'] => 'Luna', (string) $ids['wood'] => 'Eiken'], $ids['wood'], 'Het antwoord bij "Houtsoort" kan niet worden gekozen. Laad de pagina opnieuw.', 'a label instead of a choice');

        try {
            $service->validate($product, [(string) $ids['wood'] => (string) $wood[0]['id']], 'en');
            self::fail('refused');
        } catch (OrderFieldValidationException $e) {
            self::assertSame('Please fill in "Naam op het bord".', $e->getMessage(), 'in the customer\'s language');
        }
    }

    public function testAProductThatAsksNothingTakesNoAnswers(): void
    {
        $product = $this->fixture->product('ZZ Vragen Uit');
        $this->ask($product);
        (new OrderFieldRepository())->setEnabled($product, false);

        self::assertSame([], (new OrderFields())->questions($product, 'nl'));
        self::assertSame([], (new OrderFields())->validate($product, ['1' => 'x'], 'nl'));
    }

    public function testDifferentAnswersAreDifferentLines(): void
    {
        self::assertSame('', OrderFields::fingerprint([]));
        self::assertNotSame(OrderFields::fingerprint([5 => 'Luna']), OrderFields::fingerprint([5 => 'Kyra']));
        self::assertSame(OrderFields::fingerprint([5 => 'Luna', 6 => '1']), OrderFields::fingerprint([6 => '1', 5 => 'Luna']));
    }

    public function testTheOrderKeepsASnapshotThatALaterChangeDoesNotTouch(): void
    {
        $product = $this->fixture->product('ZZ Vragen Snapshot');
        $ids = $this->ask($product);
        $wood = (new OrderFieldRepository())->fieldsForProduct($product)[2]['options'];
        $service = new OrderFields();

        $answers = $service->validate($product, [(string) $ids['name'] => 'Luna', (string) $ids['wood'] => (string) $wood[1]['id'], (string) $ids['gift'] => '1'], 'en');
        $order = $this->fixture->order([['product_id' => $product, 'quantity' => 1]]);
        $itemId = (int) Database::connection()->query('SELECT id FROM order_items WHERE order_id = ' . $order)->fetchColumn();
        (new OrderItemFieldRepository())->create($itemId, $service->snapshot($product, $answers));

        $expected = [[
            'label' => 'Naam op het bord', 'value' => 'Luna', 'field_type' => 'text', 'upload' => null,
        ], [
            'label' => 'Houtsoort', 'value' => 'Noten', 'field_type' => 'radio', 'upload' => null,
        ], [
            'label' => 'Cadeauverpakking', 'value' => 'Ja', 'field_type' => 'checkbox', 'upload' => null,
        ]];
        self::assertSame([$itemId => $expected], (new OrderItemFieldRepository())->findByOrderIdGrouped($order), 'in the default language, whatever language the customer used');

        // The owner renames the question and removes the radio question.
        $this->save($product, 'nl', [
            'order_fields' => [(string) $ids['name'] => ['type' => 'text', 'label' => 'Tekst op het bord', 'help' => '', 'required' => '1', 'max_length' => '20']],
        ]);
        self::assertSame([$itemId => $expected], (new OrderItemFieldRepository())->findByOrderIdGrouped($order), 'the order says what it said');
    }

    public function testBothMailsPrintTheAnswersUnderTheirLine(): void
    {
        $order = [
            'id' => 1, 'order_number' => 'ORD-2026-000001', 'created_at' => '2026-09-28 10:00:00', 'total' => '25.00',
            'shipping_cost' => '0.00', 'shipping_method' => 'afhalen', 'billing_same_as_shipping' => 1, 'currency' => 'EUR',
        ];
        $customer = ['name' => 'Klant', 'email' => 'klant@example.com', 'phone' => null, 'address_line' => 'Straat 1', 'postal_code' => '1234AB', 'city' => 'Stad', 'country' => 'NL'];
        $items = [[
            'id' => 7, 'name' => 'Naambord', 'variant_label' => null, 'quantity' => 1, 'unit_price' => '25.00',
            'order_fields' => [['label' => 'Naam op het bord', 'value' => 'Luna <3', 'field_type' => 'text'], ['label' => 'Opmerking', 'value' => "Regel 1\nRegel 2", 'field_type' => 'textarea']],
        ]];

        $mails = OrderConfirmationBuilder::build($order, $customer, $items, []);
        foreach (['customer', 'shop'] as $kind) {
            self::assertStringContainsString('Naam op het bord: Luna &lt;3', $mails[$kind]['html'], $kind);
            self::assertStringContainsString('Regel 1<br />' . "\n" . 'Regel 2', $mails[$kind]['html'], $kind);
            self::assertStringContainsString('    Naam op het bord: Luna <3', $mails[$kind]['text'], $kind);
        }
    }

    /* ------------------------------------------------------------------ */

    /**
     * Five questions: a required short text (max 12), an optional long text,
     * a required radio with two choices, an optional dropdown with two, and
     * an optional tick box.
     *
     * @return array{name: int, note: int, wood: int, size: int, gift: int}
     */
    private function ask(int $product): array
    {
        $this->save($product, 'nl', [
            'order_fields' => [
                'new0' => ['type' => 'text', 'label' => 'Naam op het bord', 'help' => 'Zoals het er moet staan', 'required' => '1', 'max_length' => '12'],
                'new1' => ['type' => 'textarea', 'label' => 'Opmerking', 'help' => '', 'required' => '0', 'max_length' => ''],
                'new2' => ['type' => 'radio', 'label' => 'Houtsoort', 'help' => '', 'required' => '1', 'max_length' => ''],
                'new3' => ['type' => 'select', 'label' => 'Maat', 'help' => '', 'required' => '0', 'max_length' => ''],
                'new4' => ['type' => 'checkbox', 'label' => 'Cadeauverpakking', 'help' => '', 'required' => '0', 'max_length' => ''],
            ],
            'order_field_options' => [
                'new2' => ['new0' => ['label' => 'Eiken'], 'new1' => ['label' => 'Noten']],
                'new3' => ['new0' => ['label' => 'Klein'], 'new1' => ['label' => 'Groot']],
            ],
        ]);

        $ids = array_column((new OrderFieldRepository())->fieldsForProduct($product), 'id');

        return ['name' => $ids[0], 'note' => $ids[1], 'wood' => $ids[2], 'size' => $ids[3], 'gift' => $ids[4]];
    }

    /** @param array<string, mixed> $post */
    private function save(int $product, string $language, array $post, bool $assertClean = true): void
    {
        $db = Database::connection();
        $editor = ProductOrderFieldEditor::fromRequest($post + ['order_fields_present' => '1', 'order_fields_enabled' => '1'], $product, $language, $db);
        $errors = $editor->validate();
        if ($assertClean) {
            self::assertSame([], $errors);
        }

        $db->beginTransaction();
        $editor->save();
        $db->commit();
        ShopLocalization::clearCache();
    }

    /** @param array<string, mixed> $submitted */
    private function assertRefused(OrderFields $service, int $product, array $submitted, int $fieldId, string $message, string $why = ''): void
    {
        try {
            $service->validate($product, $submitted, 'nl');
            self::fail('refused: ' . $why);
        } catch (OrderFieldValidationException $e) {
            self::assertSame($fieldId, $e->fieldId, $why);
            self::assertSame($message, $e->getMessage(), $why);
        }
    }
}
