<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_ui.php';

use App\Service\AppUrl;
use App\Service\PageAdminGroup;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PagePath;
use App\Service\PageService;
use App\Service\PageTree;
use App\Service\Routing\LocalizedUrl;

/**
 * Where a page sits (Pagina's 2.0, docs/pages/NESTING.md): "Bovenliggende
 * pagina", the admin group of a root page, and the address that follows from
 * both. Printed by admin/page.php and admin/page-new.php inside their own
 * settings form, behind their own pages.manage check; the form's endpoint
 * (update-page.php, create-page.php) validates and stores the choice.
 *
 * THE LIST CANNOT MAKE A LOOP. It offers exactly
 * App\Service\PageService::parentCandidates(): not the page itself, nothing
 * below it, no page with a fixed URL. The endpoint refuses the same set, so a
 * hand-made POST cannot get further than a click.
 *
 * THE ADDRESS PREVIEW is the path in the language being edited, the same
 * App\Service\PagePath every link uses. Each option carries its page's path
 * in that language (data-page-path, '' for no parent) or says it has none
 * there; admin/assets/page-placement.js joins it with the slug field and
 * rewrites the line as the editor chooses — nothing is saved to show it.
 * Without the script the line shows the stored address and the choice still
 * posts.
 *
 * THE GROUP is offered to a root page only. A page under another follows its
 * tree, and the line under the list says which group that is; the script
 * shows one or the other, and the endpoint decides the same way whatever the
 * form sends (PageService::resolveAdminGroup()).
 *
 * @param array<string, mixed>|null $page          the stored page, null on the create screen
 * @param string                    $language      the website language being edited
 * @param int|null                  $parentId      the parent to show as chosen
 * @param string                    $adminGroup    the root group to show as chosen
 * @param string                    $slug          the slug the address preview starts from
 * @param bool                      $preview       print the address line; admin/page-new.php
 *                                                 has its own, whose base the script extends
 *                                                 with the parent's path instead
 *                                                 ([data-page-path-base])
 */
function page_placement_fields(?array $page, string $language, ?int $parentId, string $adminGroup, string $slug, bool $preview = true): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    $candidates = array_flip(PageService::parentCandidates($page));

    // The stored parent is always listed, so a page whose place is no longer
    // allowed (a tree edited in SQL) is not silently moved to the top by the
    // next save. Anything else handed back that the list does not offer — a
    // refused, hand-made choice — shows the stored parent again.
    $storedParent = $page === null ? 0 : (int) ($page['parent_id'] ?? 0);
    if ($storedParent > 0) {
        $candidates[$storedParent] = true;
    }
    if ($parentId !== null && !isset($candidates[$parentId])) {
        $parentId = $storedParent > 0 ? $storedParent : null;
    }
    $tree = PageTree::ordered();
    PageLocalization::preload(array_column($tree, 'id'));

    $groupLabels = [
        PageAdminGroup::WEBSITE => admin_t('page.admin_group_website'),
        PageAdminGroup::SERVICE => admin_t('page.admin_group_service'),
    ];
    $adminGroup = PageAdminGroup::normalise($adminGroup);
    $base = AppUrl::canonical(LocalizedUrl::home($language));
    $pageId = $page === null ? 0 : (int) ($page['id'] ?? 0);
    ?>
    <div class="admin-page-placement" data-page-placement data-url-base="<?= $h($base) ?>">
      <div class="admin-field">
        <?= admin_field_label('page-parent', admin_t('page.parent_label'), admin_t('help.page.parent')) ?>
        <select id="page-parent" name="parent_id" class="admin-select" data-page-parent>
          <option value="0" data-page-path="" data-page-group=""<?= $parentId === null ? ' selected' : '' ?>><?= admin_te('page.parent_none') ?></option>
          <?php foreach ($tree as $row): ?>
            <?php
              $id = $row['id'];
              if (!isset($candidates[$id])) {
                  continue;
              }
              $node = PagePath::node($id) ?? [];
              $path = PagePath::path($node, $language);
              $label = str_repeat("\u{00A0}\u{00A0}\u{00A0}", $row['depth']) . PageLocalization::name($id);
              if (!PageContent::isPublished($node)) {
                  $label .= ' (' . admin_t('page.status_draft') . ')';
              }
            ?>
            <option value="<?= $id ?>"
                    data-page-path="<?= $h(ltrim((string) $path, '/')) ?>"
                    <?= $path === null ? 'data-page-path-missing' : '' ?>
                    data-page-group="<?= $h($groupLabels[PagePath::effectiveGroup($id)]) ?>"
                    <?= $id === $parentId ? ' selected' : '' ?>><?= $h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <fieldset class="admin-segmented-field" data-page-group-choice<?= $parentId !== null ? ' hidden' : '' ?>>
        <legend><?= admin_te('page.admin_group_label') ?> <?= admin_help(admin_t('page.admin_group_label'), admin_t('help.page.admin_group')) ?></legend>
        <div class="admin-segmented">
          <?php foreach ($groupLabels as $value => $label): ?>
            <label class="admin-segmented__option"><input type="radio" name="admin_group" value="<?= $h($value) ?>"<?= $value === $adminGroup ? ' checked' : '' ?>> <span><?= $h($label) ?></span></label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <p class="admin-text-muted" data-page-group-follows<?= $parentId === null ? ' hidden' : '' ?>>
        <?= admin_te('page.admin_group_follows') ?>
        <strong data-page-group-follows-name><?= $h($parentId === null ? '' : $groupLabels[PagePath::effectiveGroup($parentId)]) ?></strong>
      </p>

      <?php if ($preview): ?>
      <?php
        $previewPath = PagePath::prospective($parentId, $slug, $language, $pageId);
      ?>
      <p class="admin-page-placement__preview" aria-live="polite">
        <span><?= admin_te('page.url_preview_path') ?></span>
        <code data-page-path-preview<?= $previewPath === null ? ' hidden' : '' ?>><?= $h($previewPath === null ? '' : AppUrl::canonical(LocalizedUrl::path($previewPath, $language))) ?></code>
        <span class="admin-text-muted" data-page-path-none<?= $previewPath !== null ? ' hidden' : '' ?>><?= admin_te('page.url_preview_none') ?></span>
      </p>
      <?php endif; ?>
    </div>
    <?php
}

/** The one <script> tag the fields above need. */
function page_placement_script(): void
{
    echo '<script src="' . htmlspecialchars(\App\Service\AssetVersion::url('/admin/assets/page-placement.js'), ENT_QUOTES, 'UTF-8') . '" defer></script>';
}
