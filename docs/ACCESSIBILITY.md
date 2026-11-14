# Barrierefreiheit: aktueller Stand

Die öffentliche Checkout- und Scanner-Oberfläche enthält Statusmeldungen mit `aria-live`, sichtbare Tastaturfokusse, Mindesthöhen für Schaltflächen und eine Reduced-Motion-Regel. Das ist eine Implementierungsbasis, keine WCAG-Konformitätsaussage.

Vor 1.0 sind manuelle Tastatur- und Screenreader-Prüfungen für Checkout, Scanner und WordPress-Admin nötig. Dabei müssen Fehlertexte, Fokusreihenfolge, Zoom bis 200 %, Kontrast, QR-Code-Fallback und Kamera-Berechtigungsfehler geprüft werden. Automatische Prüfungen allein reichen nicht.

## Automatische Prüfung (Release-Gate)

`tests/a11y/gate.sh` prüft öffentliche Seiten und jede Admin-Seite des Plugins mit axe-core (WCAG 2.2 A/AA + best-practice) und geht Checkout und Scanner mit der Tastatur durch; jeder Verstoß bricht ab (Details: `docs/TESTING.md`). Admin-Hinweise für eigene Erweiterungen: Selects und Felder ohne sichtbares `<label>` bekommen einen Namen über `Forms::aria_label()`; Checkboxen/Radios auf Plugin-Seiten sind 24 × 24 px groß (WCAG 2.5.8), Statusfarben erfüllen 4,5:1. Der Kamera-Berechtigungsfehler des Web-Scanners und die manuelle Code-Eingabe sind per E2E-Test (`tests/e2e/scanner.test.js`) abgedeckt; Screenreader-Tests bleiben manuell.
