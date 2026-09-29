<?php

declare(strict_types=1);

namespace App\Service\Search;

/**
 * One scored search result, as the results page and the live list show it.
 * Plain text and a root-relative URL: the screen escapes every field.
 */
final class SearchHit
{
    public function __construct(
        public readonly string $type,
        public readonly string $typeLabel,
        public readonly string $title,
        public readonly string $excerpt,
        public readonly string $url,
        public readonly ?string $thumbnail,
        public readonly int $score
    ) {
    }

    /**
     * The live list's JSON shape (api/search.php).
     *
     * @return array{type: string, type_label: string, title: string, excerpt: string, url: string, thumbnail: ?string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'type_label' => $this->typeLabel,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'url' => $this->url,
            'thumbnail' => $this->thumbnail,
        ];
    }
}
