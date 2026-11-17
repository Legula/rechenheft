<?php
// Vorlese-Stimme vom Server, für Tablets ohne eigene Sprachausgabe (z. B. Amazon Fire im Kinderprofil).
// Nutzt Piper (offline, kostenlos, läuft auf x86 und ARM) mit deutschen Stimmen.
// Piper und jede Stimme lädt diese Datei beim ersten Bedarf selbst herunter (Programm ca. 25 MB,
// Stimme 20 bis 115 MB) in den Datenordner (Docker-Volume). Danach braucht das Vorlesen kein Internet mehr.
//
// GET sprich.php?status=1[&v=kerstin]            -> {"ready": bool, "state": "bereit"|"laedt"|"fehlt"|"fehler", "voice": ..., "voices": [...]}
// GET sprich.php?status=1&install=1[&v=kerstin]  -> wie oben, startet bei Bedarf die Einrichtung im Hintergrund
// GET sprich.php?t=Text&r=0.9[&v=kerstin]        -> audio/wav (fertige Sätze werden zwischengespeichert)
//
// Umgebungsvariablen (alle optional):
//   SPRICH_STIMME  Standardstimme (Schlüssel aus der Liste unten, z. B. thorsten_froh), Standard thorsten
//   SPRICH_BIN     eigener Pfad zum piper-Programm (dann wird es nicht heruntergeladen)
//   SPRICH_AUS     "1" schaltet die Server-Stimme ab

header('X-Content-Type-Options: nosniff');

// Deutsche Piper-Stimmen: Schlüssel => [Dateiname, Sprecher (bei Stimmen mit mehreren Sprechern), Beschreibung, ca. MB]
$STIMMEN = [
    'thorsten'       => ['de_DE-thorsten-medium', null, 'Thorsten: Männerstimme, ruhig und deutlich', 63],
    'thorsten_froh'  => ['de_DE-thorsten_emotional-medium', 'amused', 'Thorsten fröhlich: Männerstimme, gut gelaunt', 77],
    'thorsten_hoch'  => ['de_DE-thorsten-high', null, 'Thorsten in bester Qualität (langsamer auf schwachen Geräten)', 114],
    'kerstin'        => ['de_DE-kerstin-low', null, 'Kerstin: Frauenstimme, freundlich', 63],
    'ramona'         => ['de_DE-ramona-low', null, 'Ramona: Frauenstimme, weich', 63],
    'eva'            => ['de_DE-eva_k-x_low', null, 'Eva: Frauenstimme, einfache Qualität, sehr klein', 20],
    'karlsson'       => ['de_DE-karlsson-low', null, 'Karlsson: Männerstimme, tief', 63],
    'pavoque'        => ['de_DE-pavoque-low', null, 'Pavoque: Männerstimme, lebhaft', 63],
];

$daten = getenv('RECHENHEFT_DATA') ?: (__DIR__ . '/data');
$ordner = $daten . '/stimme';
$piperVersion = '2023.11.14-2';
$bin = getenv('SPRICH_BIN') ?: ($ordner . '/piper/piper');
$cache = $ordner . '/cache';
$lock = $ordner . '/einrichtung.lock';

// Standardstimme: Schlüssel oder (wie früher) Dateiname aus SPRICH_STIMME
$standard = 'thorsten';
$env = (string)getenv('SPRICH_STIMME');
if ($env !== '') {
    if (isset($STIMMEN[$env])) $standard = $env;
    else foreach ($STIMMEN as $k => $s) if ($s[0] === $env && $s[1] === null) $standard = $k;
}
$wahl = isset($_GET['v']) && isset($STIMMEN[(string)$_GET['v']]) ? (string)$_GET['v'] : $standard;

function json_antwort($status, $daten) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($daten, JSON_UNESCAPED_UNICODE);
    exit;
}
function modell($k) { global $ordner, $STIMMEN; return $ordner . '/' . $STIMMEN[$k][0] . '.onnx'; }
function fehlerDatei($k) { global $ordner; return $ordner . '/fehler-' . $k . '.txt'; }
function piperDa() { global $bin; return is_file($bin) && is_executable($bin); }
function stimmeDa($k) { $m = modell($k); return is_file($m) && is_file($m . '.json'); }
function bereit($k) { return piperDa() && stimmeDa($k); }

function zustand($k) {
    global $lock, $STIMMEN, $standard;
    $liste = [];
    foreach ($STIMMEN as $key => $s) $liste[] = ['key' => $key, 'label' => $s[2], 'mb' => $s[3], 'ready' => stimmeDa($key)];
    $z = ['voice' => $k, 'default' => $standard, 'voices' => $liste];
    if (getenv('SPRICH_AUS') === '1') return $z + ['ready' => false, 'state' => 'aus', 'msg' => 'Die Server-Stimme ist abgeschaltet (SPRICH_AUS).'];
    if (bereit($k)) return $z + ['ready' => true, 'state' => 'bereit'];
    if (is_file($lock) && time() - filemtime($lock) < 900) {
        $info = trim((string)@file_get_contents($lock));
        return $z + ['ready' => false, 'state' => 'laedt', 'msg' => 'Die Stimme wird eingerichtet' . ($info !== '' ? ' (' . $info . ')' : '') . '.'];
    }
    if (is_file(fehlerDatei($k))) return $z + ['ready' => false, 'state' => 'fehler', 'msg' => trim((string)file_get_contents(fehlerDatei($k)))];
    return $z + ['ready' => false, 'state' => 'fehlt', 'msg' => 'Die Stimme ist noch nicht eingerichtet.'];
}

