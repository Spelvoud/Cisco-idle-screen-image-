<?php
/**
 * Visual voicemail voor Cisco IP-telefoons (o.a. 8865) via Cisco IP Phone XML-services.
 *
 * De berichten komen uit de voicemail-spool van Asterisk / FreePBX
 * (/var/spool/asterisk/voicemail/<context>/<mailbox>/{INBOX,Old}/msgNNNN.{txt,wav}).
 *
 * Schermen:
 *   - Inloggen met pincode (CiscoIPPhoneInput), tenzij het toestel als vertrouwd is ingesteld
 *   - Berichtenlijst (CiscoIPPhoneMenu): nieuwe en beluisterde berichten, nieuwste eerst
 *   - Berichtdetails (CiscoIPPhoneText): beller, nummer, datum, duur
 *     met de softkeys Afspelen, Terugbellen, Verwijderen en Terug
 *
 * Afspelen kan op twee manieren (zie 'playback' in de configuratie):
 *   - 'ami': Asterisk belt het toestel via de Manager-interface (AMI) en speelt het bericht af
 *            zodra er wordt opgenomen. Werkt via hoorn, headset en speaker.
 *   - 'rtp': de server pusht "RTPRx:" naar de telefoon (CiscoIPPhoneExecute) en streamt het bericht
 *            met ffmpeg als G.711 RTP rechtstreeks naar het toestel.
 *
 * Instellen op de telefoon / in CUCM: voeg een IP Phone Service toe met als URL
 *   http://server/pad/voicemail.php            (of ...?mb=100 om het mailboxnummer vast te zetten)
 * en koppel die eventueel aan de Messages-knop (CUCM: "Voice Mail"-service-URL / Messages URL).
 *
 * Instellingen kunnen ook in voicemail-config.php naast dit script staan:
 *   <?php return ['secret' => '...', 'ami' => ['secret' => '...']];
 */

date_default_timezone_set('Europe/Amsterdam');

// ---------------------------------------------------------------------------------------------
// Configuratie
// ---------------------------------------------------------------------------------------------
$config = [
    // Asterisk-voicemail
    'spool'      => '/var/spool/asterisk/voicemail',
    'context'    => 'default',
    'vm_conf'    => '/etc/asterisk/voicemail.conf',   // voor de pincodes

    // Geheim voor het ondertekenen van de sessietoken. Verander dit!
    'secret'     => 'verander-mij',
    'session_ttl' => 3600,                            // seconden geldig na inloggen

    // Vertrouwde toestellen: IP-adres van de telefoon => mailbox (geen pincode nodig)
    'trusted'    => [
        // '192.168.1.50' => '100',
    ],

    // Afspelen: 'ami' of 'rtp'
    'playback'   => 'ami',

    // Asterisk Manager Interface (manager.conf), nodig voor 'ami' en voor het bijwerken van het MWI-lampje
    'ami' => [
        'enabled'  => true,
        'host'     => '127.0.0.1',
        'port'     => 5038,
        'username' => 'visualvm',
        'secret'   => 'verander-mij',
        // Kanaal waarop het toestel gebeld wordt; {mailbox} wordt vervangen door het mailboxnummer
        'channel'  => 'PJSIP/{mailbox}',
        'callerid' => '"Voicemail" <*97>',
    ],

    // RTP-streaming naar de telefoon (alleen voor 'rtp')
    'rtp' => [
        'ffmpeg'   => 'ffmpeg',
        'port'     => 20480,
        'volume'   => 50,              // 0-100, leeg laten = huidig volume van het toestel
        // Inloggegevens voor http://<telefoon>/CGI/Execute (CUCM: gebruiker gekoppeld aan het toestel,
        // met "Authentication URL" ingesteld)
        'username' => 'pushuser',
        'password' => 'verander-mij',
    ],

    'dial_prefix'    => '',            // voorloopcijfer voor terugbellen naar buiten, bijv. '0'
    'voicemail_dial' => '*97',         // softkey "Bel VM" in de lijst, leeg = geen softkey
    'new_marker'     => '* ',          // voorvoegsel voor nieuwe berichten in de lijst
    'max_items'      => 100,           // CiscoIPPhoneMenu staat maximaal 100 items toe
];

if (is_file(__DIR__ . '/voicemail-config.php')) {
    $local = include __DIR__ . '/voicemail-config.php';
    if (is_array($local)) {
        $config = array_replace_recursive($config, $local);
    }
}

