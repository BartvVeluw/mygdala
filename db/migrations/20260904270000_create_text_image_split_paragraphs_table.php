<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Body paragraphs for the text_image_splits repeater (see previous
 * migration's docblock). Named content_nl/content_en rather than
 * paragraph_1/paragraph_2 — each row is one plain-text paragraph, there is
 * no fixed count (the "intro" section has 3, "idee-naar-product" has 1).
 * Content is plain text, never raw HTML/markup — the template wraps each
 * row in its own `<p>`.
 *
 * No is_active column here (unlike faq_items/feature_grid_items): a
 * paragraph has no meaningful "hidden but kept" state the admin UI needs —
 * deleting it is enough, same as how a paragraph would simply be removed
 * from hardcoded markup. sort_order controls display order using the same
 * "swap with neighbour" convention as faq_items.sort_order.
 *
 * The template decides whether the first paragraph gets the `.lead` style
 * (only when the section has no title — reproducing both current
 * instances from title presence alone) — that is presentation, not
 * content, so it is deliberately NOT a column here (no "is_lead" flag).
 *
 * Seeded with the exact paragraphs currently hardcoded in over-mij.php's
 * two `.service-detail__head` sections, so the public site keeps
 * rendering identical output the moment this migration runs.
 */
final class CreateTextImageSplitParagraphsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('text_image_split_paragraphs', ['id' => true]);
        $table
            ->addColumn('text_image_split_id', 'integer', ['signed' => false])
            ->addColumn('content_nl', 'text')
            ->addColumn('content_en', 'text', ['null' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('text_image_split_id', 'text_image_splits', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addIndex(['text_image_split_id'])
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

        $introId = (int) $this->fetchRow(
            "SELECT id FROM text_image_splits WHERE page_slug = 'over-mij' AND section_key = 'intro'"
        )['id'];
        $ideeId = (int) $this->fetchRow(
            "SELECT id FROM text_image_splits WHERE page_slug = 'over-mij' AND section_key = 'idee-naar-product'"
        )['id'];

        $rows = [
            [
                'text_image_split_id' => $introId,
                'content_nl' => 'Achter Van Veluw Laserdesign sta ik: iemand met een grote liefde voor ontwerpen, maken en het uitwerken van een idee tot iets tastbaars.',
                'content_en' => 'Behind Van Veluw Laserdesign is me: someone with a real love for designing, making, and turning an idea into something tangible.',
                'sort_order' => 0,
            ],
            [
                'text_image_split_id' => $introId,
                'content_nl' => "Wat begon als plezier in creatief bezig zijn, is uitgegroeid tot werk waarin ontwerp en techniek samenkomen. Ik houd me niet alleen bezig met graveren, maar ook met het ontwerpen van de producten zelf — en dat creatieve proces vind ik minstens zo belangrijk als het eindresultaat.",
                'content_en' => "What began as a creative hobby has grown into work where design and technique come together. I'm not just focused on engraving, but also on designing the products themselves — and I find that creative process just as important as the end result.",
                'sort_order' => 1,
            ],
            [
                'text_image_split_id' => $introId,
                'content_nl' => 'Ik besteed veel tijd aan het uitdenken van vormen, het kiezen van materialen en het zoeken naar een uitstraling die klopt. Ik werk vooral met hout, metaal en acryl, en maak zowel kant-en-klare items als persoonlijk maatwerk — denk aan onderzetters, naambordjes, sleutelhangers en andere ontwerpen die met zorg worden opgebouwd en afgewerkt.',
                'content_en' => 'I spend real time working out shapes, choosing materials, and finding a look that feels right. I mainly work with wood, metal and acrylic, making both ready-made items and personal custom pieces — think coasters, name signs, keyrings and other designs that are built up and finished with care.',
                'sort_order' => 2,
            ],
            [
                'text_image_split_id' => $ideeId,
                'content_nl' => 'Van Veluw Laserdesign draait voor mij om meer dan het eindresultaat alleen. Het gaat ook om het proces: van idee naar ontwerp, en van ontwerp naar een product dat met zorg is gemaakt — in mijn werkplaats in Nijmegen.',
                'content_en' => "For me, Van Veluw Laserdesign is about more than just the end result. It's also about the process: from idea to design, and from design to a product made with care — in my workshop in Nijmegen.",
                'sort_order' => 0,
            ],
        ];

        foreach ($rows as &$row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        unset($row);

        $table->insert($rows)->saveData();
    }

    public function down(): void
    {
        $this->table('text_image_split_paragraphs')->drop()->save();
    }
}
