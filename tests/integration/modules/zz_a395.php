<?php
// phpcs:ignoreFile
/**
 * A395: no focus loss after asynchronous actions of the order portal. A form whose button is
 * removed after success (transfer, refund request) moves the focus to its message (tabindex=-1);
 * buttons that were disabled during the request get the focus back. The waitlist form of the
 * event card is covered by tests/js/checkout-x3.test.js.
 */

echo "A395: focus after portal actions\n";
$a395_src = (string) file_get_contents( WPCAP_PATH . 'src/OrderPortal.php' );
$a395_start = strpos( $a395_src, '(function(){', strpos( $a395_src, 'ob_start(); // A302' ) );
$a395_end = strpos( $a395_src, '<?php echo Assets::inline_script_tag', $a395_start );
$a395_js = substr( $a395_src, $a395_start, $a395_end - $a395_start );
check( 'A395 portal script found', false !== $a395_start && false !== $a395_end && strlen( $a395_js ) > 1000 );
check( 'A395 settle() puts the message into the tab order (tabindex=-1) and focuses it', false !== strpos( $a395_js, "function settle(message,text){message.textContent=text;message.setAttribute('tabindex','-1');message.focus();}" ) );
check( 'A395 transfer: after removing label and button the confirmation gets the focus', false !== strpos( $a395_js, "form.querySelectorAll('label,button').forEach(function(n){n.remove();});settle(message,S.transferred);" ) );
check( 'A395 refund request: after removing the form controls the confirmation gets the focus', false !== strpos( $a395_js, "form.querySelectorAll('fieldset,label,button').forEach(function(n){n.remove();});settle(message,res.data.message||'');" ) );
check( 'A395 every button disabled during a request gets the focus back afterwards', 0 === substr_count( $a395_js, 'finally{button.disabled=false;}' ) && 3 === substr_count( $a395_js, 'finally{button.disabled=false;if(!document.activeElement||document.activeElement===document.body){button.focus();}}' ) );
// The inline script stays valid JavaScript (PHP values replaced by placeholders).
$a395_node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
if ( '' !== $a395_node ) {
	$a395_file = tempnam( sys_get_temp_dir(), 'a395' ) . '.js';
	file_put_contents( $a395_file, preg_replace( '/<\?php.*?\?>/s', '{}', $a395_js ) );
	exec( escapeshellarg( $a395_node ) . ' --check ' . escapeshellarg( $a395_file ) . ' 2>&1', $a395_out, $a395_rc );
	unlink( $a395_file );
	check( 'A395 the portal script is valid JavaScript' . ( $a395_rc ? ' – ' . implode( ' ', array_slice( $a395_out, 0, 3 ) ) : '' ), 0 === $a395_rc );
}
