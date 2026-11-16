# NetLab Guardian – Fix für HTTP 404 bei Aktionen und kompakte Diagramme

Projektverzeichnis auf dem Server: `/var/www/NetLab_Guardian/`

Die folgenden Dateien aus der Änderungs-ZIP **mit ihren Unterordnern** direkt in diesem Verzeichnis ersetzen:

| Datei in der ZIP | Vollständiger Zielpfad |
|---|---|
| `app/Support/helpers.php` | `/var/www/NetLab_Guardian/app/Support/helpers.php` |
| `public/assets/js/interface.js` | `/var/www/NetLab_Guardian/public/assets/js/interface.js` |
| `public/assets/css/visual-interactions.css` | `/var/www/NetLab_Guardian/public/assets/css/visual-interactions.css` |
| `app/Views/dashboard/status_chart.php` | `/var/www/NetLab_Guardian/app/Views/dashboard/status_chart.php` |
| `app/Views/layout/top.php` | `/var/www/NetLab_Guardian/app/Views/layout/top.php` |
| `public/style.css` | `/var/www/NetLab_Guardian/public/style.css` |
| `DATEIPFADE.md` | `/var/www/NetLab_Guardian/DATEIPFADE.md` |
| `README.md` | `/var/www/NetLab_Guardian/README.md` |

## Aktualisieren

1. Vorher `storage/inventory.sqlite` sichern; dieser Fix verändert das Schema nicht.
2. Die ZIP **im Projekt-Hauptverzeichnis** entpacken, nicht in `public/`.
3. Die **PHP- und JavaScript-Dateien gemeinsam** einspielen: `interface.js` erwartet die neue AJAX-Redirect-Antwort aus `helpers.php`.
4. Im Browser eine harte Aktualisierung durchführen (`Strg+F5`). Falls PHP-OPcache aktiv ist, PHP-FPM bzw. Apache PHP neu laden.
5. Die Aktionen einmal testen: Rack prüfen, Meldung mit × schließen, Gerät bearbeiten, Incident lösen. Bleibt ein Fehler, in Browser-Entwicklertools → Netzwerk den Request-Pfad und Status sowie im PHP-Log die Fehlermeldung prüfen.

Es müssen **keine alten Dateien entfernt** und **keine Datenbankmigration** ausgeführt werden. Installer, Python-Interpreter und Incident-Daten sind nicht verändert.

## Technische Änderung

Die PHP-Funktion `redirect()` antwortet bei AJAX-POSTs mit `204 No Content` und dem Header `X-NetLab-Redirect`. So wird keine möglicherweise falsche Weiterleitungsadresse automatisch abgerufen, nachdem der POST bereits ausgeführt wurde. Normale Formulare ohne JavaScript behalten das bisherige HTTP-303-Verhalten. Der Browser lädt nur die aktualisierte HTML-Ansicht über die tatsächlich funktionierende Seitenadresse nach, ohne Sprung zum Anker. Fehlerhinweise werden pro Rack nicht mehr gestapelt.
