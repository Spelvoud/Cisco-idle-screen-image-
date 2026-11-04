<?php
/**
 * Genereert een PNG van 298x156 met de laatste headlines van de NOS,
 * bedoeld als idle screen voor Cisco IP-telefoons (o.a. 7945/7965/7975).
 *
 * Gebruik:
 *   - via de webserver: http://server/nos.php  -> stuurt de PNG direct terug
 *   - via de commandline: php nos.php [uitvoer.png]  -> schrijft een bestand
 *                                                     (standaard nos.png)
 *
 * Versie "doorlopend" (http://server/nos.php?versie=doorlopend of
 * php nos.php uitvoer.png doorlopend): minder headlines onder de foto,
 * die over maximaal twee regels doorlopen in plaats van te worden afgekapt.
 *
 * Nieuws komt uit de openbare RSS-feed van de NOS. Bij het eerste bericht
 * wordt de bijbehorende foto als thumbnail getoond.
 */

const WIDTH    = 298;
const HEIGHT   = 156;
const FEED_URL = 'https://feeds.nos.nl/nosnieuwsalgemeen';

// Thumbnail van het eerste bericht (16:9)
const THUMB_X = 4;
const THUMB_Y = 22;
const THUMB_W = 100;
const THUMB_H = 56;

// Overige headlines onder de thumbnail
const HEADLINE_COUNT  = 5;
const HEADLINE_SIZE   = 6;
const HEADLINE_HEIGHT = 13;

// Versie "doorlopend": headlines over meerdere regels
const WRAP_LINES       = 2;
const WRAP_SIZE        = 7;
const WRAP_LINE_HEIGHT = 11;
const WRAP_GAP         = 4;

// TrueType-lettertypen; het eerste bestand dat bestaat wordt gebruikt.
// Is er geen enkel lettertype beschikbaar, dan valt het script terug op de ingebouwde GD-fonts.
const FONT_CANDIDATES = [
    __DIR__ . '/fonts/DejaVuSans.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/TTF/DejaVuSans.ttf',
    'C:/Windows/Fonts/arial.ttf',
];
const FONT_BOLD_CANDIDATES = [
    __DIR__ . '/fonts/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
    'C:/Windows/Fonts/arialbd.ttf',
];

/** Haalt een URL op via curl of file_get_contents. Geeft null terug als het mislukt. */
function httpGet(string $url, int $timeout = 10): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Cisco-idle-screen/1.0',
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body === false || $status !== 200) ? null : $body;
    }

    $context = stream_context_create(['http' => [
        'timeout'    => $timeout,
        'user_agent' => 'Cisco-idle-screen/1.0',
    ]]);
    $body = @file_get_contents($url, false, $context);
    return $body === false ? null : $body;
}

/** Maakt tekst uit de feed schoon: HTML-tags en entities weg, witruimte samenvoegen. */
function cleanText(string $text): string
{
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text));
}

/** Zoekt de afbeelding bij een RSS-item (enclosure, media:content/thumbnail of <img> in de omschrijving). */
function findImageUrl(SimpleXMLElement $item): ?string
{
    foreach ($item->enclosure as $enclosure) {
        $type = (string) $enclosure['type'];
        if ($type === '' || strpos($type, 'image/') === 0) {
            return (string) $enclosure['url'];
        }
    }

    $media = $item->children('http://search.yahoo.com/mrss/');
    foreach (['thumbnail', 'content'] as $tag) {
        if (isset($media->{$tag}) && (string) $media->{$tag}->attributes()->url !== '') {
            return (string) $media->{$tag}->attributes()->url;
        }
    }

    if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', (string) $item->description, $m)) {
        return html_entity_decode($m[1]);
    }
    return null;
}

/**
 * Haalt de laatste headlines op uit de NOS-feed.
 * Geeft een lijst met ['title' => ..., 'image' => ...] terug, of null als het ophalen mislukt.
 */
