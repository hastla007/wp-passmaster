<?php
/**
 * Module: print templates (ticket, badge, name tag, wristband PDFs), attendee lists and invoice PDFs.
 * Code lives in src/Printing/.
 */
namespace PassCaptain\Modules;

use PassCaptain\Printing\InvoicePdf;
use PassCaptain\Printing\Page;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PrintTemplates {
	public static function register() {
		add_filter( 'wpcap_ticket_mail', array( InvoicePdf::class, 'attach_to_ticket_mail' ), 20, 3 );
		add_action( 'template_redirect', array( InvoicePdf::class, 'maybe_customer_download' ) );
	}

	public static function schema( $prefix, $charset ) {
		return array(
			"CREATE TABLE {$prefix}wpcap_print_templates (
				template_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL,
				kind varchar(20) NOT NULL,
				body text NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				updated_at datetime NOT NULL,
				PRIMARY KEY  (template_id),
				KEY kind_status (kind,status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_print_event_templates (
				event_id bigint(20) unsigned NOT NULL,
				kind varchar(20) NOT NULL,
				template_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (event_id,kind)
			) ENGINE=InnoDB $charset;",
		);
	}

	public static function settings_defaults() {
		return array( 'print_attach_invoice_pdf' => 0 );
	}

	public static function admin_pages() {
		return array( array( Page::SLUG, __( 'Druck & Listen', 'wp-pass-captain' ), Page::menu_cap(), array( Page::class, 'render' ) ) );
	}

	public static function admin_handlers() {
		return array(
			'wpcap_print_save_template' => array( Page::class, 'handle_save_template' ),
			'wpcap_print_assign'        => array( Page::class, 'handle_assign' ),
			'wpcap_print_batch'         => array( Page::class, 'handle_batch' ),
			'wpcap_attendee_list'       => array( Page::class, 'handle_attendee_list' ),
			'wpcap_print_settings'      => array( Page::class, 'handle_settings' ),
			'wpcap_invoice_pdf'         => array( InvoicePdf::class, 'handle_admin_download' ),
		);
	}
}
