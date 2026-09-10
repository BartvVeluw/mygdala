<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Question/answer pairs for the faq_sections repeater (see previous
 * migration's docblock). Named question_nl/question_en/answer_nl/answer_en
 * rather than question_1/question_2 — each row is one FAQ item, there is no
 * fixed count.
 *
 * sort_order controls display order using the same "swap with neighbour"
 * convention as feature_grid_items.sort_order (see
 * FeatureGridRepository::moveItem / FaqRepository::moveItem). is_active lets
 * a single item be hidden without deleting it — a deleted item and a merely
 * hidden one (is_active = 0) are deliberately different: see
 * App\Service\FaqContent for how that distinction is preserved when the
 * database is unreachable.
 *
 * Seeded with the exact 5 questions currently hardcoded on diensten.php's
 * `.faq-list`, so the public site keeps rendering identical output the
 * moment this migration runs.
 */
final class CreateFaqItemsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('faq_items', ['id' => true]);
        $table
            ->addColumn('faq_section_id', 'integer', ['signed' => false])
            ->addColumn('question_nl', 'string', ['limit' => 255])
            ->addColumn('question_en', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('answer_nl', 'text')
            ->addColumn('answer_en', 'text', ['null' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('faq_section_id', 'faq_sections', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addIndex(['faq_section_id'])
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

        $faqId = (int) $this->fetchRow(
            "SELECT id FROM faq_sections WHERE page_slug = 'diensten' AND section_key = 'faq'"
        )['id'];

        $items = [
            [
                'question_nl' => 'Wat kost een gravure?',
                'question_en' => 'What does an engraving cost?',
                'answer_nl' => 'De prijs hangt af van materiaal, formaat, complexiteit van het ontwerp en het aantal stuks. Stuur je idee via het contactformulier, dan ontvang je een vrijblijvende offerte op maat.',
                'answer_en' => "The price depends on the material, size, design complexity and quantity. Send your idea through the contact form and you'll receive a free, no-obligation quote.",
                'sort_order' => 0,
            ],
            [
                'question_nl' => 'Kan ik zelf een ontwerp aanleveren?',
                'question_en' => 'Can I submit my own design?',
                'answer_nl' => 'Zeker. Deel je bestand, foto of logo via het contactformulier. Niet elk bestand is direct geschikt om te graveren — indien nodig pas ik het ontwerp aan zodat het goed uitkomt op het gekozen materiaal.',
                'answer_en' => "Absolutely. Share your file, photo or logo through the contact form. Not every file is immediately suitable for engraving — if needed, I'll adjust the design so it comes out well on the chosen material.",
                'sort_order' => 1,
            ],
            [
                'question_nl' => 'Hoe lang duurt een bestelling?',
                'question_en' => 'How long does an order take?',
                'answer_nl' => 'Dat hangt af van het ontwerp en de drukte op dat moment. Bij de offerte krijg je een indicatie van de levertijd, zodat je weet waar je aan toe bent.',
                'answer_en' => "That depends on the design and current workload. You'll get a delivery estimate along with your quote, so you know what to expect.",
                'sort_order' => 2,
            ],
            [
                'question_nl' => 'Kan ik mijn eigen product laten graveren?',
                'question_en' => 'Can I have my own item engraved?',
                'answer_nl' => 'In veel gevallen wel, bijvoorbeeld gereedschap of een persoonlijk voorwerp. Stuur een foto, de afmetingen en het materiaal (als bekend) mee, dan bekijk ik wat technisch mogelijk is.',
                'answer_en' => "In many cases, yes — for example a tool or a personal item. Send a photo, the dimensions and the material (if known), and I'll look at what's technically possible.",
                'sort_order' => 3,
            ],
            [
                'question_nl' => 'Kan ik het bestellen afhalen in Nijmegen?',
                'question_en' => 'Can I pick up my order in Nijmegen?',
                'answer_nl' => 'Ja, ophalen in Nijmegen is mogelijk. Verzenden kan ook — dit stemmen we af zodra je bestelling klaar is.',
                'answer_en' => 'Yes, pickup in Nijmegen is possible. Shipping is also an option — we\'ll arrange this once your order is ready.',
                'sort_order' => 4,
            ],
        ];

        $rows = [];
        foreach ($items as $item) {
            $rows[] = $item + [
                'faq_section_id' => $faqId,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $table->insert($rows)->saveData();
    }

    public function down(): void
    {
        $this->table('faq_items')->drop()->save();
    }
}
