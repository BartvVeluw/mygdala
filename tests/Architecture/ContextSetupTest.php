<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The four instruction layers of WORKFLOW.md, checked as plain files on disk.
 *
 * WHY THIS EXISTS. The layers are committed — `.gitignore` says so in as many
 * words — so a checkout either has them or is broken. The failure mode is the
 * problem: when they are absent, nothing fails. `/content-block` reports an
 * unknown skill, no path rule ever fires, the logging hook returns quietly
 * because its own directory is gone, and a session works without the guard
 * rails while believing it has them. That happened in a fresh agent worktree,
 * and the only reason anybody noticed is that a skill was invoked by name.
 *
 * So this test turns a silent absence into a failing `fast` run, which is the
 * first thing anybody does in a new checkout. It asserts nothing about the
 * content of an instruction, only that each layer is present and describes
 * itself well enough to load.
 *
 * It reads files and nothing else — no database, no web server, no git client
 * — because it has to run in exactly the situation where the checkout is
 * suspect. The repair is one command, in WORKFLOW.md under "Een verse
 * worktree".
 *
 * Neither list it checks against is repeated here: the skills come from
 * CLAUDE.md's own routing table and the folder rules from WORKFLOW.md's own
 * list, so there is no second list to drift away from the first.
 */
final class ContextSetupTest extends TestCase
{
    /** Where the repair is written down; quoted in every failure message. */
    private const REPAIR = 'see WORKFLOW.md, "Een verse worktree"';

    /** Directories that are never part of the application's own tree. */
    private const NOT_THE_APPLICATION = ['vendor', 'node_modules'];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function contents(string $relativePath): string
    {
        $path = self::root() . '/' . $relativePath;

        self::assertFileExists($path, $relativePath . ' is missing — ' . self::REPAIR);

        return (string) file_get_contents($path);
    }

    /* ------------------------------------------------------------------ */
    /* Layer 1: the routing document                                       */
    /* ------------------------------------------------------------------ */

    public function testTheRoutingDocumentIsThereAndStillRoutes(): void
    {
        $this->assertStringContainsString(
            'Contextregels',
            self::contents('CLAUDE.md'),
            'CLAUDE.md no longer carries the context rules every session is routed by — ' . self::REPAIR
        );
    }

    /* ------------------------------------------------------------------ */
    /* Layer 2: the path rules                                             */
    /* ------------------------------------------------------------------ */

