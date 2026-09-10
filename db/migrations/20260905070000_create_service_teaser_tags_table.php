<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Ordered repeater of short "tag" labels shown on index.php's homepage
 * Diensten-carrousel card for each service (`.tag-list > .tag`) — see
 * 20260905060000_add_teaser_fields_to_services.php's docblock for the rest
 * of the homepage-teaser projection this belongs to.
 *
 * Same parent/child shape and "swap with neighbour" sort_order convention as
 * service_paragraphs (no is_active column: like paragraphs/images, a tag has
 * no meaningful "hidden but kept" state — delete it instead). Deliberately a
 * separate table from service_points ("kenmerken"): tags are short single
 * labels with no body text and render inline in a `.tag-list`, not as
 * icon+title+body cards.
 *
 * Seeded with the exact four tags per service that were hardcoded on
 * index.php's carousel cards before this migration, so the homepage keeps
 * rendering identical output the moment this migration runs.
 */
final class CreateServiceTeaserTagsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('service_teaser_tags', ['id' => true]);
        $table
            ->addColumn('service_id', 'integer', ['signed' => false])
            ->addColumn('label_nl', 'string', ['limit' => 60])
            ->addColumn('label_en', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('service_id', 'services', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addIndex(['service_id'])
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

        $houtId = (int) $this->fetchRow("SELECT id FROM services WHERE service_key = 'hout'")['id'];
        $metaalId = (int) $this->fetchRow("SELECT id FROM services WHERE service_key = 'metaal'")['id'];
        $acrylId = (int) $this->fetchRow("SELECT id FROM services WHERE service_key = 'acryl-glas'")['id'];
        $zakelijkId = (int) $this->fetchRow("SELECT id FROM services WHERE service_key = 'zakelijk'")['id'];

        $rows = [
            // Hout
            ['service_id' => $houtId, 'label_nl' => 'Snijplanken', 'label_en' => 'Cutting boards', 'sort_order' => 0],
            ['service_id' => $houtId, 'label_nl' => 'Onderzetters', 'label_en' => 'Coasters', 'sort_order' => 1],
            ['service_id' => $houtId, 'label_nl' => 'Wanddecoratie', 'label_en' => 'Wall decor', 'sort_order' => 2],
            ['service_id' => $houtId, 'label_nl' => 'Naambordjes', 'label_en' => 'Name signs', 'sort_order' => 3],
            // Metaal
            ['service_id' => $metaalId, 'label_nl' => 'Gereedschap', 'label_en' => 'Tools', 'sort_order' => 0],
            ['service_id' => $metaalId, 'label_nl' => 'Visitekaartjes', 'label_en' => 'Business cards', 'sort_order' => 1],
            ['service_id' => $metaalId, 'label_nl' => 'Naamplaatjes', 'label_en' => 'Nameplates', 'sort_order' => 2],
            ['service_id' => $metaalId, 'label_nl' => 'Sieraden', 'label_en' => 'Jewellery', 'sort_order' => 3],
            // Acryl & glas
            ['service_id' => $acrylId, 'label_nl' => 'Naambordjes', 'label_en' => 'Name signs', 'sort_order' => 0],
            ['service_id' => $acrylId, 'label_nl' => 'Displays', 'label_en' => 'Displays', 'sort_order' => 1],
            ['service_id' => $acrylId, 'label_nl' => 'Awards', 'label_en' => 'Awards', 'sort_order' => 2],
            ['service_id' => $acrylId, 'label_nl' => 'Op aanvraag', 'label_en' => 'On request', 'sort_order' => 3],
            // Zakelijk
            ['service_id' => $zakelijkId, 'label_nl' => 'Relatiegeschenken', 'label_en' => 'Corporate gifts', 'sort_order' => 0],
            ['service_id' => $zakelijkId, 'label_nl' => 'Bulkbestellingen', 'label_en' => 'Bulk orders', 'sort_order' => 1],
            ['service_id' => $zakelijkId, 'label_nl' => 'Serienummers', 'label_en' => 'Serial numbers', 'sort_order' => 2],
            ['service_id' => $zakelijkId, 'label_nl' => 'Logo & branding', 'label_en' => 'Logo & branding', 'sort_order' => 3],
        ];

        $insertRows = [];
        foreach ($rows as $row) {
            $insertRows[] = $row + ['created_at' => $now, 'updated_at' => $now];
        }

        $table->insert($insertRows)->saveData();
    }

    public function down(): void
    {
        $this->table('service_teaser_tags')->drop()->save();
    }
}