// Lädt eine Datei mit Weiterleitungen (GitHub und Hugging Face leiten weiter).
function laden($url, $ziel) {
    $ctx = stream_context_create(['http' => ['timeout' => 60, 'follow_location' => 1, 'max_redirects' => 10, 'header' => "User-Agent: rechenheft-synology\r\n"]]);
    $ein = @fopen($url, 'rb', false, $ctx);
    if (!$ein) return false;
    $aus = @fopen($ziel . '.tmp', 'wb');
    if (!$aus) { fclose($ein); return false; }
    $n = stream_copy_to_stream($ein, $aus);
    fclose($ein); fclose($aus);
    if (!$n || $n < 1000) { @unlink($ziel . '.tmp'); return false; }
    return @rename($ziel . '.tmp', $ziel);
}

function einrichten($k) {
    global $ordner, $piperVersion, $bin, $lock, $STIMMEN;
    @file_put_contents($lock, 'Start');
    @unlink(fehlerDatei($k));
    $fehler = '';
    if (!piperDa() && !getenv('SPRICH_BIN')) {
        $arch = php_uname('m');
        $pakete = ['x86_64' => 'x86_64', 'amd64' => 'x86_64', 'aarch64' => 'aarch64', 'arm64' => 'aarch64', 'armv7l' => 'armv7l', 'armv7' => 'armv7l'];
        if (!isset($pakete[$arch])) {
            $fehler = 'Dieser Prozessor (' . $arch . ') wird von Piper nicht unterstützt.';
        } else {
            @file_put_contents($lock, 'Programm');
            $tgz = $ordner . '/piper.tar.gz';
            $url = 'https://github.com/rhasspy/piper/releases/download/' . $piperVersion . '/piper_linux_' . $pakete[$arch] . '.tar.gz';
            if (!laden($url, $tgz)) {
                $fehler = 'Piper konnte nicht von GitHub geladen werden. Hat die Synology Internet?';
            } else {
                exec('tar -xzf ' . escapeshellarg($tgz) . ' -C ' . escapeshellarg($ordner) . ' 2>&1', $ausgabe, $code);
                @unlink($tgz);
                if ($code !== 0 || !is_file($bin)) $fehler = 'Piper ließ sich nicht entpacken: ' . implode(' ', array_slice($ausgabe, 0, 3));
                else @chmod($bin, 0755);
            }
        }
    }
    if ($fehler === '' && !stimmeDa($k)) {
        // Name wie de_DE-thorsten-medium: Sprache de_DE, Sprecher thorsten, Qualität medium
        $name = $STIMMEN[$k][0];
        $teile = explode('-', $name);
        $modell = modell($k);
        @file_put_contents($lock, 'Stimme, ca. ' . $STIMMEN[$k][3] . ' MB');
        $basis = 'https://huggingface.co/rhasspy/piper-voices/resolve/v1.0.0/' . substr($teile[0], 0, 2) . '/' . $teile[0] . '/' . $teile[1] . '/' . $teile[2] . '/' . $name;
        if (!laden($basis . '.onnx', $modell) || !laden($basis . '.onnx.json', $modell . '.json')) {
            @unlink($modell); @unlink($modell . '.json');
            $fehler = 'Die Stimme konnte nicht von huggingface.co geladen werden. Hat die Synology Internet?';
        }
    }
    if ($fehler === '' && !bereit($k)) $fehler = 'Piper ist da, lässt sich aber nicht starten (Rechte im Datenordner?).';
    if ($fehler !== '') @file_put_contents(fehlerDatei($k), $fehler);
    @unlink($lock);
}

// Einrichtung als eigener Prozess: php sprich.php einrichten <stimme>
if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') === 'einrichten' && isset($STIMMEN[$argv[2] ?? ''])) {
        if (!is_dir($ordner)) @mkdir($ordner, 0775, true);
        einrichten($argv[2]);
    }
    exit;
}
function hintergrund($k) {
    if (!function_exists('exec')) return false;
    $php = '';
    foreach ([PHP_BINDIR . '/php', '/usr/local/bin/php', '/usr/bin/php'] as $kandidat) if (is_file($kandidat) && is_executable($kandidat)) { $php = $kandidat; break; }
    if ($php === '') return false;
    $umgebung = '';
    foreach (['RECHENHEFT_DATA', 'SPRICH_BIN', 'SPRICH_STIMME'] as $n) if (getenv($n) !== false && getenv($n) !== '') $umgebung .= $n . '=' . escapeshellarg(getenv($n)) . ' ';
    $befehl = $umgebung . 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg(__FILE__) . ' einrichten ' . escapeshellarg($k) . ' > /dev/null 2>&1 &';
    @exec($befehl, $aus, $code);
    return $code === 0;
}

