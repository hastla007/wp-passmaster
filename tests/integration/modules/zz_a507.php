<?php
/**
 * A507: one-time secrets (API keys, partner keys, pairing codes) are stored encrypted per user and read once.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Security\OneTimeSecret;
use PassCaptain\Security\Vault;

echo "A507: one-time secrets\n";
OneTimeSecret::put( 'a507', array( 'key' => 'wpcap_secret_value_123' ), 600 );
$a507_raw = (string) ( $GLOBALS['wpcap_test_transients'][ 'wpcap_once_a507_' . get_current_user_id() ] ?? '' );
check( 'A507: the stored value is not plaintext (when the vault is available)', ! Vault::available() || ( '' !== $a507_raw && false === strpos( $a507_raw, 'wpcap_secret_value_123' ) && Vault::is_encrypted( $a507_raw ) ) );
check( 'A507: read once, then gone', array( 'key' => 'wpcap_secret_value_123' ) === OneTimeSecret::take( 'a507' ) && null === OneTimeSecret::take( 'a507' ) );
$a507_uid = $GLOBALS['wpcap_test_user_id'] ?? null;
OneTimeSecret::put( 'a507', 'mine', 600 );
$GLOBALS['wpcap_test_user_id'] = 98765;
check( 'A507: another user cannot read it', null === OneTimeSecret::take( 'a507' ) );
if ( null === $a507_uid ) { unset( $GLOBALS['wpcap_test_user_id'] ); } else { $GLOBALS['wpcap_test_user_id'] = $a507_uid; }
check( 'A507: the owner still can', 'mine' === OneTimeSecret::take( 'a507' ) );
$a507_src = '';
foreach ( array( 'src/Admin/DeveloperKeysPage.php', 'src/Admin/ApiKeysPage.php', 'src/Partners/AdminPage.php', 'src/Admin/DevicesPage.php' ) as $f ) { $a507_src .= (string) file_get_contents( WPCAP_PATH . $f ); }
check( 'A507: no admin screen keeps a one-time secret in a plain transient', false === strpos( $a507_src, 'set_transient(' ) );
