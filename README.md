# Cisco idle screen – weer Middelburg

`weather.php` maakt met GD (`imagecreate`) een PNG van 800x480 met het actuele weer in Middelburg:
temperatuur, luchtvochtigheid, windkracht (Beaufort) en een icoon passend bij het weer
(zon/maan, half bewolkt, bewolkt, mist, regen, sneeuw, onweer).

Weerdata: [Open-Meteo](https://open-meteo.com) (gratis, geen API-sleutel).

## Gebruik

`weather-idle.php` is de Cisco IP Phone XML-service (`CiscoIPPhoneImageFile`) die deze PNG op de telefoon toont.
Via de HTTP-header `Refresh: 600` haalt de telefoon de service elke 10 minuten opnieuw op.

- Zet `weather.php` en `weather-idle.php` samen in één map op de webserver.
- Stel op de telefoon of in CUCM bij het toestel in:
  - **Idle URL**: `http://server/pad/weather-idle.php` (niet `weather.php`: de telefoon verwacht XML,
    een losse PNG geeft de fout "HTTP-inhoudstype niet ondersteund")
  - **Idle Timer**: bijv. `60`
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

# Visual voicemail – Cisco 8865

`voicemail.php` emuleert visual voicemail op Cisco IP-telefoons (o.a. 8865) met de Cisco IP Phone XML-services.
De berichten komen uit de voicemail-spool van Asterisk / FreePBX
(`/var/spool/asterisk/voicemail/<context>/<mailbox>/INBOX` en `Old`).

- **Inloggen**: mailbox + pincode (uit `voicemail.conf`), of automatisch voor vertrouwde toestellen (`trusted`: IP => mailbox).
- **Lijst** (`CiscoIPPhoneMenu`): alle berichten, nieuwste eerst; nieuwe berichten zijn gemarkeerd met `*`.
- **Details** (`CiscoIPPhoneText`): naam, nummer, datum, duur, met softkeys *Afspelen*, *Bellen* (terugbellen), *Wissen* en *Terug*.
- Na afspelen gaat een bericht van `INBOX` naar `Old`; wissen vraagt om bevestiging. Berichten worden daarna aaneengesloten
  hernummerd (zoals Asterisk verwacht) en via AMI `VoicemailRefresh` wordt het MWI-lampje bijgewerkt.

## Afspelen

- `playback => 'ami'` (standaard): Asterisk belt het toestel via de Manager Interface (`Originate` + `Playback`).
  Neem op en het bericht wordt afgespeeld. Maak in `manager.conf` een gebruiker aan met `read/write = originate,call,system`.
- `playback => 'rtp'`: de server pusht `RTPRx:<telefoon>:<poort>` naar `http://<telefoon>/CGI/Execute` en streamt het bericht
  met `ffmpeg` als G.711 µ-law RTP naar het toestel. Vereist een push-gebruiker (CUCM: gebruiker gekoppeld aan het toestel,
  *Authentication URL* ingesteld) en `ffmpeg` op de server.

## Gebruik

- Zet `voicemail.php` op een webserver die bij de voicemail-spool kan (lees- én schrijfrechten voor de webserver-gebruiker,
  bijv. door die in de groep `asterisk` te zetten).
- Pas de configuratie bovenin aan, of zet de instellingen in `voicemail-config.php`:
  ```php
  <?php return ['secret' => 'lang-willekeurig-geheim', 'context' => 'default',
                'ami' => ['username' => 'visualvm', 'secret' => '...', 'channel' => 'PJSIP/{mailbox}']];
  ```
- Voeg in CUCM een IP Phone Service toe met URL `http://server/pad/voicemail.php` (of `...?mb=100` om de mailbox vast te zetten)
  en koppel die eventueel aan de *Messages*-knop van het toestel.

Vereist PHP 7.4+ met curl (voor `rtp`) en eventueel posix.
