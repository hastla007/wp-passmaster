/**
 * A303: former block names wp-pass-captain/events and wp-pass-captain/calendar (stored in post content
 * before 1.5.0). The server registers them as hidden aliases that render as before; in the editor they
 * are replaced by pass-captain/events and pass-captain/calendar with the same attributes, so saving
 * the post stores the current name. A manual "Transform to" is offered as well.
 */
( function ( blocks, element, blockEditor, data, i18n ) {
	'use strict';
	var el = element.createElement;
	var __ = i18n && i18n.__ ? i18n.__ : function ( s ) { return s; };
	var NAMES = { 'wp-pass-captain/events': 'pass-captain/events', 'wp-pass-captain/calendar': 'pass-captain/calendar' };

	function convert( target, attributes ) {
		return blocks.createBlock( target, Object.assign( {}, attributes || {} ) );
	}

	Object.keys( NAMES ).forEach( function ( legacy ) {
		var target = NAMES[ legacy ];
		blocks.registerBlockType( legacy, {
			apiVersion: 3,
			title: __( 'Pass Captain (alter Blockname)', 'pass-captain' ),
			category: 'widgets',
			supports: { inserter: false, html: false, className: true, customClassName: true, align: [ 'wide', 'full' ], spacing: { margin: true, padding: true } },
			transforms: { to: [ { type: 'block', blocks: [ target ], transform: function ( attributes ) { return convert( target, attributes ); } } ] },
			edit: function ( props ) {
				var blockProps = blockEditor.useBlockProps ? blockEditor.useBlockProps() : {};
				var clientId = props.clientId;
				var attributes = props.attributes;
				element.useEffect( function () {
					var editor = data && data.dispatch ? data.dispatch( 'core/block-editor' ) : null;
					if ( editor && editor.replaceBlocks && clientId ) { editor.replaceBlocks( clientId, convert( target, attributes ) ); }
				}, [ clientId ] );
				return el( 'div', blockProps, __( 'Dieser Block wird auf seinen neuen Namen umgestellt …', 'pass-captain' ) );
			},
			save: function () {
				return null;
			}
		} );
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.data, window.wp.i18n );
