# Philians Rechenheft

Mathe-Übungsheft für die 2. Klasse als einzelne Web-Seite. Läuft in jedem Browser, auch im Silk-Browser auf dem Amazon Fire Tablet.

- **Plus und Minus bis 100** in 4 Stufen
- **Uhr lesen** in 4 Stufen
- **Geld** in 4 Stufen: Cent zählen, Euro zählen, Preise zusammenrechnen, Wechselgeld
- **Sachaufgaben** in 4 Stufen: kurze Rechengeschichten bis hin zu Mal-Geschichten, mit Vorlese-Taste und farbigen Silben als Lesehilfe (im Elternbereich abschaltbar)
- Kurze Erklärung vor jeder Runde, 10 Aufgaben, Lösungsweg bei Fehlern
- Schwierigkeit passt sich in jeder Runde an: nach 3 richtigen Antworten in Folge kommt eine Profi-Aufgabe aus der nächsten Stufe, nach 2 Fehlern in Folge eine leichtere Übungsaufgabe
- Ab 8 von 10 richtig wird die nächste Stufe freigeschaltet; nach zwei Runden mit höchstens 4 richtigen geht es eine Stufe zurück
- Sterne für richtige Aufgaben; für 5 Sterne gibt es ein Minispiel (Ballons platzen lassen oder Memory); auf der Baustelle wird pro Stern ein Klemmbaustein gesetzt, bis Haus, Leuchtturm, Burg und Baumhaus stehen
- Eule Olaf (das Schulmaskottchen) begrüßt und lobt
- Olafs Insel: kreativ bauen mit Holz, Stein, Ziegel, Stroh, Dach, Fenster, Tür, Lampe, Blumen und Bäumen; Tiere ziehen in Häuser mit Tür und Dach ein; Tag/Nacht und Fotoalbum
- Testmodus für Erwachsene im Elternbereich (999 Sterne, eigener Speicher, kein Abgleich)
- 18 Abzeichen für Meilensteine, mit Sammelseite
- Elternbereich mit Lernstand (hinter einer kleinen Rechenaufgabe) und einer echten Belohnung mit Sterne-Ziel

Der Lernstand wird im Browser des Geräts gespeichert (`localStorage`). Mit Familien-Code wird er zusätzlich nur mit der eigenen Synology abgeglichen. Sonst werden keine Daten verschickt; nur die Schriften kommen von Google Fonts.

## Aufs Tablet

Seite im Silk-Browser öffnen: https://legula.github.io/rechenheft/  
Menü → „Zum Startbildschirm hinzufügen“.

## Auf der Synology betreiben (mit Abgleich zwischen Geräten)

Auf der Synology läuft die App zusammen mit `sync.php`. Dann können alle Geräte denselben Lernstand nutzen. Die Daten bleiben im Heimnetz.

1. **Container Manager** im Paket-Zentrum installieren (falls noch nicht da).
2. Dieses Repository als ZIP herunterladen: https://github.com/Legula/rechenheft/archive/refs/heads/main.zip
3. In der **File Station** im Freigabeordner `docker` einen Ordner `rechenheft` anlegen und den *Inhalt* des ZIPs dort hineinlegen (so dass `docker/rechenheft/index.html` und `docker/rechenheft/docker-compose.yml` direkt darin liegen).
4. **Container Manager → Projekt → Erstellen**: Name `rechenheft`, Pfad `/docker/rechenheft`, Quelle „Vorhandene docker-compose.yml verwenden“. Weiter, fertigstellen. Das Abbild `php:8.3-apache` wird einmal geladen.
5. Im Browser öffnen: `http://<IP-der-Synology>:8088/`
6. Auf jedem Gerät: **Für Eltern → Familien-Code** eintragen (überall derselbe, z. B. `sonnenblume-42`) und speichern.

Die App funktioniert auch ohne Verbindung zur Synology. Sie speichert dann auf dem Gerät und gleicht ab, sobald die Synology wieder erreichbar ist (zu Hause oder per VPN).

Der Lernstand liegt im Docker-Volume `rechenheft-daten`. **Updates kommen automatisch:** `index.php` holt sich höchstens einmal pro Stunde die neueste `index.html` von GitHub und speichert sie im Volume. Ohne Internet läuft die zuletzt geladene Version weiter. Sofort aktualisieren: im Elternbereich auf „Jetzt aktualisieren“ tippen (oder `http://<IP>:8088/?update=1` aufrufen). Nur wenn sich `sync.php`, `index.php` oder `docker-compose.yml` ändern, das ZIP neu einspielen und das Projekt neu erstellen.

## Aufbau

Die App steckt komplett in `index.html` (HTML, CSS und JavaScript). `sync.php` speichert den Lernstand pro Familien-Code als JSON-Datei. `docker-compose.yml` startet beides auf der Synology. Zusätzlich gehostet über GitHub Pages vom Branch `main` (dort ohne Abgleich).
