# src/Update

De ingebouwde updater: een release-installatie werkt zichzelf vanuit het CMS
bij naar een gepubliceerde, ondertekende Mygdala-release. Geen Git, geen SSH,
geen handmatige upload.

- **`Ownership` is het eigendomscontract.** Wat een release bezit, wat van de
  installatie is (`.env`, uploads, `storage/`) en wat alleen in de repository
  hoort. De releasebouwer en de updater vragen allebei deze klasse. Een nieuwe
  uploadmap is één regel dáár, nooit een uitzondering elders.
- **Eén versie: `AppVersion::current()`**, uit het bestand `VERSION`. Nooit uit
  git. Releaseversie en migratieversie zijn onafhankelijk.
- **Alles wat de updater doet, komt uit het ondertekende manifest.** Een
  request levert nooit een URL, een pad of een versie aan.
- **`release.json` is het commitpunt.** Het wordt als laatste vervangen; welk
  `release.json` in de root staat, is de release die geïnstalleerd is.
- Codebestanden van de updater zelf moeten een update overleven terwijl ze
  vervangen worden: de stappen na "apply" draaien op de nieuwe code, dus het
  formaat van de state (`UpdateState::FORMAT`) verandert alleen bewust.

Lees `../../docs/updates/ARCHITECTURE.md`. Test met `--testsuite updater`.
