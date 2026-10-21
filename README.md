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
