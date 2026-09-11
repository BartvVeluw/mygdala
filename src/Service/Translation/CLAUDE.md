# src/Service/Translation

Automatisch vertalen: het providercontract, DeepL, de dienst die editors
aanroepen en de vertaalstatus.

- De provider is een **contract**, niet DeepL. Schrijf nooit rechtstreeks
  tegen DeepL vanuit een editor of een endpoint.
- Vertalen overschrijft nooit stilletjes wat een redacteur zelf schreef. De
  vier regels die dat bewaken staan in het document.
- Rich text heeft een eigen behandeling. Gooi er geen platte tekst doorheen.

Lees `../../../MULTILINGUAL.md`, hoofdstuk "Automatisch vertalen".
