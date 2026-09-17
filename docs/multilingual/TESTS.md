# Meertaligheid testen

Deel van [`MULTILINGUAL.md`](../../MULTILINGUAL.md). Welke tests de
meertaligheid bewaken en welke suite je na een wijziging draait. Commando's en
tiers staan in [`TESTING.md`](../../TESTING.md).

```bash
docker compose exec php_test php vendor/bin/phpunit --testsuite fast
docker compose exec php_test php vendor/bin/phpunit --testsuite cms
```

Draait de testcontainer niet, of draait je worktree anders, volg dan
[`TESTING.md`](../../TESTING.md): "Het commando", "`fast` wil wél dat alle
modules aan staan" en "Vanuit een git worktree".

## Per wijziging

| Wijziging | Draai |
|---|---|
| Talenregister, sitetalen, terugvalregel | `fast` |
| Het register `site_languages` of zijn repository | `fast` → `cms` |
| CMS-taal, de catalogi, een nieuwe sleutel | `fast` |
| De bewerktaal, of de onafhankelijkheid van de drie | `fast` |
| Vertaalprovider, vertaalstatus, DeepL | `fast` |
| Een editor aansluiten op de taalvelden | `fast` → `blocks` |
| Instellingen-, account- of wizardscherm | `fast` → `cms` |
| Migratie of wat een verse installatie krijgt | `migration` |
| Paginatekst per taal (`PageLocalization`, `page_translations`, de pagina-editor) | `fast` → `cms` |

## De bestanden

In `fast`:

- `tests/Service/LanguageRegistryTest.php`
- `tests/Service/LocalizedValueTest.php`
- `tests/Service/AdminLocaleTest.php`
- `tests/Service/ThreeLanguageStatesTest.php`
- `tests/Service/TranslationProviderTest.php`
- `tests/Service/MultilingualBoundaryTest.php`
- `tests/Service/LanguageCodeTest.php`
- `tests/Service/SiteLanguagesTest.php`
- `tests/Service/PageLocalizationTest.php` — de Pages-API van fase 2: terugval
  per veld, de naam in het CMS, de NL/EN-uitvoeradapter en een derde taal

Alle negen de bestanden zitten in `fast`: geen database, geen webserver, geen
netwerk. Het talenregister vervangen ze in het geheugen met
`Tests\Support\SiteLanguageFixture`.

In `cms`:

- `tests/Repository/LocalizedNavigationFooterPersistenceTest.php`
- `tests/Repository/SiteLanguageRepositoryTest.php` — de invarianten van
  `site_languages` tegen de testdatabase, elke test in een transactie die
  wordt teruggedraaid
- `tests/Repository/PageTranslationRepositoryTest.php` — `page_translations`:
  uniek per taal, de twee foreign keys, opslaan per taal en een Duitse rij
  zonder schemawijziging
- `tests/Service/PageLocalizationEditorHttpTest.php` — de pagina-editor over
  echte HTTP: één taal op het scherm, opslaan per taal, de verplichte titel in
  de standaardtaal, een geweigerde taal, Duits via het register, een nieuwe
  pagina in de standaardtaal, en de NL/EN-uitvoer op de publieke pagina en in
  het concept-voorbeeld

De drie migratietests, `MigrationTableNamesTest`,
`ContentLanguageSettingRepairTest` en `SiteLanguageRegistryMigrationTest`,
staan met uitleg in [`MIGRATIONS.md`](MIGRATIONS.md).

## Wat de twee grenstests bewaken

`ThreeLanguageStatesTest` loopt de matrix af — CMS NL/EN × bewerktaal NL/EN —
en controleert per combinatie dat de CMS-labels de interfacetaal volgen, dat
de editor naar de gekozen contenttaal wijst, dat de andere taal bewaard
blijft, en dat geen van beide voorkeuren de bezoeker raakt.

`MultilingualBoundaryTest` bewaakt de grenzen — de API-sleutel, de
onafhankelijkheid van de drie taalstaten, dat het vertaalendpoint niets
schrijft, dat de publieke taalwissel niet achter een instelling zit, dat
`::enabled()` geen opgeslagen waarde leest, dat er geen `_nl`/`_en`-kolom
verdwijnt en dat er geen hreflang binnensluipt. Sinds Multilingual 2.0 fase 1
ook: dat de taalkern en de CMS-taal elkaars opslag niet noemen, dat de
taalkern geen taalcode of taalnaam in code noemt (commentaar telt niet mee),
dat geen websitetaal via `AdminLocale` gevalideerd wordt (ook niet in de
installatiewizard), en dat alleen `SiteLanguageRepository` SQL op
`site_languages` uitvoert. Sinds fase 2 ook: dat alleen
`PageTranslationRepository` SQL op `page_translations` uitvoert en alleen
`PageLocalization` die repository gebruikt, dat niets de zes gedropte
paginakolommen nog leest, dat de terugval van paginatekst op één plek staat,
dat de editorcomponent geen taal bij naam kent, dat de pagina-endpoints alleen
via de API schrijven, en dat de schakelaar in de schil zijn talen uit het
register haalt.

`LanguageCodeTest` bewijst dat de coderegel een vorm is en geen lijst: alle
676 paren van twee kleine letters zijn geldig, ook talen die nergens in PHP
staan. `SiteLanguagesTest` doet hetzelfde voor het register met `es`, `pt`,
`pl`, `sv`, `da` en `cs`. `SetupWizardValidationTest` (`fast`) legt vast hoe
de wizard de websitetaal leest.
