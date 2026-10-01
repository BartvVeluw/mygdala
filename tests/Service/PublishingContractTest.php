<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Service\Publishing\Publishable;
use App\Service\Publishing\Publishables;
use App\Service\Publishing\PublicationRules;
use App\Service\Publishing\PublicationStatus;
use App\Service\Publishing\PublicationVisibility;
use App\Service\Publishing\PublishingClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Publishing Engine's contract without a database (docs/publishing/ARCHITECTURE.md): the
 * closed status list, the visibility rule in PHP and its SQL twin, the clock
 * and its timezone contract around midnight and daylight saving, the rules a
 * save must pass, and the registry that only knows enabled kinds.
 *
 * "Now" is always pinned (PublishingClock::freezeForTests()): no assertion
 * here depends on real seconds passing.
 */
final class PublishingContractTest extends TestCase
{
    private string $zone = 'UTC';

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        PublishingClock::freezeForTests(null);
        date_default_timezone_set($this->zone);
        ModuleRegistry::overrideForTests(null);
    }

    /* ------------------------------------------------------------------ */
    /* Status                                                              */
    /* ------------------------------------------------------------------ */

    public function testTheStatusesAreAClosedListOfFour(): void
    {
        $this->assertSame(['draft', 'published', 'scheduled', 'archived'], PublicationStatus::ALL);
        $this->assertSame(['published', 'scheduled'], PublicationStatus::LISTED);
        $this->assertSame(['published', 'scheduled', 'archived'], PublicationStatus::REACHABLE);

        foreach (PublicationStatus::ALL as $status) {
            $this->assertTrue(PublicationStatus::isValid($status));
            $this->assertSame($status, PublicationStatus::normalize(strtoupper($status) . ' '));
        }

        foreach (['', 'live', 'Published!', 'deleted', null, 1, ['published']] as $forged) {
            $this->assertFalse(PublicationStatus::isValid($forged));
            $this->assertSame('draft', PublicationStatus::normalize($forged), 'anything unknown reads as a draft');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Visibility                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * @return iterable<string, array{0: string, 1: ?string, 2: bool, 3: bool, 4: bool}>
     *         status, published_at, listed, reachable, pending — at 2026-10-01 12:00:00
     */
    public static function visibility(): iterable
    {
        yield 'published yesterday' => ['published', '2026-09-30 12:00:00', true, true, false];
        yield 'published exactly now' => ['published', '2026-10-01 12:00:00', true, true, false];
        yield 'published, date one second ahead' => ['published', '2026-10-01 12:00:01', false, false, true];
        yield 'published without a date' => ['published', null, false, false, true];
        yield 'scheduled in one minute' => ['scheduled', '2026-10-01 12:01:00', false, false, true];
        yield 'scheduled one minute ago' => ['scheduled', '2026-10-01 11:59:00', true, true, false];
        yield 'draft with a past date' => ['draft', '2026-01-01 00:00:00', false, false, false];
        yield 'draft without a date' => ['draft', null, false, false, false];
        yield 'archived, gone out before' => ['archived', '2026-09-01 08:00:00', false, true, false];
        yield 'archived without a date' => ['archived', null, false, false, true];
        yield 'an unknown status' => ['live', '2026-09-01 08:00:00', false, false, false];
        yield 'the zero date' => ['published', '0000-00-00 00:00:00', false, false, true];
        yield 'an unparseable date' => ['published', 'soon', false, false, true];
    }

    #[DataProvider('visibility')]
    public function testListedReachableAndPendingAreOneRule(string $status, ?string $at, bool $listed, bool $reachable, bool $pending): void
    {
        PublishingClock::freezeForTests(new \DateTimeImmutable('2026-10-01 12:00:00'));

        $this->assertSame($listed, PublicationVisibility::isListed($status, $at), 'listed');
        $this->assertSame($reachable, PublicationVisibility::isReachable($status, $at), 'reachable');
        $this->assertSame($pending, PublicationVisibility::isPending($status, $at), 'pending');
    }

    public function testTheSqlTwinNamesTheSameStatesAndBindsNow(): void
    {
        $this->assertSame(
            "p.status IN ('published', 'scheduled') AND p.published_at IS NOT NULL AND p.published_at <= :now",
            PublicationVisibility::listedSql('p')
        );
        $this->assertSame(
            "posts.status IN ('published', 'scheduled', 'archived') AND posts.published_at IS NOT NULL AND posts.published_at <= :moment",
            PublicationVisibility::reachableSql('posts', 'moment')
        );
        $this->assertStringNotContainsString('NOW()', PublicationVisibility::listedSql('p'), 'PHP\'s clock, never MySQL\'s');

        foreach (['p; DROP TABLE x', 'P', '1p', 'p.q', ''] as $alias) {
            try {
                PublicationVisibility::listedSql($alias);
                $this->fail('accepted alias ' . $alias);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * The engine is Core: it reaches a kind only through Publishables, never
     * by name. Comments may say "a blog post"; code may not.
     */
    public function testTheEngineNamesNoKindInCode(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/src/Service/Publishing/*.php') ?: [],
            [$root . '/admin/_publication_fields.php', $root . '/api/admin/update-publication.php']
        );
        $this->assertGreaterThanOrEqual(8, count($files));

        foreach ($files as $file) {
            $code = '';
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }

            $this->assertDoesNotMatchRegularExpression('/blog|article/i', $code, basename($file) . ' names a kind');
        }
    }

    public function testNoSourceWritesItsOwnDraftExclusion(): void
    {
        $root = dirname(__DIR__, 2);
        $repository = (string) file_get_contents($root . '/src/Repository/BlogPostRepository.php');

        $this->assertStringContainsString('PublicationVisibility::listedSql(', $repository);
        $this->assertStringNotContainsString("status <> '", $repository, 'the Blog lists through the shared rule');
        $this->assertStringContainsString('PublicationVisibility::isListed(', (string) file_get_contents($root . '/src/Service/Blog/BlogPostStatus.php'));
    }

    /* ------------------------------------------------------------------ */
    /* The clock                                                           */
    /* ------------------------------------------------------------------ */

    public function testTypedInputIsStrict(): void
    {
        $this->assertSame('2026-12-24 18:30:00', PublishingClock::fromInput('2026-12-24T18:30'));
        $this->assertSame('2026-12-24 18:30:15', PublishingClock::fromInput('2026-12-24T18:30:15'));
        $this->assertSame('2026-12-24 18:30:00', PublishingClock::fromInput(' 2026-12-24 18:30 '));
        $this->assertNull(PublishingClock::fromInput(''), 'empty is "no date", not an error');
        $this->assertNull(PublishingClock::fromInput(null));

        foreach (['tomorrow', '+1 day', '2026-02-30T10:00', '2026-13-01T10:00', '2026-12-24', '24-12-2026 18:30', '2026-12-24T25:00', '2026-12-24T18:30+02:00', '<script>', '2026-12-24T18:30x'] as $invalid) {
            $this->assertFalse(PublishingClock::fromInput($invalid), $invalid);
        }
        $this->assertFalse(PublishingClock::fromInput(['2026-12-24T18:30']));
        $this->assertFalse(PublishingClock::fromInput(20261224));
    }

    public function testAroundMidnightTheConfiguredZoneDecides(): void
    {
        date_default_timezone_set('Europe/Amsterdam');

        // 23:30 UTC on 30 September is 01:30 on 1 October in Amsterdam.
        PublishingClock::freezeForTests(new \DateTimeImmutable('2026-09-30 23:30:00', new \DateTimeZone('UTC')));

        $this->assertSame('2026-10-01 01:30:00', PublishingClock::nowForSql(), 'now is read in the configured zone, whatever zone a moment came in');
        $this->assertTrue(PublicationVisibility::isListed('scheduled', '2026-10-01 00:00:00'), 'midnight local has passed');
        $this->assertFalse(PublicationVisibility::isListed('scheduled', '2026-10-01 02:00:00'));
        $this->assertSame('01-10-2026 00:00', PublishingClock::forAdmin('2026-10-01 00:00:00'));
    }

    public function testDaylightSavingFollowsPhpsRulesTheSameWayBothWays(): void
    {
        date_default_timezone_set('Europe/Amsterdam');

        // The autumn hour happens twice: PHP reads a typed 02:30 as the SECOND
        // one (CET, 01:30 UTC), when writing and when reading back alike.
        $typed = PublishingClock::fromInput('2026-10-25T02:30');
        $this->assertSame('2026-10-25 02:30:00', $typed);
        PublishingClock::freezeForTests(new \DateTimeImmutable('2026-10-25 01:29:59', new \DateTimeZone('UTC')));
        $this->assertFalse(PublicationVisibility::isListed('scheduled', $typed), 'one second before 02:30 CET');
        PublishingClock::freezeForTests(new \DateTimeImmutable('2026-10-25 01:30:00', new \DateTimeZone('UTC')));
        $this->assertTrue(PublicationVisibility::isListed('scheduled', $typed), 'at 02:30 CET');

        // The spring hour does not exist: 02:30 moves forward by the gap.
        $this->assertSame('2026-03-29 03:30:00', PublishingClock::fromInput('2026-03-29T02:30'));
    }

    /* ------------------------------------------------------------------ */
    /* The rules                                                           */
    /* ------------------------------------------------------------------ */

    public function testTheRulesRefuseWhatCannotBeSavedAndAllowTheRest(): void
    {
        $offered = ['draft', 'published', 'scheduled'];

        $this->assertSame([], PublicationRules::validate('draft', '', $offered));
        $this->assertSame([], PublicationRules::validate('published', '', $offered));
        $this->assertSame([], PublicationRules::validate('scheduled', '2026-12-24T18:30', $offered));

        $this->assertCount(1, PublicationRules::validate('bogus', '', $offered), 'an unknown status');
        $this->assertCount(1, PublicationRules::validate(['published'], '', $offered), 'not even a string');
        $this->assertCount(1, PublicationRules::validate('archived', '2026-01-01T10:00', $offered), 'a real status this kind does not offer');
        $this->assertCount(1, PublicationRules::validate('scheduled', '', $offered), 'scheduled needs a date');
        $this->assertCount(1, PublicationRules::validate('published', 'next week', $offered), 'an invalid date is refused, not read as now');
        $this->assertCount(1, PublicationRules::validate('draft', '2026-02-30T10:00', $offered), 'even on a draft');
    }

    public function testTheOwnersCanPublishIsAskedOnlyWhenGoingOut(): void
    {
        $refusing = $this->provider(['Een titel is nodig.']);
        $allowing = $this->provider([]);
        $offered = PublicationStatus::ALL;

        $this->assertSame(['Een titel is nodig.'], PublicationRules::validate('published', '', $offered, $refusing, 7));
        $this->assertSame(['Een titel is nodig.'], PublicationRules::validate('scheduled', '2026-12-24T18:30', $offered, $refusing, 7));
        $this->assertSame(['Een titel is nodig.'], PublicationRules::validate('archived', '', $offered, $refusing, 7));
        $this->assertSame([], PublicationRules::validate('draft', '', $offered, $refusing, 7), 'a draft can always be saved');
        $this->assertSame([], PublicationRules::validate('published', '', $offered, $allowing, 7));
    }

    public function testWhatASaveStores(): void
    {
        PublishingClock::freezeForTests(new \DateTimeImmutable('2026-10-01 12:00:00'));

        $this->assertSame('2026-10-01 12:00:00', PublicationRules::resolvePublishedAt('published', ''), 'published without a date is now');
        $this->assertSame('2026-12-24 18:30:00', PublicationRules::resolvePublishedAt('scheduled', '2026-12-24T18:30'));
        $this->assertSame('2026-12-24 18:30:00', PublicationRules::resolvePublishedAt('published', '2026-12-24T18:30'));
        $this->assertNull(PublicationRules::resolvePublishedAt('draft', ''));
        $this->assertNull(PublicationRules::resolvePublishedAt('archived', ''));

        // scheduled -> draft keeps a typed date but hides it; draft -> scheduled shows it at that date.
        $this->assertSame('2026-12-24 18:30:00', PublicationRules::resolvePublishedAt('draft', '2026-12-24T18:30'));
        $this->assertFalse(PublicationVisibility::isReachable('draft', '2026-09-01 10:00:00'));
        $this->assertTrue(PublicationVisibility::isListed('published', PublicationRules::resolvePublishedAt('published', '')));
    }

    /* ------------------------------------------------------------------ */
    /* The registry                                                        */
    /* ------------------------------------------------------------------ */

    public function testOnlyAnEnabledModulesKindsExistAndATypeIsOnlyALookupKey(): void
    {
        ModuleRegistry::overrideForTests(['blog' => true]);
        $this->assertInstanceOf(Publishable::class, Publishables::get('blog_post'));
        $this->assertSame(['blog_post'], array_keys(Publishables::all()));
        $this->assertSame('blog.manage', Publishables::get('blog_post')->permission());
        $this->assertNotContains('archived', Publishables::get('blog_post')->statuses(), 'the Blog offers no archive yet');

        foreach (['Blog_Post', 'blog_post ', 'App\\Service\\Blog\\BlogPostPublishable', '../blog_post', 'article', '', null, ['blog_post']] as $forged) {
            $this->assertNull(Publishables::get($forged), var_export($forged, true));
        }

        foreach (['0', '-1', 'abc', '1.5', null, ['1']] as $badId) {
            $this->assertNull(Publishables::find('blog_post', $badId), 'a malformed id finds nothing, before any query');
        }

        ModuleRegistry::overrideForTests(['blog' => false]);
        $this->assertNull(Publishables::get('blog_post'), 'a kind whose module is off does not exist');
        $this->assertSame([], Publishables::all());
    }

    /**
     * @param list<string> $errors
     */
    private function provider(array $errors): Publishable
    {
        return new class ($errors) implements Publishable {
            /** @param list<string> $errors */
            public function __construct(private array $errors)
            {
            }

            public function type(): string
            {
                return 'zz_test';
            }

            public function module(): string
            {
                return 'zz';
            }

            public function permission(): string
            {
                return 'zz.manage';
            }

            public function statuses(): array
            {
                return PublicationStatus::ALL;
            }

            public function publication(int $id): ?array
            {
                return ['status' => 'draft', 'published_at' => null];
            }

            public function publishErrors(int $id, string $status): array
            {
                return $this->errors;
            }

            public function savePublication(int $id, string $status, ?string $publishedAt): void
            {
            }

            public function adminPath(int $id): string
            {
                return '/admin/zz.php?id=' . $id;
            }

            public function publicPath(int $id, string $language): ?string
            {
                return null;
            }

            public function alternates(int $id): array
            {
                return [];
            }
        };
    }
}
