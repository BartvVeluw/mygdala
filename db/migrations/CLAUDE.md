# db/migrations

Phinx-migraties, `YYYYMMDDHHMMSS_naam.php`.

- **Forward-only, idempotent en MySQL-compatibel.** Er zijn geen
  uninstall-migraties en geen `down()` waar je op mag rekenen.
- Nieuwe foreign keys: let op signed en unsigned. Phinx maakt `id`
  **unsigned**; een signed FK-kolom die daarnaar wijst weigert MySQL, en dan
  komt de migratie nooit af. Dat is hier één keer misgegaan.
- `bootstrap_a_generic_fresh_install` is dé plek waar staat wat een **nieuwe**
  installatie krijgt. Een bestaande database mag daar niets van merken.
- **Een fresh-install guard slaat data over, nooit schema.** Site-specifieke
  seed- of backfilldata mag achter `InstallState::isFreshInstall()`, maar
  elke schemawijziging staat ervóór, zodat een verse installatie en een
  legacy-upgrade op hetzelfde schema eindigen. Hier één keer misgegaan: zie
  `20260912100000` en `PortfolioCatalogueSchemaTest`.
- Een tabel voor een content-blok heeft minimaal `page_slug`, `section_key`,
  `is_active` en `UNIQUE(page_slug, section_key)`.
- Neemt je blok bestaande inhoud over, migreer die dan in dezelfde migratie
  mee.

Nieuwe migratie:

```bash
docker exec mygdala_php php vendor/bin/phinx create MyNewMigration
```

Lees `../../INSTALL-BOOTSTRAP.md`.
