<?php
// Vorlese-Stimme vom Server, für Tablets ohne eigene Sprachausgabe (z. B. Amazon Fire im Kinderprofil).
// Nutzt Piper (offline, kostenlos, läuft auf x86 und ARM) mit einer deutschen Stimme.
// Piper und die Stimme lädt diese Datei beim ersten Bedarf selbst herunter, einmalig ca. 90 MB,
// in den Datenordner (Docker-Volume). Danach braucht das Vorlesen kein Internet mehr.
//
// GET sprich.php?status=1            -> {"ready": bool, "state": "bereit"|"laedt"|"fehlt"|"fehler", ...}
// GET sprich.php?status=1&install=1  -> wie oben, startet bei Bedarf die Einrichtung im Hintergrund
// GET sprich.php?t=Text&r=0.9        -> audio/wav (fertige Sätze werden zwischengespeichert)
//
// Umgebungsvariablen (alle optional):
//   SPRICH_STIMME  Piper-Stimme, Standard de_DE-thorsten-medium (andere: de_DE-kerstin-low, de_DE-thorsten-low ...)
//   SPRICH_BIN     eigener Pfad zum piper-Programm (dann wird nichts heruntergeladen)
//   SPRICH_AUS     "1" schaltet die Server-Stimme ab

header('X-Content-Type-Options: nosniff');

$daten = getenv('RECHENHEFT_DATA') ?: (__DIR__ . '/data');
$ordner = $daten . '/stimme';
$stimme = preg_replace('/[^A-Za-z0-9_\-]/', '', getenv('SPRICH_STIMME') ?: 'de_DE-thorsten-medium');
$piperVersion = '2023.11.14-2';
$bin = getenv('SPRICH_BIN') ?: ($ordner . '/piper/piper');
$modell = $ordner . '/' . $stimme . '.onnx';
$cache = $ordner . '/cache';
$lock = $ordner . '/einrichtung.lock';
$fehlerDatei = $ordner . '/fehler.txt';

function json_antwort($status, $daten) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($daten, JSON_UNESCAPED_UNICODE);
    exit;
}

function bereit() {
    global $bin, $modell;
    return is_file($bin) && is_executable($bin) && is_file($modell) && is_file($modell . '.json');
}

function zustand() {
    global $lock, $fehlerDatei, $stimme;
    if (getenv('SPRICH_AUS') === '1') return ['ready' => false, 'state' => 'aus', 'msg' => 'Die Server-Stimme ist abgeschaltet (SPRICH_AUS).'];
    if (bereit()) return ['ready' => true, 'state' => 'bereit', 'voice' => $stimme];
    if (is_file($lock) && time() - filemtime($lock) < 900) {
        $info = trim((string)@file_get_contents($lock));
        return ['ready' => false, 'state' => 'laedt', 'msg' => 'Die Stimme wird eingerichtet' . ($info !== '' ? ' (' . $info . ')' : '') . '.'];
    }
    if (is_file($fehlerDatei)) return ['ready' => false, 'state' => 'fehler', 'msg' => trim((string)file_get_contents($fehlerDatei))];
    return ['ready' => false, 'state' => 'fehlt', 'msg' => 'Die Stimme ist noch nicht eingerichtet.'];
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

function einrichten() {
    global $ordner, $stimme, $piperVersion, $bin, $modell, $lock, $fehlerDatei;
    if (getenv('SPRICH_BIN')) {
        $piperOk = true;
    } else {
        $piperOk = is_file($bin);
    }
    @file_put_contents($lock, 'Start');
    @unlink($fehlerDatei);
    $fehler = '';
    if (!$piperOk) {
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
    if ($fehler === '' && (!is_file($modell) || !is_file($modell . '.json'))) {
        // Name wie de_DE-thorsten-medium: Sprache de_DE, Sprecher thorsten, Qualität medium
        $teile = explode('-', $stimme);
        if (count($teile) !== 3) {
            $fehler = 'Unbekannter Stimmenname: ' . $stimme;
        } else {
            @file_put_contents($lock, 'Stimme');
            $basis = 'https://huggingface.co/rhasspy/piper-voices/resolve/v1.0.0/' . substr($teile[0], 0, 2) . '/' . $teile[0] . '/' . $teile[1] . '/' . $teile[2] . '/' . $stimme;
            if (!laden($basis . '.onnx.json', $modell . '.json') || !laden($basis . '.onnx', $modell)) {
                @unlink($modell . '.json');
                $fehler = 'Die Stimme konnte nicht von huggingface.co geladen werden. Hat die Synology Internet?';
            }
        }
    }
    if ($fehler === '' && !bereit()) $fehler = 'Piper ist da, lässt sich aber nicht starten (Rechte im Datenordner?).';
    if ($fehler !== '') @file_put_contents($fehlerDatei, $fehler);
    @unlink($lock);
}

if (getenv('SPRICH_AUS') === '1') json_antwort(503, zustand());
if (!is_dir($ordner) && !@mkdir($ordner, 0775, true)) json_antwort(503, ['ready' => false, 'state' => 'fehler', 'msg' => 'Der Datenordner ist nicht beschreibbar.']);

// ---------- Status (und Einrichtung anstoßen) ----------
if (isset($_GET['status'])) {
    $z = zustand();
    $starten = isset($_GET['install']) && ($z['state'] === 'fehlt' || ($z['state'] === 'fehler' && isset($_GET['nochmal'])));
    if (!$starten) json_antwort(200, $z);
    // Antwort sofort schicken, die Einrichtung läuft danach weiter (dauert je nach Leitung ein bis zwei Minuten).
    @file_put_contents($lock, 'Start');
    $z = zustand();
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
    einrichten();
    exit;
}

// ---------- Text vorlesen ----------
$text = isset($_GET['t']) ? (string)$_GET['t'] : '';
$text = trim(preg_replace('/\s+/u', ' ', $text));
if ($text === '' || mb_strlen($text) > 500) json_antwort(400, ['ok' => false, 'error' => 'Text fehlt oder ist zu lang.']);
if (!bereit()) json_antwort(503, zustand());

$tempo = isset($_GET['r']) ? (float)$_GET['r'] : 0.9;
$tempo = max(0.5, min(1.5, $tempo));
$laenge = round(1 / $tempo, 2); // Piper: length_scale > 1 heißt langsamer
// Zeichen, die Piper sonst buchstabiert oder verschluckt
$gesprochen = trim(preg_replace('/ {2,}/', ' ', strtr($text, ['−' => ' minus ', '–' => ', ', '·' => ' mal ', '€' => ' Euro ', '…' => '.', '+' => ' plus ', '=' => ' gleich '])));

$schluessel = sha1($stimme . '|' . $laenge . '|' . $gesprochen);
$datei = $cache . '/' . $schluessel . '.wav';
if (!is_file($datei)) {
    if (!is_dir($cache)) @mkdir($cache, 0775, true);
    @set_time_limit(60);
    $tmp = $datei . '.' . getmypid() . '.tmp.wav';
    $befehl = escapeshellarg($bin) . ' --model ' . escapeshellarg($modell) . ' --length_scale ' . escapeshellarg((string)$laenge) . ' --output_file ' . escapeshellarg($tmp);
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
