<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Service\Language\AdminTranslator;

/**
 * Where a media item is used, told only as far as one administrator may know:
 * the places they can open, by name; the others, counted.
 *
 * WHY. The library is shared, and so is the answer to "where is this used": a
 * block's usage names the page it is on, a module's usage names a record of
 * its own. Somebody who may manage the library but not the pages must still
 * learn THAT an item is used — it is the reason the item cannot be deleted —
 * but not which page uses it. Every usage names the permission of the screen
 * it is edited on (MediaUsage::$permission), and whoever lacks that permission
 * gets the usage counted, never named: no label and no link.
 *
 * WHAT IS SAID, NEVER WHAT IS DECIDED. Deletion keeps asking the registry for
 * every usage (MediaService::delete(), MediaService::deleteMany()) and never
 * looks here, so nothing in this class can make an item look unused. It sits
 * between that complete answer and whatever reaches a screen or a response
 * body: the item view of admin/media.php and the refusals of
 * api/admin/delete-media-items.php both go through of(), so the rule is
 * written once. The grid and the confirmation dialog only ever say how MANY
 * places use an item, which everybody may know.
 *
 * NOT an access-control framework. The permissions are the ones that already
 * guard those screens, the caller hands in the one question to ask about them
 * (AdminAuth::can(...) in the admin, a fixed account in a test), and nothing
 * is stored.
 */
final class VisibleMediaUsages
{
    /**
     * @param list<MediaUsage> $shown the places the reader may open, in the order they were reported
     */
    private function __construct(
        public readonly array $shown,
        /** How many further places use the item without being named. */
        public readonly int $hidden,
    ) {
    }

    /**
     * @param list<MediaUsage>       $usages every usage of one item, as the registry reported them
     * @param callable(string): bool $can    whether the reader holds a permission
     */
    public static function of(array $usages, callable $can): self
    {
        $shown = [];
        $hidden = 0;

        foreach ($usages as $usage) {
            // Strictly true: anything else a caller's check returns is a "no".
            if ($can($usage->permission) === true) {
                $shown[] = $usage;
            } else {
                $hidden++;
            }
        }

        return new self($shown, $hidden);
    }

    /** Every place that uses the item, named or not. */
    public function count(): int
    {
        return count($this->shown) + $this->hidden;
    }

    /** "2 plekken die je niet kunt openen", or '' when the reader may open every place. */
    public function hiddenPlaces(): string
    {
        if ($this->hidden === 0) {
            return '';
        }

        return $this->hidden === 1
            ? AdminTranslator::trans('media.usage.hidden_one')
            : AdminTranslator::trans('media.usage.hidden', ['count' => $this->hidden]);
    }

    /**
     * Why one item of a selection was kept, in one sentence: the places the
     * reader may open by name, then how many more there are.
     */
    public function keptSentence(string $name): string
    {
        if ($this->count() === 0) {
            // A foreign key knew of a use that no provider reported.
            return AdminTranslator::trans('media.bulk.kept_unknown_use', ['name' => $name]);
        }

        $places = array_map(static fn (MediaUsage $usage): string => $usage->label, $this->shown);

        if ($this->hidden > 0) {
            $places[] = $this->hiddenPlaces();
        }

        return AdminTranslator::trans('media.bulk.kept_used_by', ['name' => $name, 'places' => implode(', ', $places)]);
    }
}
