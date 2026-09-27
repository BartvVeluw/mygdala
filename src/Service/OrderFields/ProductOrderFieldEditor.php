<?php

declare(strict_types=1);

namespace App\Service\OrderFields;

use App\Database;
use App\Repository\OrderFieldRepository;
use App\Service\Blocks\EditorRows;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageFallback;
use App\Service\ShopLocalization;
use PDO;

/**
 * The Bestelvelden section of the product editor (admin/_product_order_fields.php)
 * as part of the product's ONE save (api/admin/update-product.php), the way
 * App\Service\ProductVariantEditor is for Varianten: "Bestelgegevens vragen",
 * and the questions with their choices exactly as they are on screen.
 *
 * ROWS BY KEY: a stored question or choice by its id, one typed on screen by
 * "new<n>" (App\Service\Blocks\EditorRows). The order posted is the order
 * stored; a stored row that is not posted was removed, but only when the
 * form carried the section (`order_fields_present`). A key naming another
 * product's question, or another question's choice, is dropped.
 *
 * WORDS IN ONE LANGUAGE. Label, help text and a choice's label are written
 * in the one website language the editor is in (ShopLocalization), every
 * other language stays. A question needs a label in the default language,
 * and a NEW question one in the language it is typed in; a translation may
 * be left empty (it falls back).
 *
 * VALIDATE FIRST, THEN WRITE, inside the caller's transaction. Changing or
 * removing a question never touches an order: an order keeps its own
 * snapshot (App\Repository\OrderItemFieldRepository).
 */
final class ProductOrderFieldEditor
{
    public const LABEL_MAX_LENGTH = 150;
    public const HELP_MAX_LENGTH = 500;

    /** @var array<string, string|list<string>>|null */
    private ?array $errors = null;

    /**
     * @param array<int, array<string, mixed>> $stored the product's questions by id (OrderFieldRepository::fieldsForProduct())
     * @param list<array{key: string, id: int, type: string, label: string, help: string, required: bool, max_length: string, options: list<array{key: string, id: int, label: string}>}>|null $fields null: not on the form
     */
    private function __construct(
        private readonly int $productId,
        private readonly string $language,
        private readonly bool $posted,
        private readonly bool $enabled,
        private readonly array $stored,
        private readonly ?array $fields,
        private readonly OrderFieldRepository $repository
    ) {
    }

    /** @param array<string, mixed> $post */
    public static function fromRequest(array $post, int $productId, string $language, ?PDO $db = null): self
    {
        $repository = new OrderFieldRepository($db ?? Database::connection());

        $stored = [];
        foreach ($repository->fieldsForProduct($productId) as $field) {
            $stored[$field['id']] = $field;
        }

        if (!isset($post['order_fields_present'])) {
            return new self($productId, $language, false, false, $stored, null, $repository);
        }

        $postedOptions = is_array($post['order_field_options'] ?? null) ? $post['order_field_options'] : [];
        $fields = [];

        foreach (EditorRows::fromPost($post['order_fields'] ?? []) as $row) {
            if ($row['id'] > 0 && !isset($stored[$row['id']])) {
                continue;
            }

            $storedOptionIds = $row['id'] > 0 ? array_column($stored[$row['id']]['options'], 'id') : [];
            $options = [];
            foreach (EditorRows::fromPost($postedOptions[$row['key']] ?? []) as $optionRow) {
                if ($optionRow['id'] > 0 && !in_array($optionRow['id'], $storedOptionIds, true)) {
                    continue;
                }
                $label = (string) ($optionRow['fields']['label'] ?? '');
                if ($optionRow['id'] === 0 && $label === '') {
                    continue;
                }
                $options[] = ['key' => $optionRow['key'], 'id' => $optionRow['id'], 'label' => $label];
            }

            $fields[] = [
                'key' => $row['key'],
                'id' => $row['id'],
                'type' => (string) ($row['fields']['type'] ?? ''),
                'label' => (string) ($row['fields']['label'] ?? ''),
                'help' => (string) ($row['fields']['help'] ?? ''),
                'required' => ($row['fields']['required'] ?? '') === '1',
                'max_length' => (string) ($row['fields']['max_length'] ?? ''),
                'options' => $options,
            ];
        }

        return new self($productId, $language, true, ($post['order_fields_enabled'] ?? null) === '1', $stored, $fields, $repository);
    }

