<?php
/**
 * Genereert een PNG van 800x480 met het actuele weer in Middelburg.
 *
 * Gebruik:
 *   - via de webserver: http://server/weather.php  -> stuurt de PNG direct terug
 *   - via de commandline: php weather.php [uitvoer.png] [thema]  -> schrijft een bestand
 *                                                                   (standaard weather.png)
 *
 * Thema's: "standaard" (blauw) of "efteling" (sprookjesachtig, met kasteel en paddenstoel).
 * Via de webserver kies je het thema met ?theme=efteling.
 *
 * Weerdata komt van Open-Meteo (https://open-meteo.com), gratis en zonder API-sleutel.
 */

const WIDTH     = 800;
const HEIGHT    = 480;
const CITY      = 'Middelburg';
const LATITUDE  = 51.4988;
const LONGITUDE = 3.6109;

// TrueType-lettertypen; het eerste bestand dat bestaat wordt gebruikt.
// Is er geen enkel lettertype beschikbaar, dan valt het script terug op de ingebouwde GD-fonts.
const FONT_CANDIDATES = [
    __DIR__ . '/fonts/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
    'C:/Windows/Fonts/arialbd.ttf',
];

// Schreeflettertypen voor het Efteling-thema
const SERIF_FONT_CANDIDATES = [
    __DIR__ . '/fonts/DejaVuSerif-Bold.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf',
    '/usr/share/fonts/dejavu/DejaVuSerif-Bold.ttf',
    '/usr/share/fonts/TTF/DejaVuSerif-Bold.ttf',
    'C:/Windows/Fonts/georgiab.ttf',
];

/**
 * Haalt het actuele weer op bij Open-Meteo.
 * Geeft null terug als het ophalen mislukt.
 */
function fetchWeather(): ?array
{
    $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
        'latitude'        => LATITUDE,
        'longitude'       => LONGITUDE,
        'current'         => 'temperature_2m,relative_humidity_2m,wind_speed_10m,weather_code,is_day',
        'wind_speed_unit' => 'kmh',
        'timezone'        => 'Europe/Amsterdam',
    ]);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $json = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($json === false || $status !== 200) {
            return null;
        }
    } else {
        $context = stream_context_create(['http' => ['timeout' => 10]]);
        $json = @file_get_contents($url, false, $context);
        if ($json === false) {
            return null;
        }
    }

    $data = json_decode($json, true);
    if (!isset($data['current'])) {
        return null;
    }

    $c = $data['current'];
    return [
        'temperature' => (float) $c['temperature_2m'],
        'humidity'    => (int) $c['relative_humidity_2m'],
        'wind_kmh'    => (float) $c['wind_speed_10m'],
        'code'        => (int) $c['weather_code'],
        'is_day'      => (bool) $c['is_day'],
        'time'        => $c['time'] ?? null,
    ];
}

/** Zet windsnelheid in km/u om naar windkracht op de schaal van Beaufort. */
function beaufort(float $kmh): int
{
    $limits = [1, 6, 12, 20, 29, 39, 50, 62, 75, 89, 103, 118];
    foreach ($limits as $bft => $limit) {
        if ($kmh < $limit) {
            return $bft;
        }
    }
    return 12;
}

/**
 * Vertaalt een WMO-weercode naar een categorie (voor het plaatje) en een Nederlandse omschrijving.
 */
function weatherCondition(int $code): array
{
    switch (true) {
        case $code === 0:
            return ['clear', 'Onbewolkt'];
        case $code === 1:
            return ['clear', 'Overwegend helder'];
        case $code === 2:
            return ['partly', 'Half bewolkt'];
        case $code === 3:
            return ['cloudy', 'Bewolkt'];
        case $code === 45 || $code === 48:
            return ['fog', 'Mist'];
        case $code >= 51 && $code <= 57:
            return ['rain', 'Motregen'];
        case $code >= 61 && $code <= 67:
            return ['rain', 'Regen'];
        case $code >= 71 && $code <= 77:
            return ['snow', 'Sneeuw'];
        case $code >= 80 && $code <= 82:
            return ['rain', 'Regenbuien'];
        case $code === 85 || $code === 86:
            return ['snow', 'Sneeuwbuien'];
        case $code >= 95:
            return ['thunder', 'Onweer'];
        default:
            return ['cloudy', 'Onbekend'];
    }
}

/** Zoekt een bruikbaar TrueType-lettertype. */
function findFont(array $candidates = FONT_CANDIDATES): ?string
{
    if (!function_exists('imagettftext')) {
        return null;
    }
    foreach ($candidates as $font) {
        if (is_readable($font)) {
            return $font;
        }
    }
    return null;
}

