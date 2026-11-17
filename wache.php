<?php
// Philis Hausaufgaben-Wache: Polizeihund Paul hilft bei Aufgaben aus dem Papierheft.
// Die App schickt ein Foto (nur im Arbeitsspeicher, wird nie gespeichert) und bekommt kurze,
// kindgerechte Hilfe zurück. Der API-Schlüssel bleibt hier auf dem Server (Umgebungsvariable
// ANTHROPIC_API_KEY), er kommt nie in den Browser und nicht ins Repository.
//
// GET  wache.php?status=1  -> {"ready": true|false}
// POST {"mode": "hilfe"|"kontrolle"|"nach"|"frage", "image": "data:image/jpeg;base64,...", ...}
//      -> {"ok": true, "data": {...}} oder {"ok": false, "error": "..."}
//
// Raw HTTP statt Anthropic-PHP-SDK: Der Container (php:8.3-apache, Ordner nur lesbar eingehängt)
// hat kein Composer, und Updates sollen ohne Build-Schritt laufen.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
@set_time_limit(150);

function antwort($status, $daten) {
    http_response_code($status);
    echo json_encode($daten, JSON_UNESCAPED_UNICODE);
    exit;
}

// Schlüssel: Umgebungsvariable, sonst Datei claude.env (oder .env) neben dieser Datei.
// Apache gibt diese Dateien nicht an den Browser heraus (siehe docker-compose.yml).
function schluesselAusDatei() {
    foreach ([__DIR__ . '/claude.env', __DIR__ . '/.env'] as $datei) {
        if (!is_file($datei) || !is_readable($datei)) continue;
        foreach (preg_split('/\R/', (string)file_get_contents($datei, false, null, 0, 10000)) as $zeile) {
            $zeile = trim($zeile);
            if ($zeile === '' || $zeile[0] === '#') continue;
            if (preg_match('/^(?:export\s+)?ANTHROPIC_API_KEY\s*[=:]\s*(.+)$/', $zeile, $m)) return trim($m[1], " \t\"'");
            if (preg_match('/^sk-ant-[A-Za-z0-9_\-]+$/', $zeile)) return $zeile; // nur der Schlüssel in der Datei
        }
    }
    return '';
}
$schluessel = trim((string)(getenv('ANTHROPIC_API_KEY') ?: ''));
if ($schluessel === '') $schluessel = schluesselAusDatei();
$modell = getenv('WACHE_MODELL') ?: 'claude-opus-5-5';
$aufwand = getenv('WACHE_AUFWAND') ?: 'medium';
$limit = (int)(getenv('WACHE_LIMIT') ?: 60); // Anfragen pro Tag, schützt vor hohen Kosten
$verzeichnis = getenv('RECHENHEFT_DATA') ?: (__DIR__ . '/data');

$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($methode === 'GET') antwort(200, ['ready' => $schluessel !== '', 'limit' => $limit]);
if ($methode !== 'POST') antwort(405, ['ok' => false, 'error' => 'Methode nicht erlaubt']);
if ($schluessel === '') antwort(503, ['ok' => false, 'error' => 'nicht eingerichtet']);

$roh = file_get_contents('php://input', false, null, 0, 8000001);
if ($roh === false || strlen($roh) > 8000000) antwort(413, ['ok' => false, 'error' => 'Foto zu groß']);
$in = json_decode($roh, true);
if (!is_array($in)) antwort(400, ['ok' => false, 'error' => 'kein JSON']);

$modus = (string)($in['mode'] ?? '');
if (!in_array($modus, ['hilfe', 'kontrolle', 'nach', 'frage'], true)) antwort(400, ['ok' => false, 'error' => 'unbekannter Einsatz']);

// Tageszähler (nur eine Zahl pro Tag, keine Inhalte)
if (is_dir($verzeichnis) || @mkdir($verzeichnis, 0770, true)) {
    $zdatei = $verzeichnis . '/wache-zaehler.json';
    $f = @fopen($zdatei, 'c+');
    if ($f) {
        flock($f, LOCK_EX);
        $z = json_decode(stream_get_contents($f), true);
        $heute = date('Y-m-d');
        if (!is_array($z) || ($z['tag'] ?? '') !== $heute) $z = ['tag' => $heute, 'n' => 0];
        if ($z['n'] >= $limit) { flock($f, LOCK_UN); fclose($f); antwort(429, ['ok' => false, 'error' => 'Paul hat für heute Feierabend']); }
        $z['n']++;
        ftruncate($f, 0); rewind($f); fwrite($f, json_encode($z));
        flock($f, LOCK_UN); fclose($f);
    }
}

