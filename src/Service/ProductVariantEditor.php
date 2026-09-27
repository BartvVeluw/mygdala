<?php

declare(strict_types=1);

namespace App\Service;

use App\Database;
use App\Repository\ProductOptionRepository;
use App\Repository\InventoryRepository;
use App\Repository\ProductVariantRepository;
use App\Service\Blocks\EditorRows;
use App\Service\Inventory\InventoryEditor;
use App\Service\Inventory\StockConflictException;
use App\Service\Language\AdminTranslator;
use LogicException;
use PDO;

/**
 * The Varianten section of the product editor as one part of the product's
 * ONE save (admin/_product_variants.php, api/admin/update-product.php): the
 * options with their values, and the variants made of them, exactly as they
 * are on screen when Opslaan is pressed. Nothing in the section is written
 * on its own any more; adding an option is typing a row, not a request.
 *
 * ROW KEYS, NOT POSITIONS. Every row travels under a key: a stored row's id
 * ("12"), or "new<n>" for a row typed on screen (the shape of
 * App\Service\Blocks\EditorRows, whose parser reads the option and value
 * lists here). A new variant names its values by their keys too, so a
 * variant can be made from an option and a value added in the same visit.
 * The order a list is posted in is the order stored. A stored row that is
 * not posted was removed on screen — but only when the form says its list
 * was there at all (`options_present`, `variants_present`), so a request
 * without the section changes nothing in it.
 *
 * VALIDATE FIRST, THEN WRITE. validate() checks everything, the relations
 * between rows included — an option or value still used by a variant that
 * stays, a variant an order points at, two variants with the same values —
 * before anything is written. save() then writes inside the caller's
 * transaction and hands back the id of every variant by its key, for the
 * pictures and descriptions the endpoint stores after it. The screen only
 * hides a button this class would refuse anyway; it decides nothing.
 *
 * WHAT A ROW IS. An option or value typed with nothing in it is no row (the
 * empty row "Optie toevoegen" leaves behind costs nothing). A new variant is
 * always a row: adding one is a decision, so a missing choice is said, not
 * swallowed. The combination of a stored variant never changes here; a
 * different combination is a new variant, as it has always been.
 *
 * ONLY THIS PRODUCT'S ROWS. A key naming another product's option, value or
 * variant is dropped, whatever the request says.
 *
 * A VARIANT'S STOCK (Shop Product & Ordering 2.0) is part of its row: a
 * whole number from 0, written only when the admin changed it and only over
 * the value the screen showed (`stock_seen`), so a sale in between is never
 * undone (App\Service\Inventory\InventoryEditor has the same rule for the
 * product's own stock). A row without the field leaves the stock alone; a new
 * variant without one starts at 0.
 */
final class ProductVariantEditor
{
    /** product_options.name and product_option_values.value are VARCHAR(100). */
    public const TEXT_MAX_LENGTH = 100;

    private const KEY = '/^(?:[1-9][0-9]{0,9}|new[0-9]{1,4})$/';

    private const HEX = '/^#[0-9A-Fa-f]{6}$/';

    private ProductOptionRepository $optionRepository;
    private ProductVariantRepository $variantRepository;
    private InventoryRepository $inventoryRepository;

    /** @var array<string, string|list<string>>|null what validate() found, null before it ran */
    private ?array $errors = null;

    /**
     * @param array<int, array{id: int, name: string, display_type: string, values: array<int, array{id: int, value: string, hex_color: string}>}> $storedOptions by id, in stored order
     * @param array<int, array{id: int, value_ids: list<int>, label: string}> $storedVariants by id, in stored order
     * @param list<array{key: string, id: int, name: string, display_type: string, values: list<array{key: string, id: int, value: string, hex_color: string}>}>|null $options null: the list was not on the form
     * @param list<array{key: string, id: int, price: string, active: bool, stock: ?string, stock_seen: ?int, values: array<string, string>}>|null $variants null: the list was not on the form
     */
    private function __construct(
        private readonly int $productId,
        private readonly array $storedOptions,
        private readonly array $storedVariants,
        private readonly ?array $options,
        private readonly ?array $variants,
        PDO $db
    ) {
        $this->optionRepository = new ProductOptionRepository($db);
        $this->variantRepository = new ProductVariantRepository($db);
        $this->inventoryRepository = new InventoryRepository($db);
    }

