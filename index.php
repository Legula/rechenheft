<?php
// Liefert die App aus und holt sich höchstens einmal pro Stunde die neueste index.html von GitHub.
// Klappt das nicht (kein Internet, GitHub nicht erreichbar), wird die zuletzt geladene
// oder die mitgelieferte index.html verwendet.

$quelle = getenv('RECHENHEFT_UPDATE_URL') ?: 'https://raw.githubusercontent.com/Legula/rechenheft/main/index.html';
$verzeichnis = getenv('RECHENHEFT_DATA') ?: (__DIR__ . '/data');
$cache = $verzeichnis . '/app-cache.html';
$intervall = 3600;


// Holt die neue Version mit kurzen Zeitlimits, damit die Seite nie lange hängt.
function holen($url) {
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 12, CURLOPT_USERAGENT => 'rechenheft-synology', CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]);
        $neu = curl_exec($c);
        $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
        $fehler = curl_error($c);
        curl_close($c);
        if ($neu === false || $code !== 200) return [false, $fehler !== '' ? $fehler : 'GitHub antwortet mit ' . $code];
        return [$neu, ''];
    }
    @ini_set('default_socket_timeout', '6');
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'header' => "User-Agent: rechenheft-synology\r\n"]]);
    $neu = @file_get_contents($url, false, $ctx);
    return [$neu, $neu === false ? 'GitHub nicht erreichbar' : ''];
}

@set_time_limit(30);
$alt = is_file($cache) ? time() - filemtime($cache) : PHP_INT_MAX;
// ?update=1 holt sofort die neueste Version (höchstens alle 20 Sekunden).
$sofort = isset($_GET['update']) && $alt > 20;
$ergebnis = ['ok' => true, 'changed' => false, 'error' => ''];
if (isset($_GET['update']) && !$sofort) $ergebnis['error'] = 'Gerade erst geprüft. In ein paar Sekunden nochmal versuchen.';
if ($quelle === 'aus' && isset($_GET['update'])) $ergebnis = ['ok' => false, 'changed' => false, 'error' => 'Automatische Updates sind abgeschaltet (RECHENHEFT_UPDATE_URL: aus).'];
if ($quelle !== 'aus' && ($alt > $intervall || $sofort) && is_dir($verzeichnis)) {
    list($neu, $fehler) = holen($quelle);
    if ($neu !== false && strlen($neu) > 1000 && stripos($neu, '<html') !== false) {
        $vorher = is_file($cache) ? md5_file($cache) : '';
        $tmp = $cache . '.tmp';
        if (@file_put_contents($tmp, $neu) !== false && @rename($tmp, $cache)) $ergebnis['changed'] = $vorher !== md5($neu);
        else $ergebnis = ['ok' => false, 'changed' => false, 'error' => 'Die neue Version ließ sich nicht speichern (Schreibrechte im Datenordner?).'];
    } else {
        if (is_file($cache)) @touch($cache); // nicht bei jedem Aufruf erneut versuchen
        $ergebnis = ['ok' => false, 'changed' => false, 'error' => 'GitHub war nicht erreichbar' . ($fehler ? ' (' . $fehler . ')' : '') . '. Die bisherige Version läuft weiter.'];
    }
}

if (isset($_GET['json'])) {
    // Für den Knopf „Jetzt aktualisieren“: Ergebnis als JSON, die App lädt dann selbst neu
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($ergebnis, JSON_UNESCAPED_UNICODE);
    exit;
}
if (isset($_GET['update'])) {
    // zurück zur normalen Adresse, damit ein Neuladen nicht erneut aktualisiert
    header('Location: ./#' . ($ergebnis['changed'] ? 'aktualisiert' : 'unveraendert'), true, 303);
    exit;
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
readfile(is_file($cache) ? $cache : __DIR__ . '/index.html');
