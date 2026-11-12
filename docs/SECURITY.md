# Sicherheitsmodell und Meldungen

## Schutzgrenzen

- Ticket-, Gastbestellungs- und API-Schlüssel werden als zufällige Geheimnisse ausgegeben; in der Datenbank werden nur Hashes gespeichert. Gastlinks sind Inhaberzugänge und dürfen nicht weitergegeben werden.
- WordPress REST-Schreibzugriffe prüfen Berechtigungen und Eventbereiche. Entwickler-API-Schlüssel sind auf ein Event sowie `events.read` und `reports.read` beschränkt und können ablaufen oder widerrufen werden.
- Öffentliche Checkout-, Sitzplatz- und Wartelistenrouten sind nicht authentifiziert. Sie benötigen Hosting-seitige Rate-Limits, TLS, Bot-Abwehr und Überwachung. Plugin-Transients sind kein verteilter Rate-Limiter. Checkout und Servicebuchungen begrenzen zusätzlich pro IP (Filter `wpcap_checkout_ip_limit`, Standard 30/Stunde, und `wpcap_service_booking_ip_limit`, Standard 40/Stunde); hinter einem Proxy muss `REMOTE_ADDR` korrekt gesetzt sein.
- Offline-Scanner speichern Codes lokal und können während einer Netzunterbrechung nicht garantieren, dass ein Ticket an einem anderen Gerät unbenutzt geblieben ist.
- Integrationsadapter werden standardmäßig nicht aktiviert. Externe Zugangsdaten gehören in geschützte WordPress-Konfiguration bzw. Secret-Storage, niemals in Quellcode oder Logs.

## Offenlegung

Sicherheitsprobleme bitte über GitHubs private Meldemöglichkeit des Repositorys melden, sofern sie aktiviert ist. Keine Ticketcodes, Kundendaten, API-Schlüssel oder funktionsfähigen Exploits in öffentlichen Issues posten. Eine unabhängige Prüfung und ein formeller Incident-Prozess stehen noch aus.

## Release-Gate

Vor 1.0 müssen Bedrohungsmodell, Rollen-/Nonce-Prüfung, SQL-Transaktionen, Datenschutzexport und -löschung, Offline-Konflikte, Abhängigkeitsprüfung und Wiederherstellung extern oder durch eine dokumentierte unabhängige Prüfung bewertet werden. Dieses Dokument ist keine Sicherheitszertifizierung.
