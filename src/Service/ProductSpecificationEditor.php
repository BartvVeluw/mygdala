<?php

declare(strict_types=1);

namespace App\Service;

use App\Database;
use App\Repository\ProductSpecificationRepository;
use App\Service\Blocks\EditorRows;
use App\Service\Language\AdminTranslator;
use PDO;

/**
 * The Specificaties section of the product editor (admin/_product_specifications.php)
 * as part of the product's ONE save (api/admin/update-product.php): which
 * properties from the library the product has, in which order, and their
 * value in the language being edited.
 *
 * A row is one property on this product, by key: the value row's id, or
 * "new<n>" for one added on screen, which names its property from the
 * library. A stored row keeps its property (another property is another
 * row). The order posted is the order stored; a stored row that is not
 * posted was removed — only when the form carried the section
 * (`specifications_present`). A property may appear once; a key or property
 * that is not this product's or not in the library is dropped or refused.
 * A new row without a value is not a row; a value is plain text.
 */
final class ProductSpecificationEditor
{
    public const VALUE_MAX_LENGTH = 255;

    /** @var array<string, string>|null */
    private ?array $errors = null;

    /**
     * @param array<int, array{id: int, specification_id: int}> $stored the product's value rows by id
     * @param array<int, true> $library the ids of the properties in the library
     * @param list<array{key: string, id: int, specification_id: int, value: string}>|null $rows null: not on the form
     */
    private function __construct(
        private readonly int $productId,
        private readonly string $language,
        private readonly array $stored,
        private readonly array $library,
        private readonly ?array $rows,
        private readonly ProductSpecificationRepository $repository
    ) {
    }

    /** @param array<string, mixed> $post */
    public static function fromRequest(array $post, int $productId, string $language, ?PDO $db = null): self
    {
        $repository = new ProductSpecificationRepository($db ?? Database::connection());

        $stored = [];
        foreach ($repository->valuesForProduct($productId) as $row) {
            $stored[$row['id']] = $row;
        }

        $library = [];
        foreach ($repository->all() as $specification) {
            $library[$specification['id']] = true;
        }

        if (!isset($post['specifications_present'])) {
            return new self($productId, $language, $stored, $library, null, $repository);
        }

        $rows = [];
        foreach (EditorRows::fromPost($post['product_specifications'] ?? []) as $row) {
            if ($row['id'] > 0 && !isset($stored[$row['id']])) {
                continue;
            }

            $specificationId = $row['id'] > 0
                ? $stored[$row['id']]['specification_id']
                : (ctype_digit((string) ($row['fields']['specification_id'] ?? '')) ? (int) $row['fields']['specification_id'] : 0);
            $value = (string) ($row['fields']['value'] ?? '');

            if ($row['id'] === 0 && $value === '' && $specificationId === 0) {
                continue;
            }

            $rows[] = ['key' => $row['key'], 'id' => $row['id'], 'specification_id' => $specificationId, 'value' => $value];
        }

        return new self($productId, $language, $stored, $library, $rows, $repository);
    }

    /** @return array<string, string> messages by field name */
    public function validate(): array
    {
        $errors = [];
        $seen = [];

        foreach ($this->rows ?? [] as $row) {
            if ($row['id'] === 0 && !isset($this->library[$row['specification_id']])) {
                $errors[self::field($row['key'], 'specification_id')] = AdminTranslator::trans('validation.product_specification_choose');
                continue;
            }
            if (isset($seen[$row['specification_id']])) {
                $errors[self::field($row['key'], 'specification_id')] = AdminTranslator::trans('validation.product_specification_twice');
            }
            $seen[$row['specification_id']] = true;

            if (mb_strlen($row['value']) > self::VALUE_MAX_LENGTH || preg_match('/[\x00-\x1F\x7F]/u', $row['value']) === 1) {
                $errors[self::field($row['key'], 'value')] = AdminTranslator::trans('validation.product_specification_value', ['max' => (string) self::VALUE_MAX_LENGTH]);
            }
        }

        return $this->errors = $errors;
    }

    /** Writes the section inside the caller's transaction. */
    public function save(): void
    {
        if ($this->errors !== []) {
            throw new \LogicException('ProductSpecificationEditor::save() needs a validate() without errors first.');
        }
        if ($this->rows === null) {
            return;
        }

        $keep = array_column($this->rows, 'id');
        foreach (array_keys($this->stored) as $id) {
            if (!in_array($id, $keep, true)) {
                $this->repository->deleteValue($id, $this->productId);
            }
        }

        foreach (array_values($this->rows) as $position => $row) {
            if ($row['id'] > 0) {
                $id = $row['id'];
                $this->repository->updateValueOrder($id, $this->productId, $position);
            } else {
                $id = $this->repository->createValue($this->productId, $row['specification_id'], $position);
            }

            ShopLocalization::saveSpecificationValue($id, $this->language, $row['value']);
        }
    }

    public static function field(string $key, string $name): string
    {
        return 'product_specifications[' . $key . '][' . $name . ']';
    }
}
