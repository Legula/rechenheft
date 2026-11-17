<?php
// Foto vom Handy an die Hausaufgaben-Wache auf dem Tablet.
// Für Kinder-Browser (z. B. Amazon Kids), die keine Kamera und keine Dateiauswahl erlauben:
// Das Tablet holt sich einen kurzen Code, Mama oder Papa öffnen foto.php auf dem Handy,
// geben den Code ein und machen das Foto. Das Tablet fragt alle paar Sekunden nach und holt es ab.
//
// Datenschutz: Das Foto liegt nur kurz im Datenordner (nicht im Webordner) und wird gelöscht,
// sobald das Tablet es abgeholt hat, spätestens nach 10 Minuten. Nichts wird dauerhaft gespeichert.
//
// POST foto.php?neu=1                      -> {"ok":true,"code":"4821","token":"..."}  (Tablet)
// GET  foto.php                            -> Seite fürs Handy (Code eingeben, Foto machen)
// POST foto.php?code=4821  {"img":"data:image/jpeg;base64,..."}  -> {"ok":true}   (Handy)
// GET  foto.php?abholen=4821&t=...         -> {"ok":true,"img":...} oder {"ok":true,"wait":true}  (Tablet)
// POST foto.php?weg=4821&t=...             -> Code und Foto sofort löschen (Tablet bricht ab)

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$daten = getenv('RECHENHEFT_DATA') ?: (__DIR__ . '/data');
$ordner = $daten . '/foto';
$frist = 600; // 10 Minuten

function json_antwort($status, $d) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}
function aufraeumen() {
    global $ordner, $frist;
    foreach (glob($ordner . '/*') ?: [] as $f) if (time() - filemtime($f) > $frist) @unlink($f);
}
function sitzung($code) {
    global $ordner;
    if (!preg_match('/^\d{4}$/', (string)$code)) return null;
    $j = json_decode((string)@file_get_contents($ordner . '/' . $code . '.json'), true);
    return is_array($j) ? $j : null;
}
function loeschen($code) {
    global $ordner;
    @unlink($ordner . '/' . $code . '.json');
    @unlink($ordner . '/' . $code . '.jpg');
}

if (!is_dir($ordner) && !@mkdir($ordner, 0770, true)) json_antwort(503, ['ok' => false, 'error' => 'Der Datenordner ist nicht beschreibbar.']);
aufraeumen();
$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ---------- Tablet: neuen Code holen ----------
if (isset($_GET['neu']) && $methode === 'POST') {
    for ($i = 0; $i < 30; $i++) {
        $code = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        if (!is_file($ordner . '/' . $code . '.json')) break;
    }
    $token = bin2hex(random_bytes(16));
    file_put_contents($ordner . '/' . $code . '.json', json_encode(['token' => $token, 'zeit' => time()]));
    json_antwort(200, ['ok' => true, 'code' => $code, 'token' => $token, 'frist' => $frist]);
}

// ---------- Tablet: Foto abholen (wird dabei gelöscht) ----------
if (isset($_GET['abholen'])) {
    $code = (string)$_GET['abholen'];
    $s = sitzung($code);
    if (!$s || !hash_equals($s['token'], (string)($_GET['t'] ?? ''))) json_antwort(404, ['ok' => false, 'error' => 'abgelaufen']);
    $bild = $ordner . '/' . $code . '.jpg';
    if (!is_file($bild)) json_antwort(200, ['ok' => true, 'wait' => true]);
    $inhalt = (string)file_get_contents($bild);
    loeschen($code);
    json_antwort(200, ['ok' => true, 'img' => 'data:image/jpeg;base64,' . base64_encode($inhalt)]);
}

// ---------- Tablet: abbrechen ----------
if (isset($_GET['weg'])) {
    $s = sitzung((string)$_GET['weg']);
    if ($s && hash_equals($s['token'], (string)($_GET['t'] ?? ''))) loeschen((string)$_GET['weg']);
    json_antwort(200, ['ok' => true]);
}