/** Schrijft tekst; met TTF-lettertype als dat er is, anders met het grootste ingebouwde font. */
function drawText($img, string $text, int $x, int $y, float $size, int $color, ?string $font): void
{
    if ($font !== null) {
        // $y is de basislijn van de tekst
        imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
    } else {
        imagestring($img, 5, $x, $y - 15, $text, $color);
    }
}

/* ---------- Weericonen, getekend met GD ---------- */

function drawSun($img, int $cx, int $cy, int $r, array $c): void
{
    imagesetthickness($img, 6);
    for ($i = 0; $i < 12; $i++) {
        $a = deg2rad($i * 30);
        imageline(
            $img,
            (int) ($cx + cos($a) * ($r + 14)), (int) ($cy + sin($a) * ($r + 14)),
            (int) ($cx + cos($a) * ($r + 36)), (int) ($cy + sin($a) * ($r + 36)),
            $c['sunRay']
        );
    }
    imagesetthickness($img, 1);
    imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2, $c['sun']);
}

function drawMoon($img, int $cx, int $cy, int $r, array $c): void
{
    imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2, $c['moon']);
    // Een cirkel in de achtergrondkleur maakt er een maansikkel van
    imagefilledellipse($img, $cx + (int) ($r * 0.55), $cy - (int) ($r * 0.35), (int) ($r * 1.7), (int) ($r * 1.7), $c['bg']);
}

function drawCloud($img, int $cx, int $cy, float $scale, int $color): void
{
    $s = fn($v) => (int) round($v * $scale);
    imagefilledellipse($img, $cx - $s(60), $cy + $s(10), $s(110), $s(90), $color);
    imagefilledellipse($img, $cx + $s(5), $cy - $s(25), $s(140), $s(130), $color);
    imagefilledellipse($img, $cx + $s(70), $cy + $s(10), $s(110), $s(90), $color);
    imagefilledrectangle($img, $cx - $s(60), $cy + $s(10), $cx + $s(70), $cy + $s(55), $color);
}

function drawRain($img, int $cx, int $cy, array $c): void
{
    imagesetthickness($img, 5);
    for ($i = -2; $i <= 2; $i++) {
        $x = $cx + $i * 34;
        $y = $cy + (abs($i) % 2) * 18;
        imageline($img, $x, $y, $x - 12, $y + 34, $c['rain']);
    }
    imagesetthickness($img, 1);
}

function drawSnow($img, int $cx, int $cy, array $c): void
{
    imagesetthickness($img, 3);
    for ($i = -2; $i <= 2; $i++) {
        $x = $cx + $i * 34;
        $y = $cy + 16 + (abs($i) % 2) * 22;
        for ($k = 0; $k < 3; $k++) {
            $a = deg2rad($k * 60);
            $dx = (int) (cos($a) * 11);
            $dy = (int) (sin($a) * 11);
            imageline($img, $x - $dx, $y - $dy, $x + $dx, $y + $dy, $c['snow']);
        }
    }
    imagesetthickness($img, 1);
}

function drawLightning($img, int $cx, int $cy, array $c): void
{
    $points = [
        $cx + 10, $cy,
        $cx - 22, $cy + 50,
        $cx - 2,  $cy + 50,
        $cx - 14, $cy + 92,
        $cx + 28, $cy + 34,
        $cx + 6,  $cy + 34,
        $cx + 22, $cy,
    ];
    imagefilledpolygon($img, $points, $c['bolt']);
}

function drawFog($img, int $cx, int $cy, array $c): void
{
    imagesetthickness($img, 10);
    $widths = [180, 220, 160, 200, 140];
    foreach ($widths as $i => $w) {
        $y = $cy - 80 + $i * 40;
        $offset = ($i % 2) ? 20 : -20;
        imageline($img, $cx - $w / 2 + $offset, $y, $cx + $w / 2 + $offset, $y, $c['fog']);
    }
    imagesetthickness($img, 1);
}

