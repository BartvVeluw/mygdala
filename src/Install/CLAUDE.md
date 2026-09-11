# src/Install

Drie dingen die je uit elkaar moet houden.

| Klasse | De vraag die hij beantwoordt |
|---|---|
| `InstallState` | Wordt deze database vanaf nul opgebouwd, of draagt hij al inhoud? |
| `SetupState` + `SetupWizard` | Moet een verse installatie nog ingericht worden, en wat wordt er dan gevraagd? |
| `FreshSiteCopyPolicy` | Wat is applicatie en wat is site-inhoud? De grens waar `scripts/create_fresh_site_copy.php` op loopt |

- Een **lege database is nog geen lege pagina**. Wat een verse installatie
  krijgt staat in de migratie `bootstrap_a_generic_fresh_install`.
- Een bestaande installatie mag van een bootstrap-wijziging niets merken.
- De installatietests draaien phinx vanaf nul tegen een **wegwerpdatabase**
  die ze zelf aanmaken. Ze hebben het MySQL-rootaccount nodig en slaan
  zichzelf over waar dat er niet is.

Lees `../../INSTALL-BOOTSTRAP.md` en `../../SETUP.md`. Test met
`--group migration-backfill`.