    /** @param array<string, mixed> $post the request's $_POST */
    public static function fromRequest(array $post, int $productId, ?PDO $db = null): self
    {
        $db ??= Database::connection();

        $storedOptions = [];
        foreach ((new ProductOptionRepository($db))->findByProductId($productId) as $row) {
            $values = [];
            foreach ($row['values'] as $value) {
                $values[(int) $value['id']] = [
                    'id' => (int) $value['id'],
                    'value' => (string) $value['value'],
                    'hex_color' => (string) ($value['hex_color'] ?? ''),
                ];
            }

            $storedOptions[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'display_type' => (string) $row['display_type'],
                'values' => $values,
            ];
        }

        $storedVariants = [];
        foreach ((new ProductVariantRepository($db))->findByProductId($productId) as $row) {
            $storedVariants[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'value_ids' => array_map(static fn (array $value): int => (int) $value['value_id'], $row['values']),
                'label' => implode(', ', array_map(
                    static fn (array $value): string => $value['option_name'] . ': ' . $value['value'],
                    $row['values']
                )),
            ];
        }

        return new self(
            $productId,
            $storedOptions,
            $storedVariants,
            isset($post['options_present']) ? self::postedOptions($post, $storedOptions) : null,
            isset($post['variants_present']) ? self::postedVariants($post, $storedVariants) : null,
            $db
        );
    }

    /**
     * Everything that keeps this section from being saved, keyed by the name
     * of the field it is about, or by the section ("variants") when it is
     * about rows rather than one field. Empty when it may be saved.
     *
     * @return array<string, string|list<string>>
     */
    public function validate(): array
    {
        $errors = [];

        foreach ($this->options ?? [] as $option) {
            if ($option['name'] === '' || mb_strlen($option['name']) > self::TEXT_MAX_LENGTH) {
                $errors[self::optionField($option['key'], 'name')] = AdminTranslator::trans('validation.option_name_required');
            }

            foreach ($option['values'] as $value) {
                if ($value['value'] === '' || mb_strlen($value['value']) > self::TEXT_MAX_LENGTH) {
                    $errors[self::valueField($option['key'], $value['key'], 'value')] = AdminTranslator::trans('validation.option_value_required');
                }

                if ($value['hex_color'] !== '' && preg_match(self::HEX, $value['hex_color']) !== 1) {
                    $errors[self::valueField($option['key'], $value['key'], 'hex_color')] = AdminTranslator::trans('validation.option_hex_invalid');
                }
            }
        }

        $finalOptions = $this->finalOptions();

        // The value ids of the stored variants that stay, and the combination
        // of every variant after the save, to find a second one with the same.
        $keptValueIds = [];
        $combinations = [];

        foreach ($this->storedVariants as $id => $stored) {
            if ($this->variants !== null && $this->postedVariant($id) === null) {
                if ($this->variantRepository->isReferencedByOrders($id)) {
                    $errors['variants'][] = AdminTranslator::trans('validation.variant_in_orders', ['name' => $stored['label']]);
                }
                continue;
            }

            $keptValueIds = array_merge($keptValueIds, $stored['value_ids']);
            $combinations[] = self::combination(array_map(static fn (int $valueId): string => 'id:' . $valueId, $stored['value_ids']));
        }

        foreach ($this->variants ?? [] as $variant) {
            $price = $variant['price'];
            if ($price !== '') {
                if (!is_numeric($price)) {
                    $errors[self::variantField($variant['key'], 'price')] = AdminTranslator::trans('validation.prijs_override_geldig_bedrag');
                } elseif ((float) $price <= 0 || (float) $price > 99999.99) {
                    $errors[self::variantField($variant['key'], 'price')] = AdminTranslator::trans('validation.prijs_override_groter_0_maximaal');
                }
            }

            if ($variant['stock'] !== null && $variant['stock'] !== '' && !InventoryEditor::isValidStock($variant['stock'])) {
                $errors[self::variantField($variant['key'], 'stock')] = AdminTranslator::trans('validation.stock_invalid');
            }

            if ($variant['id'] > 0) {
                continue;
            }

            if ($finalOptions === []) {
                $errors[self::variantField($variant['key'], 'values')] = AdminTranslator::trans('shop.voeg_eerst_optie_waardes');
                continue;
            }

            $parts = [];
            foreach ($finalOptions as $option) {
                $valueKey = $variant['values'][$option['key']] ?? '';
                $value = $this->valueIn($option, $valueKey);

                if ($value === null) {
                    $errors[self::variantField($variant['key'], 'values') . '[' . $option['key'] . ']'] = AdminTranslator::trans(
                        'validation.choose_option_value',
                        ['v1' => $option['name'] !== '' ? $option['name'] : '…']
                    );
                    continue;
                }

                $parts[] = $value['id'] > 0 ? 'id:' . $value['id'] : 'key:' . $option['key'] . ':' . $value['key'];
            }

            if (count($parts) !== count($finalOptions)) {
                continue;
            }

            $combination = self::combination($parts);
            if (in_array($combination, $combinations, true)) {
                $errors[self::variantField($variant['key'], 'values')] = AdminTranslator::trans('validation.er_bestaat_al_variant_precies');
            }
            $combinations[] = $combination;

            foreach ($parts as $part) {
                if (str_starts_with($part, 'id:')) {
                    $keptValueIds[] = (int) substr($part, 3);
                }
            }
        }

        // An option or value that goes may not be one a staying variant uses.
        if ($this->options !== null) {
            foreach ($this->storedOptions as $optionId => $stored) {
                $posted = $this->postedOption($optionId);
                $keep = $posted === null ? [] : array_column($posted['values'], 'id');

                foreach ($stored['values'] as $valueId => $value) {
                    if (in_array($valueId, $keep, true) || !in_array($valueId, $keptValueIds, true)) {
                        continue;
                    }

                    $errors['variants'][] = $posted === null
                        ? AdminTranslator::trans('validation.option_in_use', ['name' => $stored['name']])
                        : AdminTranslator::trans('validation.option_value_in_use', ['name' => $value['value']]);

                    if ($posted === null) {
                        break;
                    }
                }
            }
        }

        return $this->errors = $errors;
    }

