<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Service\Language\EntityTranslations;

/**
 * The prefilter the module providers share: the owners whose words may
 * match a query, read with the escaped, prepared LIKE of
 * App\Repository\EntityTranslationRepository::ownersMatching() (any
 * language; the collation is case- and accent-insensitive).
 *
 * A query of one term: the owners with that term in any of the fields, as
 * before. More terms (SearchQuery::terms()): the owners that have EVERY term
 * in at least one of the fields — a term may sit in the title and the next
 * in the description. The phrase needs no query of its own: an owner with
 * the whole phrase has each of its terms too. SearchText::score() then
 * decides on the words in the language asked for.
 *
 * $alsoMatching is one more source per term (Search 2.0: the owners whose
 * content blocks have it, BlockSearchIndex::ownersMatching()), counted like
 * one more field.
 *
 * Cost: one LIKE per field and term — a fixed number, at most
 * SearchQuery::MAX_TERMS × (fields + 1), whatever the number of results.
 */
final class SearchCandidates
{
    /**
     * @param list<string> $fields
     * @param (\Closure(string): list<int>)|null $alsoMatching owner ids that have this term elsewhere
     * @return list<int>
     */
    public static function ids(EntityTranslations $translations, array $fields, SearchQuery $query, ?\Closure $alsoMatching = null): array
    {
        // The terms; for a query of short words only ("a b"), the phrase.
        $ids = null;
        foreach ($query->needles() as $term) {
            $owners = [];
            foreach ($fields as $field) {
                foreach ($translations->ownersMatching($field, $term) as $id) {
                    $owners[$id] = true;
                }
            }

            if ($alsoMatching !== null) {
                foreach ($alsoMatching($term) as $id) {
                    $owners[(int) $id] = true;
                }
            }

            $ids = $ids === null ? $owners : array_intersect_key($ids, $owners);
            if ($ids === []) {
                return [];
            }
        }

        return array_keys($ids ?? []);
    }
}
