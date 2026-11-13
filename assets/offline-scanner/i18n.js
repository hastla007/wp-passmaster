/**
 * Offline scanner translations (static, works offline – the PWA runs outside WordPress).
 * Source strings are German; catalogs map them per language. The language comes from ?lang=
 * (set by the admin page from the WordPress user locale), is remembered on the device and falls
 * back to the browser language. Missing strings fall back to German. %1, %2 … are placeholders.
 * New languages: add a catalog below (keys = German source text).
 */
(function () {
	'use strict';

	var CATALOGS = {
		en: {
			'Offline-Scanner': 'Offline scanner',
			'Offline-Scanner · WP Pass Captain': 'Offline scanner · WP Pass Captain',
			'Noch nicht hochgeladene Offline-Scans': 'Offline scans not yet uploaded',
			'%1 offen': '%1 pending',
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
			'64-stelliger Code oder Ticket-Link': '64-character code or ticket link',
			'Prüfen': 'Check',
			'Konflikte nach Synchronisierung': 'Conflicts after sync',
			'Letzte Scans': 'Recent scans',
			'Gerät': 'Device',
			'Gerät entkoppeln': 'Unpair device',
			'Einlass OK': 'Entry OK',
			'Bereits verwendet': 'Already used',
			'Widerrufen': 'Revoked',
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
			'Prüfpaket ungültig: %1': 'Verification package invalid: %1',
			'Prüfpaket abgelaufen – bitte online aktualisieren.': 'Verification package expired – please refresh online.',
			'%1 Tickets im Paket · signiert (Ed25519, Schlüssel %2) · Stand %3': '%1 tickets in package · signed (Ed25519, key %2) · as of %3',
			'Gültig bis %1 · Doppelscans: %2': 'Valid until %1 · duplicate scans: %2',
			'erste Synchronisierung gewinnt': 'first sync wins',
			'zur Prüfung': 'for review',
			'Kein Bereich': 'No area',
			'Kein Termin gewählt': 'No date selected',
			'Fehler': 'Error',
			'Einlass OK (offline)': 'Entry OK (offline)',
			'Mehrfachticket – Prüfung beim Sync': 'Multi-entry ticket – checked on sync',
			'offline': 'offline',
			'wartet auf Sync': 'waiting for sync',
			'Konflikt': 'Conflict',
			'synchronisiert': 'synced',
			'Offline-Dauer überschritten': 'Offline duration exceeded',
			'automatisch geklärt': 'resolved automatically',
			'zur Prüfung (#%1)': 'for review (#%1)',
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
			'Kopplung fehlgeschlagen': 'Pairing failed'
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
		var args = arguments;
		return String(text).replace(/%(\d)/g, function (m, n) { return args[+n] !== undefined ? String(args[+n]) : m; });
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
