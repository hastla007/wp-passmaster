<?php
/**
 * Module: print templates (ticket, badge, name tag, wristband PDFs), attendee lists and invoice PDFs.
 * Code lives in src/Printing/.
 */
namespace PassCaptain\Modules;

use PassCaptain\Printing\BatchJob;
use PassCaptain\Printing\InvoicePdf;
use PassCaptain\Printing\Page;
use PassCaptain\Printing\Templates;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class PrintTemplates {
	public static function register() {
		add_filter( 'wpcap_ticket_mail', array( InvoicePdf::class, 'attach_to_ticket_mail' ), 20, 3 );
		add_action( 'template_redirect', array( InvoicePdf::class, 'maybe_customer_download' ) );
		BatchJob::register();
	}

	public static function schema( $prefix, $charset ) {
		return array(
			"CREATE TABLE {$prefix}wpcap_print_templates (
				template_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL,
				kind varchar(20) NOT NULL,
				owner_event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				body text NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				updated_at datetime NOT NULL,
				PRIMARY KEY  (template_id),
				KEY kind_status (kind,status),
				KEY owner_event_id (owner_event_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_print_event_templates (
				event_id bigint(20) unsigned NOT NULL,
				kind varchar(20) NOT NULL,
				template_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (event_id,kind)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_print_batches (
				batch_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				token char(32) NOT NULL,
				occurrence_id bigint(20) unsigned NOT NULL,
				kind varchar(20) NOT NULL,
				with_codes tinyint(1) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'rendering',
				total int(10) unsigned NOT NULL DEFAULT 0,
				done int(10) unsigned NOT NULL DEFAULT 0,
				pages int(10) unsigned NOT NULL DEFAULT 0,
				pdf_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
				xref_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
				codes_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
				held_by_other int(10) unsigned NOT NULL DEFAULT 0,
				rotated int(10) unsigned NOT NULL DEFAULT 0,
				skipped int(10) unsigned NOT NULL DEFAULT 0,
				notified int(10) unsigned NOT NULL DEFAULT 0,
				error varchar(64) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				delivered_at datetime NULL,
				PRIMARY KEY  (batch_id),
				UNIQUE KEY token (token),
				KEY occurrence_id (occurrence_id),
				KEY updated (status,updated_at)
			) ENGINE=InnoDB $charset;",
		);
	}

	public static function settings_defaults() {
		return array(
			'print_attach_invoice_pdf' => 0,
			// SET-PC-33: logo (media id, 0 = none) and footer of ticket PDFs ('' = built-in text), footer per
			// language (locale => text), imprint/privacy links in the footer. Per event: EventOverrides.
			// PASS-FE-07: start design of the ticket PDF ('' = built-in layout, otherwise a ticket starter of
			// Printing\Templates::starters()) for events without an own ticket template.
			'ticket_pdf' => array( 'logo' => 0, 'footer' => '', 'translations' => array(), 'legal_links' => 1, 'design' => '' ),
		);
	}

	/** Settings register entries of this module (SET-PC-01, SettingsSchema). */
	public static function settings_schema() {
		return array(
			'print_attach_invoice_pdf' => array( 'type' => 'bool', 'label' => __( 'Rechnung als PDF anhängen', 'pass-captain' ), 'help' => __( 'Rechnungen werden als PDF an die Bestätigungsmail angehängt.', 'pass-captain' ), 'page' => 'wpcap-print' ),
			'ticket_pdf' => array(
				'type' => 'group',
				'label' => __( 'Ticket-PDF: Logo und Fußzeile', 'pass-captain' ),
				'help' => __( 'Logo und Fußzeile der Ticket-PDFs (E-Mail, Bestellportal, Verwaltung), Fußzeile je Sprache, Links zu Impressum und Datenschutz; je Event übersteuerbar. Ein Logo in der Druckvorlage hat Vorrang, der Filter wpcap_ticket_pdf_footer ebenso.', 'pass-captain' ),
				'page' => 'wpcap-print',
				'fields' => array(
					'logo' => array( 'type' => 'int', 'min' => 0, 'max' => PHP_INT_MAX, 'label' => __( 'Logo (Mediathek-ID, JPEG oder PNG)', 'pass-captain' ) ),
					'footer' => array( 'type' => 'textarea', 'label' => __( 'Fußzeile', 'pass-captain' ) ),
					'legal_links' => array( 'type' => 'bool', 'label' => __( 'Impressum und Datenschutz in der Fußzeile verlinken', 'pass-captain' ) ),
					'design' => array( 'type' => 'choice', 'options' => Templates::ticket_designs(), 'label' => __( 'Startdesign des Ticket-PDFs', 'pass-captain' ) ),
				),
			),
		);
	}

	public static function admin_pages() {
		return array( array( Page::SLUG, __( 'Druck & Listen', 'pass-captain' ), Page::menu_cap(), array( Page::class, 'render' ) ) );
	}

	public static function admin_handlers() {
		return array(
			'wpcap_print_save_template' => array( Page::class, 'handle_save_template' ),
			'wpcap_print_starter'       => array( Page::class, 'handle_starter' ),
			'wpcap_print_preview'       => array( Page::class, 'handle_preview' ),
			'wpcap_print_assign'        => array( Page::class, 'handle_assign' ),
			'wpcap_print_batch'         => array( Page::class, 'handle_batch' ),
			'wpcap_print_batch_download' => array( Page::class, 'handle_batch_download' ),
			'wpcap_print_batch_cancel'  => array( Page::class, 'handle_batch_cancel' ),
			'wpcap_attendee_list'       => array( Page::class, 'handle_attendee_list' ),
			'wpcap_print_settings'      => array( Page::class, 'handle_settings' ),
			'wpcap_print_ticket_pdf'    => array( Page::class, 'handle_ticket_pdf' ),
			'wpcap_invoice_pdf'         => array( InvoicePdf::class, 'handle_admin_download' ),
		);
	}
}