    /**
     * Writes the section, inside the caller's transaction: options and values
     * first (so a new variant can use a new value), then the variants, and
     * the options and values that go last of all, once no variant uses them.
     *
     * @return array<string, int> the id of every variant after the save, by the key it was posted under
     */
    public function save(): array
    {
        if ($this->errors !== []) {
            throw new LogicException('ProductVariantEditor::save() needs a validate() without errors first.');
        }

        $valueIds = [];

        if ($this->options !== null) {
            $optionOrder = [];

            foreach ($this->options as $option) {
                $optionId = $option['id'];
                if ($optionId > 0) {
                    $this->optionRepository->updateOption($optionId, $option['name'], $option['display_type']);
                } else {
                    $optionId = $this->optionRepository->createOption($this->productId, $option['name'], $option['display_type']);
                }
                $optionOrder[] = $optionId;

                $valueOrder = [];
                foreach ($option['values'] as $value) {
                    $hex = $value['hex_color'] === '' ? null : strtoupper($value['hex_color']);
                    $valueId = $value['id'];
                    if ($valueId > 0) {
                        $this->optionRepository->updateValueText($valueId, $value['value'], $hex);
                    } else {
                        $valueId = $this->optionRepository->createValue($optionId, $value['value'], $hex);
                    }
                    $valueOrder[] = $valueId;
                    $valueIds[$option['key']][$value['key']] = $valueId;
                }

                $this->optionRepository->applyValueOrder($optionId, $valueOrder);
            }

            $this->optionRepository->applyOptionOrder($this->productId, $optionOrder);
        } else {
            foreach ($this->finalOptions() as $option) {
                foreach ($option['values'] as $value) {
                    $valueIds[$option['key']][$value['key']] = $value['id'];
                }
            }
        }

        $variantIds = [];

        if ($this->variants !== null) {
            foreach (array_keys($this->storedVariants) as $id) {
                if ($this->postedVariant($id) === null) {
                    $this->variantRepository->delete($id);
                }
            }

            $order = [];
            foreach ($this->variants as $variant) {
                $price = $variant['price'] === '' ? null : (float) $variant['price'];

                if ($variant['id'] > 0) {
                    $variantId = $variant['id'];
                    $this->variantRepository->updatePriceAndActive($variantId, $price, $variant['active']);
                } else {
                    $chosen = [];
                    foreach ($this->finalOptions() as $option) {
                        $chosen[] = $valueIds[$option['key']][$variant['values'][$option['key']]];
                    }
                    $variantId = $this->variantRepository->create($this->productId, $chosen, $price, $variant['active']);
                }

                $this->saveStock($variant, $variantId);

                $order[] = $variantId;
                $variantIds[$variant['key']] = $variantId;
            }

            $this->variantRepository->applyOrder($this->productId, $order);
        } else {
            foreach (array_keys($this->storedVariants) as $id) {
                $variantIds[(string) $id] = $id;
            }
        }

        if ($this->options !== null) {
            foreach ($this->storedOptions as $optionId => $stored) {
                $posted = $this->postedOption($optionId);
                if ($posted === null) {
                    $this->optionRepository->deleteOption($optionId);
                    continue;
                }

                $keep = array_column($posted['values'], 'id');
                foreach (array_keys($stored['values']) as $valueId) {
                    if (!in_array($valueId, $keep, true)) {
                        $this->optionRepository->deleteValue($valueId);
                    }
                }
            }
        }

        return $variantIds;
    }