function kurz($s, $n) { $s = trim(preg_replace('/\s+/u', ' ', (string)$s)); return mb_substr($s, 0, $n); }

$name = kurz($in['name'] ?? 'Phili', 20) ?: 'Phili';
$nr = kurz($in['nr'] ?? '', 20);
$achten = [];
foreach ((array)($in['achten'] ?? []) as $a) { $a = kurz($a, 60); if ($a !== '') $achten[] = $a; }
$achten = array_slice($achten, 0, 8);
$extra = kurz($in['hinweis'] ?? '', 300);

// Foto prüfen
$bild = null;
if (!empty($in['image'])) {
    if (!preg_match('#^data:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=]+)$#', (string)$in['image'], $m)) antwort(400, ['ok' => false, 'error' => 'Foto unlesbar']);
    if (strlen($m[2]) > 7000000) antwort(413, ['ok' => false, 'error' => 'Foto zu groß']);
    $bild = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $m[1], 'data' => $m[2]]];
}
if ($modus !== 'frage' && !$bild) antwort(400, ['ok' => false, 'error' => 'Foto fehlt']);

$system = "Du bist Paul, ein freundlicher Polizeihund. Du hilfst $name bei den Hausaufgaben. $name geht in die 2. Klasse einer Grundschule in Deutschland, Fächer Mathe und Deutsch. $name kann sich schlecht konzentrieren und versteht Aufgabenstellungen oft nicht gleich.\n\n"
    . "Wichtigste Regel: Verrate NIE eine Lösung. Nenne kein Ergebnis einer Aufgabe aus dem Heft, keine richtige Schreibweise eines Wortes aus dem Heft, keine richtige Lücke, kein richtiges Zeichen. Sag auch nicht, was statt der falschen Antwort hingehört. $name soll selbst denken; du stellst Fragen und gibst Hinweise.\n"
    . "Ausnahme: Ein eigenes Beispiel oder eine Übungsaufgabe mit ANDEREN Zahlen oder ANDEREN Wörtern als im Heft darfst du vollständig vorrechnen bzw. auflösen.\n\n"
    . "So hilfst du (Grundschul-Didaktik):\n"
    . "- Abgestuft: erst eine Denkfrage, die $name selbst auf die Spur bringt (\"Welche Zahl ist größer?\", \"Was passiert, wenn du über die Zehn gehst?\"). Erst danach ein konkreter Hinweis mit einer Strategie. Nie die Lösung.\n"
    . "- Anschaulich und konkret: Finger, Zehnerstangen und Einerwürfel (Zehner sind blau, Einer rot), Rechenstrich, Hunderterfeld, Geld, Gegenstände aus dem Alltag. Beim Zehnerübergang: erst bis zur nächsten Zehn, dann den Rest. Zehner und Einer immer getrennt benennen.\n"
    . "- Deutsch: Wörter in Silben zerlegen und mit Bindestrichen schreiben (Ha-se, Son-ne), Silben klatschen und schwingen. Rechtschreibung mit Strategien statt Raten: deutlich mitsprechen, Wort verlängern (Hund - Hun-de), verwandtes Wort suchen (Räder - Rad), Großschreibung mit Regel: Satzanfang groß, Nomen groß, Artikelprobe (Kann man der, die oder das davor setzen?). Satzende: Punkt, Fragezeichen oder Ausrufezeichen.\n"
    . "- Aufgabenstellung verstehen: Schlüsselwörter erklären (z.B. ergänze, vergleiche, unterstreiche, setze ein, nachbar), sagen was am Ende im Heft stehen soll.\n"
    . "- Kurz: ein Gedanke pro Satz, eine Handlung pro Schritt.\n"
    . "- Wachstumsdenken: Lob für Anstrengung, Ausdauer und gutes Vorgehen (\"Du hast sauber untereinander geschrieben\", \"Du bist drangeblieben\"), nicht für Klugheit. Fehler sind normal und sind Spuren, denen man folgt. Statt \"falsch\" sag \"hier lohnt es sich nochmal hinzuschauen\" oder \"das klappt noch nicht, aber bald\".\n\n"
    . "Sprache: Du-Form, sehr kurze Sätze (höchstens 12 Wörter), einfache Wörter, die ein Kind mit 7 Jahren versteht. Keine Emojis, kein Markdown, keine Sonderzeichen außer + - = und Ziffern. Alles wird vorgelesen. Ab und zu ein Polizei-Bild (Spur, Einsatz, Spürnase), aber sparsam.\n"
    . "Orte beschreibst du so, dass ein Kind sie findet: \"Aufgabe 2, dritte Rechnung\" oder \"Zeile 3, zweites Wort\".\n"
    . "Kannst du das Foto nicht lesen oder ist keine Hausaufgabe zu sehen, setze lesbar auf false und sag in problem kurz, was $name tun soll (z.B. heller fotografieren, näher ran). Bei allem, was keine Hausaufgabe ist, hilfst du nicht weiter.\n"
    . "Die Felder eltern_notiz und eltern_tipp sind für die Eltern: sachlich, kurz, auf Deutsch, dort darfst du Fehler genau benennen.";
