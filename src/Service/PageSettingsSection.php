<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A module's own setting on the Pagina tab of the page editor
 * (admin/page.php), saved with the rest of the page by
 * api/admin/update-page.php — contributed through
 * App\Module\ModuleDefinition::pageSettingsSections(), so Core renders and
 * saves it without ever naming the module (MODULES.md).
 *
 * The page editor's own contract, kept for these as well:
 *
 *   - ONLY WHAT WAS POSTED IS WRITTEN. A request that does not carry one of
 *     fields() — another form, a script, the module switched on after the
 *     screen was rendered — leaves the stored value alone.
 *   - A REFUSED SAVE WRITES NOTHING. errors() runs with every other check
 *     before the transaction; save() runs inside it, after the page's own
 *     settings, so both land or neither does.
 *   - A REFUSED SAVE KEEPS WHAT WAS TYPED. update-page.php hands the posted
 *     fields() back with the rest of the form, and render() gets them as
 *     $old.
 *
 * Only ordinary pages reach either side: admin/page.php sends the content
 * page of a product or project to its owner's editor, and update-page.php
 * refuses one.
 */
interface PageSettingsSection
{
    /**
     * The POST keys this section owns on the page form.
     *
     * @return list<string>
     */
    public function fields(): array;

    /**
     * Prints the section's fields, inside the Pagina tab's settings form.
     *
     * @param array<string, mixed>      $page the `pages` row being edited
     * @param array<string, mixed>|null $old  a refused save's handback, or null
     */
    public function render(array $page, ?array $old): void;

    /**
     * What is wrong with the posted input, as sentences for the editor; []
     * when it is fine or when none of fields() was posted.
     *
     * @param array<string, mixed> $page
     * @param array<string, mixed> $input the request's POST fields
     * @return list<string>
     */
    public function errors(array $page, array $input): array;

    /**
     * Stores the posted input. Called only after errors() returned [], inside
     * update-page.php's transaction; does nothing when none of fields() was
     * posted.
     *
     * @param array<string, mixed> $page
     * @param array<string, mixed> $input
     */
    public function save(array $page, array $input): void;
}
