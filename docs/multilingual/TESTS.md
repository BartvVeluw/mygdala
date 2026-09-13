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
| CMS-taal, de catalogi, een nieuwe sleutel | `fast` |
| De bewerktaal, of de onafhankelijkheid van de drie | `fast` |
| Vertaalprovider, vertaalstatus, DeepL | `fast` |
| Een editor aansluiten op de taalvelden | `fast` → `blocks` |
| Instellingen-, account- of wizardscherm | `fast` → `cms` |
| Migratie of wat een verse installatie krijgt | `migration` |

## De bestanden

In `fast`:

- `tests/Service/LanguageRegistryTest.php`
- `tests/Service/LocalizedValueTest.php`
- `tests/Service/AdminLocaleTest.php`
- `tests/Service/ThreeLanguageStatesTest.php`
- `tests/Service/TranslationProviderTest.php`
- `tests/Service/MultilingualBoundaryTest.php`

Alle zes de bestanden zitten in `fast`: geen database, geen webserver, geen
netwerk.

In `cms`:

- `tests/Repository/LocalizedNavigationFooterPersistenceTest.php`

De twee migratietests, `MigrationTableNamesTest` en
`ContentLanguageSettingRepairTest`, staan met uitleg in
[`MIGRATIONS.md`](MIGRATIONS.md).

## Wat de twee grenstests bewaken

`ThreeLanguageStatesTest` loopt de matrix af — CMS NL/EN × bewerktaal NL/EN —
en controleert per combinatie dat de CMS-labels de interfacetaal volgen, dat
de editor naar de gekozen contenttaal wijst, dat de andere taal bewaard
blijft, en dat geen van beide voorkeuren de bezoeker raakt.

`MultilingualBoundaryTest` bewaakt de grenzen — de API-sleutel, de
onafhankelijkheid van de drie taalstaten, dat het vertaalendpoint niets
schrijft, dat de publieke taalwissel niet achter een instelling zit, dat
`::enabled()` de deprecated rij niet leest, dat er geen `_nl`/`_en`-kolom
verdwijnt en dat er geen hreflang binnensluipt.
