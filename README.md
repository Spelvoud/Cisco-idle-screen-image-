# Cisco idle screen – weer Middelburg

`weather.php` maakt met GD (`imagecreate`) een PNG van 800x480 met het actuele weer in Middelburg:
temperatuur, luchtvochtigheid, windkracht (Beaufort) en een icoon passend bij het weer
(zon/maan, half bewolkt, bewolkt, mist, regen, sneeuw, onweer).

Weerdata: [Open-Meteo](https://open-meteo.com) (gratis, geen API-sleutel).

## Gebruik

- Webserver: `http://server/weather.php` geeft direct de PNG terug (bijv. als idle-URL van de telefoon).
- Commandline: `php weather.php [uitvoer.png]` schrijft een bestand (standaard `weather.png`).

Vereist PHP 7.4+ met de GD-extensie (met FreeType) en curl of `allow_url_fopen`.
Voor nette tekst wordt een TrueType-lettertype gebruikt (DejaVu Sans Bold); zet eventueel een
`fonts/DejaVuSans-Bold.ttf` naast het script. Zonder lettertype valt het script terug op de ingebouwde GD-fonts.

# Cisco idle screen – NOS-headlines

`nos.php` maakt met GD een PNG van 298x156 (schermformaat van o.a. de Cisco 7945/7965/7975)
met de laatste headlines uit de RSS-feed van de NOS (`https://feeds.nos.nl/nosnieuwsalgemeen`).
Bij het eerste bericht staat de bijbehorende foto als thumbnail; daaronder volgen de andere headlines,
één regel per bericht.

`nos-idle.php` is de Cisco IP Phone XML-service (`CiscoIPPhoneImageFile`) die deze PNG op de telefoon toont.
Via de HTTP-header `Refresh: 600` haalt de telefoon de service elke 10 minuten opnieuw op.

## Gebruik

- Zet `nos.php` en `nos-idle.php` samen in één map op de webserver.
- Stel op de telefoon of in CUCM bij het toestel in:
  - **Idle URL**: `http://server/pad/nos-idle.php`
  - **Idle Timer**: bijv. `60` (seconden zonder bediening voordat het idle screen verschijnt)
- Commandline: `php nos.php [uitvoer.png]` schrijft een bestand (standaard `nos.png`).

Vereist PHP 7.4+ met GD (met FreeType en JPEG-ondersteuning), SimpleXML, mbstring en curl of `allow_url_fopen`.
Lettertype: DejaVu Sans (regular en bold); zet eventueel `fonts/DejaVuSans.ttf` en `fonts/DejaVuSans-Bold.ttf` naast het script.
