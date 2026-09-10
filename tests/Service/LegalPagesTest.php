<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\LegalPages;
use PHPUnit\Framework\TestCase;

/**
 * Covers the deterministic-hashing requirement from MAIN.MD's Terms &
 * Conditions checkout feature: identical Terms & Conditions content must
 * always hash identically, and any change to that content must change the
 * hash, with no manually maintained version number involved. Only the pure
 * hashing step (LegalPages::hashContent()) is unit-tested here — the
 * database-backed CMS lookup (hashCurrentTerms() -> termsAndConditionsPage()
 * -> PageContent::forContentKey() + the page's Rich text sections) is
 * manually verified against the
 * real dev database, same split as ShippingCalculationServiceTest/
 * PostNlRateParserTest in this project.
 */
final class LegalPagesTest extends TestCase
{
    public function testIdenticalContentAlwaysProducesTheSameHash(): void
    {
        $content = '<p><em>Concept</em></p><h2>1. Identiteit van de ondernemer</h2><p>Test.</p>';

        $this->assertSame(LegalPages::hashContent($content), LegalPages::hashContent($content));
    }

    public function testDifferentContentProducesADifferentHash(): void
    {
        $original = '<h2>6. Herroepingsrecht</h2><p>Origineel.</p>';
        $edited = '<h2>6. Herroepingsrecht</h2><p>Aangepast.</p>';

        $this->assertNotSame(LegalPages::hashContent($original), LegalPages::hashContent($edited));
    }

    public function testHashIsARealSha256HexDigest(): void
    {
        $hash = LegalPages::hashContent('<p>Voorwaarden.</p>');

        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        $this->assertSame(hash('sha256', '<p>Voorwaarden.</p>'), $hash);
    }

    public function testConstantMatchesTheSeededSlug(): void
    {
        // db/migrations/20260908100000_create_pages_table.php
        // seeds the Terms & Conditions page with exactly this slug.
        $this->assertSame('algemene-voorwaarden', LegalPages::TERMS_SLUG);
        $this->assertSame('/algemene-voorwaarden', LegalPages::termsAndConditionsUrl());
    }
}