    public function testEveryPathRuleDeclaresTheGlobsItMatchesOn(): void
    {
        $rules = glob(self::root() . '/.claude/rules/*.md') ?: [];

        $this->assertNotSame(
            [],
            $rules,
            '.claude/rules holds no rule at all, so no domain boundary can fire — ' . self::REPAIR
        );

        foreach ($rules as $rule) {
            $name = '.claude/rules/' . basename($rule);
            $source = (string) file_get_contents($rule);

            $this->assertStringStartsWith(
                '---',
                $source,
                $name . ' has no frontmatter, so the harness cannot know when to load it'
            );

            $this->assertMatchesRegularExpression(
                '/^paths:\s*$\s+^\s+-\s+\S/m',
                $source,
                $name . ' declares no paths:, so it would never match a file'
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Layer 3: the folder rules                                           */
    /* ------------------------------------------------------------------ */

    /**
     * WORKFLOW.md lists the folders that own a rule, and that list is what a
     * reader trusts. Checking it in both directions is what keeps it true: a
     * folder rule nobody wrote down is as useless as one that was written
     * down and then lost.
     */
    public function testTheFolderRulesOnDiskAndTheOnesInTheWorkflowAgree(): void
    {
        $listed = $this->folderRulesListedInTheWorkflow();
        $onDisk = $this->folderRulesOnDisk();

        $this->assertNotSame(
            [],
            $listed,
            'WORKFLOW.md lists no folder rules — either the chapter moved or its code block changed shape'
        );

        $this->assertSame(
            [],
            array_values(array_diff($listed, $onDisk)),
            'WORKFLOW.md promises a CLAUDE.md in these folders and this checkout has none — ' . self::REPAIR
        );

        $this->assertSame(
            [],
            array_values(array_diff($onDisk, $listed)),
            'these folders carry a CLAUDE.md that WORKFLOW.md does not list, so nobody knows it is there'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Layer 4: the skills                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * A skill is the only layer a session asks for by name, so a missing one
     * fails in the most confusing way available: the name is simply unknown,
     * and the session carries on without the recipe.
     */
    public function testEverySkillTheRoutingTablePromisesIsThereAndNamesItself(): void
    {
        $promised = $this->skillsPromisedByTheRoutingTable();

        $this->assertNotSame(
            [],
            $promised,
            "CLAUDE.md's routing table names no skill — either the table moved or its rows changed shape"
        );

        foreach ($promised as $skill) {
            $relative = '.claude/skills/' . $skill . '/SKILL.md';
            $source = self::contents($relative);

            $this->assertMatchesRegularExpression(
                '/^name:\s*' . preg_quote($skill, '/') . '\s*$/m',
                $source,
                $relative . ' does not declare `name: ' . $skill . '`, so /' . $skill . ' would not resolve'
            );

            $this->assertMatchesRegularExpression(
                '/^description:\s*\S/m',
                $source,
                $relative . ' has no description, so nothing tells a session when to reach for it'
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* What feeds the log                                                  */
    /* ------------------------------------------------------------------ */

    public function testTheLoggingHookAndTheSettingsThatCallItTravelTogether(): void
    {
        $settings = json_decode(self::contents('.claude/settings.json'), true);

        $this->assertIsArray($settings, '.claude/settings.json is not valid JSON, so the harness ignores all of it');
        $this->assertArrayHasKey('hooks', $settings, '.claude/settings.json declares no hooks');

        $script = '.claude/hooks/log-instructions.py';

        $this->assertStringContainsString(
            $script,
            (string) json_encode($settings['hooks'], JSON_UNESCAPED_SLASHES),
            'no hook calls ' . $script . ', so nothing records which instructions a session loaded'
        );

        self::contents($script);
    }

    /* ------------------------------------------------------------------ */

    /**
     * The skill names out of CLAUDE.md's routing table: the `/name` cells of
     * its own rows, so the table stays the one place they are promised.
     *
     * @return list<string>
     */
    private function skillsPromisedByTheRoutingTable(): array
    {
        $skills = [];

        foreach (explode("\n", self::contents('CLAUDE.md')) as $line) {
            if (!str_starts_with(trim($line), '|')) {
                continue;
            }

            preg_match_all('/`\/([a-z][a-z-]*)`/', $line, $matches);
            foreach ($matches[1] as $skill) {
                $skills[$skill] = true;
            }
        }

        $names = array_keys($skills);
        sort($names);

        return $names;
    }

    /**
     * The folder paths out of WORKFLOW.md's folder-rule list: the lines of its
     * code block that open with a path and then describe it.
     *
     * @return list<string>
     */
    private function folderRulesListedInTheWorkflow(): array
    {
        $folders = [];

        foreach (explode("\n", self::contents('WORKFLOW.md')) as $line) {
            if (preg_match('#^([a-z][A-Za-z0-9_/]*)/\s{2,}\S#', $line, $match) === 1) {
                $folders[] = $match[1];
            }
        }

        $folders = array_values(array_unique($folders));
        sort($folders);

        return $folders;
    }

    /**
     * Every folder below the project root holding a CLAUDE.md of its own. The
     * root document is layer 1 and is checked on its own.
     *
     * @return list<string>
     */
    private function folderRulesOnDisk(): array
    {
        $found = [];
        $this->collectFolderRules('', $found);
        sort($found);

        return $found;
    }

    /**
     * @param list<string> $found
     */
    private function collectFolderRules(string $relative, array &$found): void
    {
        $absolute = rtrim(self::root() . '/' . $relative, '/');

        foreach (scandir($absolute) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            if (in_array($entry, self::NOT_THE_APPLICATION, true)) {
                continue;
            }
            if (!is_dir($absolute . '/' . $entry)) {
                continue;
            }

            $child = $relative === '' ? $entry : $relative . '/' . $entry;

            if (is_file($absolute . '/' . $entry . '/CLAUDE.md')) {
                $found[] = $child;
            }

            $this->collectFolderRules($child, $found);
        }
    }
}