/** Tekent het icoon dat past bij de weersituatie, gecentreerd rond ($cx, $cy). */
function drawWeatherIcon($img, string $type, bool $isDay, int $cx, int $cy, array $c): void
{
    $celestial = $isDay ? 'drawSun' : 'drawMoon';

    switch ($type) {
        case 'clear':
            $celestial($img, $cx, $cy, 70, $c);
            break;
        case 'partly':
            $celestial($img, $cx + 45, $cy - 45, 50, $c);
            drawCloud($img, $cx - 15, $cy + 30, 1.0, $c['cloud']);
            break;
        case 'cloudy':
            drawCloud($img, $cx + 40, $cy - 30, 0.75, $c['cloudDark']);
            drawCloud($img, $cx - 15, $cy + 25, 1.0, $c['cloud']);
            break;
        case 'rain':
            drawCloud($img, $cx, $cy - 30, 1.0, $c['cloudDark']);
            drawRain($img, $cx, $cy + 50, $c);
            break;
        case 'snow':
            drawCloud($img, $cx, $cy - 30, 1.0, $c['cloud']);
            drawSnow($img, $cx, $cy + 50, $c);
            break;
        case 'thunder':
            drawCloud($img, $cx, $cy - 30, 1.0, $c['cloudDark']);
            drawLightning($img, $cx, $cy + 25, $c);
            break;
        case 'fog':
            drawFog($img, $cx, $cy, $c);
            break;
    }
}

/* ---------- Efteling-decoraties ---------- */

/** Fonkelende sterretjes in de lucht (vaste posities, zodat het beeld niet verspringt). */
function drawStars($img, array $c): void
{
    mt_srand(1952); // het openingsjaar van de Efteling
    for ($i = 0; $i < 70; $i++) {
        $x = mt_rand(25, WIDTH - 25);
        $y = mt_rand(25, 300);
        // geen sterretjes door de tekst heen
        if (($y < 110 && $x < 560) || ($x < 470 && $y > 130)) {
            continue;
        }
        if ($i % 7 === 0) {
            // een groter, vierpuntig sterretje
            imageline($img, $x - 5, $y, $x + 5, $y, $c['star']);
            imageline($img, $x, $y - 5, $x, $y + 5, $c['star']);
        } else {
            imagesetpixel($img, $x, $y, $c['star']);
            imagesetpixel($img, $x + 1, $y, $c['star']);
        }
    }
}

/** Gouden sierlijst met ornamenten in de hoeken. */
function drawOrnateBorder($img, array $c): void
{
    imagesetthickness($img, 4);
    imagerectangle($img, 8, 8, WIDTH - 9, HEIGHT - 9, $c['gold']);
    imagesetthickness($img, 1);
    imagerectangle($img, 16, 16, WIDTH - 17, HEIGHT - 17, $c['goldDark']);

    foreach ([[8, 8], [WIDTH - 9, 8], [8, HEIGHT - 9], [WIDTH - 9, HEIGHT - 9]] as [$x, $y]) {
        imagefilledpolygon($img, [$x, $y - 16, $x + 16, $y, $x, $y + 16, $x - 16, $y], $c['gold']);
        imagefilledellipse($img, $x, $y, 12, 12, $c['red']);
        imagefilledellipse($img, $x, $y, 5, 5, $c['gold']);
    }

    // Kleine ruitjes halverwege elke zijde
    foreach ([[WIDTH / 2, 8], [WIDTH / 2, HEIGHT - 9], [8, HEIGHT / 2], [WIDTH - 9, HEIGHT / 2]] as [$x, $y]) {
        imagefilledpolygon($img, [$x, $y - 9, $x + 9, $y, $x, $y + 9, $x - 9, $y], $c['gold']);
    }
}

/** Een torentje met puntdak, vlaggetje en verlicht raampje. */
function drawTower($img, int $x, int $w, int $top, int $bottom, array $c): void
{
    imagefilledrectangle($img, $x, $top, $x + $w, $bottom, $c['castle']);
    $roofTop = $top - (int) ($w * 1.4);
    imagefilledpolygon($img, [$x - 5, $top, $x + $w + 5, $top, $x + (int) ($w / 2), $roofTop], $c['roof']);
    // vlaggetje
    $mx = $x + (int) ($w / 2);
    imageline($img, $mx, $roofTop, $mx, $roofTop - 12, $c['gold']);
    imagefilledpolygon($img, [$mx, $roofTop - 12, $mx + 11, $roofTop - 9, $mx, $roofTop - 6], $c['gold']);
    // raampje met boogje
    $ww = max(4, (int) ($w / 5));
    $wy = $top + (int) ($w / 3);
    imagefilledrectangle($img, $mx - $ww, $wy, $mx + $ww, $wy + 2 * $ww, $c['window']);
    imagefilledellipse($img, $mx, $wy, 2 * $ww + 1, 2 * $ww + 1, $c['window']);
}

