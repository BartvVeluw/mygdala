<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Steps for the step_list_sections repeater (see previous migration's
 * docblock). Named title_nl/title_en/body_nl/body_en rather than step_1/
 * step_2 — each row is one step, there is no fixed count and no stored
 * step number (see previous migration's docblock on CSS-counter numbering).
 *
 * sort_order controls display order using the same "swap with neighbour"
 * convention as faq_items.sort_order (see StepListRepository::moveItem).
 * is_active lets a single step be hidden without deleting it — a deleted
 * step and a merely hidden one (is_active = 0) are deliberately different:
 * see App\Service\StepListContent for how that distinction is preserved
 * when the database is unreachable.
 *
 * Seeded with the exact 4 steps currently hardcoded on index.php's
 * `.process`, so the public site keeps rendering identical output the
 * moment this migration runs.
 */
final class CreateStepListItemsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('step_list_items', ['id' => true]);
        $table
            ->addColumn('step_list_section_id', 'integer', ['signed' => false])
            ->addColumn('title_nl', 'string', ['limit' => 255])
            ->addColumn('title_en', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('body_nl', 'text')
            ->addColumn('body_en', 'text', ['null' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('step_list_section_id', 'step_list_sections', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addIndex(['step_list_section_id'])
            ->create();

        if (InstallState::isFreshInstall($this)) {
            // Everything below is Van Veluw Laserdesign's own page
            // content, lifted out of the templates it used to be
            // hardcoded in. An installation with no history to preserve
            // gets the empty table and builds its own pages.
            // See src/Install/InstallState.php.
            return;
        }

        $now = date('Y-m-d H:i:s');

        $sectionId = (int) $this->fetchRow(
            "SELECT id FROM step_list_sections WHERE page_slug = 'index' AND section_key = 'werkwijze'"
        )['id'];

        $items = [
            [
                'title_nl' => 'Contact & wens',
                'title_en' => 'Get in touch',
                'body_nl' => 'Je stuurt je idee, foto of voorbeeld via het contactformulier. Ik denk mee over wat mogelijk is.',
                'body_en' => "Send your idea, a photo or an example through the contact form. I'll think along about what's possible.",
                'sort_order' => 0,
            ],
            [
                'title_nl' => 'Ontwerp op maat',
                'title_en' => 'Custom design',
                'body_nl' => 'Samen bepalen we tekst, plaatsing, materiaal en formaat, tot het ontwerp helemaal klopt.',
                'body_en' => 'Together we settle on text, placement, material and size, until the design feels exactly right.',
                'sort_order' => 1,
            ],
            [
                'title_nl' => 'Graveren met precisie',
                'title_en' => 'Precision engraving',
                'body_nl' => 'Met de CO₂- en MOPA-laser breng ik de gravure nauwkeurig en zorgvuldig aan.',
                'body_en' => 'Using the CO₂ and MOPA laser, I engrave the piece with care and precision.',
                'sort_order' => 2,
            ],
            [
                'title_nl' => 'Ophalen of verzenden',
                'title_en' => 'Pick up or delivery',
                'body_nl' => 'Je product wordt afgewerkt en is klaar om op te halen in Nijmegen of te verzenden.',
                'body_en' => 'Your piece is finished and ready for pickup in Nijmegen or for shipping.',
                'sort_order' => 3,
            ],
        ];

        $rows = [];
        foreach ($items as $item) {
            $rows[] = $item + [
                'step_list_section_id' => $sectionId,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $table->insert($rows)->saveData();
    }

    public function down(): void
    {
        $this->table('step_list_items')->drop()->save();
    }
}