// ---------- Handy: Code prüfen oder Foto hochladen ----------
if (isset($_GET['code'])) {
    $code = (string)$_GET['code'];
    $s = sitzung($code);
    if (!$s) json_antwort(404, ['ok' => false, 'error' => 'Diesen Code gibt es nicht (mehr). Auf dem Tablet nochmal „Foto mit dem Handy“ tippen.']);
    if ($methode !== 'POST') json_antwort(200, ['ok' => true]);
    $roh = file_get_contents('php://input', false, null, 0, 8 * 1024 * 1024);
    $d = json_decode((string)$roh, true);
    $img = is_array($d) ? (string)($d['img'] ?? '') : '';
    if (!preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', $img, $m)) json_antwort(400, ['ok' => false, 'error' => 'Kein Foto empfangen.']);
    $bin = base64_decode($m[1], true);
    if ($bin === false || strlen($bin) < 1000 || substr($bin, 0, 3) !== "\xFF\xD8\xFF") json_antwort(400, ['ok' => false, 'error' => 'Das Foto ist beschädigt. Bitte nochmal versuchen.']);
    file_put_contents($ordner . '/' . $code . '.jpg', $bin);
    json_antwort(200, ['ok' => true]);
}

// ---------- Handy: Seite ----------
header('Content-Type: text/html; charset=utf-8');
$vorgabe = isset($_GET['c']) && preg_match('/^\d{4}$/', (string)$_GET['c']) ? (string)$_GET['c'] : '';
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Foto für Paul</title>
<style>
  :root { --bg: #f4f7fb; --card: #fff; --ink: #1f2937; --muted: #5b6474; --blue: #1d6fb8; --line: #d4dbe6; --ok: #15803d; --bad: #b91c1c; }
  @media (prefers-color-scheme: dark) { :root { --bg: #0f172a; --card: #1e293b; --ink: #e5e7eb; --muted: #94a3b8; --blue: #3b82f6; --line: #334155; --ok: #4ade80; --bad: #f87171; } }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--ink); font: 18px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; padding: 16px; }
  .card { max-width: 480px; margin: 0 auto; background: var(--card); border: 1px solid var(--line); border-radius: 18px; padding: 20px; }
  h1 { font-size: 1.4rem; margin: 0 0 8px; }
  p { margin: 8px 0; }
  .muted { color: var(--muted); font-size: .95rem; }
  input[type=text] { font-size: 2rem; letter-spacing: .4em; width: 100%; text-align: center; padding: 10px; border: 2px solid var(--line); border-radius: 12px; background: transparent; color: var(--ink); }
  .btn { display: flex; align-items: center; justify-content: center; position: relative; overflow: hidden; width: 100%; margin-top: 14px; padding: 16px; font-size: 1.15rem; font-weight: 700; border-radius: 14px; border: 0; background: var(--blue); color: #fff; cursor: pointer; }
  .btn.ghost { background: transparent; color: var(--blue); border: 2px solid var(--line); }
  .btn input { position: absolute; inset: 0; opacity: 0; font-size: 200px; cursor: pointer; }
  .btn[disabled] { opacity: .5; }
  img { max-width: 100%; border-radius: 12px; margin-top: 12px; border: 1px solid var(--line); }
  .ok { color: var(--ok); font-weight: 700; } .bad { color: var(--bad); font-weight: 700; }
  [hidden] { display: none !important; }
</style>
</head>
<body>
<div class="card">
  <h1>🐕 Foto für Polizeihund Paul</h1>
  <div id="schritt1">
    <p>Gib den Code ein, der auf dem Tablet steht.</p>
    <input type="text" id="code" inputmode="numeric" maxlength="4" autocomplete="off" value="<?= htmlspecialchars($vorgabe) ?>" aria-label="Code vom Tablet">
    <button class="btn" id="weiter">Weiter</button>
    <p id="m1"></p>
  </div>
  <div id="schritt2" hidden>
    <p>Fotografiere die Heftseite von oben, gerade und gut beleuchtet.</p>
    <label class="btn">📷 Foto machen<input type="file" accept="image/*" capture="environment" id="cam"></label>
    <label class="btn ghost">🖼️ Bild aus der Galerie<input type="file" accept="image/*" id="pick"></label>
    <p id="m2"></p>
    <img id="vorschau" alt="Vorschau" hidden>
  </div>
  <p class="muted">Das Foto wird nicht dauerhaft gespeichert. Es liegt nur kurz auf der Synology und wird gelöscht, sobald das Tablet es abgeholt hat, spätestens nach 10 Minuten.</p>
</div>
<script>
(function () {
  var code = '', $ = function (id) { return document.getElementById(id); };
  function msg(id, t, cls) { var el = $(id); el.textContent = t; el.className = cls || ''; }
  function pruefen() {
    var c = $('code').value.replace(/\D/g, '');
    if (c.length !== 4) { msg('m1', 'Der Code hat 4 Ziffern.', 'bad'); return; }
    msg('m1', 'Prüfe …');
    fetch('foto.php?code=' + c, { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) { msg('m1', j.error || 'Code unbekannt.', 'bad'); return; }
      code = c; $('schritt1').hidden = true; $('schritt2').hidden = false;
    }).catch(function () { msg('m1', 'Die Synology ist nicht erreichbar. Ist das Handy im Heim-WLAN?', 'bad'); });
  }
  $('weiter').addEventListener('click', pruefen);
  $('code').addEventListener('keydown', function (e) { if (e.key === 'Enter') pruefen(); });
  if ($('code').value.length === 4) pruefen();
  function datei(e) {
    var f = e.target.files && e.target.files[0]; if (!f) return;
    msg('m2', 'Foto wird vorbereitet …');
    var url = URL.createObjectURL(f), im = new Image();
    im.onload = function () {
      var m = 1600, k = Math.min(1, m / Math.max(im.naturalWidth, im.naturalHeight)), c = document.createElement('canvas');
      c.width = Math.round(im.naturalWidth * k); c.height = Math.round(im.naturalHeight * k);
      var g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height); g.drawImage(im, 0, 0, c.width, c.height);
      URL.revokeObjectURL(url);
      var data = c.toDataURL('image/jpeg', .85);
      $('vorschau').src = data; $('vorschau').hidden = false;
      msg('m2', 'Wird an das Tablet geschickt …');
      fetch('foto.php?code=' + code, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ img: data }) })
        .then(function (r) { return r.json(); }).then(function (j) {
          if (j.ok) msg('m2', '✅ Angekommen! Paul hat das Foto jetzt auf dem Tablet. Für ein weiteres Foto auf dem Tablet wieder „Foto mit dem Handy“ tippen.', 'ok');
          else msg('m2', j.error || 'Hat nicht geklappt.', 'bad');
        }).catch(function () { msg('m2', 'Senden hat nicht geklappt. Bitte nochmal versuchen.', 'bad'); });
    };
    im.onerror = function () { msg('m2', 'Dieses Bild lässt sich nicht öffnen. Bitte mit der Kamera ein neues Foto machen.', 'bad'); };
    im.src = url;
  }
  $('cam').addEventListener('change', datei);
  $('pick').addEventListener('change', datei);
})();
</script>
</body>
</html>
