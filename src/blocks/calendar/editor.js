( function ( blocks, element, serverSideRender, blockEditor, components, i18n ) {
	'use strict';
	var el = element.createElement;
	var __ = i18n.__;
	var sprintf = i18n.sprintf ? i18n.sprintf : function ( format ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return String( format ).replace( /%d/g, function () { return String( args.shift() ); } );
	};
	// A502: published events with upcoming dates (labels with the next date and the ID).
	var choices = ( window.WPCAP_BLOCK_CALENDAR && window.WPCAP_BLOCK_CALENDAR.events ) || [];
	// A451: the server-rendered preview has real links (month navigation, checkout, .ics); inside
	// Disabled they cannot be followed or focused in the editor.
	function preview( child ) {
		return components.Disabled ? el( components.Disabled, {}, child ) : child;
	}
	// A501: a shortcode block (or pasted shortcode) [wpcap_calendar …] becomes this block.
	var fromShortcode = {
		type: 'shortcode',
		tag: [ 'wpcap_calendar', 'wp_pass_captain_calendar' ],
		attributes: {
			view: { type: 'string', shortcode: function ( a ) { return 'list' === String( a.named.view || '' ).toLowerCase() ? 'list' : 'month'; } },
			eventId: { type: 'integer', shortcode: function ( a ) { var n = parseInt( a.named.event, 10 ); return n > 0 ? n : 0; } },
			limit: { type: 'integer', shortcode: function ( a ) { var n = parseInt( a.named.limit, 10 ); return n > 0 ? Math.min( 200, n ) : 50; } }
		}
	};
	blocks.registerBlockType( 'wp-pass-captain/calendar', {
		transforms: { from: [ fromShortcode ] },
		edit: function ( props ) {
			var blockProps = blockEditor.useBlockProps ? blockEditor.useBlockProps() : {};
			var attrs = props.attributes;
			var eventId = parseInt( attrs.eventId, 10 ) || 0;
			var listed = choices.some( function ( e ) { return e.id === eventId; } );
			var eventOptions = [ { value: '0', label: __( 'Alle Events', 'pass-captain' ) } ];
			/* translators: %d: event ID */
			if ( eventId && ! listed ) { eventOptions.push( { value: String( eventId ), label: sprintf( __( 'Event #%d (nicht mehr verfügbar)', 'pass-captain' ), eventId ) } ); }
			eventOptions = eventOptions.concat( choices.map( function ( e ) { return { value: String( e.id ), label: e.label }; } ) );
			var eventControl = choices.length || eventId ? el( components.SelectControl, {
				label: __( 'Event', 'pass-captain' ),
				value: String( eventId ),
				options: eventOptions,
				onChange: function ( value ) { props.setAttributes( { eventId: parseInt( value, 10 ) || 0 } ); }
			} ) : null;
			var limitControl = 'list' === attrs.view ? el( components.TextControl, {
				label: __( 'Höchstens so viele Termine', 'pass-captain' ),
				type: 'number',
				min: 1,
				max: 200,
				value: String( attrs.limit || 50 ),
				help: __( 'Gilt für die Listenansicht (1–200).', 'pass-captain' ),
				onChange: function ( value ) { var n = parseInt( value, 10 ) || 50; props.setAttributes( { limit: Math.max( 1, Math.min( 200, n ) ) } ); }
			} ) : null;
			return el( 'div', blockProps,
				el( blockEditor.InspectorControls, null,
					el( components.PanelBody, { title: __( 'Ansicht', 'pass-captain' ) },
						el( components.SelectControl, {
							label: __( 'Darstellung', 'pass-captain' ),
							value: attrs.view,
							options: [
								{ label: __( 'Monat', 'pass-captain' ), value: 'month' },
								{ label: __( 'Liste', 'pass-captain' ), value: 'list' }
							],
							onChange: function ( value ) { props.setAttributes( { view: value } ); }
						} ),
						eventControl,
						limitControl
					)
				),
				preview( el( serverSideRender, { block: 'wp-pass-captain/calendar', attributes: attrs } ) )
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.serverSideRender, window.wp.blockEditor, window.wp.components, window.wp.i18n );