/** Sprookjeskasteel als silhouet onderin het beeld, ca. 200 px breed. */
function drawCastle($img, int $left, int $bottom, array $c): void
{
    // Middenmuur met kantelen
    imagefilledrectangle($img, $left + 30, $bottom - 52, $left + 172, $bottom, $c['castle']);
    for ($x = $left + 30; $x < $left + 172; $x += 15) {
        imagefilledrectangle($img, $x, $bottom - 60, $x + 8, $bottom - 52, $c['castle']);
    }
    // Poort
    imagefilledrectangle($img, $left + 90, $bottom - 26, $left + 112, $bottom, $c['door']);
    imagefilledellipse($img, $left + 101, $bottom - 26, 22, 22, $c['door']);

    drawTower($img, $left + 6, 28, $bottom - 82, $bottom, $c);
    drawTower($img, $left + 168, 28, $bottom - 82, $bottom, $c);
    drawTower($img, $left + 84, 34, $bottom - 110, $bottom - 52, $c);
    drawTower($img, $left + 52, 20, $bottom - 76, $bottom - 52, $c);
    drawTower($img, $left + 132, 20, $bottom - 76, $bottom - 52, $c);
}

/** Rood-witte paddenstoel, zoals de bekende pratende paddenstoelen in het park. */
function drawMushroom($img, int $cx, int $bottom, int $size, array $c): void
{
    $stemW = (int) ($size * 0.45);
    $stemH = (int) ($size * 0.7);
    imagefilledrectangle($img, $cx - (int) ($stemW / 2), $bottom - $stemH, $cx + (int) ($stemW / 2), $bottom, $c['mushroomStem']);
    imagefilledellipse($img, $cx, $bottom, $stemW + 10, 10, $c['mushroomStem']);

    $capY = $bottom - $stemH + 4;
    imagefilledarc($img, $cx, $capY, $size * 2, (int) ($size * 1.5), 180, 360, $c['red'], IMG_ARC_PIE);
    foreach ([[-0.55, -0.25, 0.22], [0.1, -0.5, 0.26], [0.6, -0.2, 0.18], [-0.15, -0.15, 0.14]] as [$dx, $dy, $r]) {
        $d = (int) ($size * $r);
        imagefilledellipse($img, $cx + (int) ($size * $dx), $capY + (int) ($size * $dy), $d, $d, $c['mushroomStem']);
    }
}

/** Eenvoudig grasrandje onder kasteel en paddenstoel. */
function drawGrass($img, int $x1, int $x2, int $y, array $c): void
{
    imagefilledrectangle($img, $x1, $y, $x2, HEIGHT - 20, $c['grass']);
    for ($x = $x1; $x < $x2; $x += 8) {
        imagefilledpolygon($img, [$x, $y, $x + 8, $y, $x + 4, $y - 7], $c['grass']);
    }
}

/* ---------- Afbeelding opbouwen ---------- */

$theme = PHP_SAPI === 'cli' ? ($argv[2] ?? 'standaard') : ($_GET['theme'] ?? 'standaard');
$efteling = strtolower($theme) === 'efteling';

$weather = fetchWeather();

$img = imagecreate(WIDTH, HEIGHT);

// Bij imagecreate (palet-afbeelding) wordt de eerst toegewezen kleur de achtergrond
$isDay = $weather['is_day'] ?? true;
if ($efteling) {
    $colors = [
        'bg'           => $isDay ? imagecolorallocate($img, 38, 52, 120) : imagecolorallocate($img, 24, 18, 60),
        'panel'        => imagecolorallocate($img, 110, 18, 32),
        'text'         => imagecolorallocate($img, 255, 236, 180),
        'muted'        => imagecolorallocate($img, 230, 196, 120),
        'gold'         => imagecolorallocate($img, 212, 166, 60),
        'goldDark'     => imagecolorallocate($img, 150, 110, 40),
        'red'          => imagecolorallocate($img, 190, 30, 45),
        'star'         => imagecolorallocate($img, 255, 240, 190),
        'castle'       => $isDay ? imagecolorallocate($img, 70, 60, 120) : imagecolorallocate($img, 48, 36, 90),
        'roof'         => imagecolorallocate($img, 140, 30, 60),
        'window'       => imagecolorallocate($img, 255, 210, 90),
        'door'         => imagecolorallocate($img, 30, 20, 40),
        'grass'        => imagecolorallocate($img, 30, 90, 50),
        'mushroomStem' => imagecolorallocate($img, 250, 245, 230),
        'sun'          => imagecolorallocate($img, 255, 200, 40),
        'sunRay'       => imagecolorallocate($img, 255, 170, 30),
        'moon'         => imagecolorallocate($img, 255, 236, 180),
        'cloud'        => imagecolorallocate($img, 240, 236, 248),
        'cloudDark'    => imagecolorallocate($img, 165, 155, 195),
        'rain'         => imagecolorallocate($img, 140, 200, 255),
        'snow'         => imagecolorallocate($img, 255, 255, 255),
        'bolt'         => imagecolorallocate($img, 255, 220, 0),
        'fog'          => imagecolorallocate($img, 200, 195, 220),
    ];
    $font = findFont(SERIF_FONT_CANDIDATES) ?? findFont();
} else {
    $colors = [
        'bg'        => $isDay ? imagecolorallocate($img, 30, 90, 160) : imagecolorallocate($img, 15, 25, 55),
        'panel'     => $isDay ? imagecolorallocate($img, 22, 70, 130) : imagecolorallocate($img, 25, 38, 75),
        'text'      => imagecolorallocate($img, 255, 255, 255),
        'muted'     => imagecolorallocate($img, 185, 210, 235),
        'sun'       => imagecolorallocate($img, 255, 200, 40),
        'sunRay'    => imagecolorallocate($img, 255, 170, 30),
        'moon'      => imagecolorallocate($img, 235, 235, 210),
        'cloud'     => imagecolorallocate($img, 240, 244, 248),
        'cloudDark' => imagecolorallocate($img, 160, 172, 188),
        'rain'      => imagecolorallocate($img, 120, 190, 255),
        'snow'      => imagecolorallocate($img, 255, 255, 255),
        'bolt'      => imagecolorallocate($img, 255, 220, 0),
        'fog'       => imagecolorallocate($img, 200, 208, 216),
    ];
    $font = findFont();
}

