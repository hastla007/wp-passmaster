/**
 * Offline scanner translations (static, works offline – the PWA runs outside WordPress).
 * Source strings are German; catalogs map them per language. The language comes from ?lang=
 * (set by the admin page from the WordPress user locale), is remembered on the device and falls
 * back to the browser language. Missing strings fall back to German. %1, %2 … and printf-style
 * %s/%d/%1$s are placeholders (app.js fills them; the server table of GET /app/offline/i18n wins).
 * New languages: add a catalog below (keys = German source text).
 */
(function () {
	'use strict';

	var CATALOGS = {
		en: {
			'Offline-Scanner': 'Offline scanner',
			'Offline-Scanner · Pass Captain': 'Offline scanner · Pass Captain',
			'Noch nicht hochgeladene Offline-Scans': 'Offline scans not yet uploaded',
			'%d offen': '%d pending',
			'Gerät koppeln': 'Pair device',
			'Im WordPress-Admin unter „Pass Captain → Scanner-Geräte“ einen Kopplungscode für die gewünschten Events erstellen und hier eingeben. Danach unter „Offline-Scanner“ das Gerät dem Event zuweisen.': 'In the WordPress admin, create a pairing code for the events under “Pass Captain → Scanner devices” and enter it here. Then assign the device to the event under “Offline scanner”.',
			'Kopplungscode': 'Pairing code',
			'Gerätename': 'Device name',
			'z. B. Einlass Nord': 'e.g. North entrance',
			'Koppeln': 'Pair',
			'Server:': 'Server:',
			'Termin': 'Date',
			'Einlassbereich': 'Entry area',
			'Paket aktualisieren': 'Refresh package',
			'Jetzt synchronisieren': 'Sync now',
			'Bereit': 'Ready',
			'Kamera starten': 'Start camera',
			'Kamera stoppen': 'Stop camera',
			'Ticketcode manuell': 'Ticket code (manual)',
			'Ticket-Code, Ticket-Link oder Hilfscode (z. B. K7QM-4XWA)': 'Ticket code, ticket link or help code (e.g. K7QM-4XWA)',
			'Prüfen': 'Check',
			'Konflikte nach Synchronisierung': 'Conflicts after sync',
			'Letzte Scans': 'Recent scans',
			'Gerät': 'Device',
			'Gerät entkoppeln': 'Unpair device',
			'Einlass OK': 'Entry OK',
			'Bereits verwendet': 'Already used',
			'Widerrufen': 'Revoked',
			'Code ersetzt': 'Code replaced',
			'Unbekanntes Ticket': 'Unknown ticket',
			'Falsches Event': 'Wrong event',
			'Falscher Termin': 'Wrong date',
			'Falscher Einlassbereich': 'Wrong entry area',
			'Termin abgesagt': 'Date cancelled',
			'Außerhalb des Einlasszeitraums': 'Outside the admission period',
			'Heute nicht gültig': 'Not valid today',
			'Tageskontingent erreicht': 'Daily quota reached',
			'Einlasslimit erreicht': 'Entry limit reached',
			'Außerhalb des Einlassfensters': 'Outside the entry window',
			'Bereits drinnen': 'Already inside',
			'Bereich voll': 'Area full',
			'Kein Einlasszugriff': 'No entry access',
			'Prüfpaket abgelaufen': 'Verification package expired',
			'Kein gültiges Prüfpaket': 'No valid verification package',
			'Kein Ticketcode erkannt': 'No ticket code recognised',
			'Gerät nicht mehr gekoppelt': 'Device no longer paired',
			'Token abgelaufen oder widerrufen. Bitte neu koppeln.': 'Token expired or revoked. Please pair again.',
			'Unbekannter Signaturschlüssel': 'Unknown signing key',
			'Signaturschlüssel abgelaufen': 'Signing key expired',
			'Signatur ungültig': 'Invalid signature',
			'Unbekanntes Paketformat': 'Unknown package format',
			'Schlüssel-ID passt nicht': 'Key ID does not match',
			'Paket gehört zu einem anderen Gerät': 'Package belongs to another device',
			'Paket passt nicht zum Termin': 'Package does not match the date',
			'Delta-Kette unterbrochen': 'Delta chain broken',
			'Delta passt nicht zum gespeicherten Paket': 'Delta does not match the stored package',
			'Keine zugewiesenen Termine': 'No assigned dates',
			'Noch kein Prüfpaket geladen.': 'No verification package loaded yet.',
			'Prüfpaket ungültig: %s': 'Verification package invalid: %s',
			'Prüfpaket abgelaufen – bitte online aktualisieren.': 'Verification package expired – please refresh online.',
			'%1$d Tickets im Paket · signiert (Ed25519, Schlüssel %2$s) · Stand %3$s': '%1$d tickets in package · signed (Ed25519, key %2$s) · as of %3$s',
			'Gültig bis %1$s · Doppelscans: %2$s': 'Valid until %1$s · duplicate scans: %2$s',
			'erste Synchronisierung gewinnt': 'first sync wins',
			'zur Prüfung': 'for review',
			'Kein Bereich': 'No area',
			'Kein Termin gewählt': 'No date selected',
			'Fehler': 'Error',
			'Rückgängig': 'Undo',
			'Antippen zum Schließen': 'Tap to close',
			'Eingecheckt: %1 / %2': 'Checked in: %1 / %2',
			'Ticket suchen': 'Find ticket',
			'Name, E-Mail oder Bestellnummer': 'Name, e-mail or order number',
			'Suchen': 'Search',
			'Einchecken': 'Check in',
			'Bestellung %1': 'Order %1',
			'bereits eingecheckt': 'already checked in',
			'widerrufen/erstattet': 'revoked/refunded',
			'Kein Ticket gefunden.': 'No ticket found.',
			'Weitere Treffer – bitte genauer suchen.': 'More results – please search more precisely.',
			'Bitte mindestens 3 Zeichen eingeben.': 'Please enter at least 3 characters.',
			'Offline ist nur die Namenssuche möglich, und nur wenn das Prüfpaket Namen enthält.': 'Offline, only names can be searched, and only if the package contains names.',
			'Manuell (Suche)': 'Manual (search)',
			'laut Prüfpaket': 'from the package',
			'Stand %1': 'as of %1',
			'Rückgängig (%1 s)': 'Undo (%1 s)',
			'Grund der Rücknahme': 'Reason for the undo',
			'– Grund wählen –': '– choose a reason –',
			'Anderer Grund …': 'Other reason …',
			'Einlass zurücknehmen': 'Undo admission',
			'Abbrechen': 'Cancel',
			'Fehlscan (falsches Ticket gescannt)': 'Wrong scan (wrong ticket scanned)',
			'Doppelt gescannt': 'Scanned twice',
			'Gast hat den Einlass nicht betreten': 'Guest did not enter',
			'Falscher Termin oder Bereich': 'Wrong date or area',
			'Bitte einen Grund wählen oder eingeben.': 'Please choose or enter a reason.',
			'Synchronisierung läuft – bitte gleich erneut versuchen.': 'Sync in progress – please try again in a moment.',
			'Einlass zurückgenommen': 'Admission undone',
			'Offline-Einlass verworfen – er wird nicht hochgeladen.': 'Offline admission discarded – it will not be uploaded.',
			'Das Ticket ist wieder gültig.': 'The ticket is valid again.',
			'Rückgängig nicht möglich': 'Undo not possible',
			'Nur online möglich.': 'Only possible online.',
			'zurückgenommen': 'undone',
			'Systemfehler – Ticket nicht geprüft': 'System error – ticket not checked',
			'Bitte erneut scannen.': 'Please scan again.',
			'Mehrere Ticketcodes erkannt': 'Several ticket codes detected',
			'Nichts geprüft. Bitte jedes Ticket einzeln scannen.': 'Nothing was checked. Please scan each ticket on its own.',
			'Einlass OK (offline)': 'Entry OK (offline)',
			'Mehrfachticket – Prüfung beim Sync': 'Multi-entry ticket – checked on sync',
			'offline': 'offline',
			'wartet auf Sync': 'waiting for sync',
			'Konflikt': 'Conflict',
			'synchronisiert': 'synced',
			'Offline-Dauer überschritten': 'Offline duration exceeded',
			'automatisch geklärt': 'resolved automatically',
			'zur Prüfung (#%d)': 'for review (#%d)',
			'Keine Konflikte.': 'No conflicts.',
			'Online': 'Online',
			'Offline': 'Offline',
			'Kamera nicht verfügbar': 'Camera not available',
			'Der Kopplungscode gehört zu einem anderen Server.': 'The pairing code belongs to another server.',
			'Offline-Scanner (PWA)': 'Offline scanner (PWA)',
			'Gerät entkoppeln? Nicht synchronisierte Scans gehen verloren.': 'Unpair device? Scans not yet synced will be lost.',
			'Browser nicht unterstützt': 'Browser not supported',
			'Benötigt HTTPS, WebCrypto und IndexedDB.': 'Requires HTTPS, WebCrypto and IndexedDB.',
			'Gekoppelt': 'Paired',
			'Jetzt im Admin unter „Offline-Scanner“ dem Event zuweisen.': 'Now assign it to the event in the admin under “Offline scanner”.',
			'Kopplung fehlgeschlagen': 'Pairing failed',
			'Ton an': 'Sound on',
			'Ton aus': 'Sound off',
			'Erstmals eingelassen': 'First admission',
			'Einlass Nr. %1 – zuerst eingelassen um %2': 'Admission no. %1 – first admitted at %2',
			'Bereits eingelassen um %1': 'Already admitted at %1',
			'Per Hilfscode geprüft': 'Checked by help code',
			'Hilfscode nur online': 'Help code only online',
			'Hilfscodes können nur online geprüft werden. Bitte QR-Code scannen oder den langen Ticket-Code eingeben.': 'Help codes can only be checked online. Please scan the QR code or enter the long ticket code.',
			'Zu viele Hilfscode-Eingaben': 'Too many help code entries',
			'online': 'online',
			'Prüfpaket wird geladen … %d kB': 'Loading verification package … %d kB',
			'Eben hier eingelassen (vor %d s)': 'Just admitted here (%d s ago)',
			'Prüfe …': 'Checking …',
			'QR-Signatur ungültig': 'QR signature invalid',
			'Bitte erneut scannen': 'Please scan again',
			'Taschenlampe': 'Torch'
		}
	};
	var SUPPORTED = ['de'].concat(Object.keys(CATALOGS));

	function pick(tag) {
		var base = String(tag || '').toLowerCase().replace('_', '-').split('-')[0];
		return SUPPORTED.indexOf(base) >= 0 ? base : '';
	}

	var lang = '';
	var param = '';
	try { param = new URLSearchParams(location.search).get('lang') || ''; } catch (e) { param = ''; }
	lang = pick(param);
	try {
		if (lang) { localStorage.setItem('wpcap_scanner_lang', lang); }
		else { lang = pick(localStorage.getItem('wpcap_scanner_lang')); }
	} catch (e) { /* storage blocked: language only for this visit */ }
	if (!lang) {
		var prefs = navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language];
		for (var i = 0; i < prefs.length && !lang; i++) { lang = pick(prefs[i]); }
	}
	lang = lang || 'de';
	var catalog = CATALOGS[lang] || {};
	var LOCALES = { de: 'de-DE', en: 'en-GB' };

	function t(source) {
		var text = Object.prototype.hasOwnProperty.call(catalog, source) ? catalog[source] : source;
		var args = Array.prototype.slice.call(arguments, 1);
		if (!args.length) { return String(text); }
		// Same placeholder rules as app.js t(): printf-style %s/%d/%1$s and the bare %1 … of the catalogs.
		var next = 0;
		return String(text).replace(/%(?:(\d+)\$)?([sd%])|%(\d)/g, function (m, pos, type, bare) {
			if ('%' === type) { return '%'; }
			var v = args[bare ? Number(bare) - 1 : (pos ? Number(pos) - 1 : next++)];
			if (v === undefined || v === null) { return bare ? m : ''; }
			return 'd' === type ? String(Math.trunc(Number(v)) || 0) : String(v);
		});
	}

	/** Translates data-i18n (text), data-i18n-placeholder and data-i18n-title of a document. */
	function apply(root) {
		root.documentElement.lang = lang;
		Array.prototype.forEach.call(root.querySelectorAll('[data-i18n]'), function (node) { node.textContent = t(node.textContent.trim()); });
		Array.prototype.forEach.call(root.querySelectorAll('[data-i18n-placeholder]'), function (node) { node.placeholder = t(node.getAttribute('placeholder')); });
		Array.prototype.forEach.call(root.querySelectorAll('[data-i18n-title]'), function (node) { node.title = t(node.getAttribute('title')); });
		root.title = t(root.title);
	}

	window.WPCAP_I18N = { lang: lang, locale: LOCALES[lang] || lang, t: t, apply: apply };
}());
