# Sicherheitsmodell und Meldungen

## Schutzgrenzen

- Ticket-, Gastbestellungs- und API-Schlüssel werden als zufällige Geheimnisse ausgegeben; in der Datenbank werden nur Hashes gespeichert. Gastlinks sind Inhaberzugänge und dürfen nicht weitergegeben werden.
- WordPress REST-Schreibzugriffe prüfen Berechtigungen und Eventbereiche. Entwickler-API-Schlüssel sind auf ein Event sowie `events.read` und `reports.read` beschränkt und können ablaufen oder widerrufen werden.
- Öffentliche Checkout-, Sitzplatz- und Wartelistenrouten sind nicht authentifiziert. Sie benötigen Hosting-seitige Rate-Limits, TLS, Bot-Abwehr und Überwachung. Plugin-Transients sind kein verteilter Rate-Limiter. Checkout und Servicebuchungen begrenzen zusätzlich pro IP (Filter `wpcap_checkout_ip_limit`, Standard 30/Stunde, und `wpcap_service_booking_ip_limit`, Standard 40/Stunde); hinter einem Proxy muss `REMOTE_ADDR` korrekt gesetzt sein.
- Selbstbedienung im Bestellportal (A118) nutzt dieselbe Prüfung wie das Portal (Bestell-ID + Zugangstoken). Ticket-PDFs werden per POST mit Nonce abgerufen, je Bestellung und Client begrenzt und enthalten nur gültige, unbenutzte Tickets der eigenen Bestellung, die nicht an andere Personen übertragen oder zugewiesen sind. Da Ticketcodes nur gehasht gespeichert sind, erhält ein Ticket beim Download einen neuen Code (der alte wird ungültig), außer ein Modul kennt den aktuellen Code noch (`wpcap_ticket_known_code`, z. B. Wallet). Stornierungsanfragen (Nonce, Idempotenzschlüssel, Rate-Limit) landen immer als `pending_approval` in der Erstattungsfreigabe; ausgezahlt wird erst nach der Entscheidung einer berechtigten Person.
- Offline-Scanner speichern Codes lokal und können während einer Netzunterbrechung nicht garantieren, dass ein Ticket an einem anderen Gerät unbenutzt geblieben ist.
- Integrationsadapter werden standardmäßig nicht aktiviert. Externe Zugangsdaten gehören in geschützte WordPress-Konfiguration bzw. Secret-Storage, niemals in Quellcode oder Logs.

## Rollen, Zugriffsbereiche und Prüfreihenfolge

Jede Admin-Seite, jeder admin-post-Handler und jede authentifizierte REST-Route prüft in dieser Reihenfolge
(zentral in `PassCaptain\Access`):

1. **Capability der Rolle** (`wpcap_manage_events`, `wpcap_manage_orders`, `wpcap_checkin`, `wpcap_view_reports`,
   `wpcap_view_finance`, `wpcap_refund`, `wpcap_approve_refunds` …).
2. **Event-Zuweisung** in `wpcap_event_staff` für genau diese Capability (`Access::can_access_event()`).
   Finanzbuchhaltung (`wpcap_view_finance`) liest Berichte, Rechnungen und Kassenbelege ohne Zuweisung.
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
`transfer`, `resend`, `assign`, `billing`, `payout-account`, `exchange` – Zugangstoken im Callback),
Sitzplätze und Sitzplatz-Holds, Servicezeiten und -buchungen, Rabatt-Vorschau, Warteliste (Eintragen,
Angebot annehmen/ablehnen mit Token), Event-Übersetzung lesen, Zahlungs-Webhooks (Anbietersignatur),
SMS-Statusrückmeldungen (Signatur), Apple-Wallet-Webservice (Authentifizierungstoken des Passes),
Scanner-App-Kopplung (`/app/pair`, einmaliger Kopplungscode) und der öffentliche Saalplan
(`/seating/occurrences/{id}`). Alle übrigen Routen haben eine Berechtigungsprüfung; der Test
`tests/integration/modules/zz_a119.php` zählt alle registrierten Routen auf und schlägt fehl, wenn eine neue
Route `__return_true` nutzt, ohne in dieser Liste zu stehen.

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
| Ansicht | `pii_list_viewed` | Liste mit Personendaten, **einmal je Seitenaufruf und Filter**, nicht je Zeile: Bestellungen (`order_list`), Tickets (`ticket_list`), Check-ins (`checkin_list`), Erstattungen (`refund_queue`), Gästelisten-Einlass (`guestlist_checkin`), Umtausche (`exchange_list`), Einlass-Konflikte (`admission_conflicts`), Veranstalter (`partner_list`), Audit-Log selbst (`audit_log`). `details.filters` und `details.rows`. |
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
