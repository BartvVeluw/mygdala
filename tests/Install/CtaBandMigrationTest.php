<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * CTA 2.0's migration (20260926120000) on a database built from zero and on
 * one upgraded from the migration before it, with the legacy button states
 * seeded first:
 *
 *   zz-cta-both      a label and an address on both buttons
 *   zz-cta-one       a first button only (the second address empty)
 *   zz-cta-stale     a second address without a second label: no button
 *                    before, and still none after (the label decides,
 *                    App\Service\CtaBandContent); the address is not rewritten
 *   zz-cta-nothing   no address at all
 *
 * Every button with an address gets the type 'url', so it keeps going where
 * it went; one without stays NULL; the addresses themselves are untouched.
 * Every band gets the presentation it had: centred, the narrow lead, not
 * full width, no picture, no panel. Both installs end on the same schema,
 * with the picture's foreign key RESTRICT, and running it again changes
 * nothing.
 */
#[Group('migration-backfill')]
final class CtaBandMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_cta_2_fresh';
    private const UPGRADED = 'mygdala_scratch_cta_2_upgraded';

    /** The last migration before CTA 2.0. */
    private const BEFORE = '20260926110000';

    private const MIGRATION = '20260926120000';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var list<array<string, mixed>> */
    private static array $afterFirstRun = [];

    /** @var list<array<string, mixed>> */
    private static array $afterReplay = [];

    /** @var list<array<string, mixed>> */
    private static array $before = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::MIGRATION);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::seed(self::$upgraded);
        self::$before = self::$upgraded->rows('SELECT id, page_slug, section_key, primary_url, secondary_url, is_active FROM cta_bands ORDER BY id');
        self::$upgraded->catchUp(self::MIGRATION);
        self::$afterFirstRun = self::$upgraded->rows('SELECT * FROM cta_bands ORDER BY id');
        self::$upgraded->replay(self::MIGRATION, self::MIGRATION);
        self::$afterReplay = self::$upgraded->rows('SELECT * FROM cta_bands ORDER BY id');
    }

    protected function setUp(): void
    {
        if (!ScratchInstall::available()) {
            $this->markTestSkipped('A from-zero install needs the MySQL root account (DB_ROOT_PASSWORD in .env).');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$fresh?->drop();
        self::$upgraded?->drop();
        self::$fresh = null;
        self::$upgraded = null;
    }

    public function testEveryButtonWithAnAddressBecomesAnAddressAndNothingElseChanges(): void
    {
        self::assertSame(
            [
                ['section_key' => 'zz-cta-both', 'primary_link_type' => 'url', 'primary_url' => '/contact', 'secondary_link_type' => 'url', 'secondary_url' => '/werk'],
                ['section_key' => 'zz-cta-one', 'primary_link_type' => 'url', 'primary_url' => '/contact', 'secondary_link_type' => null, 'secondary_url' => null],
                ['section_key' => 'zz-cta-stale', 'primary_link_type' => 'url', 'primary_url' => '/contact', 'secondary_link_type' => 'url', 'secondary_url' => '/oud'],
                ['section_key' => 'zz-cta-nothing', 'primary_link_type' => null, 'primary_url' => '', 'secondary_link_type' => null, 'secondary_url' => null],
            ],
            self::$upgraded->rows('SELECT section_key, primary_link_type, primary_url, secondary_link_type, secondary_url FROM cta_bands ORDER BY id')
        );

        self::assertSame(
            self::$before,
            self::$upgraded->rows('SELECT id, page_slug, section_key, primary_url, secondary_url, is_active FROM cta_bands ORDER BY id'),
            'the columns the rows had are untouched'
        );
        self::assertSame(0, (int) self::$upgraded->rows('SELECT COUNT(*) AS n FROM cta_bands WHERE primary_link_target_id IS NOT NULL OR secondary_link_target_id IS NOT NULL')[0]['n']);
    }

    public function testEveryBandKeepsItsLook(): void
    {
        self::assertSame(
            array_fill(0, 4, [
                'content_align' => 'center', 'lead_width' => 'narrow', 'full_width' => 0, 'background_media_id' => null,
                'background_focus' => 'center', 'background_overlay' => 'medium', 'text_panel' => 0, 'text_panel_opacity' => 'strong',
            ]),
            array_map(
                static fn (array $row): array => array_replace($row, ['full_width' => (int) $row['full_width'], 'text_panel' => (int) $row['text_panel']]),
                self::$upgraded->rows('SELECT content_align, lead_width, full_width, background_media_id, background_focus, background_overlay, text_panel, text_panel_opacity FROM cta_bands ORDER BY id')
            )
        );
    }

    public function testASecondRunChangesNothing(): void
    {
        self::assertCount(4, self::$afterFirstRun);
        self::assertSame(self::$afterFirstRun, self::$afterReplay);
    }

    public function testAFreshInstallAndAnUpgradeEndOnTheSameSchema(): void
    {
        self::assertSame(self::shape(self::$fresh), self::shape(self::$upgraded));
    }

    public function testThePictureIsProtectedByARestrictingForeignKey(): void
    {
        foreach ([self::$fresh, self::$upgraded] as $install) {
            self::assertSame(
                [['referenced_table_name' => 'media', 'delete_rule' => 'RESTRICT']],
                $install->rows(
                    "SELECT k.referenced_table_name AS referenced_table_name, r.delete_rule AS delete_rule
                       FROM information_schema.key_column_usage k
                       JOIN information_schema.referential_constraints r
                         ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
                      WHERE k.table_schema = DATABASE() AND k.table_name = 'cta_bands' AND k.column_name = 'background_media_id'"
                )
            );
        }
    }

    private static function seed(ScratchInstall $install): void
    {
        $pdo = $install->pdo();
        $band = $pdo->prepare(
            "INSERT INTO cta_bands (page_slug, section_key, primary_url, secondary_url, is_active, created_at, updated_at)
             VALUES ('zz-cta', ?, ?, ?, 1, NOW(), NOW())"
        );
        $words = $pdo->prepare(
            "INSERT INTO block_translations (owner_table, owner_id, language_code, field, value, created_at, updated_at)
             VALUES ('cta_bands', ?, 'nl', ?, ?, NOW(), NOW())"
        );

        foreach ([
            ['zz-cta-both', '/contact', '/werk', ['title' => 'Beide', 'primary_label' => 'Contact', 'secondary_label' => 'Werk']],
            ['zz-cta-one', '/contact', null, ['title' => 'Een', 'primary_label' => 'Contact']],
            ['zz-cta-stale', '/contact', '/oud', ['title' => 'Oud', 'primary_label' => 'Contact']],
            ['zz-cta-nothing', '', null, ['title' => 'Niets']],
        ] as [$key, $primary, $secondary, $fields]) {
            $band->execute([$key, $primary, $secondary]);
            $id = (int) $pdo->lastInsertId();
            foreach ($fields as $field => $value) {
                $words->execute([$id, $field, $value]);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private static function shape(ScratchInstall $install): array
    {
        return $install->rows(
            "SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS `default` FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'cta_bands' ORDER BY ordinal_position"
        );
    }
}