    /** @return array<string, string|list<string>> messages by field name, or by section ("order_fields") */
    public function validate(): array
    {
        $errors = [];
        $isDefault = $this->language === LanguageFallback::defaultLanguage();

        foreach ($this->fields ?? [] as $field) {
            if (!OrderFieldType::isValid($field['type'])) {
                $errors[self::field($field['key'], 'type')] = AdminTranslator::trans('validation.order_field_type');
                continue;
            }

            $labelNeeded = $isDefault || $field['id'] === 0;
            if ($field['label'] === '' && $labelNeeded) {
                $errors[self::field($field['key'], 'label')] = AdminTranslator::trans('validation.order_field_label');
            } elseif (mb_strlen($field['label']) > self::LABEL_MAX_LENGTH) {
                $errors[self::field($field['key'], 'label')] = AdminTranslator::trans('validation.order_field_label_long', ['max' => (string) self::LABEL_MAX_LENGTH]);
            }

            if (mb_strlen($field['help']) > self::HELP_MAX_LENGTH) {
                $errors[self::field($field['key'], 'help')] = AdminTranslator::trans('validation.order_field_help_long', ['max' => (string) self::HELP_MAX_LENGTH]);
            }

            if (OrderFieldType::isText($field['type']) && $field['max_length'] !== '') {
                $cap = OrderFieldType::CAP[$field['type']];
                if (preg_match('/^\d{1,5}$/', $field['max_length']) !== 1 || (int) $field['max_length'] < 1 || (int) $field['max_length'] > $cap) {
                    $errors[self::field($field['key'], 'max_length')] = AdminTranslator::trans('validation.order_field_max_length', ['max' => (string) $cap]);
                }
            }

            if (OrderFieldType::hasOptions($field['type'])) {
                if ($field['options'] === []) {
                    $errors[self::field($field['key'], 'options')] = AdminTranslator::trans('validation.order_field_options');
                }
                foreach ($field['options'] as $option) {
                    if ($option['label'] === '' && ($isDefault || $option['id'] === 0)) {
                        $errors[self::optionField($field['key'], $option['key'])] = AdminTranslator::trans('validation.order_field_option_label');
                    } elseif (mb_strlen($option['label']) > self::LABEL_MAX_LENGTH) {
                        $errors[self::optionField($field['key'], $option['key'])] = AdminTranslator::trans('validation.order_field_label_long', ['max' => (string) self::LABEL_MAX_LENGTH]);
                    }
                }
            }
        }

        return $this->errors = $errors;
    }

    public function posted(): bool
    {
        return $this->posted;
    }

    /** Writes the section inside the caller's transaction. */
    public function save(): void
    {
        if ($this->errors !== []) {
            throw new \LogicException('ProductOrderFieldEditor::save() needs a validate() without errors first.');
        }
        if (!$this->posted) {
            return;
        }

        $this->repository->setEnabled($this->productId, $this->enabled);

        $keep = array_column($this->fields ?? [], 'id');
        foreach (array_keys($this->stored) as $id) {
            if (!in_array($id, $keep, true)) {
                $this->repository->deleteField($id, $this->productId);
            }
        }

        foreach (array_values($this->fields ?? []) as $position => $field) {
            $maxLength = OrderFieldType::isText($field['type']) && $field['max_length'] !== '' ? (int) $field['max_length'] : null;

            if ($field['id'] > 0) {
                $fieldId = $field['id'];
                $this->repository->updateField($fieldId, $this->productId, $field['type'], $field['required'], $maxLength, $position);
            } else {
                $fieldId = $this->repository->createField($this->productId, $field['type'], $field['required'], $maxLength, $position);
            }

            ShopLocalization::saveOrderField($fieldId, $this->language, [
                ShopLocalization::LABEL => $field['label'],
                ShopLocalization::HELP_TEXT => $field['help'],
            ]);

            $storedOptionIds = $field['id'] > 0 ? array_column($this->stored[$field['id']]['options'], 'id') : [];
            $options = OrderFieldType::hasOptions($field['type']) ? $field['options'] : [];
            $keepOptions = array_column($options, 'id');

            foreach ($storedOptionIds as $optionId) {
                if (!in_array($optionId, $keepOptions, true)) {
                    $this->repository->deleteOption($optionId, $fieldId);
                }
            }

            foreach (array_values($options) as $optionPosition => $option) {
                if ($option['id'] > 0) {
                    $optionId = $option['id'];
                    $this->repository->updateOptionOrder($optionId, $fieldId, $optionPosition);
                } else {
                    $optionId = $this->repository->createOption($fieldId, $optionPosition);
                }

                ShopLocalization::saveOrderFieldOption($optionId, $this->language, $option['label']);
            }
        }
    }

    /** The form field a message about a question is shown under. */
    public static function field(string $key, string $name): string
    {
        return 'order_fields[' . $key . '][' . $name . ']';
    }

    public static function optionField(string $fieldKey, string $optionKey): string
    {
        return 'order_field_options[' . $fieldKey . '][' . $optionKey . '][label]';
    }
}
