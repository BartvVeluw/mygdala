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
 * Cost: one LIKE per field and term — a fixed number, at most
 * SearchQuery::MAX_TERMS × fields, whatever the number of results.
 */
final class SearchCandidates
{
    /**
     * @param list<string> $fields
     * @return list<int>
     */
    public static function ids(EntityTranslations $translations, array $fields, SearchQuery $query): array
    {
        $terms = $query->terms();
        if ($terms === []) {
            // A query of short words only ("a b"): the phrase as one needle.
            $terms = [$query->text];
        }

        $ids = null;
        foreach ($terms as $term) {
            $owners = [];
            foreach ($fields as $field) {
                foreach ($translations->ownersMatching($field, $term) as $id) {
                    $owners[$id] = true;
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