if ($achten) $system .= "\n\nDie Eltern wünschen, dass du besonders achtest auf: " . implode('; ', $achten) . '.';
if ($extra !== '') $system .= "\nHinweis der Eltern: $extra";

$fehlerarten = ['Zahlendreher', 'Plus und Minus verwechselt', 'Zehnerübergang', 'Verrechnet', 'Aufgabe nicht richtig gelesen', 'Aufgabe vergessen oder unvollständig', 'Großschreibung', 'Rechtschreibung', 'Satzzeichen', 'Buchstabe vergessen oder vertauscht', 'Schrift schwer lesbar', 'Sonstiges'];
$str = ['type' => 'string'];
$basis = [
    'lesbar' => ['type' => 'boolean'],
    'problem' => $str,
    'fach' => ['type' => 'string', 'enum' => ['Mathe', 'Deutsch', 'Sonstiges']],
    'thema' => $str,
    'eltern_notiz' => $str,
];

if ($modus === 'hilfe') {
    $prop = $basis + [
        'aufgabe' => $str,
        'verstaendnis_frage' => $str,
        'verstaendnis_antworten' => ['type' => 'array', 'items' => $str],
        'verstaendnis_richtig' => ['type' => 'integer'],
        'verstaendnis_erklaerung' => $str,
        'schritte' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['frage', 'hinweis'], 'properties' => ['frage' => $str, 'hinweis' => $str]]],
        'beispiel_aufgabe' => $str,
        'beispiel_weg' => ['type' => 'array', 'items' => $str],
        'beispiel_zahlen' => ['type' => 'array', 'items' => ['type' => 'integer']],
        'uebung_aufgabe' => $str,
        'uebung_loesung' => $str,
        'uebung_tipp' => $str,
        'jetzt_du' => $str,
    ];
    $text = "Einsatz \"Was soll ich machen?\". $name versteht diese Aufgabe nicht." . ($nr !== '' ? " Gemeint ist Aufgabe $nr." : " Wenn mehrere Aufgaben zu sehen sind, nimm die erste, die noch nicht fertig ist, und sag in aufgabe, welche du meinst.")
        . "\nFülle aus:\n"
        . "thema: 2-4 Wörter, z.B. \"Plus mit Zehnerübergang\".\n"
        . "aufgabe: 1-2 Sätze in einfachen Worten: was die Aufgabe will und was am Ende im Heft stehen soll. Ohne Lösung.\n"
        . "verstaendnis_frage: eine kurze Frage, ob $name verstanden hat, WAS zu tun ist (nicht das Ergebnis), z.B. \"Sollst du hier plus oder minus rechnen?\" oder \"Was sollst du mit den Nomen machen?\". verstaendnis_antworten: genau 3 kurze Antworten, verstaendnis_richtig: Index 0 bis 2 der richtigen. verstaendnis_erklaerung: 1 Satz, warum.\n"
        . "schritte: 2 bis 4 kleine Schritte. Jeder Schritt hat frage (eine Denkfrage, die $name selbst weiterbringt) und hinweis (1-2 Sätze: konkrete Strategie mit Material, Silben oder Regel, ohne Ergebnis).\n"
        . "beispiel_aufgabe: ein ähnliches Beispiel mit anderen Zahlen oder Wörtern. beispiel_weg: 2 bis 4 kurze Sätze, wie man es löst (hier ist das Ergebnis erlaubt). beispiel_zahlen: bei Mathe die Zahlen bis 100 aus dem Beispiel, die man als Zehner und Einer zeigen kann (höchstens 2), sonst leer.\n"
        . "uebung_aufgabe: noch eine ähnliche Übungsaufgabe mit anderen Zahlen oder Wörtern, die $name gleich selbst lösen soll. uebung_loesung: nur die Lösung als Zahl oder ein Wort. uebung_tipp: 1 Satz Tipp für einen zweiten Versuch.\n"
        . "jetzt_du: ein ermutigender Satz für die echte Aufgabe im Heft.\n"
        . "eltern_notiz: 1 Satz: worum ging es, was war unklar.";
} elseif ($modus === 'kontrolle' || $modus === 'nach') {
    $prop = $basis + [
        'lob' => $str,
        'anzahl_aufgaben' => ['type' => 'integer'],
        'anzahl_richtig' => ['type' => 'integer'],
        'spuren' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['wo', 'frage', 'hinweis', 'fehlerart'], 'properties' => [
            'wo' => $str, 'frage' => $str, 'hinweis' => $str, 'fehlerart' => ['type' => 'string', 'enum' => $fehlerarten]]]],
        'eltern_tipp' => $str,
    ];
    $spurText = "spuren: eine Spur je Fehler, höchstens 5, in Reihenfolge auf der Seite. wo = Ort für ein Kind. frage = eine Denkfrage, die $name selbst zum Fehler führt (\"Rechne nochmal mit den Fingern. Kommst du auf dasselbe?\", \"Kann man der, die oder das davor setzen?\"). hinweis = stärkere Hilfe, 1-2 Sätze mit Strategie, Material oder Regel, ohne Lösung. fehlerart passend wählen.";
    $text = "Einsatz \"Kontrolle!\". $name hat die Seite fertig und möchte, dass du nachschaust. Prüfe jede Aufgabe auf dem Foto genau. Rechne selbst nach, bevor du etwas als falsch meldest. Melde nur, was wirklich falsch oder vergessen ist, im Zweifel nicht."
        . "\nFülle aus: thema (2-4 Wörter), lob (1-2 Sätze: lobe konkret Anstrengung oder Vorgehen, das du auf dem Foto siehst, z.B. ordentliche Schrift, Nebenrechnung, alle Aufgaben bearbeitet; nicht \"du bist schlau\"), anzahl_aufgaben und anzahl_richtig (einzelne Rechnungen bzw. Wörter/Lücken zählen), "
        . $spurText . " eltern_notiz (1-2 Sätze mit den genauen Fehlern), eltern_tipp (1 Satz: wie die Eltern das zu Hause spielerisch und mit Material üben können). Wenn alles stimmt, ist spuren leer.";
    if ($modus === 'nach') {
        $vorher = [];
        foreach (array_slice((array)($in['spuren'] ?? []), 0, 5) as $s) $vorher[] = '- ' . kurz($s['wo'] ?? '', 80) . ': ' . kurz($s['hinweis'] ?? '', 160);
        $text = "Einsatz \"Nachkontrolle\". $name hat die Seite verbessert und zeigt sie dir nochmal. Vorher hattest du diese Spuren gefunden:\n" . implode("\n", $vorher)
            . "\nSchau, ob diese Stellen jetzt stimmen. Lob im Feld lob ganz konkret das Dranbleiben und die Verbesserungen. In spuren kommen nur noch Fehler, die immer noch da sind. Gib dort eine NEUE, andere Denkfrage und einen anderen, konkreteren Hinweis als vorher (wieder ohne Lösung). "
            . $spurText . " Zähle anzahl_aufgaben und anzahl_richtig für die ganze Seite neu. Fülle thema, eltern_notiz (was wurde verbessert, was nicht) und eltern_tipp.";
    }
} else { // frage
    $prop = ['antwort' => $str];
    $kontext = kurz($in['kontext'] ?? '', 1500);
    $frage = kurz($in['frage'] ?? '', 300);
    $stufe = max(1, min(3, (int)($in['stufe'] ?? 1)));
    if ($frage === '') antwort(400, ['ok' => false, 'error' => 'Frage fehlt']);
    $stufen = [1 => 'Hilfestufe 1: Stell eine Gegenfrage oder gib einen kleinen Denkanstoß, damit $name selbst weiterkommt.',
        2 => 'Hilfestufe 2: Gib einen konkreten Hinweis mit Material (Finger, Zehnerstangen blau, Einerwürfel rot, Rechenstrich) oder einer Regel (Silben, Verlängern, Artikelprobe).',
        3 => 'Hilfestufe 3: Mach einen Teilschritt mit $name gemeinsam vor, z.B. den ersten Rechenschritt bis zur Zehn oder die erste Silbe, aber nicht das Endergebnis.'];
    $text = "Einsatz \"Frag Paul\". Bisher hast du $name Folgendes gesagt:\n$kontext\n\n$name fragt jetzt: \"$frage\"\n"
        . str_replace('$name', $name, $stufen[$stufe]) . "\n"
        . "Wenn $name bittet, es anders zu erklären, nimm eine andere Darstellung als vorher (Material, Bild im Kopf, Alltag). "
        . "Antworte im Feld antwort mit höchstens 3 kurzen Sätzen. Keine Lösung verraten. Wenn $name nach der Lösung fragt, sag freundlich, dass ein Polizeihund nur Spuren zeigt, und gib einen kleinen Tipp. Wenn die Frage nichts mit den Hausaufgaben zu tun hat, lenk freundlich zurück zur Aufgabe. Ermutige kurz.";
}