// Het weericoon staat bij het Efteling-thema hoger, zodat er ruimte is voor het kasteel
$iconX = $efteling ? 620 : 615;
$iconY = $efteling ? 175 : 260;

if ($efteling) {
    drawStars($img, $colors);
    drawGrass($img, 500, WIDTH - 20, 448, $colors);
    drawCastle($img, 540, 450, $colors);
    drawMushroom($img, 520, 450, 18, $colors);
    drawMushroom($img, 762, 450, 15, $colors);
    drawOrnateBorder($img, $colors);
}

// Kop met plaatsnaam en tijdstip
$time = $weather['time'] ?? null;
$timeLabel = $time ? 'Bijgewerkt ' . date('d-m-Y H:i', strtotime($time)) : date('d-m-Y H:i');
if ($efteling) {
    drawText($img, 'Sprookjesweer in ' . CITY, 40, 68, 24, $colors['text'], $font);
    drawText($img, $timeLabel, 42, 100, 15, $colors['muted'], $font);
} else {
    drawText($img, CITY, 40, 70, 36, $colors['text'], $font);
    drawText($img, $timeLabel, 40, 105, 16, $colors['muted'], $font);
}

if ($weather === null) {
    drawText($img, 'Weergegevens', 40, 230, 28, $colors['text'], $font);
    drawText($img, 'niet beschikbaar', 40, 275, 28, $colors['text'], $font);
    drawWeatherIcon($img, 'cloudy', true, $iconX, $iconY, $colors);
} else {
    [$type, $description] = weatherCondition($weather['code']);
    $bft = beaufort($weather['wind_kmh']);

    // Grote temperatuur
    $temp = number_format($weather['temperature'], 1, ',', '') . ' °C';
    drawText($img, $temp, 40, 230, 72, $colors['text'], $font);
    drawText($img, $description, 44, 280, 26, $colors['muted'], $font);

    // Paneel met luchtvochtigheid en windkracht
    imagefilledrectangle($img, 30, 320, 470, 450, $colors['panel']);
    if ($efteling) {
        imagerectangle($img, 30, 320, 470, 450, $colors['gold']);
        imagerectangle($img, 35, 325, 465, 445, $colors['goldDark']);
    }
    drawText($img, 'Luchtvochtigheid', 50, 365, 18, $colors['muted'], $font);
    drawText($img, $weather['humidity'] . ' %', 315, 365, 22, $colors['text'], $font);
    drawText($img, 'Windkracht', 50, 420, 18, $colors['muted'], $font);
    drawText($img, $bft . ' Bft', 315, 420, 22, $colors['text'], $font);
    drawText($img, '(' . round($weather['wind_kmh']) . ' km/u)', 315, 442, 12, $colors['muted'], $font);

    drawWeatherIcon($img, $type, $weather['is_day'], $iconX, $iconY, $colors);
}

// Uitvoer: bestand op de commandline, anders direct naar de browser/telefoon
if (PHP_SAPI === 'cli') {
    $output = $argv[1] ?? __DIR__ . '/weather.png';
    imagepng($img, $output);
    echo "Afbeelding opgeslagen als $output\n";
} else {
    header('Content-Type: image/png');
    header('Cache-Control: no-cache, max-age=0');
    imagepng($img);
}

imagedestroy($img);
