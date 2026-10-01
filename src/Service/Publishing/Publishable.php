<?php

declare(strict_types=1);

namespace App\Service\Publishing;

/**
 * One kind of content that publishes — a blog post today, an article
 * tomorrow — as its owning module describes it to the Publishing Engine
 * (docs/publishing/ARCHITECTURE.md, "Het providercontract").
 *
 * A module contributes these through ModuleDefinition::publishables(), keyed
 * by type; Publishables is the closed registry Core reads them from. The
 * provider answers for its OWN tables: the engine never queries an owner's
 * table and never knows its columns beyond `status` and `published_at`.
 *
 * WHAT THE OWNER KEEPS. Title, body or content blocks, its own fields, its
 * URL shape and route, its permissions, its taxonomy and its editor. The
 * engine holds the vocabulary, the clock, the visibility rule and the
 * publication rules, and asks the provider everything else.
 */
interface Publishable
{
    /** The closed type key, the same one this module uses in LinkTargets ("blog_post"). */
    public function type(): string;

    /** The module that owns this kind, as ModuleRegistry knows it ("blog"). */
    public function module(): string;

    /** The permission an editor needs to change publication ("blog.manage"). */
    public function permission(): string;

    /**
     * The states this kind offers, a subset of PublicationStatus::ALL that
     * always contains draft.
     *
     * @return list<string>
     */
    public function statuses(): array;

    /**
     * The stored publication facts of one record, whatever its state, or
     * null when there is no such record of THIS kind.
     *
     * @return array{status: string, published_at: ?string}|null
     */
    public function publication(int $id): ?array;

    /**
     * Why this record cannot go out as $status right now, in the editor's
     * words, or [] when it can — the owner's own "can publish" rule (a title,
     * an address, a body). Asked only when $status would make it reachable;
     * a draft can always be saved.
     *
     * @return list<string>
     */
    public function publishErrors(int $id, string $status): array;

    /**
     * Stores a publication change PublicationRules has already allowed —
     * only the status and the moment, in the owner's own table, plus
     * whatever the owner does when its address appears or disappears (a slug
     * redirect, a cache). Never called with a status outside statuses().
     */
    public function savePublication(int $id, string $status, ?string $publishedAt): void;

    /** Where an editor works on this record, root-relative ("/admin/blog-post.php?id=7"). */
    public function adminPath(int $id): string;

    /**
     * Its public address in $language — root-relative, at the owner's own
     * route — or null when it has none there or is not reachable now.
     */
    public function publicPath(int $id, string $language): ?string;

    /**
     * Its address in every language it really has one in, for hreflang and
     * the sitemap: language code => root-relative path. Empty when not
     * reachable.
     *
     * @return array<string, string>
     */
    public function alternates(int $id): array;
}