if (getenv('SPRICH_AUS') === '1') json_antwort(503, zustand($wahl));
if (!is_dir($ordner) && !@mkdir($ordner, 0775, true)) json_antwort(503, ['ready' => false, 'state' => 'fehler', 'msg' => 'Der Datenordner ist nicht beschreibbar.']);

// ---------- Status (und Einrichtung anstoßen) ----------
if (isset($_GET['status'])) {
    $z = zustand($wahl);
    $starten = isset($_GET['install']) && ($z['state'] === 'fehlt' || ($z['state'] === 'fehler' && isset($_GET['nochmal'])));
    if (!$starten) json_antwort(200, $z);
    @file_put_contents($lock, 'Start');
    $z = zustand($wahl);
    // Am liebsten als eigener Hintergrund-Prozess, damit kein Webserver-Prozess und keine Verbindung blockiert ist.
    if (hintergrund($wahl)) json_antwort(200, $z);
    // Sonst: Antwort sofort schicken, die Einrichtung läuft danach im selben Aufruf weiter.
    ignore_user_abort(true);
    @set_time_limit(900);
    $text = json_encode($z, JSON_UNESCAPED_UNICODE);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Connection: close');
    header('Content-Length: ' . strlen($text));
    echo $text;
    while (ob_get_level() > 0) ob_end_flush();
    flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    einrichten($wahl);
    exit;
}

// ---------- Text vorlesen ----------
$text = isset($_GET['t']) ? (string)$_GET['t'] : '';
$text = trim(preg_replace('/\s+/u', ' ', $text));
if ($text === '' || mb_strlen($text) > 500) json_antwort(400, ['ok' => false, 'error' => 'Text fehlt oder ist zu lang.']);
if (!bereit($wahl)) json_antwort(503, zustand($wahl));

$tempo = isset($_GET['r']) ? (float)$_GET['r'] : 0.9;
$tempo = max(0.5, min(1.6, $tempo));
$laenge = round(1 / $tempo, 2); // Piper: length_scale > 1 heißt langsamer
// Zeichen, die Piper sonst buchstabiert oder verschluckt
$gesprochen = trim(preg_replace('/ {2,}/', ' ', strtr($text, ['−' => ' minus ', '–' => ', ', '·' => ' mal ', '€' => ' Euro ', '…' => '.', '+' => ' plus ', '=' => ' gleich '])));

$modell = modell($wahl);
$sprecher = '';
if ($STIMMEN[$wahl][1] !== null) {
    $info = json_decode((string)@file_get_contents($modell . '.json'), true);
    $map = is_array($info) && isset($info['speaker_id_map']) ? $info['speaker_id_map'] : [];
    if (isset($map[$STIMMEN[$wahl][1]])) $sprecher = (string)(int)$map[$STIMMEN[$wahl][1]];
}

$schluessel = sha1($STIMMEN[$wahl][0] . '|' . $sprecher . '|' . $laenge . '|' . $gesprochen);
$datei = $cache . '/' . $schluessel . '.wav';
if (!is_file($datei)) {
    if (!is_dir($cache)) @mkdir($cache, 0775, true);
    @set_time_limit(60);
    $tmp = $datei . '.' . getmypid() . '.tmp.wav';
    $befehl = escapeshellarg($bin) . ' --model ' . escapeshellarg($modell) . ' --length_scale ' . escapeshellarg((string)$laenge)
        . ($sprecher !== '' ? ' --speaker ' . escapeshellarg($sprecher) : '') . ' --output_file ' . escapeshellarg($tmp);
    $umgebung = ['LD_LIBRARY_PATH' => dirname($bin), 'PATH' => '/usr/local/bin:/usr/bin:/bin'];
    $p = proc_open($befehl, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($bin), $umgebung);
    if (!is_resource($p)) json_antwort(500, ['ok' => false, 'error' => 'Piper ließ sich nicht starten.']);
    fwrite($pipes[0], $gesprochen . "\n");
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $log = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($p);
    if ($code !== 0 || !is_file($tmp) || filesize($tmp) < 100) {
        @unlink($tmp);
        json_antwort(500, ['ok' => false, 'error' => 'Piper konnte den Satz nicht sprechen.', 'log' => mb_substr((string)$log, -300)]);
    }
    @rename($tmp, $datei);
    // Zwischenspeicher klein halten: ab 3000 Sätzen die ältesten 500 löschen
    if (mt_rand(1, 50) === 1) {
        $alle = glob($cache . '/*.wav') ?: [];
        if (count($alle) > 3000) {
            usort($alle, function ($a, $b) { return filemtime($a) - filemtime($b); });
            foreach (array_slice($alle, 0, 500) as $alt) @unlink($alt);
        }
    }
}
@touch($datei);
header('Content-Type: audio/wav');
header('Content-Length: ' . filesize($datei));
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: "' . $schluessel . '"');
readfile($datei);