function fetchHeadlines(int $max = 8): ?array
{
    $xml = httpGet(FEED_URL);
    if ($xml === null) {
        return null;
    }

    $previous = libxml_use_internal_errors(true);
    $rss = simplexml_load_string($xml);
    libxml_use_internal_errors($previous);
    if ($rss === false || !isset($rss->channel->item)) {
        return null;
    }

    $headlines = [];
    foreach ($rss->channel->item as $item) {
        $title = cleanText((string) $item->title);
        if ($title === '') {
            continue;
        }
        $headlines[] = ['title' => $title, 'image' => findImageUrl($item)];
        if (count($headlines) >= $max) {
            break;
        }
    }
    return $headlines ?: null;
}

/** Zoekt het eerste bruikbare TrueType-lettertype uit de lijst. */
function findFont(array $candidates): ?string
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

/** Breedte van een tekst in pixels. */
function textWidth(string $text, float $size, ?string $font): int
{
    if ($font === null) {
        return imagefontwidth(2) * strlen(utf8_decode_safe($text));
    }
    $box = imagettfbbox($size, 0, $font, $text);
    return abs($box[2] - $box[0]);
}

/** Zet UTF-8 om naar Latin-1 voor de ingebouwde GD-fonts. */
function utf8_decode_safe(string $text): string
{
    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
    }
    return preg_replace('/[^\x20-\x7E]/', '?', $text);
}

/** Schrijft tekst; met TTF-lettertype als dat er is, anders met een ingebouwd font. $y is de basislijn. */
function drawText($img, string $text, int $x, int $y, float $size, int $color, ?string $font): void
{
    if ($font !== null) {
        imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
    } else {
        imagestring($img, 2, $x, $y - 11, utf8_decode_safe($text), $color);
    }
}

/** Kort een tekst in met een beletselteken zodat hij binnen $maxWidth past. */
function fitText(string $text, int $maxWidth, float $size, ?string $font): string
{
    if (textWidth($text, $size, $font) <= $maxWidth) {
        return $text;
    }
    while (mb_strlen($text) > 1 && textWidth($text . '…', $size, $font) > $maxWidth) {
        $text = rtrim(mb_substr($text, 0, -1));
    }
    return $text . '…';
}

/** Verdeelt een tekst over maximaal $maxLines regels; de laatste regel wordt zo nodig ingekort. */
function wrapText(string $text, int $maxWidth, int $maxLines, float $size, ?string $font): array
{
    $words = explode(' ', $text);
    $lines = [];
    $line = '';

    while ($words) {
        $candidate = $line === '' ? $words[0] : $line . ' ' . $words[0];
        if (textWidth($candidate, $size, $font) <= $maxWidth || $line === '') {
            $line = $candidate;
            array_shift($words);
            continue;
        }
        $lines[] = $line;
        $line = '';
        if (count($lines) === $maxLines - 1) {
            break;
        }
    }

    // Laatste regel: alles wat over is, zo nodig ingekort
    $rest = trim($line . ' ' . implode(' ', $words));
    if ($rest !== '') {
        $lines[] = fitText($rest, $maxWidth, $size, $font);
    }
    return array_slice($lines, 0, $maxLines);
}

/** Tekent de thumbnail (bijgesneden tot 16:9) op de afbeelding. Geeft false terug als het mislukt. */
function drawThumbnail($img, ?string $url): bool
{
    if ($url === null || !function_exists('imagecreatefromstring')) {
        return false;
    }
    $data = httpGet($url, 5);
    $src = $data !== null ? @imagecreatefromstring($data) : false;
    if (!$src) {
        return false;
    }

    // Bijsnijden vanuit het midden zodat de verhouding gelijk is aan de thumbnail
    $srcW = imagesx($src);
    $srcH = imagesy($src);
    $ratio = THUMB_W / THUMB_H;
    if ($srcW / $srcH > $ratio) {
        $cropH = $srcH;
        $cropW = (int) round($srcH * $ratio);
    } else {
        $cropW = $srcW;
        $cropH = (int) round($srcW / $ratio);
    }
    $cropX = (int) (($srcW - $cropW) / 2);
    $cropY = (int) (($srcH - $cropH) / 2);

    imagecopyresampled($img, $src, THUMB_X, THUMB_Y, $cropX, $cropY, THUMB_W, THUMB_H, $cropW, $cropH);
    imagedestroy($src);
    return true;
}

/* ---------- Afbeelding opbouwen ---------- */

date_default_timezone_set('Europe/Amsterdam');

