<?php
// Philians Rechenheft: Abgleich des Lernstands zwischen Geräten.
// GET  sync.php?code=XYZ          -> {"state": {...}} oder {"state": null}
// POST {"code": "XYZ", "state": {...}} -> {"ok": true}
// Pro Familien-Code wird eine JSON-Datei gespeichert. Der Dateiname ist ein Hash des Codes.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function antwort($status, $daten) {
    http_response_code($status);
    echo json_encode($daten, JSON_UNESCAPED_UNICODE);
    exit;
}

$verzeichnis = getenv('RECHENHEFT_DATA') ?: (__DIR__ . '/data');
if (!is_dir($verzeichnis) && !@mkdir($verzeichnis, 0770, true)) {
    antwort(500, ['error' => 'Datenordner fehlt']);
}

$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$koerper = null;
if ($methode === 'POST') {
    $roh = file_get_contents('php://input', false, null, 0, 1000001);
    if ($roh === false || strlen($roh) > 1000000) antwort(413, ['error' => 'zu groß']);
    $koerper = json_decode($roh, true);
    if (!is_array($koerper)) antwort(400, ['error' => 'kein JSON']);
    $code = (string)($koerper['code'] ?? '');
} elseif ($methode === 'GET') {
    $code = (string)($_GET['code'] ?? '');
} else {
    antwort(405, ['error' => 'Methode nicht erlaubt']);
}

if (!preg_match('/^[A-Za-z0-9-]{4,40}$/', $code)) antwort(400, ['error' => 'ungültiger Code']);
$datei = $verzeichnis . '/' . hash('sha256', 'rechenheft:' . $code) . '.json';

if ($methode === 'GET') {
    if (!is_file($datei)) antwort(200, ['state' => null]);
    $inhalt = file_get_contents($datei);
    $stand = json_decode($inhalt, true);
    antwort(200, ['state' => is_array($stand) ? $stand : null]);
}

$stand = $koerper['state'] ?? null;
if (!is_array($stand)) antwort(400, ['error' => 'state fehlt']);
$tmp = $datei . '.' . bin2hex(random_bytes(4)) . '.tmp';
if (file_put_contents($tmp, json_encode($stand, JSON_UNESCAPED_UNICODE), LOCK_EX) === false || !rename($tmp, $datei)) {
    @unlink($tmp);
    antwort(500, ['error' => 'Speichern fehlgeschlagen']);
}
antwort(200, ['ok' => true]);
