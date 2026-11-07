<?php
// Liefert die App aus und holt sich höchstens einmal pro Stunde die neueste index.html von GitHub.
// Klappt das nicht (kein Internet, GitHub nicht erreichbar), wird die zuletzt geladene
// oder die mitgelieferte index.html verwendet.

$quelle = getenv('RECHENHEFT_UPDATE_URL') ?: 'https://raw.githubusercontent.com/Legula/rechenheft/main/index.html';
$verzeichnis = getenv('RECHENHEFT_DATA') ?: (__DIR__ . '/data');
$cache = $verzeichnis . '/app-cache.html';
$intervall = 3600;

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');

$alt = is_file($cache) ? time() - filemtime($cache) : PHP_INT_MAX;
// ?update=1 holt sofort die neueste Version (höchstens alle 20 Sekunden).
$sofort = isset($_GET['update']) && $alt > 20;
if ($quelle !== 'aus' && ($alt > $intervall || $sofort) && is_dir($verzeichnis)) {
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => "User-Agent: rechenheft-synology\r\n"]]);
    $neu = @file_get_contents($quelle, false, $ctx);
    if ($neu !== false && strlen($neu) > 1000 && stripos($neu, '<html') !== false) {
        $vorher = is_file($cache) ? md5_file($cache) : '';
        $tmp = $cache . '.tmp';
        if (@file_put_contents($tmp, $neu) !== false) @rename($tmp, $cache);
        $GLOBALS['aktualisiert'] = $vorher !== md5($neu);
    } elseif (is_file($cache)) {
        @touch($cache); // nicht bei jedem Aufruf erneut versuchen
    }
}

if (isset($_GET['update'])) {
    // zurück zur normalen Adresse, damit ein Neuladen nicht erneut aktualisiert
    header('Location: ./#' . (!empty($GLOBALS['aktualisiert']) ? 'aktualisiert' : 'unveraendert'), true, 303);
    exit;
}
readfile(is_file($cache) ? $cache : __DIR__ . '/index.html');