const FOLDERS = ['INBOX' => 'Nieuw', 'Old' => 'Beluisterd'];

// ---------------------------------------------------------------------------------------------
// Hulpfuncties: XML en URL's
// ---------------------------------------------------------------------------------------------
function e(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function self_url(array $params = []): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $url    = $scheme . '://' . $host . ($_SERVER['SCRIPT_NAME'] ?? '/voicemail.php');
    return $params ? $url . '?' . http_build_query($params) : $url;
}

function softkey(string $name, string $url, int $pos): string
{
    return "<SoftKeyItem><Name>" . e($name) . "</Name><URL>" . e($url) . "</URL><Position>$pos</Position></SoftKeyItem>";
}

function send_xml(string $xml): void
{
    header('Content-Type: text/xml; charset=utf-8');
    header('Cache-Control: no-cache, max-age=0');
    header('Expires: -1');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xml;
    exit;
}

function text_screen(string $title, string $prompt, string $text, array $softkeys): void
{
    $keys = '';
    foreach (array_values($softkeys) as $i => [$name, $url]) {
        $keys .= softkey($name, $url, $i + 1);
    }
    send_xml("<CiscoIPPhoneText><Title>" . e($title) . "</Title><Prompt>" . e($prompt) . "</Prompt>"
        . "<Text>" . e($text) . "</Text>$keys</CiscoIPPhoneText>");
}

function error_screen(string $text): void
{
    text_screen('Voicemail', 'Fout', $text, [['Opnieuw', self_url()], ['Afsluiten', 'SoftKey:Exit']]);
}

// ---------------------------------------------------------------------------------------------
// Sessietoken (de telefoon onthoudt geen cookies betrouwbaar, dus de token gaat mee in de URL)
// ---------------------------------------------------------------------------------------------
function make_token(string $mailbox): string
{
    global $config;
    $exp = time() + (int)$config['session_ttl'];
    $sig = substr(hash_hmac('sha256', "$mailbox|$exp", $config['secret']), 0, 24);
    return "$mailbox.$exp.$sig";
}

function check_token(?string $token): ?string
{
    global $config;
    if (!$token || !preg_match('/^(\d+)\.(\d+)\.([0-9a-f]{24})$/', $token, $m)) {
        return null;
    }
    [, $mailbox, $exp, $sig] = $m;
    $good = substr(hash_hmac('sha256', "$mailbox|$exp", $config['secret']), 0, 24);
    return ((int)$exp >= time() && hash_equals($good, $sig)) ? $mailbox : null;
}

function vm_password(string $mailbox): ?string
{
    global $config;
    $lines = @file($config['vm_conf'], FILE_IGNORE_NEW_LINES) ?: [];
    $section = '';
    foreach ($lines as $line) {
        $line = trim(preg_replace('/;.*$/', '', $line));
        if (preg_match('/^\[(.+)\]$/', $line, $m)) {
            $section = $m[1];
        } elseif ($section === $config['context'] && preg_match('/^(\d+)\s*=>?\s*([^,]*)/', $line, $m) && $m[1] === $mailbox) {
            return ltrim(trim($m[2]), '-');   // "-1234" betekent: gebruiker mag pin niet wijzigen
        }
    }
    return null;
}

// ---------------------------------------------------------------------------------------------
// Voicemail-spool
// ---------------------------------------------------------------------------------------------
function mailbox_dir(string $mailbox): string
{
    global $config;
    return rtrim($config['spool'], '/') . '/' . $config['context'] . '/' . $mailbox;
}

function parse_msg_txt(string $file): array
{
    $info = [];
    foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\s*([a-z_]+)\s*=\s*(.*)$/i', $line, $m)) {
            $info[strtolower($m[1])] = trim($m[2]);
        }
    }
    // callerid = "Jan Jansen" <0612345678>
    $name = $number = '';
    $cid = $info['callerid'] ?? '';
    if (preg_match('/^\s*"?([^"<]*)"?\s*<([^>]*)>/', $cid, $m)) {
        [$name, $number] = [trim($m[1]), trim($m[2])];
    } else {
        $number = trim($cid, ' "');
    }
    if (strcasecmp($number, 'Unknown') === 0) {
        $number = '';
    }
    return [
        'name'     => $name,
        'number'   => $number,
        'time'     => (int)($info['origtime'] ?? filemtime($file)),
        'duration' => (int)($info['duration'] ?? 0),
        'msg_id'   => $info['msg_id'] ?? '',
    ];
}