    /**
     * A variant's stock, when its row carried one: a new variant starts at
     * what was typed; a stored one changes only when the admin changed it,
     * and only over the value the screen showed.
     *
     * @param array{key: string, id: int, stock: ?string, stock_seen: ?int} $variant
     *
     * @throws StockConflictException
     */
    private function saveStock(array $variant, int $variantId): void
    {
        if ($variant['stock'] === null || $variant['stock'] === '') {
            return;
        }

        $stock = (int) $variant['stock'];
        $seen = $variant['id'] > 0 ? $variant['stock_seen'] : null;
        if ($seen !== null && $stock === $seen) {
            return;
        }

        if (!$this->inventoryRepository->setVariantStock($variantId, $this->productId, $stock, $seen)) {
            throw new StockConflictException(
                self::variantField($variant['key'], 'stock'),
                (int) $this->inventoryRepository->variantStock($variantId)
            );
        }
    }

    /** Whether this request carried the section at all. */
    public function posted(): bool
    {
        return $this->options !== null || $this->variants !== null;
    }

    /* ------------------------------------------------------------------ */
    /* The request                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $post
     * @param array<int, array{id: int, name: string, display_type: string, values: array<int, array<string, mixed>>}> $stored
     * @return list<array{key: string, id: int, name: string, display_type: string, values: list<array{key: string, id: int, value: string, hex_color: string}>}>
     */
    private static function postedOptions(array $post, array $stored): array
    {
        $postedValues = is_array($post['option_values'] ?? null) ? $post['option_values'] : [];
        $options = [];

        foreach (EditorRows::fromPost($post['options'] ?? []) as $row) {
            if ($row['id'] > 0 && !isset($stored[$row['id']])) {
                continue;
            }

            $values = [];
            foreach (EditorRows::fromPost($postedValues[$row['key']] ?? []) as $valueRow) {
                // A stored value only under the stored option it belongs to.
                if ($valueRow['id'] > 0 && ($row['id'] === 0 || !isset($stored[$row['id']]['values'][$valueRow['id']]))) {
                    continue;
                }

                $value = [
                    'key' => $valueRow['key'],
                    'id' => $valueRow['id'],
                    'value' => (string) ($valueRow['fields']['value'] ?? ''),
                    'hex_color' => (string) ($valueRow['fields']['hex_color'] ?? ''),
                ];

                if ($value['id'] === 0 && $value['value'] === '' && $value['hex_color'] === '') {
                    continue;
                }

                $values[] = $value;
            }

            $name = (string) ($row['fields']['name'] ?? '');
            if ($row['id'] === 0 && $name === '' && $values === []) {
                continue;
            }

            $options[] = [
                'key' => $row['key'],
                'id' => $row['id'],
                'name' => $name,
                'display_type' => ProductOptionRepository::normalizeDisplayType((string) ($row['fields']['display_type'] ?? 'standard')),
                'values' => $values,
            ];
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $post
     * @param array<int, array<string, mixed>> $stored
     * @return list<array{key: string, id: int, price: string, active: bool, stock: ?string, stock_seen: ?int, values: array<string, string>}>
     */
    private static function postedVariants(array $post, array $stored): array
    {
        $posted = is_array($post['variants'] ?? null) ? $post['variants'] : [];
        $variants = [];

        foreach ($posted as $key => $fields) {
            $key = (string) $key;
            if (preg_match(self::KEY, $key) !== 1 || !is_array($fields)) {
                continue;
            }

            $id = ctype_digit($key) ? (int) $key : 0;
            if ($id > 0 && !isset($stored[$id])) {
                continue;
            }

            // Only a new variant chooses its values.
            $values = [];
            if ($id === 0 && is_array($fields['values'] ?? null)) {
                foreach ($fields['values'] as $optionKey => $valueKey) {
                    if (preg_match(self::KEY, (string) $optionKey) === 1 && is_string($valueKey) && preg_match(self::KEY, $valueKey) === 1) {
                        $values[(string) $optionKey] = $valueKey;
                    }
                }
            }

            $variants[] = [
                'key' => $key,
                'id' => $id,
                'price' => is_string($fields['price'] ?? null) ? trim(str_replace(',', '.', $fields['price'])) : '',
                'active' => ($fields['active'] ?? null) === '1',
                'stock' => is_string($fields['stock'] ?? null) ? trim($fields['stock']) : null,
                'stock_seen' => is_string($fields['stock_seen'] ?? null) && preg_match('/^-?\d{1,9}$/', $fields['stock_seen']) === 1
                    ? (int) $fields['stock_seen']
                    : null,
                'values' => $values,
            ];
        }

        return $variants;
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * The options as they are after the save: what was posted, or what is
     * stored when the list was not on the form.
     *
     * @return list<array{key: string, id: int, name: string, display_type: string, values: list<array{key: string, id: int, value: string, hex_color: string}>}>
     */
    private function finalOptions(): array
    {
        if ($this->options !== null) {
            return $this->options;
        }

        $options = [];
        foreach ($this->storedOptions as $id => $option) {
            $values = [];
            foreach ($option['values'] as $valueId => $value) {
                $values[] = ['key' => (string) $valueId, 'id' => $valueId, 'value' => $value['value'], 'hex_color' => $value['hex_color']];
            }

            $options[] = ['key' => (string) $id, 'id' => $id, 'name' => $option['name'], 'display_type' => $option['display_type'], 'values' => $values];
        }

        return $options;
    }

    /**
     * @param array{values: list<array{key: string, id: int}>} $option
     * @return array{key: string, id: int}|null
     */
    private function valueIn(array $option, string $key): ?array
    {
        foreach ($option['values'] as $value) {
            if ($value['key'] === $key) {
                return $value;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function postedOption(int $id): ?array
    {
        foreach ($this->options ?? [] as $option) {
            if ($option['id'] === $id) {
                return $option;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function postedVariant(int $id): ?array
    {
        foreach ($this->variants ?? [] as $variant) {
            if ($variant['id'] === $id) {
                return $variant;
            }
        }

        return null;
    }

    /** @param list<string> $parts */
    private static function combination(array $parts): string
    {
        sort($parts);

        return implode('|', $parts);
    }

    /** The form field a message about an option is shown under. */
    public static function optionField(string $key, string $field): string
    {
        return 'options[' . $key . '][' . $field . ']';
    }

    public static function valueField(string $optionKey, string $valueKey, string $field): string
    {
        return 'option_values[' . $optionKey . '][' . $valueKey . '][' . $field . ']';
    }

    public static function variantField(string $key, string $field): string
    {
        return 'variants[' . $key . '][' . $field . ']';
    }
}
