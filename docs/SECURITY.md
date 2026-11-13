# Sicherheitsmodell und Meldungen

## Schutzgrenzen

- Ticket-, Gastbestellungs- und API-Schlüssel werden als zufällige Geheimnisse ausgegeben; in der Datenbank werden nur Hashes gespeichert. Gastlinks sind Inhaberzugänge und dürfen nicht weitergegeben werden.
- WordPress REST-Schreibzugriffe prüfen Berechtigungen und Eventbereiche. Entwickler-API-Schlüssel sind auf ein Event und lesende Scopes beschränkt, haben ein Ratenlimit und können ablaufen oder widerrufen werden (siehe [Entwickler-API-Schlüssel](#entwickler-api-schlüssel)).
- Öffentliche Checkout-, Sitzplatz- und Wartelistenrouten sind nicht authentifiziert. Sie benötigen Hosting-seitige Rate-Limits, TLS, Bot-Abwehr und Überwachung. Das Plugin begrenzt zusätzlich pro Client: Checkout (Filter `wpcap_checkout_ip_limit`, Standard 30 pro Stunde), Servicebuchungen (`wpcap_service_booking_ip_limit`, Standard 40 pro Stunde), Sitzplatz-Reservierungen, Warteliste, Bestellportal, Mollie-Webhooks und weitere. Die Zähler sind über alle PHP-Worker und Webserver hinweg atomar. Hinter einem Proxy oder CDN muss die Client-Adresse richtig aufgelöst werden; siehe [Ratenlimits hinter Proxys und mit mehreren Workern](#ratenlimits-hinter-proxys-und-mit-mehreren-workern).
- Selbstbedienung im Bestellportal (A118) nutzt dieselbe Prüfung wie das Portal (Bestell-ID + Zugangstoken). Ticket-PDFs werden per POST mit Nonce abgerufen, je Bestellung und Client begrenzt und enthalten nur gültige, unbenutzte Tickets der eigenen Bestellung, die nicht an andere Personen übertragen oder zugewiesen sind. Da Ticketcodes nur gehasht gespeichert sind, erhält ein Ticket beim Download einen neuen Code (der alte wird ungültig), außer ein Modul kennt den aktuellen Code noch (`wpcap_ticket_known_code`, z. B. Wallet). Stornierungsanfragen (Nonce, Idempotenzschlüssel, Rate-Limit) landen immer als `pending_approval` in der Erstattungsfreigabe; ausgezahlt wird erst nach der Entscheidung einer berechtigten Person.
- Offline-Scanner speichern Codes lokal und können während einer Netzunterbrechung nicht garantieren, dass ein Ticket an einem anderen Gerät unbenutzt geblieben ist.
- Integrationsadapter werden standardmäßig nicht aktiviert. Externe Zugangsdaten gehören in geschützte WordPress-Konfiguration bzw. Secret-Storage, niemals in Quellcode oder Logs.

## Ratenlimits hinter Proxys und mit mehreren Workern

**Zähler (`PassCaptain\RateLimit`).** Es gilt ein festes Zeitfenster je Bucket. Jeder Aufruf zählt;
erlaubt sind genau `limit` Aufrufe je Fenster, egal wie viele Worker gleichzeitig zählen:

| Backend | Wann | Atomar |
|---|---|---|
| `cache` | Ein persistenter Objekt-Cache ist installiert (`wp_using_ext_object_cache()`). Gezählt wird mit `wp_cache_add()` und `wp_cache_incr()`, das Fenster ist die TTL. | Ja, mit einem Redis oder Memcached, den alle Webserver gemeinsam nutzen. |
| `db` | Standard ohne Objekt-Cache. Eine Zeile je Bucket und Fenster in `wpcap_rate_limits`, gezählt mit einem einzigen `INSERT … ON DUPLICATE KEY UPDATE hits=LAST_INSERT_ID(hits+1)`. | Ja, auch mit mehreren Webservern an derselben Datenbank. |
| `transient` | Nur als Rückfall, wenn die Tabelle noch fehlt (vor dem Datenbank-Upgrade) oder die Datenbank einen Fehler meldet. | Nein. |

Schlägt `wp_cache_incr()` fehl (Schlüssel verdrängt, Cache nicht erreichbar), wird in der Datenbank
gezählt. Abgelaufene Zeilen löscht der Cron `wpcap_cleanup_reservations` (`RateLimit::purge()`), dazu
gelegentlich ein Aufruf. Die Bucket-Namen werden mit einem Schlüssel aus `wp_salt()` gehasht; IP-Adressen
stehen nie im Klartext in Datenbank oder Cache. Mit dem Filter `wpcap_rate_limit_backend` lässt sich das
Backend erzwingen.

Nachweis: `tests/integration/modules/zz_a186.php` startet 8 PHP-Prozesse, die gleichzeitig je 30 Mal
denselben Bucket mit Limit 50 zählen. Erlaubt werden genau 50 Aufrufe, gezählt werden alle 240.

**Client-Adresse (`PassCaptain\ClientIp`).** Ohne Konfiguration gilt immer `REMOTE_ADDR`.
Weiterleitungs-Header wie `X-Forwarded-For`, `Forwarded` oder `CF-Connecting-IP` werden standardmäßig
nie ausgewertet, weil jeder Client sie setzen kann. Ausgewertet wird ein Header nur, wenn die direkte
Gegenstelle (`REMOTE_ADDR`) in **Einstellungen → Allgemein → Proxy / Client-Adresse →
Vertrauenswürdige Proxys** steht. Dort stehen IP-Adressen oder CIDR-Bereiche für IPv4 und IPv6. Alternativ
geht das über die Konstante oder Umgebungsvariable `WPCAP_TRUSTED_PROXIES` oder den Filter
`wpcap_trusted_proxies`. Welcher Header gilt, legt die Einstellung `client_ip_header` fest
(`WPCAP_CLIENT_IP_HEADER`, Filter `wpcap_client_ip_header`):

- `x-forwarded-for` (Standard) und `forwarded` (RFC 7239): Die Kette wird von rechts gelesen.
  Vertrauenswürdige Proxys werden übersprungen; die **rechteste nicht vertrauenswürdige** Adresse ist der
  Client. Was der Client selbst links einträgt, wird ignoriert. Ist ein Eintrag ungültig (zum Beispiel
  `unknown` oder ein verschleierter Name), gilt `REMOTE_ADDR`.
- `cf-connecting-ip`: die von Cloudflare gesetzte Adresse. Dafür müssen die Cloudflare-IP-Bereiche als
  vertrauenswürdige Proxys eingetragen sein.

Die aufgelöste Adresse nutzen alle Ratenlimits, die Einwilligungsnachweise und die Partner-API. Der
bisherige Filter `wpcap_client_ip` kann das Ergebnis weiterhin überschreiben.

**Empfehlungen fürs Hosting**

- **Mehrere Webserver oder PHP-FPM-Worker:** einen persistenten Objekt-Cache mit Redis oder Memcached
  einrichten, den alle Server gemeinsam nutzen (zum Beispiel Redis Object Cache). Ein Objekt-Cache, der
  nur auf einem Server liegt (APCu-Drop-ins), ist zwischen Servern nicht atomar. In diesem Fall
  `add_filter( 'wpcap_rate_limit_backend', fn() => 'db' );` setzen. Ohne Objekt-Cache ist die
  Datenbank-Tabelle bereits atomar; sie kostet einen Schreibzugriff je begrenztem Aufruf.
- **Reverse Proxy, Load Balancer oder CDN:** genau dessen Adressen beziehungsweise Bereiche als
  vertrauenswürdige Proxys eintragen und den passenden Header wählen. Keine breiten Bereiche wie
  `0.0.0.0/0` eintragen. Interne Bereiche wie `10.0.0.0/8` nur dann, wenn kein Client sie erreichen kann.
  Der Proxy muss `X-Forwarded-For` anhängen und nicht ungeprüft durchreichen; bei nginx `proxy_set_header
  X-Forwarded-For $proxy_add_x_forwarded_for;`. Alternativ setzt der Webserver `REMOTE_ADDR` selbst
  korrekt, mit nginx `real_ip` oder Apache `mod_remoteip`. Dann bleibt die Liste leer.
- **Ohne Proxy-Konfiguration** teilen sich alle Käufer hinter einem Proxy dessen Adresse und damit dessen
  Limit. Das ist sicher, kann bei großen Vorverkäufen aber zu frühen 429-Antworten führen.
- Hosting-seitige Limits (WAF, nginx `limit_req`, CDN-Regeln) bleiben die erste Verteidigungslinie. Die
  Limits des Plugins sind die zweite.
## Rollen und Capabilities

Das Plugin legt diese Rollen an (`Installer::role_definitions()`; bei Updates werden Rechte nur ergänzt, nie
entzogen). Jede Rolle hat zusätzlich `read`. Administratoren (`manage_options`) erhalten alle `wpcap_*`-Rechte
und sind nie auf Events oder Bereiche beschränkt.

| Capability | Wofür | Ticket-Administration | Veranstaltungsleitung | Vor-Ort-Kasse | Einlasspersonal | Veranstalter | Leistungserbringer | Finanzbuchhaltung | Support |
|---|---|---|---|---|---|---|---|---|---|
| `wpcap_manage_events` | Events, Termine, Ticketarten, Teams, Service-Zeitfenster und Zuweisungen verwalten | ✓ | ✓ | | | | | | |
| `wpcap_manage_orders` | Bestellungen, Tickets, Kasse, Gästelisten, Erstattung anfragen | ✓ | ✓ | ✓ | | | | | ✓ |
| `wpcap_checkin` | Einlass (Web-Scanner, Scanner-App, Offline-Scanner), Check-in-Liste | ✓ | ✓ | ✓ | ✓ | ✓ | | | |
| `wpcap_view_reports` | Berichte und Kennzahlen (nur Summen) | ✓ | ✓ | | | ✓ | | ✓ | |
| `wpcap_refund` | Erstattungen ausführen bzw. anstoßen | ✓ | ✓ | | | | | | |
| `wpcap_approve_refunds` | Erstattungen freigeben (Vier-Augen) | ✓ | | | | | | ✓ | |
| `wpcap_view_finance` | Rechnungen, Kassenbelege, Buchhaltungsexport – ohne Event-Zuweisung (Bereich nach A119) | ✓ | | | | | | ✓ | |
| `wpcap_service_bookings` | Eigene Leistungen: Zeitfenster und Buchungen ansehen, bestätigen, als nicht erschienen markieren | ✓ | | | | | ✓ | | |

Rollen-Slugs: `wpcap_ticket_admin`, `wpcap_event_manager`, `wpcap_box_office`, `wpcap_checkin_staff`,
`wpcap_partner` (Veranstalter, also Organisator eines Events), `wpcap_service_provider` (Leistungserbringer,
z. B. Workshopleitung, Fotograf:in, Behandlungsraum), `wpcap_finance`, `wpcap_support`.

**Leistungserbringer (A143):** Die Rolle hat nur `wpcap_service_bookings`. Zugang entsteht ausschließlich über
einen Leistungserbringer-Datensatz (A133, **Pass Captain → Service-Zeitfenster**, Tabellen
`wpcap_service_providers`/`wpcap_resource_providers`): Ist ein *aktiver* Datensatz mit dem WordPress-Benutzer
verknüpft (`user_id`), erreicht die Person genau die Ressourcen, denen dieser Datensatz zugeordnet ist
(`ServiceProviders::resource_ids_for_user()`, `Access::can_access_resource()`). Verknüpfen und Zuordnen darf nur,
wer `wpcap_manage_events` für das Event hat – einen bereits zugeordneten Datensatz ändern (z. B. den
Benutzer umhängen) nur, wer alle Events seiner Ressourcen verwaltet (A145); beides wird auditiert (`service_provider_created`/`service_provider_updated`
mit `user_id` vorher/nachher, `service_resource_providers_set`). Derselbe Datensatz schützt vor Doppelbuchung und
bestimmt die Team-Mails der Ressource. Ohne verknüpften Datensatz sieht die Person nichts; mit ihm zeigt
**Pass Captain → Meine Leistungen** die Zeitfenster und Buchungen dieser Ressourcen (ab gestern). Je Buchung:
**Bestätigen** nur für eine aktive Reservierung (`held`) einer kostenlosen Ressource (kostenpflichtige werden
mit der Zahlung bestätigt; läuft über `ServiceBookings::confirm()` mit allen Sperren und Regeln) und
**Nicht erschienen** nur für eine bestätigte Buchung ab Beginn des Zeitfensters (`no_show`, keine Erstattung).
Jede Aktion prüft serverseitig (`ServiceBookings::set_booking_status()`), schreibt `service_booking_confirmed`
bzw. `service_booking_no_show` ins Audit-Log und löst `wpcap_service_booking_status_changed` aus. Personen mit
`wpcap_manage_events` für das Event haben dieselben Aktionen in der Buchungsliste der Service-Zeitfenster.
Andere Events, Bestellungen, Tickets, Berichte oder fremde Ressourcen erreicht die Rolle nicht; die Verknüpfung
vergibt nie selbst ein Recht (die Rolle muss vorhanden sein), ein deaktivierter Datensatz entzieht den Zugang.

## Rollen, Zugriffsbereiche und Prüfreihenfolge

Jede Admin-Seite, jeder admin-post-Handler und jede authentifizierte REST-Route prüft in dieser Reihenfolge
(zentral in `PassCaptain\Access`):

1. **Capability der Rolle** (`wpcap_manage_events`, `wpcap_manage_orders`, `wpcap_checkin`, `wpcap_view_reports`,
   `wpcap_view_finance`, `wpcap_refund`, `wpcap_approve_refunds` …).
2. **Event-Zuweisung** in `wpcap_event_staff` für genau diese Capability (`Access::can_access_event()`).
   Finanzbuchhaltung (`wpcap_view_finance`) liest Berichte, Rechnungen und Kassenbelege ohne Zuweisung.
   Leistungserbringer (`wpcap_service_bookings`) brauchen statt der Event-Zuweisung einen mit ihrem Benutzer
   verknüpften, aktiven Leistungserbringer-Datensatz, der der Ressource zugeordnet ist
   (`Access::can_access_resource()`).
2b. **Engere Zuweisungen (A144)** unter **Pass Captain → Zuweisungen** (je Event; nur mit
   `wpcap_manage_events` für das Event, Terminzuweisungen zusätzlich für den Termin):
   - **Termine:** Eine Person aus dem Event-Team wird für ein Recht (Einlass, Bestellungen/Kasse, Event
     verwalten, Berichte) auf einzelne Termine beschränkt (`wpcap_staff_assignments`, Bereich `occurrence`).
     Sobald für (Person, Event, Recht) mindestens eine Terminzuweisung besteht, lassen alle terminbezogenen
     Prüfungen mit diesem Recht nur noch diese Termine zu – Scannen (Web, App, Offline-Paket), Tickets,
     Bestellungen (alle Termine der Bestellung), Teilnehmer-/No-Show-Listen, Terminabrechnung, Bearbeiten und
     Absagen von Terminen (`Access::can_access_occurrence()`, `occurrence_in_scope()`, `order_in_scope()`).
     Listen und Exporte ohne konkretes Recht (`occurrence_scope_sql()`) nutzen die Vereinigung über alle
     Team-Rechte der Person: Sie werden erst gefiltert, wenn jedes ihrer Rechte beschränkt ist. Event-weite
     Kennzahlen (REST `/events/{id}/reports/summary`) gibt es nur, wenn das Berichtsrecht nicht beschränkt ist.
     Eine Terminzuweisung setzt den Team-Eintrag für dasselbe Recht voraus und vergibt nie selbst Zugriff;
     wird der Team-Eintrag entfernt, verschwinden ihre Terminzuweisungen mit.
   - **Einlassbereiche:** Zuordnung von Personen zu Bereichen des Einlassmoduls
     (`wpcap_admission_area_staff`); wer einem Bereich zugewiesen ist, scannt für dieses Event nur dort
     (`Admission\Areas::check()`, Bereichsauswahl im Scanner, Gerätekopplung).
   - **Leistungen:** Leistungserbringer-Datensätze je Ressource (A133, siehe oben); die Seite zeigt sie je
     Ressource, verwaltet werden sie auf der Seite Service-Zeitfenster.

   Alle Änderungen landen im Audit-Log (`staff_assignment_added`/`staff_assignment_removed`,
   `admission_area_staff_added`/`admission_area_staff_removed`, `service_resource_providers_set`). Administratoren sind nie beschränkt.
3. **Zugriffsbereich (A119, optional je Person)** im WordPress-Profil unter „Pass Captain: Zugriffsbereich“
   (User-Meta `wpcap_access_scope`, Filter `wpcap_access_scope`):
   - **Spielorte:** nur Termine, deren Ort (`occurrences.location`, ohne Groß-/Kleinschreibung) aufgeführt ist.
     Ein Event ist erreichbar, sobald einer seiner Termine im Bereich liegt; alles mit Terminbezug – Tickets,
     Check-ins, Bestellungen (alle Termine der Bestellung), Teilnehmer- und No-Show-Listen, Kasse,
     Gästelisten, Terminabrechnung, Berichtszeilen – wird auf Terminebene geprüft
     (`Access::can_access_occurrence()`, `Access::occurrence_in_scope()`, `Access::occurrence_scope_sql()`).
     Event-weite Kennzahlen (REST `/events/{id}/reports/summary`, Entwickler-Schlüssel) gibt es nur, wenn
     alle Termine des Events im Bereich liegen.
   - **Veranstalter:** nur Events, die einem der gewählten Partner zugeordnet sind (`wpcap_partner_events`);
     „nur verknüpfte Veranstalter“ nimmt die Partner, mit denen die Person verknüpft ist (`wpcap_partner_users`).

   Ohne Eintrag gilt keine zusätzliche Einschränkung (bisheriges Verhalten). Administratoren
   (`manage_options`) sind nie eingeschränkt. Ein eingeschränkter Finanzzugang sieht Berichte, Rechnungen
   und Kassenbelege nur noch innerhalb seines Bereichs.

**Öffentliche REST-Routen** (bewusst ohne Anmeldung; Schutz durch Zugangstoken, Signatur, Rate-Limit oder nur
öffentliche Daten): Eventliste, Checkout und Checkout-Optionen, Bestellportal (`/orders/{id}` sowie
`transfer`, `resend`, `assign`, `billing`, `payout-account`, `exchange`, `refund-request` – Zugangstoken im Callback, bei `refund-request` zusätzlich Nonce),
Sitzplätze und Sitzplatz-Holds, Servicezeiten und -buchungen, Rabatt-Vorschau, Warteliste (Eintragen,
Angebot annehmen/ablehnen mit Token), Event-Übersetzung lesen, Zahlungs-Webhooks (Anbietersignatur),
SMS-Statusrückmeldungen und eingehende SMS (Twilio: `X-Twilio-Signature`; generischer HTTP-Anbieter:
HMAC-SHA256 über den Rohinhalt mit `sms_http_webhook_secret`, ohne Schlüssel immer 403), Apple-Wallet-Webservice (Authentifizierungstoken des Passes),
Scanner-App-Kopplung (`/app/pair`, einmaliger Kopplungscode) und der öffentliche Saalplan
(`/seating/occurrences/{id}`). Alle übrigen Routen haben eine Berechtigungsprüfung; der Test
`tests/integration/modules/zz_a119.php` zählt alle registrierten Routen auf und schlägt fehl, wenn eine neue
Route `__return_true` nutzt, ohne in dieser Liste zu stehen.

Die Selbstbedienung für Servicebuchungen (`GET /service-bookings/{id}`, `POST /service-bookings/{id}/confirm|rebook|cancel`, A135)
ist ohne Anmeldung erreichbar, aber nur mit dem persönlichen Buchungstoken aus der Reservierung (Prüfung im
`permission_callback` `ServiceBookingFlow::token_permission`, Hash-Vergleich in konstanter Zeit, 10 Fehlversuche je Buchung
und Stunde, danach 429). Die Antworten enthalten keine Daten anderer Kund:innen.

**Objektberechtigung in allen Kanälen (A145).** `tests/integration/modules/zz_a145.php` zählt jede REST-Route,
jede admin-post-Aktion (`Admin\Menu::handlers()` samt Modulen sowie per Quelltext-Scan alle übrigen
`admin_post_*`-Registrierungen) und jede AJAX-Aktion (`wp_ajax_*`) auf und prüft für jeden authentifizierten
Einstiegspunkt:

1. **Ohne jede Capability** (angemeldet, kein Plugin-Recht) wird die Anfrage mit 403 abgewiesen – vor jeder
   Validierung oder Objektsuche. admin-post-Handler prüfen deshalb zuerst das Recht
   (`Admin\Forms::require_any_cap()` bzw. `current_user_can()`), erst danach Eingaben und Objekte.
2. **Mit allen operativen Rechten, aber ohne Zuweisung zum Event des Objekts** (eigenes Event im Team,
   ohne `manage_options`/`wpcap_view_finance`) wird jede Anfrage mit Objekt-ID – Pfad-, Query- oder
   Formularparameter, gefüllt mit IDs bestehender Objekte eines fremden Events – mit 403/404 abgewiesen oder
   scheitert an der Validierung in einem Codepfad mit objektbezogener Prüfung. Ein Filter auf ein fremdes Event
   (z. B. Berichts-Export) wird abgewiesen statt still leer geliefert.
3. **Statisch** enthält der erreichbare Code jedes Einstiegspunkts eine Rechte-/Zugriffsprüfung und – sobald er
   eine Objekt-ID liest – eine objektbezogene (`Access::can_access_event/occurrence/resource`,
   `order_in_scope`, `Scope::can_*`, Eigentum an Kassenschicht/Übergabe, Partner-Schlüssel und seine Events)
   oder ist nur für Administratoren.

Öffentliche admin-post-Aktionen sind ausschließlich die mit `admin_post_nopriv_` registrierten
(Wartelisten-Angebot annehmen/ablehnen, Austragen); sie prüfen das Token des Links. Downloads und druckbare
Seiten enden über `Http::end()` (Action `wpcap_response_end`), damit der Test sie beobachten kann.

## Entwickler-API-Schlüssel

Verwaltung unter **Pass Captain → Entwickler-API** (Bereich „Einstellungen & Integrationen“, nur `manage_options`):
Liste mit Präfix, Bezeichnung, angelegt von, Scopes, Event, Anlage, letzter Nutzung, Ablauf, Ratenlimit und
Status; Anlegen (Bezeichnung, Event, Scopes, Ablauf, Ratenlimit), einmalige Anzeige des Schlüssels direkt nach
der Anlage (gespeichert wird nur der SHA-256-Hash), Ablauf und Ratenlimit ändern, Widerrufen. Alle Aktionen
prüfen Nonce und Recht und landen im Audit-Log (`developer_key_issued`, `developer_key_updated`,
`developer_key_revoked`, Objekt `api_key`; nur Präfix, nie der Schlüssel). Per REST legen auch Personen mit
`wpcap_manage_events` für ihr Event Schlüssel an (`POST /developer/keys`, optional `rate_limit`) und widerrufen
eigene (`POST /developer/keys/{id}/revoke`).

Jede Anfrage sendet `X-WPCAP-Key: <präfix>.<geheimnis>`. Geprüft werden: Schlüssel gültig, nicht widerrufen,
nicht abgelaufen; Event der Anfrage = Event des Schlüssels; die Person, die den Schlüssel angelegt hat, ist
Administrator oder im Event-Team (`wpcap_manage_events`/`wpcap_view_reports`) und hat alle Termine des Events
in ihrem Standort-/Partnerbereich (A119); der Scope der Route ist im Schlüssel enthalten; das Ratenlimit des
Schlüssels (Standard 600 Anfragen/Minute, je Schlüssel 1–10000, Filter `wpcap_developer_key_rate_limit`) ist
nicht überschritten. Sonst lehnt WordPress die Anfrage ab (401/403); ist nur das Ratenlimit erreicht, antwortet
die API mit 429 `wpcap_rate_limited` und `Retry-After` (Sekunden bis zur nächsten Minute).

| Route (Namensraum `pass-captain/v1`) | Scope | Inhalt |
|---|---|---|
| `GET /developer/events/{event_id}` | `events.read` | Name, Slug, Beschreibung, Status des Events |
| `GET /developer/events/{event_id}/service-slots` | `slots.read` | Alle Service-Zeitfenster mit Ressource, Zeit, Kapazität, gebuchten Plätzen und Status – ohne Kundendaten |
| `GET /events/{event_id}/reports/summary` | `reports.read` | Umsatz je Währung und Check-ins im Zeitraum (`from`, `to`, höchstens 90 Tage) |

Die Zuordnung steht in `DeveloperKeys::ROUTES`; weitere Scopes gibt es bewusst nicht: Routen mit
Personendaten (Warteliste, Gästelisten, Bestellungen) bleiben eingeloggten Personen bzw. der Partner-API mit
eigenem Schlüsselmodell vorbehalten.

## PII je Rolle

Personenbezogene Daten in Admin-Listen, Detailansichten, Exporten und REST-Antworten laufen über
`PassCaptain\Pii` (`Pii::can_view( $feld )`, `Pii::show( $feld, $wert )`, `Pii::mask_email()`). Das Profil ergibt
sich aus den Capabilities, nicht aus dem Rollennamen; wer mehrere Profile hat, sieht die Vereinigung.
Nicht freigegebene Felder werden maskiert (E-Mail `a***@e***.de`, Namen als Initialen, Antworten und
Rechnungsdaten leer) und sind auch nicht durchsuchbar.

| Profil (Capabilities) | Käufer-Name | Käufer-E-Mail | Telefon | Rechnungsdaten | Teilnehmer-Name | Teilnehmer-E-Mail | Antworten (Zusatzfelder) |
|---|---|---|---|---|---|---|---|
| **voll** – `manage_options`, `wpcap_manage_orders` oder `wpcap_manage_events` (Administration, Ticket-Administration, Veranstaltungsleitung, Vor-Ort-Kasse, Support) | ja | ja | ja | ja | ja | ja | ja |
| **Finanzen** – `wpcap_view_finance` (Finanzbuchhaltung) | ja | ja | ja | ja | nein | nein | nein |
| **Einlass** – `wpcap_checkin` (Einlasspersonal, Veranstalter) | nein | nein | nein | nein | nur mit Einstellung „Teilnehmernamen beim Scannen anzeigen“ | nein | nein |
| **Leistung** – `wpcap_service_bookings` (Leistungserbringer) | nein | ja (nur Buchungen der zugeordneten Ressourcen) | nein | nein | nein | nein | nein |
| **Berichte** – `wpcap_view_reports` | nein (nur Summen) | nein | nein | nein | nein | nein | nein |

Wirkung im Plugin:

- **Scanner** (Web, Scanner-App, Offline-Paket): nie Käuferdaten; Teilnehmername nur mit der Einstellung –
  auch für volle Profile, denn der Scan-Bildschirm ist öffentlich sichtbar.
- **Ticketliste im Admin:** Käufer- und Teilnehmer-E-Mail sowie Teilnehmername gemäß Matrix; Suche nur über
  sichtbare Felder.
- **Teilnehmer- und No-Show-Liste (Druck/CSV):** die volle Liste (E-Mails, Antworten) nur mit
  `wpcap_manage_events`; die Basisliste zeigt den Namen gemäß Matrix.
- **Berichte und Terminabrechnung:** ausschließlich Summen, keine Namen oder E-Mail-Adressen.
- **REST:** Wartelisten-Einträge (E-Mail gemäß Matrix), Scan-Antworten (Name gemäß Matrix und Einstellung).
- **Ausnahme Gästelisten:** Der Name des Gasts ist der Suchschlüssel an der Abendkasse und bleibt für
  Einlasspersonal sichtbar; E-Mail-Adressen der Gäste werden nicht ausgegeben.
- Partner-API-Schlüssel (Maschinenzugang) folgen den Einstellungen des Partners (`anonymous_buyer`), nicht
  dieser Matrix.

Anpassbar über `wpcap_pii_matrix`, `wpcap_pii_can_view` und `wpcap_pii_mask` (siehe [HOOKS.md](HOOKS.md)).

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

## Audit-Log: Zugriffe auf personenbezogene Daten

Alle Einträge stehen in `wpcap_audit_logs` (Wer = `user_id`, Was = `action`, Objekt = `object_type`/`object_id`, Details als JSON) und sind unter **Pass Captain → Audit-Log** (nur Administratoren, `manage_options`) nach Bereich, Aktion, Benutzer, Objekt und Datum filterbar. Sie werden mit der Audit-Frist gelöscht (Löschlauf, Schritt `audit_logs`, Standard 730 Tage, Filter `wpcap_privacy_retention_days`). Details enthalten nie Geheimnisse (Tokens, Hashes, Codes, IBAN, Schlüssel) und personenbezogene Werte nur maskiert (`e***@e***.com`, `E***`); Suchbegriffe werden ebenso maskiert.

| Bereich | Aktion | Wann |
|---|---|---|
| Ansicht | `pii_viewed` | Detailansicht mit Personendaten geöffnet: Bestellung (`order_detail`), Gästeliste eines Events (`guestlist`), Warteliste (`waitlist`), Umtausch eines Tickets (`exchange_ticket`), Kontingente eines Termins (`allocation_occurrence`), Veranstalter (`partner_detail`), Kassenbeleg-Suche (`pos_sale`). `details.view` nennt die Ansicht. |
| Ansicht | `pii_list_viewed` | Liste mit Personendaten, **einmal je Seitenaufruf und Filter**, nicht je Zeile: Bestellungen (`order_list`), Tickets (`ticket_list`), Check-ins (`checkin_list`), Erstattungen (`refund_queue`), Gästelisten-Einlass (`guestlist_checkin`), Umtausche (`exchange_list`), Einlass-Konflikte (`admission_conflicts`), Buchungen der Service-Zeitfenster (`service_bookings`), Veranstalter (`partner_list`), Audit-Log selbst (`audit_log`). `details.filters` und `details.rows`. |
| Ansicht | `pii_api_read` | Erfolgreicher GET auf eine REST-Route mit Personendaten: Warteliste (`waitlist_entries`), Gästelisten-Suche/Abholliste (`guestlist_search`, `guestlist_will_call`), Offline-Paket (`offline_package`), Partner-API Bestellungen/Tickets/Check-ins (`partner_*`, mit `key_id`). Erweiterbar über `wpcap_pii_audit_rest_routes`. |
| Export | `pii_exported` | Jede Datei/Druckansicht (ohne Entprellung), `details.kind`: `guestlist_csv`, `guestlist_print`, `invoice_pdf`, `invoice_html`, `pos_receipt`, `pos_tickets_pdf`, `zreport_html`, `zreport_csv`, `partner_settlement_csv`/`_html`, `partner_statement_csv`. |
| Export | bestehend | `attendee_list_csv`/`_html`, `no_show_list_csv`/`_html`, `print_batch`, `report_exported`, `accounting_exported`, `settlement_sheet_csv`/`_html`. |
| Datenschutz | `privacy_export_run`, `privacy_erasure_run` | Je Seite eines Plugin-Exporters/-Löschers der WordPress-Datenschutzwerkzeuge (`object_type` `privacy_request`, `object_id` = Anfrage-ID; Exporter/Löscher-Schlüssel, Seite, Anzahl bzw. entfernt/behalten, maskierte E-Mail). Erste Seite immer, weitere nur mit Daten. |
| Datenschutz | `privacy_export_completed`, `privacy_erasure_completed` | WordPress hat die Exportdatei erstellt (`wp_privacy_personal_data_exported`) bzw. die Löschanfrage abgeschlossen (`wp_privacy_personal_data_erased`). Bestehend: `privacy_erasure` je anonymisierter Bestellung, `privacy_erasure_deferred`, `privacy_retention`. |
| Rollen | `user_role_set`, `user_role_added`, `user_role_removed` | WordPress-Rollenänderung (`set_user_role`, `add_user_role`, `remove_user_role`) mit einer Plugin-Rolle oder einer Rolle mit `wpcap_*`-Rechten (z. B. Administrator); `object_type` `user`, Handelnde Person = `user_id`. |
| Rollen | `user_cap_added`, `user_cap_removed` | Einzelrecht `wpcap_*` direkt am Benutzer vergeben/entzogen (z. B. `wpcap_checkin` durch die Teamzuordnung eines Events). Bestehend: `staff_assigned`/`staff_removed` (Event-Team), `admission_area_staff_added`/`admission_area_staff_removed`, `partner_users_set`, `pos_cashier_added`. |
| Korrekturen | `record_corrected` | Personal hat Personendaten korrigiert, `details.changes`: Feldname → maskiertes `from`/`to`. Quellen: Teilnehmerkorrektur im Ticket-Admin (`object_type` `ticket`, mit Grund; der Ticketcode bleibt), neue E-Mail beim erneuten Senden einer Gästelisten-Freikarte (`guest_entry`). `billing_updated` (Bestellportal) enthält dieselbe maskierte Änderungsliste. Bestehend: `ticket_holder_changed`, `ticket_assigned`, `ticket_reissued`. |

**Entprellung:** Ansichten und REST-Lesezugriffe derselben Person auf dasselbe Objekt mit derselben Ansicht und denselben Filtern werden innerhalb von 10 Minuten nur einmal protokolliert (Filter `wpcap_pii_audit_window`, `0` = jeden Aufruf). Geprüft wird direkt im Audit-Log über einen Fingerabdruck (`details.fp`), ohne zusätzliche Optionen oder Transients. Exporte, Datenschutzläufe, Rollen und Korrekturen werden immer protokolliert.

**Nicht protokolliert:** einzelne Scans am Einlass (stehen als Check-in in `wpcap_checkins`), Käuferzugriffe über den eigenen Bestell- oder Ticketlink (betreffen die eigenen Daten).

## Offenlegung

Sicherheitsprobleme bitte über GitHubs private Meldemöglichkeit des Repositorys melden, sofern sie aktiviert ist. Keine Ticketcodes, Kundendaten, API-Schlüssel oder funktionsfähigen Exploits in öffentlichen Issues posten. Eine unabhängige Prüfung und ein formeller Incident-Prozess stehen noch aus.

## Release-Gate

Vor 1.0 müssen Bedrohungsmodell, Rollen-/Nonce-Prüfung, SQL-Transaktionen, Datenschutzexport und -löschung, Offline-Konflikte, Abhängigkeitsprüfung und Wiederherstellung extern oder durch eine dokumentierte unabhängige Prüfung bewertet werden. Dieses Dokument ist keine Sicherheitszertifizierung.