$schema = ['type' => 'object', 'additionalProperties' => false, 'properties' => $prop, 'required' => array_keys($prop)];
$inhalt = [];
if ($bild) $inhalt[] = $bild;
$inhalt[] = ['type' => 'text', 'text' => $text];

$anfrage = [
    'model' => $modell,
    'max_tokens' => 16000,
    'system' => $system,
    'output_config' => ['effort' => $modus === 'frage' ? 'low' : $aufwand, 'format' => ['type' => 'json_schema', 'schema' => $schema]],
    'messages' => [['role' => 'user', 'content' => $inhalt]],
];
$kopf = [
    'Content-Type: application/json',
    'x-api-key: ' . $schluessel,
    'anthropic-version: 2026-10-21',
];
// Lehnt das Hauptmodell ab, springt serverseitig ein Ersatzmodell ein (nur bei Modellen, die das kennen)
if (preg_match('/^claude-(opus-5|fable-5|sonnet-5-5)/', $modell)) {
    $anfrage['fallbacks'] = 'default';
    $kopf[] = 'anthropic-beta: server-side-fallback-2026-07-01';
}

$body = json_encode($anfrage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$url = getenv('WACHE_API_URL') ?: 'https://api.anthropic.com/v1/messages';
$status = 0; $resp = false;
if (function_exists('curl_init')) {
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $kopf, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 140, CURLOPT_CONNECTTIMEOUT => 10]);
    $resp = curl_exec($c);
    $status = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
} else {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $kopf), 'content' => $body, 'timeout' => 140, 'ignore_errors' => true]]);
    $resp = @file_get_contents($url, false, $ctx);
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $mm)) $status = (int)$mm[1];
}
if ($resp === false || $status === 0) antwort(502, ['ok' => false, 'error' => 'Paul ist gerade nicht erreichbar (keine Verbindung)']);
$r = json_decode($resp, true);
if ($status !== 200 || !is_array($r)) {
    $msg = is_array($r) ? ($r['error']['message'] ?? '') : '';
    $was = $status === 401 ? 'API-Schlüssel ungültig' : ($status === 429 ? 'zu viele Anfragen, bitte kurz warten' : ($status >= 500 ? 'Dienst überlastet, gleich nochmal' : 'Fehler ' . $status));
    antwort(502, ['ok' => false, 'error' => $was, 'detail' => mb_substr($msg, 0, 200)]);
}
if (($r['stop_reason'] ?? '') === 'refusal') antwort(200, ['ok' => false, 'error' => 'Paul kann bei diesem Foto nicht helfen']);
$txt = '';
foreach ((array)($r['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') $txt .= $b['text'];
$daten = json_decode($txt, true);
if (!is_array($daten)) antwort(502, ['ok' => false, 'error' => 'Paul hat sich verhaspelt, bitte nochmal']);
antwort(200, ['ok' => true, 'data' => $daten]);
