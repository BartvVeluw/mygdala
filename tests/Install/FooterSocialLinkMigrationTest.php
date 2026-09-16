<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * The social profiles moving into their own table:
 * db/migrations/20260917100000_move_social_profiles_into_footer_social_links.php.
 *
 * WHAT IT HAS TO GET RIGHT. Until this migration a site had one optional URL
 * per network, as seven social_<network>_url settings. The footer now renders
 * footer_social_links rows, so without the copy an existing site would lose
 * every profile icon the moment the code is deployed, and nobody should have
 * to re-enter them by hand.
 *
 * Two throwaway databases (Tests\Support\ScratchInstall): one built from zero,
 * and one standing where a site stood before this migration, with social
 * settings written the way api/admin/update-header-footer-settings.php wrote
 * them (one row per network, empty ones included).
 *
 * What it proves: both databases get the same table; a fresh install gets no
 * profile; all seven filled in become seven visible rows in the order the
 * footer rendered them, with exactly the address it rendered; a partly
 * filled-in set becomes only its filled-in rows, still in that order; an
 * empty set becomes nothing; a second run adds nothing; and the old settings
 * are left exactly as they were in every case.
 */
#[Group('migration-backfill')]
final class FooterSocialLinkMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_footer_social_fresh';
    private const DEPLOYED = 'mygdala_scratch_footer_social_deployed';

    /** The migration before this one. */
    private const BEFORE = '20260916230000';

    private const MOVE = '20260917100000';

    /** In the registry's order, which was the footer's order. */
    private const ALL_SEVEN = [
        'social_instagram_url' => 'https://www.instagram.com/voorbeeld/',
        'social_facebook_url' => 'https://www.facebook.com/voorbeeld',
        'social_pinterest_url' => 'https://nl.pinterest.com/voorbeeld/',
        'social_linkedin_url' => 'https://www.linkedin.com/company/voorbeeld',
        'social_youtube_url' => 'https://www.youtube.com/@voorbeeld',
        'social_tiktok_url' => 'https://www.tiktok.com/@voorbeeld',
        'social_etsy_url' => 'https://www.etsy.com/shop/voorbeeld',
    ];

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $deployed = null;

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::fresh(self::FRESH);

        self::$deployed = ScratchInstall::upTo(self::DEPLOYED, self::BEFORE);

        // Written in a different order than the registry, so the rows can
        // only come out in registry order if the migration puts them there.
        self::writeSettings(array_reverse(self::ALL_SEVEN, true));

        self::$deployed->catchUp();
    }

    protected function setUp(): void
    {
        if (!ScratchInstall::available()) {
            $this->markTestSkipped('no root database credentials for a throwaway installation');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$fresh?->drop();
        self::$deployed?->drop();
        self::$fresh = null;
        self::$deployed = null;
    }

    public function testBothDatabasesEndWithTheSameTable(): void
    {
        $expected = [
            'network' => ['NO', null],
            'url' => ['NO', null],
            'sort_order' => ['NO', '0'],
            'is_visible' => ['NO', '1'],
        ];

        foreach ([self::FRESH => self::$fresh, self::DEPLOYED => self::$deployed] as $name => $install) {
            $this->assertNotNull($install);
            $this->assertTrue($install->hasTable('footer_social_links'), $name);

            foreach ($expected as $column => [$null, $default]) {
                $definition = $install->pdo()->query("SHOW COLUMNS FROM footer_social_links LIKE '{$column}'")->fetch();

                $this->assertIsArray($definition, "{$name} has {$column}");
                $this->assertSame($null, $definition['Null'], "{$name}: {$column} is never NULL");
                $this->assertSame($default, $definition['Default'], "{$name}: {$column} default");
            }
        }
    }

    public function testAFreshInstallGetsNoProfile(): void
    {
        $this->assertNotNull(self::$fresh);

        $this->assertSame(0, self::$fresh->count('footer_social_links'));
    }

    public function testAllSevenBecomeSevenVisibleRowsInTheFootersOrder(): void
    {
        $this->assertSame(
            [
                ['instagram', 'https://www.instagram.com/voorbeeld/', 0, 1],
                ['facebook', 'https://www.facebook.com/voorbeeld', 1, 1],
                ['pinterest', 'https://nl.pinterest.com/voorbeeld/', 2, 1],
                ['linkedin', 'https://www.linkedin.com/company/voorbeeld', 3, 1],
                ['youtube', 'https://www.youtube.com/@voorbeeld', 4, 1],
                ['tiktok', 'https://www.tiktok.com/@voorbeeld', 5, 1],
                ['etsy', 'https://www.etsy.com/shop/voorbeeld', 6, 1],
            ],
            $this->profiles()
        );
    }

    public function testTheOldSettingsAreLeftExactlyAsTheyWere(): void
    {
        $this->assertSame(self::ALL_SEVEN, $this->storedSettings());
    }

    public function testRunningItAgainAddsNothing(): void
    {
        $this->assertNotNull(self::$deployed);

        self::$deployed->replay(self::MOVE);

        $this->assertCount(7, $this->profiles());
    }

    /**
     * A row an editor already has — here one added after the first run — is
     * the reason a second run copies nothing: the table is no longer the
     * migration's to fill.
     */
    public function testAnEditorsOwnRowsAreNeverJoinedByASecondCopy(): void
    {
        $this->assertNotNull(self::$deployed);

        self::$deployed->pdo()->exec('DELETE FROM footer_social_links');
        self::$deployed->pdo()->exec(
            "INSERT INTO footer_social_links (network, url, sort_order, is_visible, created_at, updated_at)
             VALUES ('etsy', 'https://www.etsy.com/shop/eigen', 0, 0, NOW(), NOW())"
        );

        self::$deployed->replay(self::MOVE);

        $this->assertSame([['etsy', 'https://www.etsy.com/shop/eigen', 0, 0]], $this->profiles());
    }

    /**
     * Only the filled-in networks, in registry order, numbered without gaps.
     * Whitespace around an address is dropped (the old endpoint trimmed and
     * the footer trimmed); whitespace alone is empty. An address longer than
     * the column is not truncated into a different one; its setting stays.
     */
    public function testAPartlyFilledInSetBecomesOnlyItsFilledInRows(): void
    {
        $settings = [
            'social_instagram_url' => '',
            'social_facebook_url' => '  https://www.facebook.com/deels  ',
            'social_pinterest_url' => '   ',
            'social_linkedin_url' => '',
            'social_youtube_url' => 'https://www.youtube.com/' . str_repeat('x', 2048),
            'social_tiktok_url' => 'https://www.tiktok.com/@deels',
            'social_etsy_url' => 'https://www.etsy.com/shop/deels',
        ];

        $this->rerunWith($settings);

        $this->assertSame(
            [
                ['facebook', 'https://www.facebook.com/deels', 0, 1],
                ['tiktok', 'https://www.tiktok.com/@deels', 1, 1],
                ['etsy', 'https://www.etsy.com/shop/deels', 2, 1],
            ],
            $this->profiles()
        );
        $this->assertSame($settings, $this->storedSettings());
    }

    /**
     * Copied as stored, even an address the stricter check of Footer phase B
     * refuses: the Footer screen then shows it as not on the website, and the
     * editor can correct it, instead of it silently disappearing.
     */
    public function testAnAddressTheNewCheckRefusesIsStillCopied(): void
    {
        $this->rerunWith(['social_pinterest_url' => 'https://pin.nl/oud'] + array_fill_keys(array_keys(self::ALL_SEVEN), ''));

        $this->assertSame([['pinterest', 'https://pin.nl/oud', 0, 1]], $this->profiles());
    }

    public function testAnEmptySetBecomesNothingAndKeepsItsSettings(): void
    {
        $empty = array_fill_keys(array_keys(self::ALL_SEVEN), '');

        $this->rerunWith($empty);

        $this->assertSame([], $this->profiles());
        $this->assertSame($empty, $this->storedSettings());
    }

    /** @param array<string, string> $settings */
    private function rerunWith(array $settings): void
    {
        $this->assertNotNull(self::$deployed);

        self::$deployed->pdo()->exec('DELETE FROM footer_social_links');
        self::writeSettings($settings);
        self::$deployed->replay(self::MOVE);
    }

    /** @param array<string, string> $settings */
    private static function writeSettings(array $settings): void
    {
        $statement = self::$deployed->pdo()->prepare(
            'INSERT INTO site_settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );

        foreach ($settings as $key => $value) {
            $statement->execute([$key, $value]);
        }
    }

    /** @return array<string, string> the seven settings, in registry order */
    private function storedSettings(): array
    {
        $this->assertNotNull(self::$deployed);

        $stored = [];
        foreach (self::$deployed->rows("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'social\\_%\\_url'") as $row) {
            $stored[(string) $row['setting_key']] = (string) $row['setting_value'];
        }

        $ordered = [];
        foreach (array_keys(self::ALL_SEVEN) as $key) {
            if (array_key_exists($key, $stored)) {
                $ordered[$key] = $stored[$key];
            }
        }

        return $ordered;
    }

    /** @return list<array{0: string, 1: string, 2: int, 3: int}> network, url, sort_order, is_visible */
    private function profiles(): array
    {
        $this->assertNotNull(self::$deployed);

        return array_map(
            static fn (array $row): array => [(string) $row['network'], (string) $row['url'], (int) $row['sort_order'], (int) $row['is_visible']],
            self::$deployed->rows('SELECT * FROM footer_social_links ORDER BY sort_order, id')
        );
    }
}