/** Alle berichten uit INBOX en Old, nieuwste eerst. */
function list_messages(string $mailbox): array
{
    $messages = [];
    foreach (array_keys(FOLDERS) as $folder) {
        foreach (glob(mailbox_dir($mailbox) . "/$folder/msg[0-9][0-9][0-9][0-9].txt") ?: [] as $txt) {
            $msg = parse_msg_txt($txt);
            $msg['folder'] = $folder;
            $msg['base']   = substr($txt, 0, -4);   // pad zonder extensie
            // Stabiele sleutel: de bestandsnaam verandert bij verplaatsen/hernummeren, msg_id en origtime niet
            $msg['key']    = $msg['msg_id'] !== '' ? $msg['msg_id']
                : $msg['time'] . '-' . substr(md5($msg['number'] . '|' . $msg['duration']), 0, 8);
            $messages[] = $msg;
        }
    }
    usort($messages, fn($a, $b) => $b['time'] <=> $a['time']);
    return $messages;
}

function find_message(string $mailbox, string $key): ?array
{
    foreach (list_messages($mailbox) as $msg) {
        if ($msg['key'] === $key) {
            return $msg;
        }
    }
    return null;
}

function audio_file(array $msg): ?string
{
    foreach (['wav', 'WAV', 'gsm', 'ulaw', 'alaw', 'g722', 'sln'] as $ext) {
        if (is_file($msg['base'] . '.' . $ext)) {
            return $msg['base'] . '.' . $ext;
        }
    }
    return null;
}

