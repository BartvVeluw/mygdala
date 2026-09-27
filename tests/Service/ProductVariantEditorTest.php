<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\CustomerRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductOptionRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use App\Service\ProductVariantEditor;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * The Varianten section of the product editor as ONE part of the product's
 * one save (App\Service\ProductVariantEditor): options, values and variants
 * exactly as they are on screen, by row key, validated first and written in
 * the caller's transaction.
 *
 * What these pin down is what used to take a request and a page load per
 * click: an option, its values and a variant made of them in one go; a
 * removal that is only allowed because another removal in the same save
 * frees it; and the refusals that must come before a single row is written.
 *
 * Runs against the test database; every row it makes is its own and is
 * removed again in tearDown().
 */
final class ProductVariantEditorTest extends TestCase
{
    /** @var list<int> */
    private array $productIds = [];

    private ?int $orderId = null;
    private ?int $customerId = null;

    protected function tearDown(): void
    {
        $db = Database::connection();

        if ($this->orderId !== null) {
            $db->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$this->orderId]);
            $db->prepare('DELETE FROM orders WHERE id = ?')->execute([$this->orderId]);
        }
        if ($this->customerId !== null) {
            $db->prepare('DELETE FROM customers WHERE id = ?')->execute([$this->customerId]);
        }
        foreach ($this->productIds as $id) {
            $db->prepare('DELETE FROM product_variants WHERE product_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        }

        $this->productIds = [];
        $this->orderId = null;
        $this->customerId = null;
    }

    public function testAnOptionItsValuesAndAVariantMadeOfThemAreStoredInOneSave(): void
    {
        $productId = $this->product('one-go');

        $editor = ProductVariantEditor::fromRequest([
            'options_present' => '1',
            'options' => ['new0' => ['name' => 'Kleur', 'display_type' => 'color']],
            'option_values' => ['new0' => [
                'new0' => ['value' => 'Noten', 'hex_color' => '#7b4a2a'],
                'new1' => ['value' => 'Berken', 'hex_color' => ''],
            ]],
            'variants_present' => '1',
            'variants' => ['new0' => ['values' => ['new0' => 'new1'], 'price' => '12,50', 'active' => '1']],
        ], $productId);

        $this->assertSame([], $editor->validate());
        $ids = $editor->save();

        $options = (new ProductOptionRepository())->findByProductId($productId);
        $this->assertCount(1, $options);
        $this->assertSame('Kleur', $options[0]['name']);
        $this->assertSame('color', $options[0]['display_type']);
        $this->assertSame(['Noten', 'Berken'], array_column($options[0]['values'], 'value'));
        $this->assertSame('#7B4A2A', $options[0]['values'][0]['hex_color'], 'hex is stored upper-case');
        $this->assertNull($options[0]['values'][1]['hex_color']);

        $variants = (new ProductVariantRepository())->findByProductId($productId);
        $this->assertCount(1, $variants);
        $this->assertSame(['new0' => (int) $variants[0]['id']], $ids, 'the new variant is handed back under its key');
        $this->assertSame('12.50', (string) $variants[0]['price']);
        $this->assertSame([(int) $options[0]['values'][1]['id']], array_map(static fn (array $v): int => (int) $v['value_id'], $variants[0]['values']));
    }

    public function testThePostedOrderIsTheStoredOrderAndStoredRowsAreUpdatedInPlace(): void
    {
        $productId = $this->product('order');
        [$kleur, $noten, $berken] = $this->option($productId, 'Kleur', ['Noten', 'Berken']);
        [$maat, $s] = $this->option($productId, 'Maat', ['S']);
        $a = (new ProductVariantRepository())->create($productId, [$noten, $s], null, true);
        $b = (new ProductVariantRepository())->create($productId, [$berken, $s], null, true);

        $editor = ProductVariantEditor::fromRequest([
            'options_present' => '1',
            // Maat first now, Kleur renamed, its values swapped.
            'options' => [
                (string) $maat => ['name' => 'Maat', 'display_type' => 'standard'],
                (string) $kleur => ['name' => 'Houtsoort', 'display_type' => 'standard'],
            ],
            'option_values' => [
                (string) $maat => [(string) $s => ['value' => 'Small']],
                (string) $kleur => [(string) $berken => ['value' => 'Berken'], (string) $noten => ['value' => 'Noten']],
            ],
            'variants_present' => '1',
            'variants' => [(string) $b => ['price' => '', 'active' => ''], (string) $a => ['price' => '15', 'active' => '1']],
        ], $productId);

        $this->assertSame([], $editor->validate());
        $ids = $editor->save();

        $options = (new ProductOptionRepository())->findByProductId($productId);
        $this->assertSame([$maat, $kleur], array_map(static fn (array $o): int => (int) $o['id'], $options), 'same ids, posted order');
        $this->assertSame('Houtsoort', $options[1]['name']);
        $this->assertSame('Small', $options[0]['values'][0]['value']);
        $this->assertSame([$berken, $noten], array_map(static fn (array $v): int => (int) $v['id'], $options[1]['values']));

        $variants = (new ProductVariantRepository())->findByProductId($productId);
        $this->assertSame([$b, $a], array_map(static fn (array $v): int => (int) $v['id'], $variants));
        $this->assertSame(0, (int) $variants[0]['active']);
        $this->assertSame('15.00', (string) $variants[1]['price']);
        $this->assertSame([(string) $b => $b, (string) $a => $a], $ids);
    }

    /** A value may go when the one variant that used it goes in the same save. */
    public function testAVariantAndTheOptionOnlyItUsedCanBeRemovedTogether(): void
    {
        $productId = $this->product('together');
        [, $noten] = $this->option($productId, 'Kleur', ['Noten']);
        (new ProductVariantRepository())->create($productId, [$noten], null, true);

        $editor = ProductVariantEditor::fromRequest(['options_present' => '1', 'variants_present' => '1'], $productId);

        $this->assertSame([], $editor->validate());
        $this->assertSame([], $editor->save());
        $this->assertSame([], (new ProductOptionRepository())->findByProductId($productId));
        $this->assertSame([], (new ProductVariantRepository())->findByProductId($productId));
    }

    public function testAValueOrOptionAStayingVariantUsesIsRefusedAndNothingIsWritten(): void
    {
        $productId = $this->product('in-use');
        [$kleur, $noten, $berken] = $this->option($productId, 'Kleur', ['Noten', 'Berken']);
        [, $s] = $this->option($productId, 'Maat', ['S']);
        $variant = (new ProductVariantRepository())->create($productId, [$noten, $s], null, true);

        // Noten goes (a variant uses it), Maat goes (the same variant uses S),
        // and Kleur is renamed: nothing of it may be stored.
        $editor = ProductVariantEditor::fromRequest([
            'options_present' => '1',
            'options' => [(string) $kleur => ['name' => 'Hernoemd', 'display_type' => 'standard']],
            'option_values' => [(string) $kleur => [(string) $berken => ['value' => 'Berken']]],
            'variants_present' => '1',
            'variants' => [(string) $variant => ['price' => '', 'active' => '1']],
        ], $productId);

        $errors = $editor->validate();
        $this->assertArrayHasKey('variants', $errors);
        $this->assertCount(2, $errors['variants']);
        $this->assertStringContainsString('Noten', implode(' ', $errors['variants']));
        $this->assertStringContainsString('Maat', implode(' ', $errors['variants']));

        $this->expectException(LogicException::class);
        try {
            $editor->save();
        } finally {
            $options = (new ProductOptionRepository())->findByProductId($productId);
            $this->assertSame('Kleur', $options[0]['name'], 'nothing was written');
            $this->assertCount(2, $options);
        }
    }

    public function testAVariantAnOrderPointsAtCannotBeRemoved(): void
    {
        $productId = $this->product('ordered');
        [, $noten] = $this->option($productId, 'Kleur', ['Noten']);
        $variant = (new ProductVariantRepository())->create($productId, [$noten], null, true);
        $this->orderFor($productId, $variant);

        $errors = ProductVariantEditor::fromRequest(['variants_present' => '1'], $productId)->validate();

        $this->assertArrayHasKey('variants', $errors);
        $this->assertStringContainsString('Kleur: Noten', $errors['variants'][0]);
        $this->assertSame([$variant], (new ProductVariantRepository())->idsInOrders($productId));
    }

    public function testANewVariantNeedsAValueOfEveryOptionAndADifferentCombination(): void
    {
        $productId = $this->product('choices');
        [$kleur, $noten] = $this->option($productId, 'Kleur', ['Noten']);
        [$maat, $s] = $this->option($productId, 'Maat', ['S']);
        $existing = (new ProductVariantRepository())->create($productId, [$noten, $s], null, true);

        $errors = ProductVariantEditor::fromRequest([
            'variants_present' => '1',
            'variants' => [
                (string) $existing => ['price' => '', 'active' => '1'],
                'new0' => ['values' => [(string) $kleur => (string) $noten], 'price' => '', 'active' => '1'],
                'new1' => ['values' => [(string) $kleur => (string) $noten, (string) $maat => (string) $s], 'price' => 'veel', 'active' => '1'],
            ],
        ], $productId)->validate();

        $this->assertArrayHasKey('variants[new0][values][' . $maat . ']', $errors, 'the missing choice, under its own select');
        $this->assertArrayHasKey('variants[new1][values]', $errors, 'the same combination as the stored variant');
        $this->assertArrayHasKey('variants[new1][price]', $errors);
    }

    public function testEmptyNewRowsAreNoRowsAndAnotherProductsRowsAreIgnored(): void
    {
        $productId = $this->product('empty');
        $other = $this->product('other');
        [$foreignOption, $foreignValue] = $this->option($other, 'Vreemd', ['X']);

        $editor = ProductVariantEditor::fromRequest([
            'options_present' => '1',
            'options' => [
                'new0' => ['name' => '', 'display_type' => 'standard'],
                'new1' => ['name' => 'Maat', 'display_type' => 'nonsense'],
                (string) $foreignOption => ['name' => 'Overgenomen', 'display_type' => 'standard'],
            ],
            'option_values' => [
                'new0' => [],
                'new1' => ['new0' => ['value' => '', 'hex_color' => ''], 'new1' => ['value' => 'L'], (string) $foreignValue => ['value' => 'Gestolen']],
            ],
        ], $productId);

        $this->assertSame([], $editor->validate());
        $editor->save();

        $options = (new ProductOptionRepository())->findByProductId($productId);
        $this->assertSame(['Maat'], array_column($options, 'name'));
        $this->assertSame('standard', $options[0]['display_type'], 'an unknown display type falls back');
        $this->assertSame(['L'], array_column($options[0]['values'], 'value'));

        $foreign = (new ProductOptionRepository())->findByProductId($other);
        $this->assertSame('Vreemd', $foreign[0]['name'], "another product's option is never touched");
        $this->assertSame('X', $foreign[0]['values'][0]['value']);
    }

    public function testTextsAreCheckedAndNamedByTheirFields(): void
    {
        $productId = $this->product('texts');
        [$kleur, $noten] = $this->option($productId, 'Kleur', ['Noten']);

        $errors = ProductVariantEditor::fromRequest([
            'options_present' => '1',
            'options' => [(string) $kleur => ['name' => '', 'display_type' => 'color'], 'new0' => ['name' => str_repeat('x', 101)]],
            'option_values' => [
                (string) $kleur => [(string) $noten => ['value' => '', 'hex_color' => 'bruin']],
                'new0' => ['new0' => ['value' => 'Ok']],
            ],
        ], $productId)->validate();

        $this->assertSame([
            'options[' . $kleur . '][name]',
            'option_values[' . $kleur . '][' . $noten . '][value]',
            'option_values[' . $kleur . '][' . $noten . '][hex_color]',
            'options[new0][name]',
        ], array_keys($errors));
    }

    /** A request without the section changes nothing in it, and still names every variant. */
    public function testASaveWithoutTheSectionLeavesItAlone(): void
    {
        $productId = $this->product('absent');
        [, $noten] = $this->option($productId, 'Kleur', ['Noten']);
        $variant = (new ProductVariantRepository())->create($productId, [$noten], null, true);

        $editor = ProductVariantEditor::fromRequest([], $productId);
        $this->assertSame([], $editor->validate());
        $this->assertSame([(string) $variant => $variant], $editor->save());
        $this->assertCount(1, (new ProductOptionRepository())->findByProductId($productId));
        $this->assertCount(1, (new ProductVariantRepository())->findByProductId($productId));
    }

    /** Everything goes through the caller's connection, so its transaction holds all of it. */
    public function testARolledBackSaveLeavesNoRowBehind(): void
    {
        $productId = $this->product('rollback');
        $db = Database::connection();

        $editor = ProductVariantEditor::fromRequest([
            'options_present' => '1',
            'options' => ['new0' => ['name' => 'Kleur']],
            'option_values' => ['new0' => ['new0' => ['value' => 'Noten']]],
            'variants_present' => '1',
            'variants' => ['new0' => ['values' => ['new0' => 'new0'], 'active' => '1']],
        ], $productId, $db);
        $this->assertSame([], $editor->validate());

        $db->beginTransaction();
        $editor->save();
        $this->assertCount(1, (new ProductVariantRepository($db))->findByProductId($productId), 'written inside the transaction');
        $db->rollBack();

        $this->assertSame([], (new ProductOptionRepository())->findByProductId($productId));
        $this->assertSame([], (new ProductVariantRepository())->findByProductId($productId));
    }

    public function testSavingWithoutValidatingFirstIsAProgrammingError(): void
    {
        $this->expectException(LogicException::class);
        ProductVariantEditor::fromRequest(['options_present' => '1'], $this->product('unvalidated'))->save();
    }

    private function product(string $suffix): int
    {
        $id = (new ProductRepository())->create([
            'slug' => '__test_variant_editor_' . $suffix . '__',
            'price' => 10.00,
            'image_path' => null,
            'active' => true,
            'in_shop' => true,
            'in_personalization_catalog' => false,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 25,
            'requires_parcel' => false,
        ]);
        $this->productIds[] = $id;

        return $id;
    }

    /**
     * @param list<string> $values
     * @return list<int> the option id, then the value ids
     */
    private function option(int $productId, string $name, array $values): array
    {
        $repository = new ProductOptionRepository();
        $optionId = $repository->createOption($productId, $name);
        $ids = [$optionId];
        foreach ($values as $value) {
            $ids[] = $repository->createValue($optionId, $value);
        }

        return $ids;
    }

    private function orderFor(int $productId, int $variantId): void
    {
        $this->customerId = (new CustomerRepository())->findOrCreateByEmail([
            'name' => 'Variant Editor Test',
            'email' => 'variant-editor-test@example.test',
            'phone' => null,
            'address_line' => 'Teststraat 1',
            'postal_code' => '1234AB',
            'city' => 'Teststad',
            'country' => 'NL',
        ]);

        $orders = new OrderRepository();
        $this->orderId = $orders->create(
            $this->customerId,
            10.00,
            0.00,
            'afhalen',
            'EUR',
            true,
            new \DateTimeImmutable(),
            hash('sha256', 'test-terms'),
            [
                'first_name' => 'Variant', 'last_name' => 'Test', 'company' => null,
                'country' => 'NL', 'postal_code' => '1234AB', 'house_number' => '1',
                'house_number_addition' => null, 'street' => 'Teststraat', 'city' => 'Teststad',
            ],
            null
        );
        $orders->addItems($this->orderId, [[
            'product_id' => $productId,
            'variant_id' => $variantId,
            'variant_label' => 'Kleur: Noten',
            'quantity' => 1,
            'unit_price' => 10.00,
            'product_name' => 'Testproduct',
        ]]);
    }
}
