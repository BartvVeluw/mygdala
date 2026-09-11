# src/Service/Redirects

De Redirect Manager: padnormalisatie, bestemmingen, opslaanregels en de
opzoeking bij een verzoek.

- De opzoeking gebeurt op **één plek**: `404.php`, Apache's `ErrorDocument`.
  Dat is het enige punt waar een URL die Apache niet kon plaatsen PHP bereikt.
  Bouw nergens anders een tweede opzoeking.
- Een bestemming van een **uitgeschakelde module** wordt niet uitgevoerd. De
  bezoeker krijgt de 404 die hij toch al kreeg, de rij blijft ongewijzigd
  staan, en hij werkt weer zodra de module aan gaat.
- Kringetjes en ketens worden bij het **opslaan** geweigerd, niet bij het
  volgen.
- Een pagina of blogbericht hernoemen maakt automatisch een redirect.

Lees `../../../REDIRECTS.md`.
