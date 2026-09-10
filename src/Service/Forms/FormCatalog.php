<?php

declare(strict_types=1);

namespace App\Service\Forms;

use App\Repository\FormRepository;

/**
 * How anything gets hold of a FormDefinition: by id, with a per-request
 * cache in front and a database failure turning into "no such form" instead
 * of an exception.
 *
 * The same contract every `*Content` class in this project has
 * (CONTENT-BLOCKS.md): a lookup that fails is logged once through
 * error_log() and degrades to null, so an unreachable database costs a
 * visitor the form and not the whole page. clearCache() is called by the
 * admin write endpoints right after they change something.
 */
final class FormCatalog
{
    /** @var array<int, FormDefinition|null> */
    private static array $cache = [];

    /**
     * The form with this id, or null when it does not exist (or could not be
     * read). Says nothing about whether it may be SHOWN — that is
     * FormDefinition::isRenderable(), and the caller decides what to do
     * about it.
     */
    public static function find(int $id): ?FormDefinition
    {
        if (array_key_exists($id, self::$cache)) {
            return self::$cache[$id];
        }

        if ($id < 1) {
            return self::$cache[$id] = null;
        }

        try {
            $repository = new FormRepository();
            $row = $repository->find($id);

            if ($row === null) {
                return self::$cache[$id] = null;
            }

            return self::$cache[$id] = FormDefinition::fromRows($row, $repository->fieldsFor($id));
        } catch (\Throwable $e) {
            error_log('[FormCatalog] lookup failed for form #' . $id . ': ' . $e->getMessage());

            return self::$cache[$id] = null;
        }
    }

    /**
     * The form a block points at, ready to render — null when there is no
     * form, it was deleted, it is switched off, or it has no usable fields.
     * One method so every caller fails the same way (FORMS.md, "Een
     * formulier dat niet getoond kan worden").
     */
    public static function renderable(?int $id): ?FormDefinition
    {
        if ($id === null) {
            return null;
        }

        $form = self::find($id);

        return ($form !== null && $form->isRenderable()) ? $form : null;
    }

    /** By its generated key — used by migrations, tests and log lines. */
    public static function findByInternalKey(string $internalKey): ?FormDefinition
    {
        try {
            $repository = new FormRepository();
            $row = $repository->findByInternalKey($internalKey);

            if ($row === null) {
                return null;
            }

            return self::find((int) $row['id']);
        } catch (\Throwable $e) {
            error_log('[FormCatalog] lookup failed for "' . $internalKey . '": ' . $e->getMessage());

            return null;
        }
    }

    /**
     * A stable, unique internal key for a new form, derived from its name.
     * Uniqueness is checked against the database rather than assumed, so two
     * forms called "Contact" get `contact` and `contact-2`.
     */
    public static function internalKeyFor(string $name, ?FormRepository $repository = null): string
    {
        $repository ??= new FormRepository();

        $base = FormFieldKey::fromLabel($name);
        $candidate = $base;
        $suffix = 2;

        while ($repository->internalKeyExists($candidate)) {
            $tail = '-' . $suffix;
            $candidate = substr($base, 0, 100 - strlen($tail)) . $tail;
            $suffix++;
        }

        return $candidate;
    }

    /** Empties the per-request cache; every admin write endpoint calls it. */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