function with_lock(string $mailbox, callable $fn)
{
    $fh = fopen(mailbox_dir($mailbox) . '/.visualvm.lock', 'c');
    if ($fh) {
        flock($fh, LOCK_EX);
    }
    try {
        return $fn();
    } finally {
        if ($fh) {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}

/** Hernummer msg0000..msgNNNN aaneengesloten; Asterisk verwacht geen gaten. */
function renumber(string $dir): void
{
    $txts = glob("$dir/msg[0-9][0-9][0-9][0-9].txt") ?: [];
    sort($txts);
    foreach ($txts as $i => $txt) {
        $from = substr($txt, 0, -4);
        $to   = sprintf('%s/msg%04d', $dir, $i);
        if ($from !== $to) {
            foreach (glob("$from.*") ?: [] as $file) {
                rename($file, $to . substr($file, strlen($from)));
            }
        }
    }
}

/** Verplaats een bericht naar een andere map (bijv. INBOX -> Old na beluisteren). Geeft de nieuwe basis terug. */
function move_message(string $mailbox, array $msg, string $folder): string
{
    return with_lock($mailbox, function () use ($mailbox, $msg, $folder) {
        $dir = mailbox_dir($mailbox) . "/$folder";
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $n = count(glob("$dir/msg[0-9][0-9][0-9][0-9].txt") ?: []);
        while (file_exists(sprintf('%s/msg%04d.txt', $dir, $n))) {
            $n++;
        }
        $to = sprintf('%s/msg%04d', $dir, $n);
        foreach (glob($msg['base'] . '.*') ?: [] as $file) {
            rename($file, $to . substr($file, strlen($msg['base'])));
        }
        renumber(dirname($msg['base']));
        return $to;
    });
}

function delete_message(string $mailbox, array $msg): void
{
    with_lock($mailbox, function () use ($msg) {
        foreach (glob($msg['base'] . '.*') ?: [] as $file) {
            unlink($file);
        }
        renumber(dirname($msg['base']));
    });
}

// ---------------------------------------------------------------------------------------------
// Asterisk Manager Interface
// ---------------------------------------------------------------------------------------------
function ami(array $action): ?array
{
    global $config;
    $ami = $config['ami'];
    if (empty($ami['enabled'])) {
        return null;
    }
    $sock = @fsockopen($ami['host'], (int)$ami['port'], $errno, $errstr, 3);
    if (!$sock) {
        return null;
    }
    stream_set_timeout($sock, 5);
    fgets($sock);   // banner "Asterisk Call Manager/x.y"

    $send = function (array $fields) use ($sock): array {
        $packet = '';
        foreach ($fields as $k => $v) {
            foreach ((array)$v as $item) {
                $packet .= "$k: $item\r\n";
            }
        }
        fwrite($sock, $packet . "\r\n");
        $resp = [];
        while (($line = fgets($sock)) !== false && trim($line) !== '') {
            if (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $resp[trim($k)] = trim($v);
            }
        }
        return $resp;
    };

    $login = $send(['Action' => 'Login', 'Username' => $ami['username'], 'Secret' => $ami['secret'], 'Events' => 'off']);
    $resp = null;
    if (($login['Response'] ?? '') === 'Success') {
        $resp = $send($action);
        $send(['Action' => 'Logoff']);
    }
    fclose($sock);
    return $resp;
}

/** Laat Asterisk de mailbox opnieuw tellen, zodat het MWI-lampje klopt. */
function refresh_mwi(string $mailbox): void
{
    global $config;
    ami(['Action' => 'VoicemailRefresh', 'Context' => $config['context'], 'Mailbox' => $mailbox]);
}

// ---------------------------------------------------------------------------------------------
// Afspelen
// ---------------------------------------------------------------------------------------------
function phone_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/** Stuur een CiscoIPPhoneExecute naar de telefoon (http://<telefoon>/CGI/Execute). */
function push_execute(string $uri): bool
{
    global $config;
    $xml = '<CiscoIPPhoneExecute><ExecuteItem Priority="0" URL="' . e($uri) . '"/></CiscoIPPhoneExecute>';
    $ch = curl_init('http://' . phone_ip() . '/CGI/Execute');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'XML=' . rawurlencode($xml),
        CURLOPT_USERPWD        => $config['rtp']['username'] . ':' . $config['rtp']['password'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200 && is_string($body) && stripos($body, 'CiscoIPPhoneError') === false;
}

function pid_file(): string
{
    return sys_get_temp_dir() . '/visualvm-' . preg_replace('/[^0-9a-f.:]/i', '_', phone_ip()) . '.pid';
}

function stop_stream(): void
{
    $pid = (int)@file_get_contents(pid_file());
    if ($pid > 0 && function_exists('posix_kill')) {
        posix_kill($pid, SIGTERM);
    } elseif ($pid > 0) {
        exec('kill ' . $pid . ' 2>/dev/null');
    }
    @unlink(pid_file());
}

/** Geeft null bij succes, anders een foutmelding. */
function play_message(string $mailbox, array $msg): ?string
{
    global $config;
    $audio = audio_file($msg);
    if ($audio === null) {
        return 'Geen audiobestand gevonden voor dit bericht.';
    }

    if ($config['playback'] === 'ami') {
        $resp = ami([
            'Action'      => 'Originate',
            'Channel'     => str_replace('{mailbox}', $mailbox, $config['ami']['channel']),
            'Application' => 'Playback',
            'Data'        => $msg['base'],   // Asterisk kiest zelf het beste formaat
            'CallerID'    => $config['ami']['callerid'],
            'Timeout'     => 30000,
            'Async'       => 'true',
        ]);
        return ($resp['Response'] ?? '') === 'Success' ? null : 'Kon het afspeelgesprek niet starten (AMI).';
    }

    // RTP: telefoon laten luisteren en het bericht als G.711 u-law streamen
    $rtp  = $config['rtp'];
    $ip   = phone_ip();
    $port = (int)$rtp['port'];
    stop_stream();
    $uri = "RTPRx:$ip:$port" . ($rtp['volume'] !== '' ? ':' . (int)$rtp['volume'] : '');
    if (!push_execute($uri)) {
        return 'De telefoon weigerde RTPRx (controleer de push-gebruiker).';
    }
    $cmd = sprintf(
        '(sleep 1; exec %s -nostdin -loglevel error -re -i %s -ar 8000 -ac 1 -c:a pcm_mulaw -f rtp %s) > /dev/null 2>&1 & echo $!',
        escapeshellcmd($rtp['ffmpeg']),
        escapeshellarg($audio),
        escapeshellarg("rtp://$ip:$port")
    );
    $pid = (int)exec($cmd);
    if ($pid > 0) {
        file_put_contents(pid_file(), (string)$pid);
    }
    return null;
}

// ---------------------------------------------------------------------------------------------
// Opmaak
// ---------------------------------------------------------------------------------------------
function caller_label(array $msg): string
{
    if ($msg['name'] !== '' && $msg['name'] !== $msg['number']) {
        return $msg['name'];
    }
    return $msg['number'] !== '' ? $msg['number'] : 'Onbekend nummer';
}

function nice_date(int $t): string
{
    $day = date('Y-m-d', $t);
    if ($day === date('Y-m-d')) {
        return 'Vandaag ' . date('H:i', $t);
    }
    if ($day === date('Y-m-d', strtotime('-1 day'))) {
        return 'Gisteren ' . date('H:i', $t);
    }
    return date('d-m-Y H:i', $t);
}

function nice_duration(int $s): string
{
    return sprintf('%d:%02d', intdiv($s, 60), $s % 60);
}

// ---------------------------------------------------------------------------------------------
// Schermen
// ---------------------------------------------------------------------------------------------
function login_screen(?string $mailbox, string $prompt = 'Voer uw pincode in'): void
{
    $items = '';
    if ($mailbox === null) {
        $items .= '<InputItem><DisplayName>Mailbox</DisplayName><QueryStringParam>mb</QueryStringParam>'
            . '<DefaultValue></DefaultValue><InputFlags>N</InputFlags></InputItem>';
    }
    $items .= '<InputItem><DisplayName>Pincode</DisplayName><QueryStringParam>pin</QueryStringParam>'
        . '<DefaultValue></DefaultValue><InputFlags>NP</InputFlags></InputItem>';
    $params = ['action' => 'auth'];
    if ($mailbox !== null) {
        $params['mb'] = $mailbox;
        $params['fixed'] = 1;
    }
    send_xml('<CiscoIPPhoneInput><Title>Voicemail</Title><Prompt>' . e($prompt) . '</Prompt>'
        . '<URL>' . e(self_url($params)) . "</URL>$items</CiscoIPPhoneInput>");
}

function list_screen(string $mailbox, string $token): void
{
    global $config;
    $messages = list_messages($mailbox);
    $new = count(array_filter($messages, fn($m) => $m['folder'] === 'INBOX'));

    $items = '';
    foreach (array_slice($messages, 0, (int)$config['max_items']) as $msg) {
        $label = ($msg['folder'] === 'INBOX' ? $config['new_marker'] : '')
            . caller_label($msg) . '  ' . nice_date($msg['time']) . '  (' . nice_duration($msg['duration']) . ')';
        $url = self_url(['action' => 'msg', 'id' => $msg['key'], 't' => $token]);
        $items .= '<MenuItem><Name>' . e($label) . '</Name><URL>' . e($url) . '</URL></MenuItem>';
    }
    if ($items === '') {
        text_screen("Voicemail $mailbox", 'Geen berichten', 'U heeft geen voicemailberichten.', [
            ['Vernieuw', self_url(['action' => 'list', 't' => $token])],
            ['Afsluiten', 'SoftKey:Exit'],
        ]);
    }

    $keys = softkey('Kies', 'SoftKey:Select', 1)
        . softkey('Vernieuw', self_url(['action' => 'list', 't' => $token]), 2);
    $pos = 3;
    if ($config['voicemail_dial'] !== '') {
        $keys .= softkey('Bel VM', 'Dial:' . $config['voicemail_dial'], $pos++);
    }
    $keys .= softkey('Afsluiten', 'SoftKey:Exit', $pos);

    $prompt = sprintf('%d nieuw, %d totaal', $new, count($messages));
    send_xml('<CiscoIPPhoneMenu><Title>' . e("Voicemail $mailbox") . '</Title><Prompt>' . e($prompt) . '</Prompt>'
        . $items . $keys . '</CiscoIPPhoneMenu>');
}

function detail_screen(string $mailbox, string $token, array $msg, string $prompt = ''): void
{
    global $config;
    $text = 'Van: ' . ($msg['name'] !== '' ? $msg['name'] : '-') . "\n"
        . 'Nummer: ' . ($msg['number'] !== '' ? $msg['number'] : 'Onbekend') . "\n"
        . 'Datum: ' . nice_date($msg['time']) . "\n"
        . 'Duur: ' . nice_duration($msg['duration']) . "\n"
        . 'Status: ' . FOLDERS[$msg['folder']];

    $keys = [['Afspelen', self_url(['action' => 'play', 'id' => $msg['key'], 't' => $token])]];
    if ($msg['number'] !== '' && preg_match('/^[0-9*#+]+$/', $msg['number'])) {
        $keys[] = ['Bellen', 'Dial:' . $config['dial_prefix'] . $msg['number']];
    }
    $keys[] = ['Wissen', self_url(['action' => 'delask', 'id' => $msg['key'], 't' => $token])];
    $keys[] = ['Terug', self_url(['action' => 'list', 't' => $token])];

    text_screen(caller_label($msg), $prompt !== '' ? $prompt : FOLDERS[$msg['folder']] . ' bericht', $text, $keys);
}

// ---------------------------------------------------------------------------------------------
// Afhandeling van het verzoek
// ---------------------------------------------------------------------------------------------
$action = $_GET['action'] ?? '';
$token  = $_GET['t'] ?? null;
$fixedMailbox = isset($_GET['mb']) && preg_match('/^\d+$/', $_GET['mb']) ? $_GET['mb'] : null;

// Inloggen
if ($action === 'auth') {
    $pin = (string)($_GET['pin'] ?? '');
    $password = $fixedMailbox !== null ? vm_password($fixedMailbox) : null;
    if ($password === null || $pin === '' || !hash_equals($password, $pin)) {
        usleep(500000);   // brute force afremmen
        login_screen(isset($_GET['fixed']) ? $fixedMailbox : null, 'Onjuiste mailbox of pincode');
    }
    $token = make_token($fixedMailbox);
    $action = 'list';
}

$mailbox = check_token($token);
if ($mailbox === null) {
    $trusted = $config['trusted'][phone_ip()] ?? null;
    if ($trusted !== null) {
        $mailbox = (string)$trusted;
        $token = make_token($mailbox);
    } else {
        login_screen($fixedMailbox);
    }
}

if (!is_dir(mailbox_dir($mailbox))) {
    error_screen("Mailbox $mailbox bestaat niet of is niet leesbaar voor de webserver.");
}

$msg = isset($_GET['id']) ? find_message($mailbox, (string)$_GET['id']) : null;
if (in_array($action, ['msg', 'play', 'stop', 'delask', 'del'], true) && $msg === null) {
    list_screen($mailbox, $token);   // bericht bestaat niet meer
}

switch ($action) {
    case 'msg':
        detail_screen($mailbox, $token, $msg);

    case 'play':
        if ($msg['folder'] === 'INBOX') {
            // Eerst naar Old verplaatsen, zodat het afspelen (ook later, bij AMI) het juiste pad gebruikt
            $msg['base'] = move_message($mailbox, $msg, 'Old');
            $msg['folder'] = 'Old';
            refresh_mwi($mailbox);
        }
        $error = play_message($mailbox, $msg);
        if ($error !== null) {
            detail_screen($mailbox, $token, $msg, $error);
        }
        if ($config['playback'] === 'ami') {
            text_screen(caller_label($msg), 'Neem de oproep aan', 'De telefoon gaat over. Neem op om het bericht te beluisteren.', [
                ['Wissen', self_url(['action' => 'delask', 'id' => $msg['key'], 't' => $token])],
                ['Terug', self_url(['action' => 'msg', 'id' => $msg['key'], 't' => $token])],
            ]);
        }
        text_screen(caller_label($msg), 'Bericht wordt afgespeeld', 'Duur: ' . nice_duration($msg['duration']), [
            ['Stop', self_url(['action' => 'stop', 'id' => $msg['key'], 't' => $token])],
            ['Opnieuw', self_url(['action' => 'play', 'id' => $msg['key'], 't' => $token])],
            ['Wissen', self_url(['action' => 'delask', 'id' => $msg['key'], 't' => $token])],
            ['Terug', self_url(['action' => 'stop', 'id' => $msg['key'], 't' => $token])],
        ]);

    case 'stop':
        if ($config['playback'] === 'rtp') {
            stop_stream();
            push_execute('RTPRx:Stop');
        }
        detail_screen($mailbox, $token, $msg);

    case 'delask':
        if ($config['playback'] === 'rtp') {
            stop_stream();
        }
        text_screen('Bericht wissen', caller_label($msg), 'Weet u zeker dat u dit bericht van '
            . caller_label($msg) . ' (' . nice_date($msg['time']) . ') wilt wissen?', [
            ['Wissen', self_url(['action' => 'del', 'id' => $msg['key'], 't' => $token])],
            ['Annuleer', self_url(['action' => 'msg', 'id' => $msg['key'], 't' => $token])],
        ]);

    case 'del':
        delete_message($mailbox, $msg);
        refresh_mwi($mailbox);
        list_screen($mailbox, $token);

    default:
        list_screen($mailbox, $token);
}
