<?php
/**
 * Cisco IP Phone XML-service: toont het weer in Middelburg (weather.php) als idle screen.
 *
 * Stel op de telefoon (of in CUCM bij het toestel) in:
 *   Idle URL:   http://server/pad/weather-idle.php
 *   Idle Timer: bijv. 60 seconden
 *
 * Het antwoord is een CiscoIPPhoneImageFile-object dat de PNG van 800x480 toont.
 * Met de HTTP-header "Refresh" haalt de telefoon deze pagina elke 10 minuten opnieuw op,
 * zodat het weer actueel blijft.
 */

const REFRESH_SECONDS = 600;

date_default_timezone_set('Europe/Amsterdam');

// Absolute URL's opbouwen op basis van de huidige request (de telefoon heeft volledige URL's nodig)
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = $scheme . '://' . $host . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

$selfUrl  = $base . '/weather-idle.php';
// Tijdstempel in de URL voorkomt dat de telefoon een oude afbeelding uit de cache toont
$imageUrl = $base . '/weather.php?t=' . time();

header('Content-Type: text/xml; charset=utf-8');
header('Cache-Control: no-cache, max-age=0');
header('Refresh: ' . REFRESH_SECONDS . '; url=' . $selfUrl);

$e = fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<CiscoIPPhoneImageFile>
  <Title>Weer Middelburg</Title>
  <Prompt>Bijgewerkt <?= $e(date('H:i')) ?></Prompt>
  <LocationX>0</LocationX>
  <LocationY>0</LocationY>
  <URL><?= $e($imageUrl) ?></URL>
</CiscoIPPhoneImageFile>
