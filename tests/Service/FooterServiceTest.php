<?php

namespace Tests\Service;

use App\Repository\FooterRepository;
use App\Repository\SiteSettingRepository;
use App\Service\FooterService;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests against the real dev database (FooterRepository has no
 * mocking seam — same rationale as every other repository test here).
 */
class FooterServiceTest extends TestCase
{
    private FooterRepository $repository;

    /** @var list<int> */
    private array $createdColumnIds = [];

    protected function setUp(): void
    {
        $this->repository = new FooterRepository();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdColumnIds as $id) {
            $this->repository->deleteColumn($id);
        }
        SiteSettings::clearCache();
    }

    public function testHiddenColumnsAreExcludedFromPublicOutput(): void
    {
        $visibleId = $this->repository->createColumn(['title_nl' => 'Zichtbaar', 'title_en' => 'Visible', 'is_visible' => true]);
        $hiddenId = $this->repository->createColumn(['title_nl' => 'Verborgen', 'title_en' => 'Hidden', 'is_visible' => false]);
        $this->createdColumnIds = [$visibleId, $hiddenId];

        $this->repository->createLink([
            'column_id' => $visibleId, 'label_nl' => 'Link', 'label_en' => 'Link', 'link_type' => 'external',
            'target_page_id' => null, 'target_route' => null, 'external_url' => '/test',
            'action_key' => null, 'open_in_new_tab' => false, 'is_visible' => true,
        ]);
        $this->repository->createLink([
            'column_id' => $hiddenId, 'label_nl' => 'Link', 'label_en' => 'Link', 'link_type' => 'external',
            'target_page_id' => null, 'target_route' => null, 'external_url' => '/test',
            'action_key' => null, 'open_in_new_tab' => false, 'is_visible' => true,
        ]);

        $titles = array_map(static fn (array $c): string => $c['title_nl'], FooterService::columns());

        $this->assertContains('Zichtbaar', $titles);
        $this->assertNotContains('Verborgen', $titles);
    }

    public function testColumnWithOnlyHiddenLinksIsOmitted(): void
    {
        $columnId = $this->repository->createColumn(['title_nl' => 'Alles verborgen', 'title_en' => 'All hidden', 'is_visible' => true]);
        $this->createdColumnIds = [$columnId];

        $linkId = $this->repository->createLink([
            'column_id' => $columnId, 'label_nl' => 'Link', 'label_en' => 'Link', 'link_type' => 'external',
            'target_page_id' => null, 'target_route' => null, 'external_url' => '/test',
            'action_key' => null, 'open_in_new_tab' => false, 'is_visible' => true,
        ]);
        $this->repository->setLinkVisible($linkId, false);

        $titles = array_map(static fn (array $c): string => $c['title_nl'], FooterService::columns());

        $this->assertNotContains('Alles verborgen', $titles);
    }

    public function testActionLinkIsExposedAsAnActionNotAHref(): void
    {
        $columnId = $this->repository->createColumn(['title_nl' => 'Legal', 'title_en' => 'Legal', 'is_visible' => true]);
        $this->createdColumnIds = [$columnId];

        $this->repository->createLink([
            'column_id' => $columnId, 'label_nl' => 'Cookie-instellingen', 'label_en' => 'Cookie settings',
            'link_type' => 'action', 'target_page_id' => null, 'target_route' => null, 'external_url' => null,
            'action_key' => 'cookie_preferences', 'open_in_new_tab' => false, 'is_visible' => true,
        ]);

        $columns = FooterService::columns();
        $legal = array_values(array_filter($columns, static fn (array $c): bool => $c['title_nl'] === 'Legal'));

        $this->assertCount(1, $legal);
        $this->assertTrue($legal[0]['links'][0]['is_action']);
        $this->assertSame('cookie_preferences', $legal[0]['links'][0]['action_key']);
        $this->assertNull($legal[0]['links'][0]['href']);
    }

    public function testRenderCopyrightSubstitutesYearAndSiteName(): void
    {
        $repository = new SiteSettingRepository();
        $original = SiteSettings::get('footer_copyright_template');
        $repository->upsertMany(['footer_copyright_template' => '© {{year}} {{site_name}} test']);
        SiteSettings::clearCache();

        $result = FooterService::renderCopyright();

        $this->assertSame('© ' . date('Y') . ' ' . SiteSettings::get('site_name') . ' test', $result);

        $repository->upsertMany(['footer_copyright_template' => $original]);
        SiteSettings::clearCache();
    }

    public function testBrandSettingsReflectSiteSettings(): void
    {
        $repository = new SiteSettingRepository();
        $repository->upsertMany(['footer_show_phone' => '1']);
        SiteSettings::clearCache();

        $this->assertTrue(FooterService::brandSettings()['show_phone']);

        $repository->upsertMany(['footer_show_phone' => '0']);
        SiteSettings::clearCache();
    }
}
