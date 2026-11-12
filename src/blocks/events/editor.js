( function ( blocks, element, serverSideRender, blockEditor ) {
	'use strict';
	var el = element.createElement;
	blocks.registerBlockType( 'wp-pass-captain/events', {
		edit: function () {
			var props = blockEditor.useBlockProps ? blockEditor.useBlockProps() : {};
			return el( 'div', props, el( serverSideRender, { block: 'wp-pass-captain/events' } ) );
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.serverSideRender, window.wp.blockEditor );
