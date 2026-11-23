<?php
/**
 * Module: print templates (ticket, badge, name tag, wristband PDFs), attendee lists and invoice PDFs.
 * Code lives in src/Printing/.
 */
namespace PassCaptain\Modules;

use PassCaptain\Printing\BatchJob;
use PassCaptain\Printing\InvoicePdf;
use PassCaptain\Printing\Page;

if ( ! defined( 'ABSPATH' ) ) { exit; }

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
		return array( 'print_attach_invoice_pdf' => 0 );
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
			'wpcap_invoice_pdf'         => array( InvoicePdf::class, 'handle_admin_download' ),
		);
	}
}
