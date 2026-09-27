<?php

declare(strict_types=1);

namespace App\Service;

use App\Database;
use App\Repository\ProductSpecificationRepository;
use App\Service\Blocks\EditorRows;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageFallback;
use PDO;

/**
 * Shop → Specificaties (admin/product-specifications.php, Shop Product &
 * Ordering 2.0): the library of reusable product properties, edited as ONE
 * list with ONE save (api/admin/update-product-specifications.php). A
 * property is a row — its name in the language being edited and an optional
 * short unit — and rows go by key: an id, or "new<n>" for one typed on
 * screen (App\Service\Blocks\EditorRows). The order on screen is the order
 * stored; a stored row that is not posted was removed, and takes its value
 * off every product that had it (the screen says how many first).
 *
 * VALIDATE FIRST, THEN WRITE, in one transaction. A name is needed in the
 * default language, and for a new property in the language it is typed in;
 * a translation may be left empty (it falls back). A unit is plain short
 * text, the same in every language.
 */
final class SpecificationLibraryEditor
{
    public const NAME_MAX_LENGTH = 100;
    public const UNIT_MAX_LENGTH = 20;

    /** @var array<string, string>|null */
    private ?array $errors = null;

    /**
     * @param array<int, array{id: int, unit: string, sort_order: int, usage: int}> $stored by id
     * @param list<array{key: string, id: int, name: string, unit: string}> $rows
     */
    private function __construct(
        private readonly string $language,
        private readonly array $stored,
        private readonly array $rows,
        private readonly ProductSpecificationRepository $repository
    ) {
    }

    /** @param array<string, mixed> $post */
    public static function fromRequest(array $post, string $language, ?PDO $db = null): self
    {
        $repository = new ProductSpecificationRepository($db ?? Database::connection());

        $stored = [];
        foreach ($repository->all() as $row) {
            $stored[$row['id']] = $row;
        }

        $rows = [];
        foreach (EditorRows::fromPost($post['specifications'] ?? []) as $row) {
            if ($row['id'] > 0 && !isset($stored[$row['id']])) {
                continue;
            }
            $name = (string) ($row['fields']['name'] ?? '');
            $unit = (string) ($row['fields']['unit'] ?? '');
            if ($row['id'] === 0 && $name === '' && $unit === '') {
                continue;
            }
            $rows[] = ['key' => $row['key'], 'id' => $row['id'], 'name' => $name, 'unit' => $unit];
        }

        return new self($language, $stored, $rows, $repository);
    }

    /** @return array<string, string> messages by field name */
    public function validate(): array
    {
        $errors = [];
        $isDefault = $this->language === LanguageFallback::defaultLanguage();

        foreach ($this->rows as $row) {
            if ($row['name'] === '' && ($isDefault || $row['id'] === 0)) {
                $errors[self::field($row['key'], 'name')] = AdminTranslator::trans('validation.specification_name');
            } elseif (mb_strlen($row['name']) > self::NAME_MAX_LENGTH) {
                $errors[self::field($row['key'], 'name')] = AdminTranslator::trans('validation.specification_name_long', ['max' => (string) self::NAME_MAX_LENGTH]);
            }

            if (mb_strlen($row['unit']) > self::UNIT_MAX_LENGTH || preg_match('/[\x00-\x1F\x7F<>]/u', $row['unit']) === 1) {
                $errors[self::field($row['key'], 'unit')] = AdminTranslator::trans('validation.specification_unit', ['max' => (string) self::UNIT_MAX_LENGTH]);
            }
        }

        return $this->errors = $errors;
    }

    /** Writes the library inside the caller's transaction. */
    public function save(): void
    {
        if ($this->errors !== []) {
            throw new \LogicException('SpecificationLibraryEditor::save() needs a validate() without errors first.');
        }

        $keep = array_column($this->rows, 'id');
        foreach (array_keys($this->stored) as $id) {
            if (!in_array($id, $keep, true)) {
                $this->repository->delete($id);
            }
        }

        foreach (array_values($this->rows) as $position => $row) {
            $unit = $row['unit'] !== '' ? $row['unit'] : null;
            if ($row['id'] > 0) {
                $id = $row['id'];
                $this->repository->update($id, $unit, $position);
            } else {
                $id = $this->repository->create($unit, $position);
            }

            ShopLocalization::saveSpecification($id, $this->language, $row['name']);
        }
    }

    public static function field(string $key, string $name): string
    {
        return 'specifications[' . $key . '][' . $name . ']';
    }
}
