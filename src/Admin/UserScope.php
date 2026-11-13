<?php
/**
 * Access scope of a staff member (A119) on the WordPress user profile: locations and partners the
 * user is limited to (Access::scope()). Only administrators see and change it; administrators
 * themselves are never scoped.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Access;
use PassCaptain\Privacy;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class UserScope {
	const NONCE = 'wpcap_user_scope';

	public static function register() {
		add_action( 'edit_user_profile', array( __CLASS__, 'render' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save' ) );
	}

	/** Raw stored scope (for the form). */
	public static function stored( $user_id ) {
		$raw = get_user_meta( (int) $user_id, Access::SCOPE_META, true );
		return Access::normalize( is_array( $raw ) ? $raw : array() );
	}

	public static function render( $user ) {
		if ( ! current_user_can( 'manage_options' ) || ! is_object( $user ) ) { return; }
		$scope = self::stored( (int) $user->ID );
		$partners = Privacy::has_table( 'wpcap_partners' ) ? \PassCaptain\Partners\Repository::all() : array();
		?>
		<h2><?php esc_html_e( 'Pass Captain: Zugriffsbereich', 'pass-captain' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Optional: beschränkt diese Person zusätzlich zu ihren Event-Zuweisungen auf Spielorte und/oder Veranstalter. Leer lassen = keine zusätzliche Einschränkung. Administratoren sind nie eingeschränkt.', 'pass-captain' ); ?></p>
		<?php wp_nonce_field( self::NONCE, self::NONCE . '_nonce' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="wpcap_scope_locations"><?php esc_html_e( 'Spielorte', 'pass-captain' ); ?></label></th>
				<td>
					<textarea id="wpcap_scope_locations" name="wpcap_scope_locations" rows="3" class="regular-text"><?php echo esc_textarea( implode( "\n", $scope['locations'] ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Ein Ort pro Zeile, genau wie beim Termin eingetragen (Groß-/Kleinschreibung egal). Die Person sieht nur Termine an diesen Orten.', 'pass-captain' ); ?></p>
				</td>
			</tr>
			<?php if ( $partners ) : ?>
			<tr>
				<th><?php esc_html_e( 'Veranstalter', 'pass-captain' ); ?></th>
				<td>
					<label><input type="checkbox" name="wpcap_scope_linked_partners" value="1" <?php checked( $scope['linked_partners'] ); ?>> <?php esc_html_e( 'Nur Events der Veranstalter, mit denen diese Person verknüpft ist', 'pass-captain' ); ?></label><br>
					<?php foreach ( $partners as $partner ) : ?>
						<label><input type="checkbox" name="wpcap_scope_partners[]" value="<?php echo (int) $partner->partner_id; ?>" <?php checked( in_array( (int) $partner->partner_id, (array) $scope['partners'], true ) ); ?>> <?php echo esc_html( $partner->name ); ?></label><br>
					<?php endforeach; ?>
				</td>
			</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	public static function save( $user_id ) {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST[ self::NONCE . '_nonce' ] ) ) { return; }
		check_admin_referer( self::NONCE, self::NONCE . '_nonce' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$input = array(
			'locations' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['wpcap_scope_locations'] ?? '' ) ) ),
			'partners' => array_map( 'absint', (array) ( $_POST['wpcap_scope_partners'] ?? array() ) ),
			'linked_partners' => ! empty( $_POST['wpcap_scope_linked_partners'] ),
		);
		// phpcs:enable
		Access::set_scope( (int) $user_id, $input );
	}
}
