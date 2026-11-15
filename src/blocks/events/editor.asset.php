<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Dependencies of the block editor script (no build step required). */
return array(
	'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-server-side-render', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
	'version'      => '0.4.0',
);
