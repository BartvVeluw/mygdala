<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PageSectionRepository;

/**
 * Central reference point for the one CMS page (see App\Service\PageContent)
 * that other application code — checkout, order audit — needs to identify
 * and link to by *identity*, rather than render generically like pagina.php
 * does for any published slug.
 *
 * The page is identified by its `content_key`, not its public slug: since
 * the unified page model landed, content_key is the page's immutable
 * identity and the slug is an editable URL (see
 * db/migrations/20260908100000_create_pages_table.php). Renaming the Terms &
 * Conditions page's URL in the admin therefore no longer breaks checkout —
 * the link simply follows to the new URL — which is a real improvement over
 * the previous, slug-based version of this class.
 *
 * TERMS_SLUG keeps its original name because it holds exactly what it always
 * did (that page's key), and is referenced from api/checkout.php's error log
 * and tests/Service/LegalPagesTest.php.
 */
class LegalPages
{
    /**
     * The Terms & Conditions page's immutable pages.content_key. Only a
     * migration could ever change it; the admin-editable slug is separate.
     */
    public const TERMS_SLUG = 'algemene-voorwaarden';

    /**
     * The page's CURRENT public URL, resolved per call — so a slug change in
     * the admin is picked up automatically here and in checkout.php's link.
     * Falls back to /<content_key> when the page can't be loaded, which is
     * the URL it has by default.
     */
    public static function termsAndConditionsUrl(): string
    {
        $page = PageContent::forContentKey(self::TERMS_SLUG);

        // In the language the visitor is reading, like every other internal
        // link (docs/multilingual/ROUTING.md). The fallback keeps the shape
        // the page has by default, prefixed for a non-default language.
        return $page !== null
            ? PageContent::publicUrl($page)
            : \App\Service\Routing\LocalizedUrl::path('/' . self::TERMS_SLUG);
    }

    /**
     * The current, published Terms & Conditions page, or null if it doesn't
     * exist, isn't published, or the lookup failed.
     *
     * @return array<string, mixed>|null
     */
    public static function termsAndConditionsPage(): ?array
    {
        $page = PageContent::forContentKey(self::TERMS_SLUG);

        return ($page !== null && PageContent::isPublished($page)) ? $page : null;
    }

    /**
     * The legally relevant text of the current, published Terms &
     * Conditions page: every visible Rich text section on it, in page order,
     * concatenated. With the single rich-text block that page has carried
     * since it was an information page, this is byte-identical to the string
     * this class hashed before the page builder took over — so the SHA-256
     * hashes already stored on existing orders stay valid and comparable.
     *
     * Only Rich text sections count: they are what actually carry the
     * conditions. A hero heading or a CTA band added to the page is
     * presentation around the terms, not the terms themselves, and must not
     * silently change the hash of what customers accepted.
     *
     * Returns null when the page or its content can't be loaded right now.
     */
    public static function termsContent(): ?string
    {
        $page = self::termsAndConditionsPage();
        if ($page === null) {
            return null;
        }

        try {
            $sections = (new PageSectionRepository())->findForPage((int) $page['id'], true);
        } catch (\Throwable $e) {
            error_log('[LegalPages] could not load the terms page sections: ' . $e->getMessage());

            return null;
        }

        $parts = [];
        foreach ($sections as $section) {
            if ((string) $section['section_type'] !== 'rich_text') {
                continue;
            }

            $content = RichTextContent::forSection(
                (string) $section['page_slug'],
                (string) $section['section_key']
            );

            if ($content['state'] === RichTextContent::STATE_HIDDEN) {
                continue;
            }

            // The body a customer sees first when they follow the checkout
            // link: the website's default language, with its fallback, as
            // the page renders it. On a Dutch-default site that is the same
            // string the Dutch column held, so stored hashes stay comparable.
            $parts[] = \App\Service\Language\SiteText::visibleOf($content[RichTextContent::BODY]);
        }

        return $parts === [] ? null : implode('', $parts);
    }

    /**
     * SHA-256 of the current, published Terms & Conditions text — the exact
     * legally relevant content a customer sees when they follow the checkout
     * link. Deterministic: identical stored content always sanitizes to the
     * same string and therefore hashes identically; any CMS edit changes the
     * content and therefore this hash, with no manually maintained version
     * number involved.
     *
     * Returns null if the page/content can't be loaded right now (missing,
     * unpublished, emptied, or a lookup failure) — callers must treat that
     * as a hard stop, never create an order without a hash (see
     * api/checkout.php).
     */
    public static function hashCurrentTerms(): ?string
    {
        $content = self::termsContent();

        return $content === null ? null : self::hashContent($content);
    }

    /**
     * Pure, side-effect-free hashing step — split out from hashCurrentTerms()
     * specifically so it can be unit-tested directly (identical input always
     * produces identical output, different input always produces a different
     * hash) without a database, same "pure logic tested directly, DB-backed
     * lookup verified manually" split as
     * App\Service\Shipping\ShippingCalculationService's pickRate()/
     * determineMethod(). See tests/Service/LegalPagesTest.php.
     */
    public static function hashContent(string $contentHtml): string
    {
        return hash('sha256', $contentHtml);
    }
}
