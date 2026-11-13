# Sicherheitsmodell und Meldungen

## Schutzgrenzen

- Ticket-, Gastbestellungs- und API-Schlüssel werden als zufällige Geheimnisse ausgegeben; in der Datenbank werden nur Hashes gespeichert. Gastlinks sind Inhaberzugänge und dürfen nicht weitergegeben werden.
- WordPress REST-Schreibzugriffe prüfen Berechtigungen und Eventbereiche. Entwickler-API-Schlüssel sind auf ein Event sowie `events.read` und `reports.read` beschränkt und können ablaufen oder widerrufen werden.
- Öffentliche Checkout-, Sitzplatz- und Wartelistenrouten sind nicht authentifiziert. Sie benötigen Hosting-seitige Rate-Limits, TLS, Bot-Abwehr und Überwachung. Plugin-Transients sind kein verteilter Rate-Limiter. Checkout und Servicebuchungen begrenzen zusätzlich pro IP (Filter `wpcap_checkout_ip_limit`, Standard 30/Stunde, und `wpcap_service_booking_ip_limit`, Standard 40/Stunde); hinter einem Proxy muss `REMOTE_ADDR` korrekt gesetzt sein.
- Offline-Scanner speichern Codes lokal und können während einer Netzunterbrechung nicht garantieren, dass ein Ticket an einem anderen Gerät unbenutzt geblieben ist.
- Integrationsadapter werden standardmäßig nicht aktiviert. Externe Zugangsdaten gehören in geschützte WordPress-Konfiguration bzw. Secret-Storage, niemals in Quellcode oder Logs.

## Hilfscode für den Einlass

Jedes Ticket hat neben dem 64-stelligen Ticket-Code (QR) einen kurzen **Hilfscode** (8 Zeichen, z. B. `K7QM-4XWA`), der auf der Ticketseite, im Ticket-PDF, in den Ticket-Mails und auf gedruckten Tickets (`{help_code}`) unter dem QR-Code steht. Einlasspersonal tippt ihn ein, wenn der QR-Code nicht lesbar ist (Web-Scanner, Offline-Scanner-PWA online, Scanner-App-API `code`).

Entscheidung und Begründung:

- **Nur für Einlasspersonal.** Der Hilfscode wird ausschließlich von den Scan-Endpunkten angenommen (Berechtigung `wpcap_checkin` mit Event-Zuweisung bzw. gekoppeltes Scannergerät). Ticketseite, Bestellportal, Wallet und alle öffentlichen Routen verlangen weiterhin den 64-stelligen Code. Ein Hilfscode eines fremden Events führt wie ein QR-Code zu `forbidden`/`wrong_event`.
- **Raten ist gedrosselt und unergiebig.** Alphabet ohne 0/O/1/I (32 Zeichen) → 40 Bit (≈ 1,1 Billionen Codes). Jede Eingabe zählt gegen drei Grenzen: je Benutzer 30, je Client-IP 60 und je Website 600 in 10 Minuten (Filter `wpcap_help_code_rate_limits`). Selbst wer die Website-Grenze dauerhaft ausschöpft (86 400 Versuche/Tag), trifft bei 100 000 gültigen Tickets mit etwa 0,8 % Wahrscheinlichkeit pro Tag irgendein Ticket – und braucht dafür ein Einlasskonto für genau dieses Event, also ohnehin die Möglichkeit, Personen einzulassen. Überschreitungen werden im Audit-Log (`help_code_rate_limited`) protokolliert.
- **Kein Klartext in der Datenbank.** Der Hilfscode wird per HMAC aus dem Ticket-Code abgeleitet; gespeichert wird nur ein HMAC (`wpcap_tickets.help_hash`, eindeutig) und ein Zähler (`help_n`) für den seltenen Fall einer Kollision. Der Schlüssel stammt aus `WPCAP_HELP_CODE_KEY` oder dem WordPress-Auth-Salt, nicht aus der Datenbank. Wer nur die Datenbank liest, kann Hilfscodes weder ablesen noch offline durchprobieren.
- **Neuer Ticket-Code = neuer Hilfscode.** Jede Code-Rotation (Neuversand, Übertragung, Neuausstellung, Zuweisung, Druck, Gästeliste) ersetzt den Hilfscode sofort (Action `wpcap_ticket_code_rotated`); der alte funktioniert nicht mehr.
- **Nachvollziehbar.** Einlässe per Hilfscode stehen im Audit-Log mit `via: help_code`; der Scanner zeigt „Per Hilfscode geprüft – Ticketinhaber ggf. mit Ausweis abgleichen“.
- **Nur online.** Die Offline-Pakete enthalten keine Hilfscodes (sie wären ohne Server-Schlüssel nicht prüfbar und sonst offline durchprobierbar). Offline weisen Web-Scanner und PWA auf QR-Code bzw. langen Code hin.
- **Bestand.** Tickets aus der Zeit vor Schema 3.8.6 erhalten ihren Hilfscode beim nächsten Aufruf der Ticketseite oder bei der nächsten Code-Rotation (nur dann ist der Ticket-Code im Klartext bekannt). Ein geänderter Auth-Salt (bzw. `WPCAP_HELP_CODE_KEY`) macht bereits ausgegebene Hilfscodes ungültig; QR-Codes bleiben gültig, neue Hilfscodes entstehen beim nächsten Seitenaufruf.

## Offenlegung

Sicherheitsprobleme bitte über GitHubs private Meldemöglichkeit des Repositorys melden, sofern sie aktiviert ist. Keine Ticketcodes, Kundendaten, API-Schlüssel oder funktionsfähigen Exploits in öffentlichen Issues posten. Eine unabhängige Prüfung und ein formeller Incident-Prozess stehen noch aus.

## Release-Gate

Vor 1.0 müssen Bedrohungsmodell, Rollen-/Nonce-Prüfung, SQL-Transaktionen, Datenschutzexport und -löschung, Offline-Konflikte, Abhängigkeitsprüfung und Wiederherstellung extern oder durch eine dokumentierte unabhängige Prüfung bewertet werden. Dieses Dokument ist keine Sicherheitszertifizierung.
