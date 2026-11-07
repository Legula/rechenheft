# Philians Rechenheft

Mathe-Übungsheft für die 2. Klasse als einzelne Web-Seite. Läuft in jedem Browser, auch im Silk-Browser auf dem Amazon Fire Tablet.

- **Plus und Minus bis 100** in 4 Stufen
- **Uhr lesen** in 4 Stufen
- Kurze Erklärung vor jeder Runde, 10 Aufgaben, Lösungsweg bei Fehlern
- Schwierigkeit passt sich in jeder Runde an: nach 3 richtigen Antworten in Folge kommt eine Profi-Aufgabe aus der nächsten Stufe, nach 2 Fehlern in Folge eine leichtere Übungsaufgabe
- Ab 8 von 10 richtig wird die nächste Stufe freigeschaltet; nach zwei Runden mit höchstens 4 richtigen geht es eine Stufe zurück
- Sterne für richtige Aufgaben; für 5 Sterne gibt es ein Minispiel: Ballons platzen lassen oder Memory
- Elternbereich mit Lernstand (hinter einer kleinen Rechenaufgabe)

Der Lernstand wird nur im Browser des Geräts gespeichert (`localStorage`). Es werden keine Daten verschickt; nur die Schriften kommen von Google Fonts.

## Aufs Tablet

Seite im Silk-Browser öffnen: https://legula.github.io/rechenheft/  
Menü → „Zum Startbildschirm hinzufügen“.

## Aufbau

Alles steckt in `index.html` (HTML, CSS und JavaScript). Gehostet über GitHub Pages vom Branch `main`.