$version = PHP_SAPI === 'cli' ? ($argv[2] ?? '') : ($_GET['versie'] ?? '');
$wrapHeadlines = $version === 'doorlopend';

$headlines = fetchHeadlines();

// Truecolor, zodat de foto er goed uitziet
$img = imagecreatetruecolor(WIDTH, HEIGHT);

$colors = [
    'bg'      => imagecolorallocate($img, 255, 255, 255),
    'header'  => imagecolorallocate($img, 229, 28, 35),
    'text'    => imagecolorallocate($img, 25, 25, 25),
    'muted'   => imagecolorallocate($img, 110, 110, 110),
    'white'   => imagecolorallocate($img, 255, 255, 255),
    'line'    => imagecolorallocate($img, 220, 220, 220),
];

$font = findFont(FONT_CANDIDATES);
$fontBold = findFont(FONT_BOLD_CANDIDATES) ?? $font;

imagefilledrectangle($img, 0, 0, WIDTH - 1, HEIGHT - 1, $colors['bg']);

// Kopbalk met NOS-logo-tekst en tijdstip
imagefilledrectangle($img, 0, 0, WIDTH - 1, 17, $colors['header']);
drawText($img, 'NOS Nieuws', 5, 13, 9, $colors['white'], $fontBold);
$time = date('H:i');
drawText($img, $time, WIDTH - 5 - textWidth($time, 8, $font), 13, 8, $colors['white'], $font);

if ($headlines === null) {
    drawText($img, 'Nieuws op dit moment', 10, 70, 10, $colors['text'], $fontBold);
    drawText($img, 'niet beschikbaar', 10, 88, 10, $colors['text'], $fontBold);
} else {
    // Eerste bericht: thumbnail met de headline ernaast
    $top = array_shift($headlines);
    $textX = THUMB_X;
    if (drawThumbnail($img, $top['image'])) {
        $textX = THUMB_X + THUMB_W + 6;
    }
    $lines = wrapText($top['title'], WIDTH - $textX - 4, 4, 8.5, $fontBold);
    foreach ($lines as $i => $line) {
        drawText($img, $line, $textX, THUMB_Y + 10 + $i * 14, 8.5, $colors['text'], $fontBold);
    }

    $y = THUMB_Y + THUMB_H + 4;
    imageline($img, 4, $y, WIDTH - 5, $y, $colors['line']);

    if ($wrapHeadlines) {
        // Overige headlines over maximaal WRAP_LINES regels, zolang ze volledig passen
        $y += WRAP_GAP - 1;
        foreach ($headlines as $headline) {
            $lines = wrapText($headline['title'], WIDTH - 14, WRAP_LINES, WRAP_SIZE, $font);
            if ($y + count($lines) * WRAP_LINE_HEIGHT > HEIGHT - 1) {
                break;
            }
            imagefilledrectangle($img, 4, $y + WRAP_LINE_HEIGHT - 6, 6, $y + WRAP_LINE_HEIGHT - 4, $colors['header']);
            foreach ($lines as $line) {
                $y += WRAP_LINE_HEIGHT;
                drawText($img, $line, 10, $y, WRAP_SIZE, $colors['text'], $font);
            }
            $y += WRAP_GAP;
        }
    } else {
        // Overige headlines, één regel per bericht
        foreach (array_slice($headlines, 0, HEADLINE_COUNT) as $headline) {
            $y += HEADLINE_HEIGHT;
            if ($y > HEIGHT - 3) {
                break;
            }
            imagefilledrectangle($img, 4, $y - 5, 6, $y - 3, $colors['header']);
            $title = fitText($headline['title'], WIDTH - 14, HEADLINE_SIZE, $font);
            drawText($img, $title, 10, $y, HEADLINE_SIZE, $colors['text'], $font);
        }
    }
}

// Uitvoer: bestand op de commandline, anders direct naar de browser/telefoon
if (PHP_SAPI === 'cli') {
    $output = $argv[1] ?? __DIR__ . '/nos.png';
    imagepng($img, $output);
    echo "Afbeelding opgeslagen als $output\n";
} else {
    header('Content-Type: image/png');
    header('Cache-Control: no-cache, max-age=0');
    imagepng($img);
}

imagedestroy($img);
